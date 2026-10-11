<?php

/*
 * Phase 03A — Data Center import: REAL Laravel database-queue recovery verification.
 *
 * What it exercises (real components, separate OS processes, no mocks):
 *   - the `database` queue driver (`jobs` table, DatabaseQueue::pop / reserve / delete),
 *   - the ShouldBeUnique lock (cache store `database`, `cache_locks` table),
 *   - a real `php artisan queue:work --once` worker (reserve -> run -> delete),
 *   - ProcessImportBatch / ImportBatchService / ImportChunkProcessor exactly as committed.
 *
 * Scenario:
 *   1. Dispatch ProcessImportBatch twice -> exactly ONE queued job (the second dispatch is suppressed).
 *   2. Start a real worker; once it has committed some chunks, KILL it hard (the job stays reserved).
 *   3. A second worker started immediately finds nothing to do (the reservation is still fresh).
 *   4. SIMULATED AGING: set that one job's `reserved_at` to (retry_after + 5) seconds in the past.
 *   5. Start a third real worker: it redelivers the job, resumes and completes the batch.
 *   6. Check final status, counters, students, enrollment history (exactly once) and unique-lock release.
 *
 * WHAT STEP 4 DOES AND DOES NOT PROVE
 *   It proves: given a reservation older than the configured retry_after (960 s), the real
 *   DatabaseQueue hands the job to a new worker, attempts are incremented, and the committed code resumes
 *   from the first unprocessed row and finishes without duplicates.
 *   It does NOT prove: that 960 real seconds elapsed, how the queue behaves with a different retry_after
 *   or driver, behaviour under pcntl timeouts, or anything about production deployment settings.
 *   retry_after (960), the 900 s job timeouts and the 3,600 s unique lock are READ and ASSERTED, never changed.
 *
 * SAFETY (the script refuses to run otherwise, before any write):
 *   - DB_DATABASE must already be set in the process environment to exactly `buksu_comelec_phase03a_verify`
 *     (checked before the application is even booted; the project's .env is never edited).
 *   - After boot, the application, queue, cache-lock and failed-job connections must each (a) be configured
 *     for that database, (b) be a local MySQL/MariaDB host and (c) report SELECT DATABASE() = that name on a
 *     live connection. `buksu_comelec_v2` is explicitly forbidden.
 *   - Worker child processes receive an explicit environment and are probed first; they must report the
 *     same database. Child output goes to temporary files and is read only after the child has exited.
 *   - The `jobs` table must be empty at the start (a worker pops the NEXT job), and before every worker is
 *     started every row in `jobs` must still be this run's own job; otherwise the run aborts.
 *   - The test job is identified by its exact payload (class ProcessImportBatch AND this run's batch id),
 *     never by "whatever is in the table". Only rows that match are ever deleted.
 *   - There is no exit() inside the test flow: every abort is an exception, so the `finally` block always runs.
 *   - The configured table names of the queue (`jobs`), failed-job store (`failed_jobs`) and cache-lock store
 *     (`cache_locks`) must equal the tables this script checks and cleans up; otherwise it aborts before any write.
 *   - Temporary files (the generated CSV) are registered before they are created and removed in `finally`,
 *     including on errors.
 *   - Child processes are started without a shell. On Windows each worker runs inside its own NAMED JOB OBJECT
 *     (created by a small PowerShell wrapper, so descendants inherit membership from the OS). Termination is
 *     TerminateJobObject on that name and verification is "no active process in the job (or the job no longer
 *     exists)", validated against a strict reply. No pid from a snapshot is ever used to kill anything, so PID reuse
 *     cannot cause an unrelated process to be killed. The root is only killed through the handle PHP holds.
 *   - Windows startup handshake: the wrapper writes an exact readiness marker to a dedicated temp file only after it
 *     created the job and joined it, and starts the real worker only after that. spawn() returns only once PHP has
 *     read exactly that marker; a missing/malformed marker, premature wrapper exit or timeout aborts the run (the
 *     wrapper is killed through its own handle first, then the named job, then the post-exit job check decides).
 *   - A failed termination verification is sticky (an exited root never proves its descendants stopped).
 *   - Cleanup first confirms that EVERY child process has stopped. If one cannot be confirmed stopped, the
 *     database cleanup is skipped entirely and the reason is reported. Otherwise it re-checks the database
 *     name and deletes only the rows this run created (tracked ids / unique prefix). Nothing is dropped,
 *     truncated or recreated.
 *   - All data is synthetic.
 *
 * Exit codes: 0 all passed and cleaned up | 1 a check failed | 2 aborted (guard / precondition / missed kill
 * window) | 3 unexpected error | 5 checks passed but cleanup was skipped or incomplete.
 *
 * Usage (from the project root; the throwaway database must already be migrated):
 *     PowerShell:  $env:DB_DATABASE='buksu_comelec_phase03a_verify'; $env:QUEUE_CONNECTION='database'; $env:CACHE_STORE='database'; php database/verification/phase03a_queue_recovery.php [students=4000]
 *     bash:        DB_DATABASE=buksu_comelec_phase03a_verify QUEUE_CONNECTION=database CACHE_STORE=database php database/verification/phase03a_queue_recovery.php [students=4000]
 */

use App\Jobs\ProcessImportBatch;
use App\Jobs\ValidateImportBatch;
use App\Models\ImportBatch;
use App\Services\StudentImport\ImportBatchService;
use App\Services\StudentImport\ImportValidator;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

const THROWAWAY_DB = 'buksu_comelec_phase03a_verify';
const FORBIDDEN_DB = 'buksu_comelec_v2';
const LOCAL_HOSTS = ['127.0.0.1', 'localhost', '::1'];
// the exact tables this script reads, checks and cleans up (the configured names must equal these)
const TABLE_JOBS = 'jobs';
const TABLE_FAILED_JOBS = 'failed_jobs';
const TABLE_CACHE_LOCKS = 'cache_locks';

// ---- 0. environment gate: BEFORE the application boots or any connection is opened ------------------
// (nothing exists yet, so a plain exit is correct here; the test flow below never calls exit())
$envDatabase = getenv('DB_DATABASE');
if ($envDatabase !== THROWAWAY_DB) {
    fwrite(STDERR, "REFUSING TO RUN: DB_DATABASE must be set in the process environment to exactly '".THROWAWAY_DB."' (got ".($envDatabase === false ? 'nothing' : "'$envDatabase'").").\n"
        ."The project's .env is deliberately not trusted for this script. Example (PowerShell):\n"
        ."  \$env:DB_DATABASE='".THROWAWAY_DB."'; php database/verification/phase03a_queue_recovery.php\n");
    exit(2);
}

$root = dirname(__DIR__, 2);
chdir($root);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

/** Thrown for every deliberate abort so that the `finally` block (cleanup) always runs. */
final class RunAborted extends RuntimeException {}

/**
 * A child process whose stdout/stderr go to temporary files (no pipe-buffer deadlock, nothing lost).
 * Output can only be read after the process has stopped. Exit status is captured exactly once, from
 * the first proc_get_status() call that reports the process as not running.
 *
 * Process-tree control never uses a pid taken from a snapshot:
 *   - POSIX: the root is killed through its proc_open handle (this worker spawns no children).
 *   - Windows: the worker is started inside a NAMED JOB OBJECT (qrv_<random>_<n>). A small PowerShell wrapper
 *     creates the job, assigns itself to it, and then runs the real command, so every descendant inherits job
 *     membership from the OS. Termination is TerminateJobObject on that name, verification is
 *     "ActiveProcesses == 0" (or "job does not exist", which Windows only reports once no handle and no member is
 *     left). No pid is ever looked up, so a reused pid cannot be hit. The root itself is only ever killed through
 *     the handle PHP holds (while a handle is open its pid cannot be reused).
 */
final class ChildProcess
{
    private static int $sequence = 0;

    private mixed $proc;

    private string $outFile;

    private string $errFile;

    private ?int $pid;

    private ?string $jobName = null;

    private ?string $readyFile = null;

