<?php

namespace App\Services\StudentImport;

use App\Jobs\ProcessImportBatch;
use App\Models\ImportBatch;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * The single entry point for the Data Center import lifecycle:
 *
 *   stage -> validate -> (PREVIEWED: review) -> confirm -> processing -> COMPLETED | FAILED
 *
 * Status only changes here, through BatchStateMachine, and each change is a conditional UPDATE
 * (`WHERE status = <expected>`), so two racing callers can never both win a transition.
 *
 * Audit events carry batch ids and counters only - never student data, filenames, emails or
 * Google identifiers. Imports are for student master data: nothing here reads or writes
 * administrator records.
 */
class ImportBatchService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ImportValidator $validator,
        private readonly ImportChunkProcessor $processor,
    ) {}

    /**
     * Registers an uploaded file as a STAGED batch. Nothing is parsed or applied yet.
     *
     * @throws SourceFileException
     */
    public function stage(string $path, string $originalName, int $adminId, string $academicYear, string $semester): ImportBatch
    {
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        if (! in_array($extension, (array) config('comelec.import.extensions'), true)) {
            throw new SourceFileException(SourceFileException::UNSUPPORTED_FILE_TYPE);
        }

        if (! is_file($path) || ! is_readable($path)) {
            throw new SourceFileException(SourceFileException::FILE_UNREADABLE);
        }

        if (filesize($path) > (int) config('comelec.import.max_file_bytes')) {
            throw new SourceFileException(SourceFileException::FILE_TOO_LARGE);
        }

        $checksum = hash_file('sha256', $path);
        $disk = Storage::disk((string) config('comelec.import.disk'));
        $storedKey = null; // the exact key written for THIS attempt (a DB rollback cannot undo a file write)

        try {
            $batch = DB::transaction(function () use ($path, $originalName, $adminId, $academicYear, $semester, $checksum, $disk, &$storedKey): ImportBatch {
                $id = DB::table('import_batches')->insertGetId([
                    'source_filename' => $originalName,
                    'academic_year' => trim($academicYear),
                    'semester' => trim($semester),
                    'uploaded_by' => $adminId,
                    'status' => BatchStateMachine::STAGED,
                    'checksum' => $checksum,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $key = ImportValidator::storagePath((object) ['id' => $id, 'source_filename' => $originalName]);
                $storedKey = $key; // recorded BEFORE the write so even a partial write is cleaned up

                $stream = fopen($path, 'rb');
                try {
                    $stored = $disk->put($key, $stream);
                } finally {
                    fclose($stream);
                }

                if (! $stored) {
                    throw new SourceFileException(SourceFileException::FILE_UNREADABLE);
                }

                return ImportBatch::query()->findOrFail($id);
            });
        } catch (Throwable $e) {
            $this->discardStoredFile($disk, $storedKey);

            throw $e; // the ORIGINAL failure, never replaced by a cleanup problem
        }

        $this->record('import.batch.staged', $batch, $adminId);

        return $batch;
    }

    /**
     * Best-effort removal of a source file whose batch row was rolled back. It never throws: the caller is
     * already propagating the original failure and must not have it masked. A cleanup that fails leaves a
     * file that no batch row references (a re-staged batch overwrites its own key, so an orphan is never
     * adopted as a valid source) and is reported through the application log with the storage key only:
     * no filename, no file contents, no student data.
     */
    private function discardStoredFile(mixed $disk, ?string $key): void
    {
        if ($key === null) {
            return;
        }

        try {
            if ($disk->delete($key) === false) {
                throw new RuntimeException('delete returned false');
            }
        } catch (Throwable) {
            Log::error('import.staged_file_cleanup_failed', [
                'disk' => (string) config('comelec.import.disk'),
                'storage_key' => $key,
            ]);
        }
    }

    /**
     * Runs validation/classification synchronously and leaves the batch PREVIEWED, or FAILED with a
     * coarse reason. A file-level problem (bad format/columns/encoding/empty) is an expected,
     * user-fixable outcome: it ends in FAILED and is returned, not thrown.
     */
    public function validate(ImportBatch $batch, ?int $adminId = null): ImportBatch
    {
        $this->transition($batch, BatchStateMachine::VALIDATING);

        try {
            $this->validator->run($batch);
        } catch (SourceFileException $e) {
            $this->fail($batch, $e->reason, $adminId);

            return $batch->refresh();
        } catch (Throwable $e) {
            $this->fail($batch, 'VALIDATION_ERROR', $adminId);

            throw $e;
        }

        $this->transition($batch, BatchStateMachine::PREVIEWED);
        $this->record('import.batch.validated', $batch->refresh(), $adminId, extra: ['classification_counts' => $this->classificationCounts((int) $batch->id)]);

        return $batch;
    }

    /**
     * @return array<string, int> classification => rows (for the preview/review step), sorted by name.
     *                            Sorted in PHP: SQL ordering of an ENUM column follows the ENUM definition
     *                            order on MySQL/MariaDB but alphabetical order elsewhere.
     */
    public function classificationCounts(int $batchId): array
    {
        $counts = DB::table('import_batch_rows')
            ->where('import_batch_id', $batchId)
            ->selectRaw('classification, COUNT(*) as aggregate')
            ->groupBy('classification')
            ->pluck('aggregate', 'classification')
            ->map(fn ($n) => (int) $n)
            ->all();

        ksort($counts);

        return $counts;
    }

    public function confirm(ImportBatch $batch, ?int $adminId = null): ImportBatch
    {
        $this->transition($batch, BatchStateMachine::CONFIRMED, ['confirmed_at' => now()]);
        $this->record('import.batch.confirmed', $batch, $adminId);

        return $batch;
    }

    /** CONFIRMED (or FAILED-after-confirmation) -> PROCESSING, then queue the background job. */
    public function startProcessing(ImportBatch $batch, ?int $adminId = null): ImportBatch
    {
        $this->beginProcessing($batch, $adminId);

        dispatch(new ProcessImportBatch((int) $batch->id));

        return $batch->refresh();
    }

    /** The state change of startProcessing() without queueing (used by the job's own tests). */
    public function beginProcessing(ImportBatch $batch, ?int $adminId = null): ImportBatch
    {
        $extra = $batch->started_at === null ? ['started_at' => now()] : [];
        $resuming = $batch->status === BatchStateMachine::FAILED;

        $this->transition($batch, BatchStateMachine::PROCESSING, $extra);
        $this->record($resuming ? 'import.batch.processing_resumed' : 'import.batch.processing_started', $batch, $adminId);

        return $batch;
    }

    /**
     * Applies all remaining chunks and completes the batch. Any exception leaves the batch
     * PROCESSING (truthful: not done); the queue retries, and the job's failed() hook calls
     * failProcessing() once retries are exhausted.
     */
    public function runProcessing(ImportBatch $batch): ImportBatch
    {
        $this->processor->processAll($batch);

        return $this->complete($batch);
    }

    /**
     * Reconciles before declaring success. A mismatch is an anomaly: the batch FAILS with
     * INTEGRITY_MISMATCH and nothing is silently corrected (CLAUDE.md rule 14).
     */
    public function complete(ImportBatch $batch): ImportBatch
    {
        $id = (int) $batch->id;

        $batch->refresh();
        if ($batch->status !== BatchStateMachine::PROCESSING) {
            throw new ImportTransitionException((string) $batch->status, BatchStateMachine::COMPLETED);
        }

        $applicable = $this->rowCount($id, [RowClassifier::UPDATED, RowClassifier::NEW]);
        $processed = $this->rowCount($id, [RowClassifier::UPDATED, RowClassifier::NEW], processedOnly: true);
        $enrollments = (int) DB::table('student_enrollments')->where('import_batch_id', $id)->count();

        if ($processed !== $applicable || $enrollments !== $processed) {
            $this->fail($batch, 'INTEGRITY_MISMATCH', null);

            return $batch->refresh();
        }

        $received = (int) DB::table('import_batch_rows')->where('import_batch_id', $id)->count();

        $this->transition($batch, BatchStateMachine::COMPLETED, [
            'completed_at' => now(),
            'records_received' => $received,
            'records_created' => $this->rowCount($id, [RowClassifier::NEW], processedOnly: true),
            'records_updated' => $this->rowCount($id, [RowClassifier::UPDATED], processedOnly: true),
            'records_errored' => $received - $applicable,
        ]);
        $this->record('import.batch.completed', $batch->refresh());

        return $batch;
    }

    /** Called by the job's failed() hook; a no-op unless the batch is still PROCESSING. */
    public function failProcessing(int $batchId, string $code): void
    {
        $batch = ImportBatch::query()->find($batchId);

        if ($batch !== null && $batch->status === BatchStateMachine::PROCESSING) {
            $this->fail($batch, $code, null);
        }
    }

    public function fail(ImportBatch $batch, string $code, ?int $adminId): void
    {
        $this->transition($batch, BatchStateMachine::FAILED);
        $this->record('import.batch.failed', $batch, $adminId, AuditLogger::WARNING, ['failure_code' => $code]);
    }

    /**
     * @param  array<string, mixed>  $extra  columns written together with the status change
     *
     * @throws ImportTransitionException
     */
    private function transition(ImportBatch $batch, string $to, array $extra = []): void
    {
        $current = DB::table('import_batches')->where('id', $batch->id)->first(['status', 'confirmed_at']);

        BatchStateMachine::assertAllowed((string) $current->status, $to, $current->confirmed_at !== null);

        $affected = DB::table('import_batches')
            ->where('id', $batch->id)
            ->where('status', $current->status)
            ->update(['status' => $to, 'updated_at' => now()] + $extra);

        if ($affected !== 1) {
            throw new ImportTransitionException((string) $current->status, $to);
        }

        $batch->refresh();
    }

    /** @param list<string> $classifications */
    private function rowCount(int $batchId, array $classifications, bool $processedOnly = false): int
    {
        $query = DB::table('import_batch_rows')->where('import_batch_id', $batchId)->whereIn('classification', $classifications);

        if ($processedOnly) {
            $query->whereNotNull('processed_at');
        }

        return (int) $query->count();
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function record(string $event, ImportBatch $batch, ?int $adminId = null, string $severity = AuditLogger::INFO, array $extra = []): void
    {
        $this->audit->record(
            eventType: $event,
            severity: $severity,
            actorType: $adminId === null ? 'system' : 'admin',
            actorId: $adminId,
            targetType: 'import_batch',
            targetId: $batch->id,
            metadata: [
                'status' => $batch->status,
                'records_received' => (int) $batch->records_received,
                'records_created' => (int) $batch->records_created,
                'records_updated' => (int) $batch->records_updated,
                'records_errored' => (int) $batch->records_errored,
            ] + $extra,
        );
    }
}
