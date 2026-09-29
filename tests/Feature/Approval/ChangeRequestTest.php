<?php

use App\Models\AdminUser;
use App\Models\ChangeRequest;
use App\Models\Student;
use App\Services\Approval\ChangeRequestConflict;
use App\Services\Approval\ChangeRequestService;
use App\Services\Audit\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

beforeEach(function () {
    // TEST-ONLY action type. Phase 02 registers no real election action types.
    config(['comelec.change_request_action_types' => ['TEST_ONLY_ACTION']]);
    [$this->a, $this->b, $this->c] = makeAdminRoster();
    $this->service = app(ChangeRequestService::class);
});

function auditEvents(): array
{
    return DB::table('audit_logs')->orderBy('id')->pluck('event_type')->all();
}

test('an admin creates a PENDING request attributed to themselves', function () {
    $request = $this->service->create($this->a, 'TEST_ONLY_ACTION', 'test_subject', '42', ['note' => 'hello']);

    expect($request->status)->toBe('PENDING')
        ->and($request->requested_by)->toBe($this->a->id)
        ->and($request->decided_by)->toBeNull()
        ->and($request->decided_at)->toBeNull()
        ->and($request->fresh()->payload_json)->toBe(['note' => 'hello']);
});

test('no action types are registered by default: the mechanism invents none', function () {
    config(['comelec.change_request_action_types' => []]);

    expect(fn () => $this->service->create($this->a, 'ANYTHING'))->toThrow(InvalidArgumentException::class);
    expect(ChangeRequest::count())->toBe(0);
    expect(config('comelec.change_request_action_types'))->toBe([]);
});

test('unregistered action types and payloads that look like secrets are rejected', function () {
    expect(fn () => $this->service->create($this->a, 'NOT_REGISTERED'))->toThrow(InvalidArgumentException::class);
    expect(fn () => $this->service->create($this->a, 'TEST_ONLY_ACTION', payload: ['client_secret' => 'x']))->toThrow(InvalidArgumentException::class);
    expect(fn () => $this->service->create($this->a, 'TEST_ONLY_ACTION', payload: ['nested' => ['google_subject' => 'x']]))->toThrow(InvalidArgumentException::class);

    expect(ChangeRequest::count())->toBe(0);
});

test('students cannot create or decide requests (policy)', function () {
    $student = Student::factory()->linked()->create();
    $request = $this->service->create($this->a, 'TEST_ONLY_ACTION');

    expect(Gate::forUser($student)->denies('create', ChangeRequest::class))->toBeTrue()
        ->and(Gate::forUser($student)->denies('decide', $request))->toBeTrue()
        ->and(Gate::forUser($student)->denies('viewAny', ChangeRequest::class))->toBeTrue();
});

test('creation and decisions are refused unless the roster is exactly three admins', function () {
    $request = $this->service->create($this->a, 'TEST_ONLY_ACTION');
    $this->c->delete();

    expect(fn () => $this->service->create($this->a, 'TEST_ONLY_ACTION'))->toThrow(AuthorizationException::class);
    expect(fn () => $this->service->decide($this->b, $request, 'APPROVED'))->toThrow(AuthorizationException::class);
    expect($request->fresh()->status)->toBe('PENDING');
});

test('the requester cannot approve their own request', function () {
    $request = $this->service->create($this->a, 'TEST_ONLY_ACTION');

    expect(fn () => $this->service->decide($this->a, $request, 'APPROVED'))->toThrow(AuthorizationException::class);

    $fresh = $request->fresh();
    expect($fresh->status)->toBe('PENDING')->and($fresh->decided_by)->toBeNull();
});

test('the requester cannot reject their own request either', function () {
    $request = $this->service->create($this->a, 'TEST_ONLY_ACTION');

    expect(fn () => $this->service->decide($this->a, $request, 'REJECTED'))->toThrow(AuthorizationException::class);

    expect($request->fresh()->status)->toBe('PENDING');
});

test('self-decision attempts leave audit evidence that survives the refusal', function () {
    $request = $this->service->create($this->a, 'TEST_ONLY_ACTION');
    try {
        $this->service->decide($this->a, $request, 'APPROVED');
    } catch (AuthorizationException) {
    }

    $row = DB::table('audit_logs')->where('event_type', 'change_request.self_decision_denied')->first();
    expect($row)->not->toBeNull()
        ->and($row->severity)->toBe('WARNING')
        ->and($row->actor_id)->toBe((string) $this->a->id)
        ->and($row->target_id)->toBe((string) $request->id);
});