    private bool $stopped = false;

    private ?int $exitCode = null;

    private string $failure = '';

    private bool $verified = false;

    private bool $unverified = false;

    /** @param list<string> $cmd  @param array<string, string> $env */
    public function __construct(public readonly string $label, array $cmd, string $cwd, array $env)
    {
        $this->outFile = (string) tempnam(sys_get_temp_dir(), 'qrvo');
        $this->errFile = (string) tempnam(sys_get_temp_dir(), 'qrve');

        if (PHP_OS_FAMILY === 'Windows') {
            $this->jobName = 'qrv_'.bin2hex(random_bytes(8)).'_'.(++self::$sequence);
            $this->readyFile = (string) tempnam(sys_get_temp_dir(), 'qrvr'); // dedicated; never stdout/stderr
            $env['QRV_JOB_NAME'] = $this->jobName;
            $env['QRV_READY_FILE'] = $this->readyFile;
            $env['QRV_CHILD_CMD'] = (string) json_encode(array_values($cmd), JSON_UNESCAPED_SLASHES);
            $cmd = self::powershellCommand(self::wrapperScript());
        }

        $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['file', $this->outFile, 'w'], 2 => ['file', $this->errFile, 'w']], $pipes, $cwd, $env, ['bypass_shell' => true]);
        if (! is_resource($proc)) {
            @unlink($this->outFile);
            @unlink($this->errFile);
            if ($this->readyFile !== null) {
                @unlink($this->readyFile);
            }
            throw new RuntimeException("could not start child process '{$this->label}'");
        }
        fclose($pipes[0]);
        $this->proc = $proc;
        $this->pid = proc_get_status($proc)['pid'] ?? null;
    }

    /**
     * Windows startup handshake. The wrapper writes the exact line `QRV_JOB_READY,<job name>` to the dedicated
     * ready file only AFTER it created the named job and assigned itself to it, and launches the real worker only
     * after that. This waits (bounded) for exactly that line, polling the file and the root's process handle.
     * Missing, malformed or premature-exit => the startup is aborted (see abortStartup()). POSIX: nothing to do.
     */
    public function awaitReady(float $seconds = 60): void
    {
        if ($this->jobName === null) {
            return;
        }
        $expected = 'QRV_JOB_READY,'.$this->jobName."\n";
        $deadline = microtime(true) + $seconds;
        while (true) {
            $state = $this->readyState($expected);
            if ($state === 'ready') {
                @unlink((string) $this->readyFile);
                $this->readyFile = null;

                return;
            }
            if ($state === 'malformed') {
                $this->abortStartup('the readiness marker is malformed');
            }
            if (! $this->isRunning()) {
                // the wrapper may have written the marker and exited between the read above and this status check
                $state = $this->readyState($expected);
                if ($state === 'ready') {
                    @unlink((string) $this->readyFile);
                    $this->readyFile = null;

                    return;
                }
                $this->abortStartup($state === 'malformed' ? 'the readiness marker is malformed' : 'the wrapper exited before signalling readiness: '.substr($this->stderr(), 0, 300));
            }
            if (microtime(true) >= $deadline) {
                $this->abortStartup('the wrapper did not signal readiness within '.(int) $seconds.' s');
            }
            usleep(50000);
        }
    }

    /** @return 'ready'|'pending'|'malformed' */
    private function readyState(string $expected): string
    {
        $content = @file_get_contents((string) $this->readyFile);
        if ($content === false || $content === '') {
            return 'pending';
        }
        if ($content === $expected) {
            return 'ready';
        }
        // a partial write cannot end in a newline yet, so it is still pending; a complete but different line is bad
        if (! str_ends_with($content, "\n") && str_starts_with($expected, $content)) {
            return 'pending';
        }

        return 'malformed';
    }

    /**
     * Startup failed or timed out. Order matters: first the root wrapper is killed through ITS OWN HANDLE (so it can
     * never go on to launch the worker), then the named job is terminated, then the normal stop() runs, whose final
     * post-exit job verification alone decides whether termination counts as verified. Never kills by pid.
     */
    private function abortStartup(string $reason): never
    {
        if ($this->isRunning()) {
            proc_terminate($this->proc, 9);
            $this->waitForExit(10);
        }
        $this->jobHelper('terminate'); // best effort, name-bound; its result is not trusted
        $verified = $this->stop();
        throw new RunAborted("worker startup failed for '{$this->label}': $reason; termination ".($verified ? 'verified by the post-exit job check' : 'NOT verified ('.$this->failure.')').'. Nothing further was started.');
    }

    public function isRunning(): bool
    {
        if ($this->stopped) {
            return false;
        }
        $status = proc_get_status($this->proc);
        if ($status['running']) {
            return true;
        }
        $this->exitCode = (int) $status['exitcode']; // -1 when killed by a signal
        proc_close($this->proc);
        $this->stopped = true;

        return false;
    }

    /** Waits (without killing) for the process to exit on its own. */
    public function waitForExit(float $seconds): bool
    {
        $deadline = microtime(true) + $seconds;
        while ($this->isRunning()) {
            if (microtime(true) >= $deadline) {
                return false;
            }
            usleep(50000);
        }

        return true;
    }

    /**
     * Kills the process hard and returns true only once termination is VERIFIED for the root AND its descendants.
     *
     * The verdict is sticky: once a verification has failed every later call returns false, even if the root has
     * exited meanwhile. An exited root is never treated as proof that its descendants are gone.
     *
     * Windows: while the root runs, TerminateJobObject on this worker's own named job and a handle kill of the root.
     * Then, once the root is confirmed exited by its handle, a FINAL `verify` of the named job must return the strictly
     * validated reply (job absent or zero active processes); only that sets verified = true. POSIX: the root is killed
     * via its handle, reaped, and its pid must no longer answer.
     */
    public function stop(float $seconds = 15): bool
    {
        if ($this->unverified) {
            $this->bestEffortKill();

            return false;
        }
        if ($this->verified) {
            return true;
        }

        if ($this->jobName !== null) {
            // 1. kill every current member of this worker's named job, then the root through the handle PHP holds
            if ($this->isRunning()) {
                $problem = $this->jobHelper('terminate');
                if ($this->isRunning()) {
                    proc_terminate($this->proc, 9); // handle-bound, identity-safe
                }
                if (! $this->waitForExit($seconds)) {
                    return $this->fail($problem ?? 'the root process is still running after the job was terminated and its handle killed');
                }
                if ($problem !== null) {
                    return $this->fail($problem);
                }
            }

            // 2. the root is now confirmed exited by its handle. The first helper call may have raced the wrapper's
            //    job creation ("absent" before the job existed), so it proves nothing about descendants: only this
            //    post-exit check can set verified = true.
            $final = $this->jobHelper('verify');
            if ($final !== null) {
                return $this->fail('post-exit job verification failed: '.$final);
            }
            $this->verified = true;

            return true;
        }

        for ($attempt = 1; $attempt <= 2 && $this->isRunning(); $attempt++) {
            proc_terminate($this->proc, 9);
            $this->waitForExit($attempt === 1 ? $seconds : 5);
        }
        if ($this->isRunning()) {
            return $this->fail('the process is still running after two hard kills');
        }
        if ($this->pid !== null && function_exists('posix_kill') && @posix_kill($this->pid, 0)) {
            return $this->fail('pid '.$this->pid.' still answers after the process was reaped');
        }
        $this->verified = true;

        return true;
    }

    /** Records a failed verification permanently and returns false. */
    private function fail(string $reason): bool
    {
        $this->unverified = true;
        $this->failure = $reason;

        return false;
    }

    /** Only name-bound (job) or handle-bound (root) actions; never a pid from a snapshot. */
    private function bestEffortKill(): void
    {
        if ($this->jobName !== null) {
            $this->jobHelper('terminate');
        }
        if (! $this->stopped && $this->isRunning()) {
            proc_terminate($this->proc, 9);
        }
    }

    /** True only after stop() verified the root and all descendants. */
    public function terminationVerified(): bool
    {
        return $this->verified && ! $this->unverified;
    }

    public function failure(): string
    {
        return $this->failure;
    }

    /**
     * Runs the job helper. Returns null ONLY for a strictly valid success reply, otherwise a reason.
     * Accepted reply (stdout, exit code 0, nothing else):
     *     QRV_JOB_STATE,absent|empty,0
     *     QRV_JOB_OK
     */
    private function jobHelper(string $operation): ?string
    {
        $run = self::runPowerShell(self::helperScript(), ['QRV_JOB_NAME' => (string) $this->jobName, 'QRV_JOB_OP' => $operation]);
        if ($run['code'] === null) {
            return "the job helper ($operation) did not finish or could not start: ".$run['err'];
        }
        if ($run['code'] !== 0) {
            return "the job helper ($operation) exited with code {$run['code']}: ".substr($run['err'], 0, 300);
        }
        $lines = preg_split('/\r?\n/', rtrim($run['out'], "\r\n"));
        if (! is_array($lines) || count($lines) !== 2
            || preg_match('/^QRV_JOB_STATE,(absent|empty),0$/', $lines[0]) !== 1 || $lines[1] !== 'QRV_JOB_OK') {
            return "the job helper ($operation) reply is not the expected success reply: ".substr(str_replace("\n", ' | ', $run['out']), 0, 200);
        }

        return null;
    }

    /** @return list<string> */
    private static function powershellCommand(string $script): array
    {
        if (preg_match('/[^\x09\x0A\x0D\x20-\x7E]/', $script) === 1) {
            throw new LogicException('internal: PowerShell script is not ASCII');
        }
        // -EncodedCommand = base64 of UTF-16LE; the script is pure ASCII, so every byte is followed by a NUL
        $encoded = base64_encode((string) preg_replace('/(.)/s', "$1\0", str_replace("\r\n", "\n", $script)));

        return ['powershell', '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-EncodedCommand', $encoded];
    }

    /**
     * Short-lived PowerShell run with a hard 90 s limit. stdout/stderr go to temporary files (stream_select() is not
     * supported on proc_open pipes on Windows); the process is polled through its own handle and, on timeout,
     * terminated through that same handle only. Output is read after the process has exited.
     *
     * @param  array<string, string>  $extraEnv
     * @return array{code: ?int, out: string, err: string}
     */
    private static function runPowerShell(string $script, array $extraEnv): array
    {
        $outFile = (string) tempnam(sys_get_temp_dir(), 'qrvho');
        $errFile = (string) tempnam(sys_get_temp_dir(), 'qrvhe');
        $proc = null;
        try {
            $proc = @proc_open(self::powershellCommand($script), [0 => ['pipe', 'r'], 1 => ['file', $outFile, 'w'], 2 => ['file', $errFile, 'w']], $pipes, null, array_merge(getenv(), $extraEnv), ['bypass_shell' => true]);
            if (! is_resource($proc)) {
                return ['code' => null, 'out' => '', 'err' => 'powershell could not be started'];
            }
            fclose($pipes[0]);

            $code = null;
            $exited = false;
            $timedOut = false;
            $deadline = microtime(true) + 90;
            while (true) {
                $status = proc_get_status($proc);
                if (! $status['running']) {
                    $code = (int) $status['exitcode']; // only the first call after exit carries the real code
                    $exited = true;
                    break;
                }
                if (microtime(true) >= $deadline) {
                    $timedOut = true;
                    break;
                }
                usleep(50000);
            }

            if ($timedOut) {
                proc_terminate($proc, 9); // this helper only, through its own handle
                $grace = microtime(true) + 5;
                while (microtime(true) < $grace) {
                    if (! proc_get_status($proc)['running']) {
                        $exited = true;

                        break;
                    }
                    usleep(50000);
                }
            }
            if ($exited) {
                proc_close($proc); // never blocks: the process has exited
                $proc = null;
            }

            $out = (string) @file_get_contents($outFile);
            $err = trim((string) @file_get_contents($errFile));
            if ($timedOut) {
                return ['code' => null, 'out' => $out, 'err' => trim($err.' (timed out after 90 s'.($exited ? '' : '; the helper could not be confirmed stopped').')')];
            }

            return ['code' => $code, 'out' => $out, 'err' => $err];
        } finally {
            if (is_resource($proc)) {
                @proc_terminate($proc, 9); // not confirmed exited: do not call proc_close(), which would block
            }
            @unlink($outFile);
            @unlink($errFile);
        }
    }

    private static function jobTypeSource(): string
    {
        return <<<'CS'
using System;
using System.Runtime.InteropServices;
public static class QrvJob {
  [StructLayout(LayoutKind.Sequential)] public struct Basic { public long A; public long B; public long C; public long D; public uint PageFaults; public uint TotalProcesses; public uint ActiveProcesses; public uint TotalTerminated; }
  [DllImport("kernel32.dll", SetLastError=true, CharSet=CharSet.Unicode)] static extern IntPtr CreateJobObjectW(IntPtr attrs, string name);
  [DllImport("kernel32.dll", SetLastError=true, CharSet=CharSet.Unicode)] static extern IntPtr OpenJobObjectW(uint access, bool inherit, string name);
  [DllImport("kernel32.dll", SetLastError=true)] static extern bool AssignProcessToJobObject(IntPtr job, IntPtr process);
  [DllImport("kernel32.dll", SetLastError=true)] static extern bool TerminateJobObject(IntPtr job, uint exitCode);
  [DllImport("kernel32.dll", SetLastError=true)] static extern bool QueryInformationJobObject(IntPtr job, int infoClass, out Basic info, int len, IntPtr ret);
  [DllImport("kernel32.dll")] static extern IntPtr GetCurrentProcess();
  [DllImport("kernel32.dll")] static extern bool CloseHandle(IntPtr h);
  public static IntPtr Create(string name) {
    IntPtr h = CreateJobObjectW(IntPtr.Zero, name);
    int err = Marshal.GetLastWin32Error();
    if (h == IntPtr.Zero) throw new Exception("CreateJobObject failed: " + err);
    if (err == 183) { CloseHandle(h); throw new Exception("job name already exists"); }
    return h;
  }
  public static void AssignSelf(IntPtr job) {
    if (!AssignProcessToJobObject(job, GetCurrentProcess())) throw new Exception("AssignProcessToJobObject failed: " + Marshal.GetLastWin32Error());
  }
  public static IntPtr Open(string name, out int err) {
    IntPtr h = OpenJobObjectW(0x000C, false, name);
    err = (h == IntPtr.Zero) ? Marshal.GetLastWin32Error() : 0;
    return h;
  }
  public static uint Active(IntPtr job) {
    Basic b;
    if (!QueryInformationJobObject(job, 1, out b, Marshal.SizeOf(typeof(Basic)), IntPtr.Zero)) throw new Exception("QueryInformationJobObject failed: " + Marshal.GetLastWin32Error());
    return b.ActiveProcesses;
  }
  public static void Terminate(IntPtr job) {
    if (!TerminateJobObject(job, 1)) throw new Exception("TerminateJobObject failed: " + Marshal.GetLastWin32Error());
  }
}
CS;
    }

    /** Runs as the tracked root on Windows: creates + joins the named job, signals readiness, THEN runs the real command inside it. */
    private static function wrapperScript(): string
    {
        return "\$ErrorActionPreference = 'Stop'\n\$src = @'\n".self::jobTypeSource()."\n'@\n".<<<'PS'
try {
    Add-Type -TypeDefinition $src
    $name = $env:QRV_JOB_NAME
    $ready = $env:QRV_READY_FILE
    if ($name -notmatch '^qrv_[0-9a-f]{16}_[0-9]+$') { throw 'invalid job name' }
    if ([string]::IsNullOrEmpty($ready)) { throw 'no readiness file' }
    $cmd = ConvertFrom-Json -InputObject $env:QRV_CHILD_CMD
        if ($cmd -isnot [System.Array] -or $cmd.Count -lt 1) {
            throw 'child command must be a non-empty JSON array'
        }
    if ($cmd.Count -lt 1) { throw 'empty command' }
    $job = [QrvJob]::Create($name)
    [QrvJob]::AssignSelf($job)
    [System.IO.File]::WriteAllText($ready, 'QRV_JOB_READY,' + $name + "`n")
} catch {
    [Console]::Error.WriteLine('QRV_WRAPPER_ERROR: ' + $_.Exception.Message)
    exit 97
}
Remove-Item Env:QRV_READY_FILE -ErrorAction SilentlyContinue
$exe = [string]$cmd[0]
$rest = @()
if ($cmd.Count -gt 1) { $rest = @($cmd[1..($cmd.Count - 1)] | ForEach-Object { [string]$_ }) }
& $exe @rest
if ($null -eq $LASTEXITCODE) { exit 0 }
exit $LASTEXITCODE
PS;
    }

    /** `terminate` (kill every member, then require none left) or `verify` (require none left); name-bound only. */
    private static function helperScript(): string
    {
        return "\$ErrorActionPreference = 'Stop'\n\$src = @'\n".self::jobTypeSource()."\n'@\n".<<<'PS'
try {
    Add-Type -TypeDefinition $src
    $name = $env:QRV_JOB_NAME
    $op = $env:QRV_JOB_OP
    if ($name -notmatch '^qrv_[0-9a-f]{16}_[0-9]+$') { throw 'invalid job name' }
    if ($op -ne 'terminate' -and $op -ne 'verify') { throw 'invalid operation' }
    $err = 0
    $h = [QrvJob]::Open($name, [ref]$err)
    if ($h -eq [IntPtr]::Zero) {
        if ($err -eq 2) {
            [Console]::Out.Write("QRV_JOB_STATE,absent,0`nQRV_JOB_OK`n")
            exit 0
        }
        throw ('OpenJobObject failed: ' + $err)
    }
    if ($op -eq 'terminate') { [QrvJob]::Terminate($h) }
    $deadline = (Get-Date).AddSeconds(15)
    $active = [QrvJob]::Active($h)
    while ($active -ne 0 -and (Get-Date) -lt $deadline) {
        Start-Sleep -Milliseconds 200
        $active = [QrvJob]::Active($h)
    }
    if ($active -ne 0) { throw ('job still has ' + $active + ' active process(es)') }
    [Console]::Out.Write("QRV_JOB_STATE,empty,0`nQRV_JOB_OK`n")
    exit 0
} catch {
    [Console]::Error.WriteLine('QRV_JOB_ERROR: ' + $_.Exception.Message)
    exit 3
}
PS;
    }

    public function stopped(): bool
    {
        return ! $this->isRunning();
    }

    public function exitCode(): int
    {
        $this->requireStopped();

        return (int) $this->exitCode;
    }

    public function stdout(): string
    {
        $this->requireStopped();

        return trim((string) @file_get_contents($this->outFile));
    }

    public function stderr(): string
    {
        $this->requireStopped();

        return trim((string) @file_get_contents($this->errFile));
    }

    public function output(): string
    {
        return trim($this->stdout()."\n".$this->stderr());
    }

    /** Removes the temporary output files (only once the process has stopped). */
    public function discard(): void
    {
        if ($this->stopped()) {
            @unlink($this->outFile);
            @unlink($this->errFile);
            if ($this->readyFile !== null) {
                @unlink($this->readyFile);
            }
        }
    }

    public function describe(): string
    {
        return $this->label.' (pid '.($this->pid ?? '?').')'.($this->failure !== '' ? ' ['.$this->failure.']' : '');
    }

    private function requireStopped(): void
    {
        if (! $this->stopped()) {
            throw new LogicException("output of '{$this->label}' requested while it is still running");
        }
    }
}

