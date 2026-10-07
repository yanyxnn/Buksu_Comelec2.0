<?php

/*
 * Phase 03A — Data Center import: REAL multi-process verification against MySQL/MariaDB.
 *
 * The Pest suite runs inside a single transaction, so it cannot show two workers racing or a worker
 * dying. This script does, with separate PHP processes over separate PDO connections:
 *
 *   1. CONCURRENT WORKERS - N processes run the chunk processor on the SAME batch at once.
 *      Expected: no deadlock/error escapes, every row applied exactly once, exactly one enrollment per
 *      student, counters reconcile.
 *   2. KILLED WORKER + RESTART - a worker is hard-killed mid-batch (its open chunk transaction is rolled
 *      back by the server), then a fresh worker resumes. Expected: nothing duplicated, nothing lost,
 *      the final state equals an uninterrupted run.
 *
 * Usage (from the project root, against a MIGRATED, EMPTY database named in .env / DB_* variables):
 *     php database/verification/phase03a_import_concurrency.php [students=3000] [workers=3]
 *
 * SAFETY: refuses to run unless students, student_enrollments, import_batches and admin_users are all
 * empty, and removes everything it created when it finishes. Use a throw-away database.
 * All data is synthetic.
 */

use App\Models\ImportBatch;
use App\Services\StudentImport\ImportBatchService;
use App\Services\StudentImport\ImportChunkProcessor;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

$root = dirname(__DIR__, 2);
chdir($root);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

config(['comelec.import.chunk_size' => 100]);

// ---- worker mode ---------------------------------------------------------------------------------
if (($argv[1] ?? '') === 'worker') {
    $batchId = (int) $argv[2];
    $tag = $argv[3] ?? '?';
    $started = microtime(true);
    $applied = 0;
    $error = 'none';
    try {
        $applied = app(ImportChunkProcessor::class)->processAll(ImportBatch::query()->findOrFail($batchId));
    } catch (Throwable $e) {
        $error = get_class($e).': '.substr(str_replace(["\n", "\r"], ' ', $e->getMessage()), 0, 140);
    }
    printf("worker=%s applied=%d seconds=%.2f error=%s\n", $tag, $applied, microtime(true) - $started, $error);
    exit($error === 'none' ? 0 : 3);
}

// ---- orchestrator --------------------------------------------------------------------------------
$students = max(200, (int) ($argv[1] ?? 3000));
$workers = max(2, (int) ($argv[2] ?? 3));
$pass = 0;
$fail = 0;

function check(string $name, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? 'PASS  ' : 'FAIL  ').$name.($detail !== '' ? '  ['.$detail.']' : '')."\n";
}

foreach (['students', 'student_enrollments', 'import_batches', 'admin_users'] as $table) {
    if (DB::table($table)->count() > 0) {
        fwrite(STDERR, "REFUSING TO RUN: table `$table` is not empty. Use a throw-away migrated database.\n");
        exit(2);
    }
}
$driver = DB::connection()->getDriverName();
if (! in_array($driver, ['mysql', 'mariadb'], true)) {
    fwrite(STDERR, "REFUSING TO RUN: needs a real MySQL/MariaDB connection (got $driver).\n");
    exit(2);
}
echo 'Server: '.DB::selectOne('select version() as v')->v.'  database: '.DB::connection()->getDatabaseName()."\n";

/** Seeds students, stages + validates + confirms a batch and leaves it PROCESSING. */
function seedBatch(int $students, int $label): int
{
    $now = now();
    $adminId = DB::table('admin_users')->value('id') ?? DB::table('admin_users')->insertGetId([
        'authorized_email' => 'verify@admins.example.test', 'display_name' => 'Verification', 'role' => 'BUKSU_COMELEC_IT_ADMIN',
        'created_at' => $now, 'updated_at' => $now,
    ]);
    $prior = DB::table('import_batches')->insertGetId([
        'source_filename' => 'seed.csv', 'academic_year' => '2025-2026', 'semester' => '1st', 'uploaded_by' => $adminId,
        'status' => 'COMPLETED', 'created_at' => $now, 'updated_at' => $now,
    ]);
    foreach (array_chunk(range(1, $students), 1000) as $chunk) {
        DB::table('students')->insert(array_map(fn (int $i) => [
            'institutional_id' => sprintf('%07d', $i), 'institutional_email' => "s$i@students.example.test",
            'first_name' => "F$i", 'last_name' => "L$i", 'current_college' => 'CON', 'current_course' => 'BSN',
            'current_year_level' => '1st Year', 'status' => 'ACTIVE', 'last_import_batch_id' => $prior,
            'created_at' => $now, 'updated_at' => $now,
        ], $chunk));
    }

    $file = tempnam(sys_get_temp_dir(), 'p3a').'.csv';
    $h = fopen($file, 'wb');
    fputcsv($h, ['No.', 'Code', 'Last Name', 'First Name', 'Middle Name', 'Sex', 'Colleges', 'Course', 'Year'], ',', '"', '');
    for ($i = 1; $i <= $students; $i++) {
        fputcsv($h, [$i, sprintf('%07d', $i), "L$i", "F$i", '', 'F', 'CON', $i % 10 === 0 ? 'BSIT' : 'BSN', (string) (($i % 4) + 1)], ',', '"', '');
    }
    fclose($h);

    $service = app(ImportBatchService::class);
    $batch = $service->stage($file, "roster-$label.csv", $adminId, '2026-2027', '1st');
    unlink($file);
    $service->validate($batch);
    $service->confirm($batch);
    $service->beginProcessing($batch);

    return (int) $batch->id;
}