test('a different authorized admin can approve', function () {
    $request = $this->service->create($this->a, 'TEST_ONLY_ACTION');

    $decided = $this->service->decide($this->b, $request, 'APPROVED', 'looks fine');

    expect($decided->status)->toBe('APPROVED')
        ->and($decided->decided_by)->toBe($this->b->id)
        ->and($decided->decided_at)->not->toBeNull()
        ->and($decided->decision_note)->toBe('looks fine');
});

test('a different authorized admin can reject', function () {
    $request = $this->service->create($this->a, 'TEST_ONLY_ACTION');

    $decided = $this->service->decide($this->c, $request, 'REJECTED', 'not now');

    expect($decided->status)->toBe('REJECTED')->and($decided->decided_by)->toBe($this->c->id);
});

test('an invalid decision value is refused', function () {
    $request = $this->service->create($this->a, 'TEST_ONLY_ACTION');

    expect(fn () => $this->service->decide($this->b, $request, 'PENDING'))->toThrow(InvalidArgumentException::class);
    expect(fn () => $this->service->decide($this->b, $request, 'approved-ish'))->toThrow(InvalidArgumentException::class);
});

test('a decided request cannot be decided again, even with a fresh model', function () {
    $request = $this->service->create($this->a, 'TEST_ONLY_ACTION');
    $this->service->decide($this->b, $request, 'APPROVED');

    expect(fn () => $this->service->decide($this->c, $request->fresh(), 'REJECTED'))->toThrow(AuthorizationException::class);

    expect($request->fresh()->status)->toBe('APPROVED')->and($request->fresh()->decided_by)->toBe($this->b->id);
});

test('race: with two admins deciding the same PENDING request only one decision wins', function () {
    $created = $this->service->create($this->a, 'TEST_ONLY_ACTION');

    // Both reviewers loaded the request while it was still PENDING.
    $seenByB = ChangeRequest::find($created->id);
    $seenByC = ChangeRequest::find($created->id);
    expect($seenByB->status)->toBe('PENDING')->and($seenByC->status)->toBe('PENDING');

    $this->service->decide($this->b, $seenByB, 'APPROVED');

    // C acts on a stale PENDING copy: the policy would still pass, the atomic UPDATE must not.
    expect(fn () => $this->service->decide($this->c, $seenByC, 'REJECTED'))->toThrow(ChangeRequestConflict::class);

    $final = $created->fresh();
    expect($final->status)->toBe('APPROVED')->and($final->decided_by)->toBe($this->b->id);
    expect(DB::table('audit_logs')->whereIn('event_type', ['change_request.approved', 'change_request.rejected'])->count())->toBe(1);
});

test('the atomic decision statement itself can never let a requester decide (query-level guard)', function () {
    $request = $this->service->create($this->a, 'TEST_ONLY_ACTION');

    $affected = ChangeRequest::query()->whereKey($request->id)
        ->where('status', 'PENDING')->where('requested_by', '<>', $this->a->id)
        ->update(['status' => 'APPROVED', 'decided_by' => $this->a->id, 'decided_at' => now()]);

    expect($affected)->toBe(0)->and($request->fresh()->status)->toBe('PENDING');
});

test('creating a request notifies ALL THREE admins in-app', function () {
    $request = $this->service->create($this->a, 'TEST_ONLY_ACTION');

    $rows = DB::table('notifications')->get();
    expect($rows)->toHaveCount(3);
    expect($rows->pluck('notifiable_id')->map(fn ($id) => (int) $id)->sort()->values()->all())
        ->toBe(collect([$this->a, $this->b, $this->c])->pluck('id')->sort()->values()->all());
    expect($rows->pluck('notifiable_type')->unique()->all())->toBe([AdminUser::class]);

    $data = json_decode($rows->first()->data, true);
    expect($data['change_request_id'])->toBe($request->id)
        ->and($data['action_type'])->toBe('TEST_ONLY_ACTION')
        ->and($data['requested_by_admin_id'])->toBe($this->a->id);

    foreach ([$this->a, $this->b, $this->c] as $admin) {
        expect($admin->fresh()->unreadNotifications)->toHaveCount(1);
    }
});

