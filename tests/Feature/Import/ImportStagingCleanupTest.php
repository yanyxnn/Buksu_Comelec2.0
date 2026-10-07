<?php

use App\Services\StudentImport\ImportBatchService;
use App\Services\StudentImport\RowClassifier;
use App\Services\StudentImport\SourceFileException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ImportScenario as S;
use Tests\Support\ImportTestKit as Kit;

/*
 * stage() writes the source file to the configured import disk INSIDE the database transaction. A
 * rollback cannot undo a file write, so a failure after the write must remove the file it created, must
 * never replace the original exception with a cleanup problem, and must never leave a batch row behind.
 */

beforeEach(function () {
    $this->disk = (string) config('comelec.import.disk');
    Storage::fake($this->disk);
});

function stageFilePath(string $contents, string $suffix = '.csv'): string
{
    $path = Kit::tempPath($suffix);
    file_put_contents($path, $contents);

    return $path;
}

/** Fails ONCE: the first read of import_batches after the file has been stored (a real DB-layer failure). */
function failDatabaseAfterFileIsStored(string $disk): void
{
    $armed = true;

    DB::listen(function ($query) use ($disk, &$armed) {
        $isBatchRead = str_starts_with(strtolower(ltrim($query->sql)), 'select') && str_contains($query->sql, 'import_batches');

        if ($armed && $isBatchRead && Storage::disk($disk)->allFiles() !== []) {
            $armed = false;

            throw new RuntimeException('injected database failure after storage');
        }
    });
}

test('successful staging stores exactly one source file and one batch row, unchanged', function () {
    $csv = Kit::csv([['id' => '2020-00001']]);
    $path = stageFilePath($csv);

    $batch = S::service()->stage($path, 'roster.csv', S::admin()->id, '2026-2027', '1st');
    @unlink($path);

    $files = Storage::disk($this->disk)->allFiles();
    expect($files)->toBe(['student-imports/'.$batch->id.'.csv']);
    expect(hash('sha256', Storage::disk($this->disk)->get($files[0])))->toBe($batch->checksum);
    expect(DB::table('import_batches')->count())->toBe(1);
    expect($batch->status)->toBe('STAGED');
});

test('a database failure after the file was stored removes that file and leaves no batch row', function () {
    $admin = S::admin();
    $path = stageFilePath(Kit::csv([['id' => '2020-00001']]));
    failDatabaseAfterFileIsStored($this->disk);

    $thrown = null;
    try {
        S::service()->stage($path, 'roster.csv', $admin->id, '2026-2027', '1st');
    } catch (Throwable $e) {
        $thrown = $e;
    } finally {
        @unlink($path);
    }

    expect($thrown)->toBeInstanceOf(RuntimeException::class);
    expect($thrown->getMessage())->toBe('injected database failure after storage'); // the ORIGINAL failure
    expect(DB::table('import_batches')->count())->toBe(0);
    expect(Storage::disk($this->disk)->allFiles())->toBe([]);       // no orphaned staged file
    expect(DB::table('audit_logs')->where('event_type', 'import.batch.staged')->count())->toBe(0); // never looked successful
});

test('a failing cleanup never masks the original database failure and is reported without sensitive data', function () {
    $admin = S::admin();
    $path = stageFilePath(Kit::csv([['id' => '2020-00001', 'last' => 'Santos', 'first' => 'Ana']]));
    failDatabaseAfterFileIsStored($this->disk);

    $real = Storage::disk($this->disk);
    $failing = Mockery::mock($real)->makePartial();
    $failing->shouldReceive('delete')->andThrow(new RuntimeException('storage unavailable: Santos Ana roster.csv'));
    Storage::set($this->disk, $failing);
    Log::spy();

    $thrown = null;
    try {
        S::service()->stage($path, 'roster.csv', $admin->id, '2026-2027', '1st');
    } catch (Throwable $e) {
        $thrown = $e;
    } finally {
        @unlink($path);
    }

    // The caller sees the original failure, not the cleanup one; staging did not "succeed".
    expect($thrown)->toBeInstanceOf(RuntimeException::class);
    expect($thrown->getMessage())->toBe('injected database failure after storage');
    expect(DB::table('import_batches')->count())->toBe(0);

    // The cleanup failure is observable: one error event carrying only the disk and the storage key.
    Log::shouldHaveReceived('error')->once()->withArgs(function (string $message, array $context) {
        $flat = json_encode($context);

        return $message === 'import.staged_file_cleanup_failed'
            && array_keys($context) === ['disk', 'storage_key']
            && preg_match('#^student-imports/\d+\.csv$#', $context['storage_key']) === 1
            && ! str_contains($flat, 'Santos') && ! str_contains($flat, 'roster.csv') && ! str_contains($flat, 'storage unavailable');
    });

    // The orphan is still there (cleanup failed), but no batch row references it.
    expect(count($real->allFiles()))->toBe(1);
});