/** @return array{0: resource, 1: array<int, resource>} */
function spawn(int $batchId, string $tag): array
{
    $cmd = [PHP_BINARY, __FILE__, 'worker', (string) $batchId, $tag];
    $process = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (! is_resource($process)) {
        fwrite(STDERR, "could not start worker $tag\n");
        exit(2);
    }

    return [$process, $pipes];
}

/** @return array{out: string, code: int} */
function reap(array $spawned): array
{
    [$process, $pipes] = $spawned;
    $out = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return ['out' => trim($out), 'code' => proc_close($process)];
}

function invariants(string $scenario, int $batchId, int $students): void
{
    $applicable = (int) DB::table('import_batch_rows')->where('import_batch_id', $batchId)->where('classification', 'UPDATED')->count();
    $processed = (int) DB::table('import_batch_rows')->where('import_batch_id', $batchId)->where('classification', 'UPDATED')->whereNotNull('processed_at')->count();
    $enrollments = (int) DB::table('student_enrollments')->where('import_batch_id', $batchId)->count();
    $distinct = (int) DB::table('student_enrollments')->where('import_batch_id', $batchId)->distinct()->count('student_id');
    $stamped = (int) DB::table('students')->where('last_import_batch_id', $batchId)->count();
    $counter = (int) DB::table('import_batches')->where('id', $batchId)->value('records_updated');
    $changed = (int) DB::table('students')->where('current_course', 'BSIT')->where('current_year_level', '1st Year')->count();
    $expectedChanged = intdiv($students, 10);

    check("$scenario: every applicable row applied exactly once", $applicable === $students && $processed === $students, "applicable=$applicable processed=$processed");
    check("$scenario: exactly one enrollment per student (no duplicates, none missing)", $enrollments === $students && $distinct === $students, "enrollments=$enrollments distinct=$distinct");
    check("$scenario: every student stamped with last_import_batch_id", $stamped === $students, "stamped=$stamped");
    check("$scenario: batch counter equals committed rows", $counter === $processed, "records_updated=$counter");
    check("$scenario: course-change rule applied once (1st Year in new course)", $changed === $expectedChanged, "changed=$changed expected=$expectedChanged");
}

function cleanup(): void
{
    DB::statement('SET FOREIGN_KEY_CHECKS=0');
    foreach (['import_batch_rows', 'student_enrollments', 'import_batches', 'students', 'admin_users'] as $table) {
        DB::table($table)->delete();
    }
    DB::statement('SET FOREIGN_KEY_CHECKS=1');
    DB::table('audit_logs')->where('target_type', 'import_batch')->delete();
}

try {
    // 1. concurrent workers ---------------------------------------------------------------------
    $batchId = seedBatch($students, 1);
    $procs = [];
    for ($w = 1; $w <= $workers; $w++) {
        $procs[$w] = spawn($batchId, "W$w");
    }
    $errors = 0;
    $applied = 0;
    foreach ($procs as $w => $p) {
        $r = reap($p);
        echo '      '.$r['out']."\n";
        $errors += $r['code'] === 0 ? 0 : 1;
        if (preg_match('/applied=(\d+)/', $r['out'], $m)) {
            $applied += (int) $m[1];
        }
    }
    check("concurrent: $workers workers finished without an escaped error (deadlocks are serialized by the batch lock)", $errors === 0);
    check('concurrent: the workers\' applied counts add up to the batch size (no row applied twice)', $applied === $students, "sum=$applied");
    invariants('concurrent', $batchId, $students);
    $done = app(ImportBatchService::class)->complete(ImportBatch::query()->findOrFail($batchId));
    check('concurrent: batch reconciles and completes', $done->status === 'COMPLETED', 'status='.$done->status);
    cleanup();

    // 2. killed worker, then restart -----------------------------------------------------------
    $batchId = seedBatch($students, 2);
    $victim = spawn($batchId, 'VICTIM');
    $deadline = microtime(true) + 20;
    $progress = 0;
    while (microtime(true) < $deadline) { // wait until it has really committed some work, then kill it
        $progress = (int) DB::table('import_batch_rows')->where('import_batch_id', $batchId)->whereNotNull('processed_at')->count();
        if ($progress >= 200) {
            break;
        }
        usleep(20000);
    }
    proc_terminate($victim[0], 9);
    $killed = reap($victim);
    $afterKill = (int) DB::table('import_batch_rows')->where('import_batch_id', $batchId)->where('classification', 'UPDATED')->whereNotNull('processed_at')->count();
    $enrollAfterKill = (int) DB::table('student_enrollments')->where('import_batch_id', $batchId)->count();
    check('killed worker: it was killed part-way (some, not all, work committed)', $afterKill > 0 && $afterKill < $students, "processed=$afterKill of $students");
    check('killed worker: committed rows and enrollments agree (the open chunk rolled back as a unit)', $afterKill === $enrollAfterKill, "rows=$afterKill enrollments=$enrollAfterKill");
    check('killed worker: the batch still says PROCESSING (it never claims to be done)', DB::table('import_batches')->where('id', $batchId)->value('status') === 'PROCESSING');

    $resume = reap(spawn($batchId, 'RESUME'));
    echo '      '.$resume['out']."\n";
    check('restart: a fresh worker resumes and finishes without error', $resume['code'] === 0);
    invariants('restart', $batchId, $students);
    $done = app(ImportBatchService::class)->complete(ImportBatch::query()->findOrFail($batchId));
    check('restart: batch reconciles and completes', $done->status === 'COMPLETED', 'status='.$done->status);
} finally {
    cleanup();
}

echo "\nRESULT: $pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
