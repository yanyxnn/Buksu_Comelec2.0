<?php

namespace App\Notifications;

use App\Models\ChangeRequest;
use Illuminate\Notifications\Notification;

/**
 * In-app (database) notification only. Deliberately NOT queued: it must be
 * written inside the same DB transaction as the change request. No email in
 * Phase 02. Carries no payload contents, only identifiers and labels.
 */
class ChangeRequestPending extends Notification
{
    public function __construct(private readonly ChangeRequest $request, private readonly string $requesterName) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'change_request_id' => $this->request->getKey(),
            'action_type' => $this->request->action_type,
            'requested_by_admin_id' => (int) $this->request->requested_by,
            'requested_by_name' => $this->requesterName,
        ];
    }
}
