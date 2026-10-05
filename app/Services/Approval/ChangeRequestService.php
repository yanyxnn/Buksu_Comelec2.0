<?php

namespace App\Services\Approval;

use App\Models\AdminUser;
use App\Models\ChangeRequest;
use App\Notifications\ChangeRequestPending;
use App\Services\Audit\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;

/**
 * Generic proposal/approval mechanism (AUTH-034).
 *
 * Rules enforced here AND below the service:
 *  - only an authorized admin can create a request; the requester is always the
 *    authenticated admin passed in, never request input;
 *  - the requester can never approve or reject their own request (policy,
 *    service pre-check, the atomic UPDATE predicate, and a DB CHECK on
 *    MySQL/MariaDB);
 *  - PENDING -> APPROVED|REJECTED is one atomic conditional UPDATE, so of two
 *    racing decisions exactly one wins;
 *  - every admin on the authorized roster is notified in the SAME transaction as the request;
 *  - creation and every decision are audited in the same transaction.
 *
 * No real election action types and no multi-approval thresholds are defined
 * here (both unresolved in OPEN_DECISIONS.md): one decision by one non-requester
 * admin finalizes a request.
 */
class ChangeRequestService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $payload  must not contain secrets/credentials
     *
     * @throws AuthorizationException
     * @throws InvalidArgumentException
     */
    public function create(
        AdminUser $requester,
        string $actionType,
        ?string $subjectType = null,
        ?string $subjectId = null,
        array $payload = [],
    ): ChangeRequest {
        Gate::forUser($requester)->authorize('create', ChangeRequest::class);

        if (! in_array($actionType, (array) config('comelec.change_request_action_types', []), true)) {
            throw new InvalidArgumentException('Unregistered change-request action type.');
        }

        AuditLogger::assertMetadataIsSafe($payload);

        return DB::transaction(function () use ($requester, $actionType, $subjectType, $subjectId, $payload): ChangeRequest {
            $request = new ChangeRequest;
            $request->forceFill([
                'action_type' => $actionType,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'payload_json' => $payload === [] ? null : $payload,
                'status' => ChangeRequest::STATUS_PENDING,
                'requested_by' => $requester->getKey(),
            ])->save();

            // Every admin on the roster (verified intact by the policy above).
            // Same connection/transaction: a notification failure rolls back the request.
            Notification::send(AdminUser::query()->get(), new ChangeRequestPending($request, $requester->display_name));

            $this->audit->record(
                eventType: 'change_request.created',
                actorType: 'ADMIN',
                actorId: $requester->getKey(),
                targetType: 'change_request',
                targetId: $request->getKey(),
                description: 'Change request created.',
                metadata: ['action_type' => $actionType],
            );

            return $request;
        });
    }

    /**
     * @param  'APPROVED'|'REJECTED'  $decision
     *
     * @throws AuthorizationException
     * @throws ChangeRequestConflict
     */
    public function decide(AdminUser $actor, ChangeRequest $request, string $decision, ?string $note = null): ChangeRequest
    {
        if (! in_array($decision, [ChangeRequest::STATUS_APPROVED, ChangeRequest::STATUS_REJECTED], true)) {
            throw new InvalidArgumentException('Invalid decision.');
        }

        if ((int) $request->requested_by === (int) $actor->getKey()) {
            // Recorded outside any transaction so the evidence persists.
            $this->audit->record(
                eventType: 'change_request.self_decision_denied',
                severity: AuditLogger::WARNING,
                actorType: 'ADMIN',
                actorId: $actor->getKey(),
                targetType: 'change_request',
                targetId: $request->getKey(),
                description: 'Requester attempted to decide their own change request.',
                metadata: ['attempted' => $decision],
            );

            throw new AuthorizationException('A requester cannot decide their own change request.');
        }

        Gate::forUser($actor)->authorize('decide', $request);

        return DB::transaction(function () use ($actor, $request, $decision, $note): ChangeRequest {
            $affected = ChangeRequest::query()
                ->whereKey($request->getKey())
                ->where('status', ChangeRequest::STATUS_PENDING)
                ->where('requested_by', '<>', $actor->getKey())
                ->update([
                    'status' => $decision,
                    'decided_by' => $actor->getKey(),
                    'decided_at' => now(),
                    'decision_note' => $note,
                    'updated_at' => now(),
                ]);

            if ($affected !== 1) {
                throw new ChangeRequestConflict('This change request has already been decided.');
            }

            $this->audit->record(
                eventType: $decision === ChangeRequest::STATUS_APPROVED ? 'change_request.approved' : 'change_request.rejected',
                actorType: 'ADMIN',
                actorId: $actor->getKey(),
                targetType: 'change_request',
                targetId: $request->getKey(),
                description: 'Change request '.strtolower($decision).'.',
                metadata: [
                    'action_type' => $request->action_type,
                    'requested_by_admin_id' => (int) $request->requested_by,
                ],
            );

            return $request->refresh();
        });
    }
}