// ---- connection / configuration guard (used at start, in the worker probe, and again before cleanup) -----

/** @return array<string, string> role => connection name */
function resolveConnectionNames(): array
{
    $default = (string) config('database.default');

    return [
        'application' => $default,
        'queue' => (string) (config('queue.connections.database.connection') ?: $default),
        'cache-lock' => (string) (config('cache.stores.database.lock_connection') ?: config('cache.stores.database.connection') ?: $default),
        'failed-jobs' => (string) (config('queue.failed.database') ?: $default),
    ];
}

/**
 * Returns a list of problems (empty = safe). Never writes anything.
 *
 * @return list<string>
 */
function guardProblems(): array
{
    $problems = [];

    if (app()->environment('production')) {
        $problems[] = 'APP_ENV is production';
    }
    if (config('queue.default') !== 'database' || config('queue.connections.database.driver') !== 'database') {
        $problems[] = 'queue.default must be the `database` queue (got '.var_export(config('queue.default'), true).'); set QUEUE_CONNECTION=database in the process environment';
    }
    if (config('cache.default') !== 'database' || config('cache.stores.database.driver') !== 'database') {
        $problems[] = 'cache.default must be the `database` store (got '.var_export(config('cache.default'), true).'); set CACHE_STORE=database in the process environment';
    }
    if (! in_array(config('queue.failed.driver'), ['database-uuids', 'database'], true)) {
        $problems[] = 'queue.failed.driver must be a database failer (got '.var_export(config('queue.failed.driver'), true).')';
    }

    // the tables this script checks and cleans up must be the tables the queue / failer / lock store really use
    // (null falls back to the same framework defaults Laravel itself applies)
    $tables = [
        'queue table (queue.connections.database.table)' => [config('queue.connections.database.table') ?? 'jobs', TABLE_JOBS],
        'failed-jobs table (queue.failed.table)' => [config('queue.failed.table') ?? 'failed_jobs', TABLE_FAILED_JOBS],
        'cache-lock table (cache.stores.database.lock_table)' => [config('cache.stores.database.lock_table') ?? 'cache_locks', TABLE_CACHE_LOCKS],
    ];
    foreach ($tables as $what => [$configured, $expected]) {
        if ($configured !== $expected) {
            $problems[] = "$what is ".var_export($configured, true).", but this script only checks and cleans `$expected`";
        }
    }

    foreach (resolveConnectionNames() as $role => $name) {
        $cfg = config("database.connections.$name");
        if (! is_array($cfg)) {
            $problems[] = "$role: connection `$name` is not configured";

            continue;
        }
        if (! in_array($cfg['driver'] ?? null, ['mysql', 'mariadb'], true)) {
            $problems[] = "$role: connection `$name` is not MySQL/MariaDB";
        }
        if (($cfg['database'] ?? null) !== THROWAWAY_DB) {
            $problems[] = "$role: connection `$name` is configured for database '".($cfg['database'] ?? '')."', not '".THROWAWAY_DB."'";
        }
        if (($cfg['database'] ?? null) === FORBIDDEN_DB) {
            $problems[] = "$role: connection `$name` points at the application database ".FORBIDDEN_DB;
        }
        if (! empty($cfg['url'])) {
            $problems[] = "$role: connection `$name` uses a DB_URL, which could override the database name";
        }
        $hosts = array_map('strval', (array) ($cfg['host'] ?? []));
        if (empty($cfg['unix_socket']) && array_diff($hosts, LOCAL_HOSTS) !== []) {
            $problems[] = "$role: connection `$name` host is not local (".implode(',', $hosts).')';
        }

        // the authoritative check: what the SERVER says this live connection is using
        try {
            $live = DB::connection($name)->selectOne('select database() as db');
            if (($live->db ?? null) !== THROWAWAY_DB) {
                $problems[] = "$role: live connection `$name` reports database '".($live->db ?? 'NULL')."', not '".THROWAWAY_DB."'";
            }
        } catch (Throwable $e) {
            $problems[] = "$role: live connection `$name` failed: ".get_class($e);
        }
    }

    return $problems;
}

