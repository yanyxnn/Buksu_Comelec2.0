<?php

namespace App\Jobs;

use App\Models\ImportBatch;
use App\Services\StudentImport\BatchStateMachine;
use App\Services\StudentImport\ImportBatchService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Throwable;

/**
 * Background validation/classification of a STAGED (or re-validated) batch. The service marks the
 * batch FAILED itself on any error, so the job does not retry blindly.
 */
class ValidateImportBatch implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    /** Lock expiry (seconds), longer than the timeout so a crashed worker cannot block the batch for long. */
    public int $uniqueFor = 1000;

    public function __construct(public readonly int $batchId, public readonly ?int $adminId = null) {}

    /** One queued/running validation per batch, whichever administrator or session queued it. */
    public function uniqueId(): string
    {
        return 'validate-import-batch-'.$this->batchId;
    }

    public function handle(ImportBatchService $service): void
    {
        $batch = ImportBatch::query()->find($this->batchId);

        if ($batch === null || ! in_array($batch->status, [BatchStateMachine::STAGED, BatchStateMachine::PREVIEWED, BatchStateMachine::FAILED], true)) {
            return;
        }

        $service->validate($batch, $this->adminId);
    }

    /**
     * Called by the queue when the job fails for good (an exception, a timeout, exhausted attempts).
     * A batch still VALIDATING is marked FAILED so it is never left looking busy; any other status is
     * left alone. Re-validating a FAILED, unconfirmed batch is the existing recovery path.
     */
    public function failed(?Throwable $exception): void
    {
        app(ImportBatchService::class)->failValidation($this->batchId, 'VALIDATION_ERROR', $this->adminId);
    }
}
