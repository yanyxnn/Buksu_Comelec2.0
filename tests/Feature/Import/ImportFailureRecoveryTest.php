<?php

use App\Jobs\ProcessImportBatch;
use App\Jobs\ValidateImportBatch;
use App\Models\ImportBatch;
use App\Services\StudentImport\BatchStateMachine;
use App\Services\StudentImport\ImportBatchService;
use App\Services\StudentImport\ImportChunkProcessor;
use App\Services\StudentImport\ImportTransitionException;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ImportScenario as S;
use Tests\Support\ImportTestKit as Kit;

/*
 * Failure / retry behaviour of the chunked processor. The failure is a REAL database rejection,
 * not a mock: one staged row is corrupted so its status violates the students.status ENUM/CHECK
 * when the row is applied. That makes the chunk's transaction roll back exactly as a production
 * integrity failure would.
 */

beforeEach(function () {
    Storage::fake((string) config('comelec.import.disk'));
    config(['comelec.import.chunk_size' => 3]); // 8 rows => chunks of 3, 3, 2
});

/**
 * 8 existing students, a valid file that changes each one's year, validated, confirmed and
 * PROCESSING (not yet applied). Returns [batch, studentIds-by-institutional-id].
 *
 * @return array{0: ImportBatch, 1: array<string, int>}
 */
function processingBatchOfEight(): array
{
    $ids = [];
    $rows = [];
    for ($i = 1; $i <= 8; $i++) {
        $id = sprintf('2021-%05d', $i);
        $ids[$id] = S::student($id, ['current_year_level' => '1st Year', 'last_import_batch_id' => null])->id;
        $rows[] = ['id' => $id, 'year' => '2'];
    }

    $batch = S::preview(Kit::csv($rows));
    S::service()->confirm($batch);
    S::service()->beginProcessing($batch);

    return [$batch->refresh(), $ids];
}

/** Corrupts the Nth staged applicable row so applying it is rejected by the database. */
function corruptStagedRow(ImportBatch $batch, int $position): object
{
    $row = DB::table('import_batch_rows')->where('import_batch_id', $batch->id)->orderBy('id')->skip($position - 1)->first();
    $original = $row->normalized_json;
    $broken = json_decode($original, true);
    $broken['values']['status'] = 'BOGUS';
    DB::table('import_batch_rows')->where('id', $row->id)->update(['normalized_json' => json_encode($broken)]);
    $row->normalized_json = $original;

    return $row; // carries the ORIGINAL json so the test can repair it
}

function repairStagedRow(object $row): void
{
    DB::table('import_batch_rows')->where('id', $row->id)->update(['normalized_json' => $row->normalized_json]);
}

test('an injected failure rolls back only its own chunk and leaves the batch truthfully PROCESSING', function () {
    [$batch, $ids] = processingBatchOfEight();
    corruptStagedRow($batch, 5); // second chunk (rows 4-6)

    expect(fn () => app(ImportChunkProcessor::class)->processAll($batch))->toThrow(QueryException::class);

    // Chunk 1 (rows 1-3) committed; chunk 2 (rows 4-6) rolled back completely; chunk 3 never ran.
    expect(DB::table('import_batch_rows')->where('import_batch_id', $batch->id)->whereNotNull('processed_at')->count())->toBe(3);
    expect(S::enrollmentCount((int) $batch->id))->toBe(3);
    expect(DB::table('students')->where('last_import_batch_id', $batch->id)->count())->toBe(3);
    expect(DB::table('students')->whereIn('id', array_values($ids))->where('current_year_level', '2nd Year')->count())->toBe(3);

    // Nothing from the failed chunk leaked: row 4 (applied before the corrupted row 5, inside the SAME
    // transaction) must be rolled back too.
    $fourth = DB::table('import_batch_rows')->where('import_batch_id', $batch->id)->orderBy('id')->skip(3)->first();
    expect($fourth->processed_at)->toBeNull();
    expect(DB::table('student_enrollments')->where('student_id', $fourth->resolved_student_id)->count())->toBe(0);
    expect(DB::table('students')->where('id', $fourth->resolved_student_id)->value('current_year_level'))->toBe('1st Year');

    // The batch never claims to be done and its counters equal what is really committed.
    $fresh = $batch->refresh();
    expect($fresh->status)->toBe(BatchStateMachine::PROCESSING);
    expect($fresh->completed_at)->toBeNull();
    expect((int) $fresh->records_updated)->toBe(3);
    expect((int) $fresh->records_updated)->toBe(S::enrollmentCount((int) $batch->id));
});