test('a cleanup that reports failure without throwing is also surfaced', function () {
    $admin = S::admin();
    $path = stageFilePath(Kit::csv([['id' => '2020-00001']]));
    failDatabaseAfterFileIsStored($this->disk);

    $failing = Mockery::mock(Storage::disk($this->disk))->makePartial();
    $failing->shouldReceive('delete')->andReturn(false);
    Storage::set($this->disk, $failing);
    Log::spy();

    expect(fn () => S::service()->stage($path, 'roster.csv', $admin->id, '2026-2027', '1st'))->toThrow(RuntimeException::class, 'injected database failure after storage');
    @unlink($path);

    Log::shouldHaveReceived('error')->once()->withArgs(fn (string $message) => $message === 'import.staged_file_cleanup_failed');
});

test('a later staging never inherits an orphaned file: the new batch gets exactly its own source', function () {
    $admin = S::admin();
    S::student('2020-00002', ['current_year_level' => '1st Year']);

    // First attempt fails and its cleanup also fails, leaving an orphan behind.
    $orphanPath = stageFilePath(Kit::csv([['id' => '2020-99999', 'last' => 'Orphan']]));
    failDatabaseAfterFileIsStored($this->disk);
    $real = Storage::disk($this->disk);
    $failing = Mockery::mock($real)->makePartial();
    $failing->shouldReceive('delete')->andThrow(new RuntimeException('down'));
    Storage::set($this->disk, $failing);
    Log::spy();
    expect(fn () => S::service()->stage($orphanPath, 'old.csv', $admin->id, '2026-2027', '1st'))->toThrow(RuntimeException::class);
    @unlink($orphanPath);
    expect(DB::table('import_batches')->where('source_filename', 'old.csv')->count())->toBe(0); // (the factory's seed batch is a fixture)
    expect(count($real->allFiles()))->toBe(1); // the orphan really is there

    // Re-run with a different file (and a healthy disk): the batch is built from ITS file only.
    Storage::fake($this->disk);
    $csv = Kit::csv([['id' => '2020-00002', 'year' => '2']]);
    $path = stageFilePath($csv);
    app()->forgetInstance(ImportBatchService::class);
    $batch = app(ImportBatchService::class)->stage($path, 'new.csv', $admin->id, '2026-2027', '1st');
    @unlink($path);

    expect(hash('sha256', Storage::disk($this->disk)->get('student-imports/'.$batch->id.'.csv')))->toBe($batch->checksum);
    $validated = app(ImportBatchService::class)->validate($batch);
    expect($validated->status)->toBe('PREVIEWED');
    expect(DB::table('import_batch_rows')->where('import_batch_id', $batch->id)->pluck('institutional_id')->all())->toBe(['2020-00002']);
    expect(DB::table('import_batch_rows')->where('import_batch_id', $batch->id)->value('classification'))->toBe(RowClassifier::UPDATED);
});

test('a file refused before the transaction stores nothing and creates no batch', function () {
    $path = stageFilePath('x', '.exe');

    expect(fn () => S::service()->stage($path, 'malware.exe', S::admin()->id, '2026-2027', '1st'))
        ->toThrow(SourceFileException::class);
    @unlink($path);

    expect(DB::table('import_batches')->count())->toBe(0);
    expect(Storage::disk($this->disk)->allFiles())->toBe([]);
});