// ---- worker-probe mode: a child process reports what IT resolves (same env as a real worker) ---------
// (child-process entry point, not part of the orchestrated test flow)
if (($argv[1] ?? '') === 'probe') {
    $problems = guardProblems();
    echo json_encode(['safe' => $problems === [], 'problems' => $problems, 'connections' => resolveConnectionNames()])."\n";
    exit($problems === [] ? 0 : 4);
}

// ---- helpers ------------------------------------------------------------------------------------------

function check(string $name, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? 'PASS  ' : 'FAIL  ').$name.($detail !== '' ? '  ['.$detail.']' : '')."\n";
}

function info(string $line): void
{
    echo '      '.$line."\n";
}

/** Creates the synthetic CSV; both paths are registered for removal BEFORE anything is written. */
function writeSyntheticCsv(array &$ctx, string $token, int $students): string
{
    $base = tempnam(sys_get_temp_dir(), 'qrv');
    if ($base === false) {
        throw new RuntimeException('could not create a temporary file');
    }
    $csv = $base.'.csv';
    $ctx['tempFiles'][] = $base;
    $ctx['tempFiles'][] = $csv;

    $h = fopen($csv, 'wb');
    if ($h === false) {
        throw new RuntimeException('could not open the temporary CSV for writing');
    }
    try {
        fputcsv($h, ['No.', 'Code', 'Last Name', 'First Name', 'Middle Name', 'Sex', 'Colleges', 'Course', 'Year'], ',', '"', '');
        for ($i = 1; $i <= $students; $i++) {
            fputcsv($h, [$i, sprintf('QRV%s-%07d', $token, $i), "L$i", "F$i", '', 'F', 'CON', $i % 10 === 0 ? 'BSIT' : 'BSN', (string) (($i % 4) + 1)], ',', '"', '');
        }
    } finally {
        fclose($h);
    }

    return $csv;
}

