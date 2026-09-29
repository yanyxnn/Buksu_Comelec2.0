<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Generic proposal/approval record. Created and decided only through
 * App\Services\Approval\ChangeRequestService; nothing is mass-assignable.
 */
class ChangeRequest extends Model
{
    public const STATUS_PENDING = 'PENDING';

    public const STATUS_APPROVED = 'APPROVED';

    public const STATUS_REJECTED = 'REJECTED';

    protected $table = 'change_requests';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'payload_json' => 'array',
            'decided_at' => 'datetime',
        ];
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'requested_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'decided_by');
    }
}