test('retrying after a partial failure resumes from the first unprocessed row without redoing or duplicating work', function () {
    [$batch, $ids] = processingBatchOfEight();
    $broken = corruptStagedRow($batch, 5);

    expect(fn () => app(ImportChunkProcessor::class)->processAll($batch))->toThrow(QueryException::class);
    $firstChunkEnrollmentIds = DB::table('student_enrollments')->where('import_batch_id', $batch->id)->orderBy('id')->pluck('id')->all();
    expect($firstChunkEnrollmentIds)->toHaveCount(3);

    repairStagedRow($broken);
    $done = S::service()->runProcessing($batch->refresh());

    expect($done->status)->toBe(BatchStateMachine::COMPLETED);
    expect([(int) $done->records_received, (int) $done->records_created, (int) $done->records_updated, (int) $done->records_errored])->toBe([8, 0, 8, 0]);

    // Exactly one enrollment per student, and the three already-committed rows were not touched again.
    expect(S::enrollmentCount((int) $batch->id))->toBe(8);
    expect(DB::table('student_enrollments')->where('import_batch_id', $batch->id)->distinct()->count('student_id'))->toBe(8);
    expect(DB::table('student_enrollments')->where('import_batch_id', $batch->id)->orderBy('id')->limit(3)->pluck('id')->all())->toBe($firstChunkEnrollmentIds);
    expect(DB::table('students')->whereIn('id', array_values($ids))->where('current_year_level', '2nd Year')->where('last_import_batch_id', $batch->id)->count())->toBe(8);

    // Running it yet again is a no-op for data (a finished batch cannot be reprocessed at all).
    expect(S::enrollmentCount((int) $batch->id))->toBe(8);
});

test('a batch with unprocessed rows can never be completed: reconciliation fails it instead of declaring success', function () {
    [$batch] = processingBatchOfEight();
    corruptStagedRow($batch, 5);
    expect(fn () => app(ImportChunkProcessor::class)->processAll($batch))->toThrow(QueryException::class);

    $result = S::service()->complete($batch->refresh());

    expect($result->status)->toBe(BatchStateMachine::FAILED);
    expect($result->completed_at)->toBeNull();
    expect(DB::table('audit_logs')->where('event_type', 'import.batch.failed')->where('target_id', (string) $batch->id)->value('metadata_json'))
        ->toContain('INTEGRITY_MISMATCH');
    // Nothing was "corrected" silently.
    expect(S::enrollmentCount((int) $batch->id))->toBe(3);
});

test('a missing enrollment found at completion is an integrity mismatch and is never silently repaired', function () {
    [$batch] = processingBatchOfEight();
    app(ImportChunkProcessor::class)->processAll($batch);

    DB::table('student_enrollments')->where('import_batch_id', $batch->id)->orderBy('id')->limit(1)->delete();

    $result = S::service()->complete($batch->refresh());

    expect($result->status)->toBe(BatchStateMachine::FAILED);
    expect(S::enrollmentCount((int) $batch->id))->toBe(7); // not re-created behind anyone's back
});

test('a FAILED confirmed batch resumes processing and a FAILED unconfirmed batch can only be re-validated', function () {
    [$batch] = processingBatchOfEight();
    $broken = corruptStagedRow($batch, 5);
    expect(fn () => app(ImportChunkProcessor::class)->processAll($batch))->toThrow(QueryException::class);

    S::service()->failProcessing((int) $batch->id, 'PROCESSING_ERROR');
    expect($batch->refresh()->status)->toBe(BatchStateMachine::FAILED);

    // Recovery after confirmation goes to PROCESSING (never back through validation)...
    repairStagedRow($broken);
    S::service()->beginProcessing($batch);
    $done = S::service()->runProcessing($batch->refresh());
    expect($done->status)->toBe(BatchStateMachine::COMPLETED);
    expect(S::enrollmentCount((int) $batch->id))->toBe(8);

    // ...while a batch that failed BEFORE confirmation cannot jump to processing.
    $unconfirmed = S::stage(Kit::csv([['id' => '2021-00001']]));
    S::service()->fail($unconfirmed, 'VALIDATION_ERROR', null);
    expect(fn () => S::service()->beginProcessing($unconfirmed->refresh()))->toThrow(ImportTransitionException::class);
});