/** Removes every temporary file this run registered (local files only; no database access). */
function removeTempFiles(array &$ctx): void
{
    foreach ($ctx['tempFiles'] as $path) {
        if (is_string($path) && $path !== '' && file_exists($path)) {
            @unlink($path);
        }
    }
    $ctx['tempFiles'] = array_values(array_filter($ctx['tempFiles'], fn ($p) => is_string($p) && file_exists($p)));
}

/** Aborts the run by exception (never exit()), so the caller's `finally` cleanup always executes. */
function abortRun(string $why): never
{
    throw new RunAborted($why);
}

/** @return array<string, string> */
function workerEnvironment(): array
{
    return array_merge(getenv(), [
        'DB_DATABASE' => THROWAWAY_DB,
        'QUEUE_CONNECTION' => 'database',
        'CACHE_STORE' => 'database',
        'COMELEC_IMPORT_CHUNK_SIZE' => '100',
    ]);
}

/** @param list<string> $cmd */
function spawn(array $cmd, array &$ctx, string $label): ChildProcess
{
    global $root;
    $child = new ChildProcess($label, $cmd, $root, workerEnvironment());
    $ctx['procs'][] = $child; // registered immediately so cleanup can always see (and stop) it
    $child->awaitReady();     // Windows: returns only after the worker's job exists and the wrapper has joined it

    return $child;
}

/** @return list<string> */
function workerCommand(): array
{
    return [PHP_BINARY, 'artisan', 'queue:work', 'database', '--once', '--queue='.(config('queue.connections.database.queue') ?: 'default'), '--sleep=1', '--tries=3', '--timeout=900'];
}

/**
 * True only for a payload that is a ProcessImportBatch job for THIS run's batch id.
 * (public property => serialized as `"batchId";i:<id>;`)
 */
function isOwnedPayload(?string $payload, ?int $batchId): bool
{
    if ($batchId === null || $payload === null) {
        return false;
    }
    $decoded = json_decode($payload, true);
    if (! is_array($decoded) || ($decoded['displayName'] ?? null) !== ProcessImportBatch::class) {
        return false;
    }
    $command = (string) ($decoded['data']['command'] ?? '');

    return preg_match('/"(?:\x00\*\x00)?batchId";i:'.$batchId.';/', $command) === 1;
}

/** The queued job(s) that belong to this run's batch (exact payload match), regardless of row id. */
function ownedJobs(?int $batchId): Collection
{
    if ($batchId === null) {
        return collect();
    }

    return DB::table('jobs')->where('payload', 'like', '%ProcessImportBatch%')->get()
        ->filter(fn ($row) => isOwnedPayload($row->payload, $batchId))->values();
}

/** A worker pops the NEXT job, so before starting one every row in `jobs` must be this run's own job. */
function assertQueueIsolated(?int $batchId): void
{
    $foreign = DB::table('jobs')->get(['id', 'payload'])->reject(fn ($row) => isOwnedPayload($row->payload, $batchId));
    if ($foreign->isNotEmpty()) {
        abortRun('the `jobs` table contains '.$foreign->count().' row(s) that are not this run\'s job (ids '.$foreign->pluck('id')->take(5)->implode(',').'); a worker could consume them, so no worker is started.');
    }
}

function jobRows(array $ctx): Collection
{
    return DB::table('jobs')->whereIn('id', $ctx['jobIds'])->get();
}

function lockRow(array $ctx, string $prefix, string $lockTable): ?object
{
    return DB::table($lockTable)->where('key', $prefix.$ctx['lockName'])->first();
}

function dispatchOnce(int $batchId): void
{
    $pending = dispatch(new ProcessImportBatch($batchId));
    unset($pending); // PendingDispatch pushes (or is suppressed by the unique lock) when destroyed
}

function processedRows(int $batchId): int
{
    return (int) DB::table('import_batch_rows')->where('import_batch_id', $batchId)->where('classification', 'UPDATED')->whereNotNull('processed_at')->count();
}

function enrollmentRows(int $batchId): int
{
    return (int) DB::table('student_enrollments')->where('import_batch_id', $batchId)->count();
}

/**
 * Stops every child, then (only if all are confirmed stopped) deletes ONLY what this run created.
 * Never throws. Never drops or truncates anything.
 *
 * @return array{status: string, detail: string}  status: ok | skipped | partial
 */
