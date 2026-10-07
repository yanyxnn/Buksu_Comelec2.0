<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Audit/history record of one official Data Center import.
 *
 * `status` is NEVER mass-assignable: it only moves through
 * App\Services\StudentImport\ImportBatchService, which enforces BatchStateMachine.
 * Counters are derived by the service from staged rows and are not editable here either.
 */
class ImportBatch extends Model
{
    protected $table = 'import_batches';

    protected $guarded = [
        'id', 'status', 'records_received', 'records_created', 'records_updated', 'records_errored',
        'checksum', 'started_at', 'confirmed_at', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