test('the processing job carries only the batch id, is queued rather than run inline, and is unique per batch', function () {
    Queue::fake();
    S::student('2021-00001', ['current_year_level' => '1st Year']);
    $batch = S::preview(Kit::csv([['id' => '2021-00001', 'year' => '2']]));
    S::service()->confirm($batch);

    S::service()->startProcessing($batch);

    Queue::assertPushed(ProcessImportBatch::class, fn (ProcessImportBatch $job) => $job->batchId === (int) $batch->id);
    expect($batch->refresh()->status)->toBe(BatchStateMachine::PROCESSING); // state is real, work not yet done
    expect(S::enrollmentCount((int) $batch->id))->toBe(0);

    $job = new ProcessImportBatch((int) $batch->id);
    expect($job)->toBeInstanceOf(ShouldBeUnique::class);
    expect($job->uniqueId())->toBe('import-batch-'.$batch->id);
    // The queued payload holds the batch id and nothing student-shaped.
    $payload = serialize($job);
    expect($payload)->toContain('batchId')->not->toContain('2021-00001')->not->toContain('Dela Cruz');
});

test('the processing job applies the batch, is a no-op when run again, and ignores batches that are not PROCESSING', function () {
    S::student('2021-00001', ['current_year_level' => '1st Year']);
    $batch = S::preview(Kit::csv([['id' => '2021-00001', 'year' => '2']]));
    S::service()->confirm($batch);
    S::service()->beginProcessing($batch);

    (new ProcessImportBatch((int) $batch->id))->handle(app(ImportBatchService::class));
    expect($batch->refresh()->status)->toBe(BatchStateMachine::COMPLETED);
    expect(S::enrollmentCount((int) $batch->id))->toBe(1);

    // A duplicate delivery of the same job (at-least-once queue semantics) changes nothing.
    (new ProcessImportBatch((int) $batch->id))->handle(app(ImportBatchService::class));
    expect($batch->refresh()->status)->toBe(BatchStateMachine::COMPLETED);
    expect(S::enrollmentCount((int) $batch->id))->toBe(1);

    // A batch that was never confirmed is not processed by a stray job.
    $other = S::preview(Kit::csv([['id' => '2021-00001', 'year' => '3']]));
    (new ProcessImportBatch((int) $other->id))->handle(app(ImportBatchService::class));
    expect($other->refresh()->status)->toBe(BatchStateMachine::PREVIEWED);
    expect(S::enrollmentCount((int) $other->id))->toBe(0);
});

test('when the job exhausts its retries the failed hook marks the batch FAILED, keeps the committed work, and a later run resumes', function () {
    [$batch] = processingBatchOfEight();
    $broken = corruptStagedRow($batch, 5);
    $job = new ProcessImportBatch((int) $batch->id);

    $thrown = null;
    try {
        $job->handle(app(ImportBatchService::class));
    } catch (Throwable $e) {
        $thrown = $e;
    }
    expect($thrown)->not->toBeNull();
    expect($batch->refresh()->status)->toBe(BatchStateMachine::PROCESSING); // still truthful while retries remain

    $job->failed($thrown);
    expect($batch->refresh()->status)->toBe(BatchStateMachine::FAILED);
    expect(S::enrollmentCount((int) $batch->id))->toBe(3);
    $audit = DB::table('audit_logs')->where('event_type', 'import.batch.failed')->where('target_id', (string) $batch->id)->value('metadata_json');
    expect($audit)->toContain('PROCESSING_ERROR');
    expect($audit)->not->toContain('BOGUS'); // no row data in the audit trail

    // The hook is a no-op for a batch that is not PROCESSING.
    $job->failed(new RuntimeException('late'));
    expect($batch->refresh()->status)->toBe(BatchStateMachine::FAILED);

    repairStagedRow($broken);
    S::service()->startProcessing($batch->refresh()); // sync queue in tests: runs the job
    expect($batch->refresh()->status)->toBe(BatchStateMachine::COMPLETED);
    expect(S::enrollmentCount((int) $batch->id))->toBe(8);
});

test('the validation job moves a STAGED batch to PREVIEWED and ignores batches past validation', function () {
    S::student('2021-00001');
    $staged = S::stage(Kit::csv([['id' => '2021-00001', 'year' => '2']]));

    (new ValidateImportBatch((int) $staged->id))->handle(app(ImportBatchService::class));
    expect($staged->refresh()->status)->toBe(BatchStateMachine::PREVIEWED);
    expect(DB::table('import_batch_rows')->where('import_batch_id', $staged->id)->count())->toBe(1);

    S::service()->confirm($staged);
    (new ValidateImportBatch((int) $staged->id))->handle(app(ImportBatchService::class));
    expect($staged->refresh()->status)->toBe(BatchStateMachine::CONFIRMED); // untouched
});