test('notification and request are one transaction: if notifying fails nothing is created', function () {
    failOnInsertInto('notifications'); // the notification write fails mid-transaction

    expect(fn () => $this->service->create($this->a, 'TEST_ONLY_ACTION'))->toThrow(RuntimeException::class, 'Injected failure');

    expect(ChangeRequest::count())->toBe(0);
    expect(DB::table('audit_logs')->where('event_type', 'change_request.created')->count())->toBe(0);
});

test('if the audit write fails the request and its notifications are rolled back too', function () {
    failOnInsertInto('audit_logs');

    expect(fn () => $this->service->create($this->a, 'TEST_ONLY_ACTION'))->toThrow(RuntimeException::class, 'Injected failure');

    expect(ChangeRequest::count())->toBe(0)->and(DB::table('notifications')->count())->toBe(0);
});

test('a failed decision audit rolls the decision back (no unaudited approvals)', function () {
    $request = $this->service->create($this->a, 'TEST_ONLY_ACTION');
    failOnInsertInto('audit_logs');

    expect(fn () => $this->service->decide($this->b, $request, 'APPROVED'))->toThrow(RuntimeException::class, 'Injected failure');

    expect($request->fresh()->status)->toBe('PENDING');
});

test('every step is audited with actor, target and no sensitive data', function () {
    $request = $this->service->create($this->a, 'TEST_ONLY_ACTION', payload: ['harmless' => 'value']);
    $this->service->decide($this->b, $request, 'APPROVED');

    expect(auditEvents())->toBe(['change_request.created', 'change_request.approved']);

    $created = DB::table('audit_logs')->where('event_type', 'change_request.created')->first();
    $approved = DB::table('audit_logs')->where('event_type', 'change_request.approved')->first();

    expect($created->actor_type)->toBe('ADMIN')->and($created->actor_id)->toBe((string) $this->a->id)
        ->and($created->target_type)->toBe('change_request')->and($created->target_id)->toBe((string) $request->id);
    expect($approved->actor_id)->toBe((string) $this->b->id)
        ->and(json_decode($approved->metadata_json, true)['requested_by_admin_id'])->toBe($this->a->id);

    $dump = DB::table('audit_logs')->get()->toJson();
    foreach ([$this->a, $this->b, $this->c] as $admin) {
        expect($dump)->not->toContain($admin->google_subject);
    }
    expect($dump)->not->toContain('harmless'); // payload contents are not copied into the audit trail
});

test('rejection is audited as its own event', function () {
    $request = $this->service->create($this->a, 'TEST_ONLY_ACTION');
    $this->service->decide($this->c, $request, 'REJECTED');

    expect(auditEvents())->toBe(['change_request.created', 'change_request.rejected']);
});

test('the audit logger refuses forbidden metadata keys', function () {
    $audit = app(AuditLogger::class);

    foreach (['google_subject', 'access_token', 'password', 'email', 'client_secret', 'selection'] as $key) {
        expect(fn () => $audit->record('x.test', metadata: [$key => 'v']))->toThrow(InvalidArgumentException::class);
    }
    expect(DB::table('audit_logs')->count())->toBe(0);
});

test('DB constraints: a decider can never be the requester, and decision fields must match status', function () {
    if (! dbEnforcesChecks()) {
        $this->markTestSkipped('CHECK constraints are only created on MySQL/MariaDB (SQLite cannot ALTER ... ADD CONSTRAINT).');
    }

    $request = $this->service->create($this->a, 'TEST_ONLY_ACTION');

    expect(fn () => DB::table('change_requests')->where('id', $request->id)->update(['status' => 'APPROVED', 'decided_by' => $this->a->id, 'decided_at' => now()]))
        ->toThrow(QueryException::class);
    expect(fn () => DB::table('change_requests')->where('id', $request->id)->update(['status' => 'APPROVED']))
        ->toThrow(QueryException::class);
    expect(fn () => DB::table('change_requests')->where('id', $request->id)->update(['decided_by' => $this->b->id]))
        ->toThrow(QueryException::class);
});

test('admins see the notification and the approval controls through the UI; the requester does not get buttons', function () {
    $request = $this->service->create($this->a, 'TEST_ONLY_ACTION');

    $this->actingAs($this->b, 'admin')->get(route('admin.home'))->assertOk()->assertSee('Change request #'.$request->id, false);
    $this->actingAs($this->b, 'admin')->get(route('admin.approvals'))->assertOk()->assertSee('Approve')->assertSee('Reject');

    Auth::forgetGuards();
    $this->actingAs($this->a, 'admin')->get(route('admin.approvals'))->assertOk()->assertDontSee('Approve');
});
