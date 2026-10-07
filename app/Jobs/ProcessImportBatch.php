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
 * Applies a confirmed batch to the student master data in the background (chunked, restartable).
 * Carries only the batch id. One job per batch at a time (ShouldBeUnique); a retry simply
 * continues from the first unprocessed row. After the final failed attempt the batch is marked
 * FAILED so its state stays truthful; re-dispatching it later resumes from where it stopped.
 */
class ProcessImportBatch implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    public int $timeout = 900;

    public int $uniqueFor = 3600;

    public function __construct(public readonly int $batchId) {}

    public function uniqueId(): string
    {
        return 'import-batch-'.$this->batchId;
    }

    public function handle(ImportBatchService $service): void
    {
        $batch = ImportBatch::query()->find($this->batchId);

        if ($batch === null || $batch->status !== BatchStateMachine::PROCESSING) {
            return; // already completed / failed / not started: nothing to do (idempotent)
        }

        $service->runProcessing($batch);
    }

    public function failed(?Throwable $exception): void
    {
        app(ImportBatchService::class)->failProcessing($this->batchId, 'PROCESSING_ERROR');
    }
}