function cleanup(array $ctx, ?string $prefix, ?string $lockTable, string $likeId): array
{
    // 1. every child must be confirmed stopped BEFORE any record is touched
    $unconfirmed = [];
    foreach ($ctx['procs'] as $child) {
        try {
            if (! $child->stop() || ! $child->terminationVerified()) {
                $unconfirmed[] = $child->describe();
            }
        } catch (Throwable $e) {
            $unconfirmed[] = $child->describe().' ['.get_class($e).']';
        }
    }
    if ($unconfirmed !== []) {
        $reason = 'could not VERIFY that these child process trees stopped (an exited root does not prove its descendants stopped): '.implode(', ', $unconfirmed);
        fwrite(STDERR, "CLEANUP SKIPPED: $reason.\n"
            ."  No database record was deleted, because a live worker could still be writing to this run's rows.\n"
            ."  Stop the process(es) yourself, then remove this run's rows manually. Tracked state:\n"
            .'  '.json_encode(['token' => $ctx['token'], 'admin_id' => $ctx['adminId'], 'prior_batch_id' => $ctx['priorBatchId'], 'batch_id' => $ctx['batchId'], 'job_ids' => $ctx['jobIds'], 'lock' => $ctx['lockName'], 'storage_key' => $ctx['storageKey']])."\n");

        return ['status' => 'skipped', 'detail' => $reason];
    }
    foreach ($ctx['procs'] as $child) {
        $child->discard();
    }
    echo '      cleanup: '.count($ctx['procs'])." child process(es) confirmed stopped\n";

    // 2. nothing created yet (the admin is the first thing written) => nothing to clean, no database access needed
    if ($ctx['adminId'] === null) {
        echo "      cleanup: nothing was created by this run\n";

        return ['status' => 'ok', 'detail' => 'nothing to clean'];
    }

    // 3. the connection guard must still pass
    try {
        $problems = guardProblems();
    } catch (Throwable $e) {
        $problems = ['guard raised '.get_class($e)];
    }
    if ($problems !== []) {
        $reason = 'the connection guard no longer passes: '.implode('; ', $problems);
        fwrite(STDERR, "CLEANUP SKIPPED: $reason\n  Nothing was deleted.\n");

        return ['status' => 'skipped', 'detail' => $reason];
    }

    $failedSteps = [];
    $step = function (string $what, callable $fn) use (&$failedSteps): void {
        try {
            $n = $fn();
            echo "      cleanup: $what".($n === null ? '' : " ($n)")."\n";
        } catch (Throwable $e) {
            $failedSteps[] = $what;
            fwrite(STDERR, "      cleanup FAILED for $what: ".get_class($e).': '.$e->getMessage()."\n");
        }
    };
    $batchId = $ctx['batchId'];
    $batchIds = array_values(array_filter([$ctx['batchId'], $ctx['priorBatchId']]));

    if ($batchId !== null && $ctx['failedBaseline'] !== null) {
        $step('failed_jobs created by this run for this batch', function () use ($ctx, $batchId) {
            $ids = DB::table('failed_jobs')->whereNotIn('uuid', $ctx['failedBaseline'])->where('payload', 'like', '%ProcessImportBatch%')->get(['id', 'payload'])
                ->filter(fn ($row) => isOwnedPayload($row->payload, $batchId))->pluck('id')->all();

            return $ids === [] ? 0 : DB::table('failed_jobs')->whereIn('id', $ids)->delete();
        });
    }
    if ($batchId !== null) {
        $step('jobs rows for this batch (exact payload match)', function () use ($ctx, $batchId) {
            // only rows whose payload is a ProcessImportBatch job for THIS batch id are deleted;
            // a retry that re-inserted the job under a new row id is still this batch's job.
            $matching = ownedJobs($batchId)->pluck('id')->map(fn ($id) => (int) $id)->all();
            $tracked = array_map('intval', $ctx['jobIds']);
            $untracked = array_values(array_diff($matching, $tracked));
            $others = DB::table('jobs')->count() - count($matching);
            $n = $matching === [] ? 0 : DB::table('jobs')->whereIn('id', $matching)->delete();

            return "$n deleted; ".count($untracked).' re-queued row(s) of this batch included; '.$others.' other row(s) left untouched';
        });
    }
    if ($ctx['lockName'] !== null && $prefix !== null && $lockTable !== null) {
        $step('unique lock row', fn () => DB::table($lockTable)->where('key', $prefix.$ctx['lockName'])->delete());
    }
    if ($batchIds !== []) {
        $step('audit_logs for the batches', fn () => DB::table('audit_logs')->where('target_type', 'import_batch')->whereIn('target_id', array_map('strval', $batchIds))->delete());
    }
    $ourStudents = DB::table('students')->select('id')->where('institutional_id', 'like', $likeId);
    $step('student_enrollments', fn () => DB::table('student_enrollments')->where(function ($q) use ($batchIds, $ourStudents) {
        $q->whereIn('student_id', $ourStudents);
        if ($batchIds !== []) {
            $q->orWhereIn('import_batch_id', $batchIds);
        }
    })->delete());
    if ($batchId !== null) {
        $step('import_batch_rows', fn () => DB::table('import_batch_rows')->where('import_batch_id', $batchId)->delete());
    }
    $step('students (synthetic prefix only)', fn () => DB::table('students')->where('institutional_id', 'like', $likeId)->delete());
    if ($batchIds !== []) {
        $step('import_batches', fn () => DB::table('import_batches')->whereIn('id', $batchIds)->delete());
    }
    $step('admin_users (the synthetic admin)', fn () => DB::table('admin_users')->where('id', $ctx['adminId'])->delete());
    if ($ctx['storageKey'] !== null) {
        $step('staged source file', function () use ($ctx) {
            Storage::disk((string) config('comelec.import.disk'))->delete($ctx['storageKey']);
        });
    }

    return $failedSteps === []
        ? ['status' => 'ok', 'detail' => 'cleaned']
        : ['status' => 'partial', 'detail' => 'these cleanup steps failed: '.implode(', ', $failedSteps)];
}

// ---- orchestrator state (everything the cleanup needs lives here, created before the first write) ---------
$students = max(1500, (int) ($argv[1] ?? 4000));
$pass = 0;
$fail = 0;
$exit = 0;
$token = bin2hex(random_bytes(3));
$likeId = "QRV{$token}-%";
$ctx = [
    'token' => $token, 'adminId' => null, 'priorBatchId' => null, 'batchId' => null,
    'jobIds' => [], 'lockName' => null, 'storageKey' => null, 'procs' => [],
    'failedBaseline' => null, 'tempFiles' => [],
];
$prefix = null;
$lockTable = null;
$cleanupResult = ['status' => 'ok', 'detail' => ''];

// last-resort safety net (fatal error, uncaught signal path): kill children; this never touches the database
register_shutdown_function(function () use (&$ctx): void {
    foreach ($ctx['procs'] as $child) {
        try {
            $child->stop(5);
        } catch (Throwable) {
        }
    }
});

// ---- run ----------------------------------------------------------------------------------------------
try {
    $problems = guardProblems();
    if ($problems !== []) {
        foreach ($problems as $p) {
            fwrite(STDERR, "  - $p\n");
        }
        abortRun('safety guard failed; nothing was written.');
    }

    $names = resolveConnectionNames();
    $tableHomes = [
        TABLE_JOBS => $names['queue'], TABLE_FAILED_JOBS => $names['failed-jobs'], TABLE_CACHE_LOCKS => $names['cache-lock'],
    ];
    foreach (['students', 'student_enrollments', 'import_batches', 'import_batch_rows', 'admin_users', 'audit_logs'] as $table) {
        $tableHomes[$table] = $names['application'];
    }
    foreach ($tableHomes as $table => $connection) {
        if (! Schema::connection($connection)->hasTable($table)) {
            abortRun("table `$table` does not exist on connection `$connection` of the throwaway database; migrate it first (php artisan migrate with DB_DATABASE=".THROWAWAY_DB.').');
        }
    }
    if (DB::table('jobs')->count() > 0) {
        abortRun('the `jobs` table is not empty. A worker pops the NEXT job, so a foreign job would be consumed. Use an empty queue table; nothing was written.');
    }
    $importDisk = (string) config('comelec.import.disk');
    if (config("filesystems.disks.$importDisk.driver") !== 'local') {
        abortRun("import disk `$importDisk` is not a local disk.");
    }

    // committed values are asserted, never changed
    $probeJob = new ProcessImportBatch(1);
    $retryAfter = (int) config('queue.connections.database.retry_after');
    if ($probeJob->timeout !== 900 || (new ValidateImportBatch(1))->timeout !== 900 || $retryAfter !== 960 || $probeJob->uniqueFor !== 3600 || ! ($probeJob instanceof Illuminate\Contracts\Queue\ShouldBeUnique)) {
        abortRun('the committed queue settings changed (expected timeouts 900/900, retry_after 960, uniqueFor 3600, ShouldBeUnique): '
            .json_encode(['process_timeout' => $probeJob->timeout, 'retry_after' => $retryAfter, 'uniqueFor' => $probeJob->uniqueFor]).'. This script does not edit them; review before running.');
    }
    $queueName = (string) (config('queue.connections.database.queue') ?: 'default');
    $prefix = (string) Cache::store()->getStore()->getPrefix();
    $lockTable = (string) (config('cache.stores.database.lock_table') ?: 'cache_locks');
    $serverVersion = (string) DB::selectOne('select version() as v')->v;
    echo "Server: $serverVersion  database: ".THROWAWAY_DB."  queue: database/$queueName  retry_after=$retryAfter  chunk=100 (worker env)\n";

    $ctx['failedBaseline'] = DB::table('failed_jobs')->pluck('uuid')->all();
    $foreignStudents = (int) DB::table('students')->where('institutional_id', 'not like', $likeId)->count();
    $foreignEnrollments = (int) DB::table('student_enrollments')->whereNotIn('student_id', DB::table('students')->select('id')->where('institutional_id', 'like', $likeId))->count();

    // 0. child processes must resolve the same throwaway database as this one
    $probeChild = spawn([PHP_BINARY, __FILE__, 'probe'], $ctx, 'safety probe');
    if (! $probeChild->waitForExit(90)) {
        $probeChild->stop();
        abortRun('the safety probe child did not finish within 90 s; nothing was written.');
    }
    $probed = null;
    foreach (preg_split('/\R/', $probeChild->stdout()) ?: [] as $line) {
        $candidate = json_decode(trim($line), true);
        if (is_array($candidate) && array_key_exists('safe', $candidate)) {
            $probed = $candidate;
            break;
        }
    }
    $probeSafe = $probeChild->exitCode() === 0 && $probed !== null && ($probed['safe'] ?? false) === true;
    check('worker environment: a child process resolves the same throwaway database on all four connections', $probeSafe,
    $probeSafe ? '' : 'exit='.$probeChild->exitCode()
    .' stdout='.json_encode(substr($probeChild->stdout(), 0, 2000))
    .' stderr='.json_encode(substr($probeChild->stderr(), 0, 3000)));
    if (! $probeSafe) {
        abortRun('the worker environment is not safe; nothing was written.');
    }

    // 1. seed synthetic data (admin, prior batch, students), then stage -> validate -> confirm -> PROCESSING (no dispatch yet)
    $now = now();
    $ctx['adminId'] = DB::table('admin_users')->insertGetId([
        'authorized_email' => "qrv-$token@admins.example.test", 'display_name' => 'Queue Recovery Verification',
        'role' => 'BUKSU_COMELEC_IT_ADMIN', 'created_at' => $now, 'updated_at' => $now,
    ]);
    $ctx['priorBatchId'] = DB::table('import_batches')->insertGetId([
        'source_filename' => 'seed.csv', 'academic_year' => '2025-2026', 'semester' => '1st', 'uploaded_by' => $ctx['adminId'],
        'status' => 'COMPLETED', 'created_at' => $now, 'updated_at' => $now,
    ]);
    foreach (array_chunk(range(1, $students), 1000) as $chunk) {
        DB::table('students')->insert(array_map(fn (int $i) => [
            'institutional_id' => sprintf('QRV%s-%07d', $token, $i), 'institutional_email' => "qrv$token-$i@students.example.test",
            'first_name' => "F$i", 'last_name' => "L$i", 'current_college' => 'CON', 'current_course' => 'BSN',
            'current_year_level' => '1st Year', 'status' => 'ACTIVE', 'last_import_batch_id' => $ctx['priorBatchId'],
            'created_at' => $now, 'updated_at' => $now,
        ], $chunk));
    }

    $file = writeSyntheticCsv($ctx, $token, $students);

    $service = app(ImportBatchService::class);
    try {
        $batch = $service->stage($file, "qrv-$token.csv", $ctx['adminId'], '2026-2027', '1st');
    } finally {
        removeTempFiles($ctx);
    }
    $ctx['batchId'] = (int) $batch->id;
    $ctx['storageKey'] = ImportValidator::storagePath($batch);
    $service->validate($batch);
    $service->confirm($batch);
    $service->beginProcessing($batch);
    $batchId = $ctx['batchId'];

    $applicable = (int) DB::table('import_batch_rows')->where('import_batch_id', $batchId)->where('classification', 'UPDATED')->count();
    check('setup: batch is PROCESSING with every synthetic row staged as UPDATED and nothing applied yet',
        $batch->refresh()->status === 'PROCESSING' && $applicable === $students && enrollmentRows($batchId) === 0, "rows=$applicable status={$batch->status}");

    // 2. duplicate dispatch is suppressed by ShouldBeUnique
    $ctx['lockName'] = UniqueLock::getKey(new ProcessImportBatch($batchId));
    check('unique lock: none exists before the first dispatch', lockRow($ctx, $prefix, $lockTable) === null);
    $dispatchedAt = time();
    dispatchOnce($batchId);
    dispatchOnce($batchId);
    $allJobs = DB::table('jobs')->get();
    $owned = ownedJobs($batchId);
    $ctx['jobIds'] = $owned->pluck('id')->map(fn ($id) => (int) $id)->all(); // ONLY the job whose payload is this batch's
    check('duplicate dispatch: two dispatches produced exactly ONE queued job for this batch (and no other row exists)',
        $owned->count() === 1 && $allJobs->count() === 1, 'owned='.$owned->count().' total='.$allJobs->count());
    $lock = lockRow($ctx, $prefix, $lockTable);
    $ttl = $lock === null ? -1 : (int) $lock->expiration - $dispatchedAt;
    check('unique lock: held after dispatch with the committed 3,600 s lifetime', $lock !== null && $ttl >= 3590 && $ttl <= 3610, "ttl=$ttl");
    if ($owned->count() !== 1 || $allJobs->count() !== 1) {
        abortRun('cannot continue without exactly one queued job that belongs to this batch.');
    }
    $jobId = (int) $ctx['jobIds'][0];
    $ownedRow = $owned->first();
    check('queued job starts unreserved with attempts=0', $ownedRow->reserved_at === null && (int) $ownedRow->attempts === 0);

    // 3. real worker #1: let it commit some chunks, then kill it hard
    assertQueueIsolated($batchId);
    $victim = spawn(workerCommand(), $ctx, 'worker 1 (victim)');
    $deadline = microtime(true) + 120;
    $processed = 0;
    while (microtime(true) < $deadline) {
        $processed = processedRows($batchId);
        if ($processed >= 200 || ! $victim->isRunning()) {
            break;
        }
        usleep(10000);
    }
    $wasRunning = $victim->isRunning();
    if (! $victim->stop()) {
        abortRun('worker 1 could not be confirmed stopped after a hard kill; no further step is safe.');
    }
    $processedAtKill = processedRows($batchId);
    $enrolledAtKill = enrollmentRows($batchId);
    $killWindowHit = $wasRunning && $processedAtKill >= 100 && $processedAtKill <= $students - 100;
    check('killed worker: it was hard-killed part-way (some, not all, rows committed)', $killWindowHit, "processed=$processedAtKill of $students running=".($wasRunning ? 'yes' : 'no'));
    if (! $killWindowHit) {
        info('worker output: '.substr($victim->output(), 0, 300));
        abortRun('the kill window was missed (worker finished or never started); rerun with a larger students argument.');
    }
    check('killed worker: committed rows and enrollments agree (the open chunk rolled back as a unit)', $processedAtKill === $enrolledAtKill, "rows=$processedAtKill enrollments=$enrolledAtKill");
    $job = jobRows($ctx)->first();
    $reservedAtAfterKill = $job?->reserved_at;
    check('killed worker: the job is still in the queue, reserved, attempts=1 (never deleted or failed)', $job !== null && $job->reserved_at !== null && (int) $job->attempts === 1, 'attempts='.($job->attempts ?? 'none'));
    check('killed worker: the batch still says PROCESSING and the unique lock is still held', $batch->refresh()->status === 'PROCESSING' && lockRow($ctx, $prefix, $lockTable) !== null);
    check('killed worker: no failed_jobs row was written', DB::table('failed_jobs')->whereNotIn('uuid', $ctx['failedBaseline'])->count() === 0);

    // 3b. while the lock is held a manual re-dispatch is suppressed (this is the documented block)
    dispatchOnce($batchId);
    check('unique lock: a manual re-dispatch while the dead worker holds the lock is suppressed',
        ownedJobs($batchId)->count() === 1 && DB::table('jobs')->count() === 1 && (int) ownedJobs($batchId)->first()->id === $jobId);

    // 4. a worker started now must NOT take a freshly reserved job (no early redelivery, no waiting for 960 s)
    assertQueueIsolated($batchId);
    $early = spawn(workerCommand(), $ctx, 'worker 2 (early)');
    $earlyDone = $early->waitForExit(60);
    if (! $earlyDone && ! $early->stop()) {
        abortRun('worker 2 could not be confirmed stopped; no further step is safe.');
    }
    $jobNow = jobRows($ctx)->first();
    check('no early redelivery: a worker started right away left the fresh reservation untouched',
        $earlyDone && $jobNow !== null && (int) $jobNow->attempts === 1 && $jobNow->reserved_at === $reservedAtAfterKill && processedRows($batchId) === $processedAtKill,
        'attempts='.($jobNow->attempts ?? 'none').' processed='.processedRows($batchId));

    // 5. SIMULATED AGING of this one job's reservation (see the header for what this does and does not prove)
    $aged = time() - ($retryAfter + 5);
    $affected = DB::table('jobs')->where('id', $jobId)->whereIn('id', $ctx['jobIds'])->update(['reserved_at' => $aged]);
    check('simulated aging: exactly this run\'s job now has a reservation older than retry_after', $affected === 1 && (int) DB::table('jobs')->where('id', $jobId)->value('reserved_at') === $aged, 'reserved_at set '.($retryAfter + 5).' s in the past; NOT a real '.$retryAfter.' s wait');

    // 6. real worker #3 redelivers and finishes
    assertQueueIsolated($batchId);
    $rescuer = spawn(workerCommand(), $ctx, 'worker 3 (rescue)');
    $maxAttemptsSeen = 0;
    $until = microtime(true) + 240;
    while ($rescuer->isRunning() && microtime(true) < $until) {
        $seen = DB::table('jobs')->where('id', $jobId)->value('attempts');
        $maxAttemptsSeen = max($maxAttemptsSeen, (int) $seen);
        usleep(20000);
    }
    $rescuerDone = ! $rescuer->isRunning();
    if (! $rescuerDone && ! $rescuer->stop()) {
        abortRun('worker 3 could not be confirmed stopped; no further step is safe.');
    }
    info('rescue worker output: '.str_replace("\n", ' | ', substr($rescuer->output(), 0, 300)));
    check('redelivery: the rescue worker exited cleanly after handling the job', $rescuerDone && $rescuer->exitCode() === 0, 'exit='.$rescuer->exitCode());
    check('redelivery: the job was handed out again (attempts observed 2) or finished before it could be observed', $maxAttemptsSeen === 0 || $maxAttemptsSeen === 2, "max attempts seen=$maxAttemptsSeen");
    check('redelivery: the job row is gone and nothing was failed',
        DB::table('jobs')->where('id', $jobId)->count() === 0 && ownedJobs($batchId)->isEmpty() && DB::table('failed_jobs')->whereNotIn('uuid', $ctx['failedBaseline'])->count() === 0);

    // 7. final state
    $final = ImportBatch::query()->findOrFail($batchId);
    check('final: batch is COMPLETED with started/confirmed/completed timestamps', $final->status === 'COMPLETED' && $final->started_at !== null && $final->confirmed_at !== null && $final->completed_at !== null, 'status='.$final->status);
    check('final: counters received/updated/created/errored = N/N/0/0',
        (int) $final->records_received === $students && (int) $final->records_updated === $students && (int) $final->records_created === 0 && (int) $final->records_errored === 0,
        "received={$final->records_received} updated={$final->records_updated} created={$final->records_created} errored={$final->records_errored}");
    check('final: every staged row processed exactly once', processedRows($batchId) === $students && (int) DB::table('import_batch_rows')->where('import_batch_id', $batchId)->whereNull('processed_at')->count() === 0);
    $enrolled = enrollmentRows($batchId);
    $distinct = (int) DB::table('student_enrollments')->where('import_batch_id', $batchId)->distinct()->count('student_id');
    $dupes = (int) DB::table('student_enrollments')->where('import_batch_id', $batchId)->select('student_id')->groupBy('student_id')->havingRaw('count(*) > 1')->get()->count();
    check('final: exactly one enrollment per student (none missing, none duplicated)', $enrolled === $students && $distinct === $students && $dupes === 0, "enrollments=$enrolled distinct=$distinct duplicated=$dupes");
    $mismatch = (int) DB::table('student_enrollments as e')->join('students as s', 's.id', '=', 'e.student_id')->where('e.import_batch_id', $batchId)
        ->where(fn ($q) => $q->whereColumn('e.course', '!=', 's.current_course')->orWhereColumn('e.year_level', '!=', 's.current_year_level')->orWhereColumn('e.college', '!=', 's.current_college'))->count();
    check('final: each enrollment equals the student\'s resulting placement', $mismatch === 0, "mismatches=$mismatch");

    $labels = ['1st Year', '2nd Year', '3rd Year', '4th Year'];
    $wrong = 0;
    $stamped = 0;
    foreach (DB::table('students')->where('institutional_id', 'like', $likeId)->orderBy('id')->cursor() as $s) {
        $i = (int) substr((string) $s->institutional_id, -7);
        $courseChanged = $i % 10 === 0;
        $expectedCourse = $courseChanged ? 'BSIT' : 'BSN';
        $expectedYear = $courseChanged ? '1st Year' : $labels[$i % 4];
        $stamped += (int) $s->last_import_batch_id === $batchId ? 1 : 0;
        if ($s->current_course !== $expectedCourse || $s->current_year_level !== $expectedYear || $s->status !== 'ACTIVE' || $s->current_college !== 'CON') {
            $wrong++;
        }
    }
    check('final: every student has the expected placement (course-change rule applied once)', $wrong === 0, "wrong=$wrong");
    check('final: every student is stamped with last_import_batch_id of this batch', $stamped === $students, "stamped=$stamped");
    check('final: students and enrollments outside this run are untouched',
        (int) DB::table('students')->where('institutional_id', 'not like', $likeId)->count() === $foreignStudents
        && (int) DB::table('student_enrollments')->whereNotIn('student_id', DB::table('students')->select('id')->where('institutional_id', 'like', $likeId))->count() === $foreignEnrollments);

    $events = DB::table('audit_logs')->where('target_type', 'import_batch')->where('target_id', (string) $batchId)->pluck('event_type')->all();
    check('final: audit trail shows started + completed once and no failure', count(array_keys($events, 'import.batch.processing_started', true)) === 1
        && count(array_keys($events, 'import.batch.completed', true)) === 1 && ! in_array('import.batch.failed', $events, true), implode(',', array_unique($events)));

    // 8. unique lock released after success
    check('unique lock: released after successful completion', lockRow($ctx, $prefix, $lockTable) === null);
    $probeLock = Cache::lock($ctx['lockName'], 30);
    $acquired = $probeLock->get();
    if ($acquired) {
        $probeLock->release();
    }
    check('unique lock: the key can be acquired again after completion (a new dispatch would be accepted)', $acquired === true && lockRow($ctx, $prefix, $lockTable) === null);
} catch (RunAborted $e) {
    fwrite(STDERR, 'ABORT: '.$e->getMessage()."\n");
    $exit = 2;
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: '.get_class($e).': '.$e->getMessage()."\n");
    $fail++;
    $exit = 3;
} finally {
    echo "\nCleaning up (only rows created by this run; children are stopped first)...\n";
    removeTempFiles($ctx);
    try {
        $cleanupResult = cleanup($ctx, $prefix, $lockTable, $likeId);
    } catch (Throwable $e) { // cleanup() is written not to throw; this is belt and braces
        $cleanupResult = ['status' => 'partial', 'detail' => 'cleanup raised '.get_class($e).': '.$e->getMessage()];
        fwrite(STDERR, 'CLEANUP ERROR: '.$cleanupResult['detail']."\n");
    }
}

if ($exit === 0 && $fail > 0) {
    $exit = 1;
}
if ($exit === 0 && $cleanupResult['status'] !== 'ok') {
    $exit = 5;
}

echo "\nRESULT: $pass passed, $fail failed; cleanup: {$cleanupResult['status']}".($cleanupResult['status'] === 'ok' ? '' : " ({$cleanupResult['detail']})")."\n";
echo "NOTE: redelivery was driven by a SIMULATED aged reserved_at (retry_after + 5 s in the past), not a real 960 s wait.\n";
exit($exit);
