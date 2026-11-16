<?php

use App\Models\AdminUser;
use App\Models\Contest;
use App\Models\ContestRule;
use App\Models\Election;
use App\Models\ElectionConfigVersion;
use App\Models\RepresentationGroup;
use App\Models\Student;
use App\Services\Audit\AuditLogger;
use App\Services\Election\ElectionContestService;
use App\Services\Election\ElectionDraftService;
use App\Services\Election\ElectionScopeService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Volt\Volt;

/*
 * Phase 04C: contests and their basic voting rules. All data is synthetic: every contest name, position label and group
 * name below is an arbitrary test string - the application ships no contest list, seat counts or eligibility rules.
 */

beforeEach(function () {
    $this->admin = makeAdminRoster()->first();
});

function ctService(): ElectionContestService
{
    return app(ElectionContestService::class);
}

function ctDraft(string $name = 'Contest test election'): Election
{
    return app(ElectionDraftService::class)->createDraft(test()->admin, $name, null, 'UNIVERSITY_WIDE', null, (string) Str::uuid())[0];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function ctInput(array $overrides = []): array
{
    return $overrides + [
        'name' => 'Sample contest',
        'position_label' => 'Sample position',
        'representation_group_id' => null,
        'seat_count' => 1,
        'selection_limit' => 1,
        'allow_abstain' => true,
        'voting_method' => 'SINGLE_CHOICE',
    ];
}

/** @param  array<string, mixed>  $overrides */
function ctContest(Election $election, array $overrides = []): Contest
{
    return ctService()->createContest(test()->admin, $election, ctInput($overrides));
}

function ctGroup(Election $election, string $name = 'Group A'): RepresentationGroup
{
    return app(ElectionScopeService::class)->createGroup(test()->admin, $election, $name, ['dimension_a' => ['one']]);
}

function ctPage(Election $election)
{
    test()->actingAs(test()->admin, 'admin');

    return Volt::test('admin.elections.contests', ['election' => $election]);
}

function ctSetState(Election $election, string $state): void
{
    DB::table('elections')->where('id', $election->getKey())->update(['state' => $state]);
}

function ctAudit(string $event): Collection
{
    return DB::table('audit_logs')->where('event_type', $event)->get();
}

/** A second configuration version row of the same election (the current one is left alone). */
function ctOtherVersion(Election $election): int
{
    return DB::table('election_config_versions')->insertGetId([
        'election_id' => $election->getKey(), 'version_number' => 2, 'status' => 'SUPERSEDED',
        'proposed_by' => test()->admin->getKey(), 'created_at' => now(), 'updated_at' => now(),
    ]);
}

function ctFillForm($page, array $values = [])
{
    $values += ['name' => 'Page contest', 'positionLabel' => 'Page position', 'representationGroupId' => '', 'seatCount' => '2', 'selectionLimit' => '2', 'allowAbstain' => true, 'votingMethod' => 'MULTI_CHOICE'];

    foreach ($values as $property => $value) {
        $page->set($property, $value);
    }

    return $page;
}

// ---------------------------------------------------------------------------------------------- authorization

test('only an administrator can open the contests page, and unknown elections are not found', function () {
    $election = ctDraft();
    $url = route('admin.elections.contests', $election->getKey());

    auth('admin')->logout();
    $this->get($url)->assertRedirect(route('login'));

    $this->actingAs(Student::factory()->linked()->create(), 'student')->get($url)->assertForbidden();
    auth('student')->logout();

    $this->actingAs($this->admin, 'admin')->get($url)->assertOk();
    $this->get(route('admin.elections.contests', 987654))->assertNotFound();
    $this->get('/admin/elections/not-a-number/contests')->assertNotFound();
});

test('the Livewire component refuses a guest and a student session', function () {
    $election = ctDraft();

    Volt::test('admin.elections.contests', ['election' => $election])->assertForbidden();

    $this->actingAs(Student::factory()->linked()->create(), 'student');
    Volt::test('admin.elections.contests', ['election' => $election])->assertForbidden();
});

test('every action re-checks the administrator: a session that is no longer an admin cannot write', function () {
    $election = ctDraft();
    $contest = ctContest($election);
    [$saving, $editing, $deleting] = [ctFillForm(ctPage($election)), ctPage($election), ctPage($election)];

    auth('admin')->logout();

    $saving->call('save')->assertForbidden();
    $editing->call('editContest', $contest->getKey())->assertForbidden();
    $deleting->call('deleteContest', $contest->getKey())->assertForbidden();

    expect(Contest::query()->count())->toBe(1)->and($contest->fresh()->name)->toBe('Sample contest');
});

test('the contest policies: reading needs an administrator, changing also needs an editable draft', function () {
    $election = ctDraft();
    $student = Student::factory()->linked()->create();

    expect(Gate::forUser($student)->denies('viewContests', $election))->toBeTrue()
        ->and(Gate::forUser($student)->denies('manageContests', $election))->toBeTrue()
        ->and(Gate::forUser($this->admin)->allows('viewContests', $election))->toBeTrue()
        ->and(Gate::forUser($this->admin)->allows('manageContests', $election))->toBeTrue();

    ctSetState($election, 'READY');
    expect(Gate::forUser($this->admin)->allows('viewContests', $election->fresh()))->toBeTrue()
        ->and(Gate::forUser($this->admin)->denies('manageContests', $election->fresh()))->toBeTrue();
});

test('a broken administrator roster can neither read nor change contests', function () {
    $election = ctDraft();
    config(['comelec.admin_roster_size' => 4]); // the roster no longer matches the configured size, so it is not intact

    expect(Gate::forUser($this->admin)->denies('viewContests', $election))->toBeTrue()
        ->and(Gate::forUser($this->admin)->denies('manageContests', $election))->toBeTrue()
        ->and(fn () => ctContest($election))->toThrow(AuthorizationException::class)
        ->and(Contest::query()->count())->toBe(0);
});

// ---------------------------------------------------------------------------------------------- DRAFT-only editability

test('each way an election stops being editable denies changes but not reading', function (Closure $break) {
    $election = ctDraft();
    $break($election);
    $election = $election->fresh();

    expect(Gate::forUser($this->admin)->denies('manageContests', $election))->toBeTrue()
        ->and(Gate::forUser($this->admin)->allows('viewContests', $election))->toBeTrue()
        ->and($election->allowsContestEditing($election->currentConfigVersion))->toBeFalse()
        ->and(fn () => ctContest($election))->toThrow(AuthorizationException::class, ElectionContestService::MSG_NOT_EDITABLE)
        ->and(Contest::query()->count())->toBe(0)->and(ContestRule::query()->count())->toBe(0)
        ->and(ctAudit('election.contest_created'))->toHaveCount(0);
})->with([
    'READY' => [fn (Election $e) => ctSetState($e, 'READY')],
    'a later lifecycle state' => [fn (Election $e) => ctSetState($e, 'APPROVED')],
    'a locked configuration version is recorded' => [function (Election $e) {
        DB::table('elections')->where('id', $e->getKey())->update(['locked_config_version_id' => $e->current_config_version_id]);
    }],
    'the current version is no longer a draft' => [function (Election $e) {
        DB::table('election_config_versions')->where('id', $e->current_config_version_id)->update(['status' => 'APPROVED']);
    }],
    'the current version holds frozen JSON' => [function (Election $e) {
        DB::table('election_config_versions')->where('id', $e->current_config_version_id)->update(['config_json' => json_encode(['frozen' => true])]);
    }],
    'there is no current version' => [function (Election $e) {
        DB::table('elections')->where('id', $e->getKey())->update(['current_config_version_id' => null]);
    }],
]);

test('existing contests cannot be changed or deleted once the election stops being an editable draft', function () {
    $election = ctDraft();
    $contest = ctContest($election);
    ctSetState($election, 'READY');

    expect(fn () => ctService()->updateContest($this->admin, $election, $contest->getKey(), ctInput(['name' => 'Changed'])))->toThrow(AuthorizationException::class)
        ->and(fn () => ctService()->deleteContest($this->admin, $election, $contest->getKey()))->toThrow(AuthorizationException::class)
        ->and($contest->fresh()->name)->toBe('Sample contest')->and(ContestRule::query()->count())->toBe(1);
});

test('the editability predicate refuses a missing version row, a foreign version and a non-current version', function () {
    $election = ctDraft();
    $version = $election->currentConfigVersion;

    expect($election->allowsContestEditing($version))->toBeTrue()
        ->and($election->allowsContestEditing(null))->toBeFalse();

    $foreign = new ElectionConfigVersion;
    $foreign->forceFill($version->getAttributes());
    $foreign->election_id = (int) $election->getKey() + 1000;
    expect($election->allowsContestEditing($foreign))->toBeFalse();

    $other = new ElectionConfigVersion;
    $other->forceFill($version->getAttributes());
    $other->id = (int) $version->getKey() + 1000;
    expect($election->allowsContestEditing($other))->toBeFalse();
});

test('contests and scope share one editability decision: DRAFT only', function () {
    $election = ctDraft();

    expect(Election::CONTEST_EDITABLE_STATES)->toBe(['DRAFT'])->and(Election::SCOPE_EDITABLE_STATES)->toBe(['DRAFT']);

    foreach (['DRAFT', 'READY', 'APPROVED', 'LOCKED', 'OPEN'] as $state) {
        ctSetState($election, $state);
        $fresh = $election->fresh();

        expect($fresh->allowsContestEditing($fresh->currentConfigVersion))->toBe($fresh->allowsScopeEditing($fresh->currentConfigVersion))
            ->and($fresh->allowsContestEditing($fresh->currentConfigVersion))->toBe($state === 'DRAFT');
    }
});

// ---------------------------------------------------------------------------------------------- create / update / delete

test('create, update and delete are transactional, bound to the current version, and audited with the election id', function () {
    $election = ctDraft();
    $group = ctGroup($election);
    $versionId = $election->current_config_version_id;

    $contest = ctService()->createContest($this->admin, $election, ctInput([
        'name' => '  Secret   Contest ', 'position_label' => 'Secret position', 'representation_group_id' => $group->getKey(),
        'seat_count' => 3, 'selection_limit' => 2, 'allow_abstain' => false, 'voting_method' => 'MULTI_CHOICE',
    ]));

    $row = DB::table('contests')->where('id', $contest->getKey())->first();
    $rule = DB::table('contest_rules')->where('contest_id', $contest->getKey())->first();

    expect($row->election_id)->toBe($election->id)->and($row->election_config_version_id)->toBe($versionId)
        ->and($row->name)->toBe('Secret Contest')->and($row->position_label)->toBe('Secret position')
        ->and($row->representation_group_id)->toBe($group->id)->and($row->scope)->toBeNull()
        ->and($rule->seat_count)->toBe(3)->and($rule->selection_limit)->toBe(2)->and((bool) $rule->allow_abstain)->toBeFalse()->and($rule->voting_method)->toBe('MULTI_CHOICE');

    $updated = ctService()->updateContest($this->admin, $election, $contest->getKey(), ctInput([
        'name' => 'Renamed', 'position_label' => 'Other position', 'seat_count' => 1, 'selection_limit' => 1, 'voting_method' => 'SINGLE_CHOICE',
    ]));
    $rule = DB::table('contest_rules')->where('contest_id', $contest->getKey())->get();

    expect($updated->name)->toBe('Renamed')->and($updated->representation_group_id)->toBeNull()
        ->and($rule)->toHaveCount(1)->and($rule->first()->voting_method)->toBe('SINGLE_CHOICE')->and((bool) $rule->first()->allow_abstain)->toBeTrue()
        ->and($updated->election_config_version_id)->toBe($versionId);

    ctService()->deleteContest($this->admin, $election, $contest->getKey());
    expect(Contest::query()->count())->toBe(0)->and(ContestRule::query()->count())->toBe(0);

    foreach (['created', 'updated', 'deleted'] as $verb) {
        $event = ctAudit("election.contest_{$verb}");
        expect($event)->toHaveCount(1);
        $audit = $event->first();

        expect($audit->election_id)->toBe($election->id)
            ->and($audit->actor_type)->toBe('ADMIN')->and($audit->actor_id)->toBe((string) $this->admin->id)
            ->and($audit->target_type)->toBe('contest')->and($audit->target_id)->toBe((string) $contest->id);

        foreach (['Secret', 'Renamed', 'Other position', 'Group A'] as $secret) {
            expect(json_encode($audit))->not->toContain($secret);
        }
    }

    expect(json_decode(ctAudit('election.contest_created')->first()->metadata_json, true))
        ->toBe(['contest_id' => $contest->id, 'representation_group_id' => $group->id, 'seat_count' => 3])
        ->and(json_decode(ctAudit('election.contest_updated')->first()->metadata_json, true))
        ->toBe(['contest_id' => $contest->id, 'representation_group_id' => null, 'seat_count' => 1])
        ->and(json_decode(ctAudit('election.contest_deleted')->first()->metadata_json, true))
        ->toBe(['contest_id' => $contest->id]);
});

test('both existing voting methods persist, and the contest rule is one row per contest', function () {
    $election = ctDraft();

    foreach (ContestRule::VOTING_METHODS as $method) {
        $contest = ctContest($election, ['name' => "Contest {$method}", 'voting_method' => $method]);
        expect(DB::table('contest_rules')->where('contest_id', $contest->getKey())->value('voting_method'))->toBe($method);
    }

    expect(ContestRule::VOTING_METHODS)->toBe(['SINGLE_CHOICE', 'MULTI_CHOICE'])
        ->and(ContestRule::query()->count())->toBe(2)
        ->and(fn () => DB::table('contest_rules')->insert(['contest_id' => Contest::query()->first()->id, 'seat_count' => 1, 'selection_limit' => 1, 'allow_abstain' => true, 'voting_method' => 'SINGLE_CHOICE', 'created_at' => now(), 'updated_at' => now()]))->toThrow(QueryException::class);
});

test('seats and the selection limit are independent settings: no relation between them is enforced', function () {
    $election = ctDraft();

    foreach ([[1, 5], [5, 1], [3, 3], [1, 1]] as [$seats, $limit]) {
        $contest = ctContest($election, ['name' => "Seats {$seats} limit {$limit}", 'seat_count' => $seats, 'selection_limit' => $limit]);
        $rule = DB::table('contest_rules')->where('contest_id', $contest->getKey())->first();

        expect([$rule->seat_count, $rule->selection_limit])->toBe([$seats, $limit]);
    }
});

test('a failure while auditing rolls the whole change back, including the rule row', function () {
    $election = ctDraft();
    $existing = ctContest($election);
    $failing = new class extends AuditLogger
    {
        public function record(string $eventType, string $severity = self::INFO, ?string $actorType = null, int|string|null $actorId = null, ?string $targetType = null, int|string|null $targetId = null, ?string $description = null, array $metadata = [], ?string $correlationId = null, ?int $electionId = null): void
        {
            throw new RuntimeException('audit failed');
        }
    };
    $service = new ElectionContestService($failing);

    expect(fn () => $service->createContest($this->admin, $election, ctInput(['name' => 'New'])))->toThrow(RuntimeException::class)
        ->and(fn () => $service->updateContest($this->admin, $election, $existing->getKey(), ctInput(['name' => 'Changed', 'seat_count' => 9])))->toThrow(RuntimeException::class)
        ->and(fn () => $service->deleteContest($this->admin, $election, $existing->getKey()))->toThrow(RuntimeException::class);

    expect(Contest::query()->count())->toBe(1)->and(ContestRule::query()->count())->toBe(1)
        ->and($existing->fresh()->name)->toBe('Sample contest')
        ->and(DB::table('contest_rules')->where('contest_id', $existing->getKey())->value('seat_count'))->toBe(1);
});

test('contest edits never create or change a configuration version', function () {
    $election = ctDraft();
    $before = DB::table('election_config_versions')->where('election_id', $election->id)->first();
    $electionBefore = DB::table('elections')->where('id', $election->id)->first();

    $contest = ctContest($election);
    ctService()->updateContest($this->admin, $election, $contest->getKey(), ctInput(['name' => 'Renamed']));
    ctService()->deleteContest($this->admin, $election, $contest->getKey());

    expect(DB::table('election_config_versions')->where('election_id', $election->id)->get())->toHaveCount(1)
        ->and(DB::table('election_config_versions')->where('election_id', $election->id)->first())->toEqual($before)
        ->and(DB::table('elections')->where('id', $election->id)->value('current_config_version_id'))->toBe($electionBefore->current_config_version_id)
        ->and(DB::table('elections')->where('id', $election->id)->value('locked_config_version_id'))->toBeNull()
        ->and(DB::table('elections')->where('id', $election->id)->value('state'))->toBe('DRAFT');
});

// ---------------------------------------------------------------------------------------------- validation

test('invalid input is rejected before anything is written', function (array $overrides) {
    $election = ctDraft();

    expect(fn () => ctService()->createContest($this->admin, $election, ctInput($overrides)))->toThrow(InvalidArgumentException::class);
    expect(Contest::query()->count())->toBe(0)->and(ContestRule::query()->count())->toBe(0)
        ->and(DB::table('audit_logs')->where('event_type', 'like', 'election.contest_%')->count())->toBe(0);
})->with([
    'empty name' => [['name' => '   ']],
    'name too long' => [['name' => str_repeat('n', 256)]],
    'name with a control character' => [['name' => "Bad\x07name"]],
    'name with a format character' => [['name' => "Bad\u{200B}name"]],
    'name with invalid UTF-8' => [['name' => "Bad\xC3\x28name"]],
    'name that is not text' => [['name' => 5]],
    'empty position label' => [['position_label' => '']],
    'position label too long' => [['position_label' => str_repeat('p', 256)]],
    'position label with a control character' => [['position_label' => "Bad\x00label"]],
    'zero seats' => [['seat_count' => 0]],
    'too many seats' => [['seat_count' => 101]],
    'seats as text' => [['seat_count' => '2']],
    'seats as a float' => [['seat_count' => 2.0]],
    'seats missing' => [['seat_count' => null]],
    'negative seats' => [['seat_count' => -1]],
    'zero selections' => [['selection_limit' => 0]],
    'too many selections' => [['selection_limit' => 101]],
    'selections as text' => [['selection_limit' => '1']],
    'abstain as text' => [['allow_abstain' => 'yes']],
    'abstain as a number' => [['allow_abstain' => 1]],
    'abstain missing' => [['allow_abstain' => null]],
    'an unknown voting method' => [['voting_method' => 'RANKED_CHOICE']],
    'a voting method in the wrong case' => [['voting_method' => 'single_choice']],
    'a missing voting method' => [['voting_method' => null]],
    'a voting method that is not text' => [['voting_method' => ['SINGLE_CHOICE']]],
    'a group id as text' => [['representation_group_id' => '1']],
    'a group id of zero' => [['representation_group_id' => 0]],
    'a negative group id' => [['representation_group_id' => -3]],
]);

test('the documented limits are accepted exactly at the boundary', function () {
    $election = ctDraft();

    $min = ctContest($election, ['name' => 'n', 'position_label' => 'p', 'seat_count' => ElectionContestService::MIN_COUNT, 'selection_limit' => ElectionContestService::MIN_COUNT]);
    $max = ctContest($election, [
        'name' => str_repeat('n', ElectionContestService::MAX_NAME_LENGTH), 'position_label' => str_repeat('p', ElectionContestService::MAX_POSITION_LABEL_LENGTH),
        'seat_count' => ElectionContestService::MAX_COUNT, 'selection_limit' => ElectionContestService::MAX_COUNT,
    ]);

    expect(mb_strlen($max->name))->toBe(255)->and(mb_strlen($max->position_label))->toBe(255)
        ->and(DB::table('contest_rules')->where('contest_id', $max->getKey())->value('seat_count'))->toBe(100)
        ->and($min->name)->toBe('n');
});

test('non-breaking and other Unicode spaces are trimmed and inner whitespace is collapsed', function () {
    $election = ctDraft();
    $contest = ctContest($election, ['name' => "\u{00A0}\u{2003}Sample \u{00A0}  name\u{3000}", 'position_label' => '  A   B  ']);

    expect($contest->name)->toBe('Sample name')->and($contest->position_label)->toBe('A B');
});

// ---------------------------------------------------------------------------------------------- representation groups

test('a contest can use a representation group of its own election, or none', function () {
    $election = ctDraft();
    $group = ctGroup($election);

    $with = ctContest($election, ['name' => 'With group', 'representation_group_id' => $group->getKey()]);
    $without = ctContest($election, ['name' => 'Without group']);

    expect($with->representation_group_id)->toBe($group->id)->and($without->representation_group_id)->toBeNull();

    $moved = ctService()->updateContest($this->admin, $election, $without->getKey(), ctInput(['name' => 'Without group', 'representation_group_id' => $group->getKey()]));
    expect($moved->representation_group_id)->toBe($group->id);
});

test('a representation group of another election, or one that does not exist, is refused', function () {
    $mine = ctDraft('Mine');
    $theirs = ctDraft('Theirs');
    $foreign = ctGroup($theirs, 'Foreign group');
    $contest = ctContest($mine);

    foreach ([$foreign->getKey(), 987654] as $groupId) {
        expect(fn () => ctService()->createContest($this->admin, $mine, ctInput(['representation_group_id' => $groupId])))
            ->toThrow(InvalidArgumentException::class, ElectionContestService::MSG_GROUP_NOT_IN_ELECTION)
            ->and(fn () => ctService()->updateContest($this->admin, $mine, $contest->getKey(), ctInput(['representation_group_id' => $groupId])))
            ->toThrow(InvalidArgumentException::class, ElectionContestService::MSG_GROUP_NOT_IN_ELECTION);
    }

    expect(Contest::query()->count())->toBe(1)->and($contest->fresh()->representation_group_id)->toBeNull()
        ->and(ctAudit('election.contest_created'))->toHaveCount(1);
});

test('the database itself refuses a contest that points at another election\'s group', function () {
    $mine = ctDraft('Mine');
    $theirs = ctDraft('Theirs');
    $foreign = ctGroup($theirs);

    expect(fn () => DB::table('contests')->insert([
        'election_id' => $mine->id, 'election_config_version_id' => $mine->current_config_version_id, 'name' => 'x', 'position_label' => 'y',
        'representation_group_id' => $foreign->id, 'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

test('a group used by a contest cannot be deleted, and the scope page counts it', function () {
    $election = ctDraft();
    $group = ctGroup($election);
    ctContest($election, ['representation_group_id' => $group->getKey()]);

    expect(fn () => app(ElectionScopeService::class)->deleteGroup($this->admin, $election, $group->getKey()))
        ->toThrow(InvalidArgumentException::class, ElectionScopeService::MSG_IN_USE);
});

// ---------------------------------------------------------------------------------------------- ownership

test('a contest cannot be read, updated or deleted through a different election', function () {
    $first = ctDraft('First');
    $second = ctDraft('Second');
    $contest = ctContest($first);

    expect(fn () => ctService()->updateContest($this->admin, $second, $contest->getKey(), ctInput(['name' => 'Hijack'])))->toThrow(ModelNotFoundException::class)
        ->and(fn () => ctService()->deleteContest($this->admin, $second, $contest->getKey()))->toThrow(ModelNotFoundException::class)
        ->and(fn () => ctService()->updateContest($this->admin, $first, 987654, ctInput()))->toThrow(ModelNotFoundException::class)
        ->and(fn () => ctService()->createContest($this->admin, (new Election)->forceFill(['id' => 987654]), ctInput()))->toThrow(ModelNotFoundException::class);

    expect($contest->fresh()->name)->toBe('Sample contest')->and($contest->fresh()->election_id)->toBe($first->id);
});

test('a contest bound to another configuration version of the election is not editable here', function () {
    $election = ctDraft();
    $contest = ctContest($election);
    $otherVersion = ctOtherVersion($election);
    DB::table('contests')->where('id', $contest->getKey())->update(['election_config_version_id' => $otherVersion]);

    expect(fn () => ctService()->updateContest($this->admin, $election, $contest->getKey(), ctInput(['name' => 'Changed'])))->toThrow(ModelNotFoundException::class)
        ->and(fn () => ctService()->deleteContest($this->admin, $election, $contest->getKey()))->toThrow(ModelNotFoundException::class);

    $page = ctPage($election);
    expect($page->html())->not->toContain('Sample contest');
    $page->call('editContest', $contest->getKey());
    expect($page->get('problem'))->toContain('no longer exists')->and($contest->fresh()->name)->toBe('Sample contest');
});

test('the database refuses a contest bound to a configuration version of another election', function () {
    $mine = ctDraft('Mine');
    $theirs = ctDraft('Theirs');

    expect(fn () => DB::table('contests')->insert([
        'election_id' => $mine->id, 'election_config_version_id' => $theirs->current_config_version_id, 'name' => 'x', 'position_label' => 'y',
        'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

// ---------------------------------------------------------------------------------------------- deletion and dependents

test('a contest with dependent records cannot be deleted, and nothing is lost', function () {
    $election = ctDraft();
    $contest = ctContest($election);
    DB::table('contest_eligibility_rules')->insert(['contest_id' => $contest->getKey(), 'rule_definition_json' => json_encode(['synthetic' => true]), 'created_at' => now(), 'updated_at' => now()]);

    expect(ctService()->contestIdsWithDependents([$contest->getKey()]))->toBe([$contest->id])
        ->and(fn () => ctService()->deleteContest($this->admin, $election, $contest->getKey()))->toThrow(InvalidArgumentException::class, ElectionContestService::MSG_IN_USE);

    expect(Contest::query()->count())->toBe(1)->and(ContestRule::query()->count())->toBe(1)
        ->and(ctAudit('election.contest_deleted'))->toHaveCount(0);
});

test('if a dependent row appears after the application check, the database still refuses and the rule row is restored', function () {
    $election = ctDraft();
    $contest = ctContest($election);
    DB::table('contest_eligibility_rules')->insert(['contest_id' => $contest->getKey(), 'rule_definition_json' => json_encode(['synthetic' => true]), 'created_at' => now(), 'updated_at' => now()]);

    $racing = new class(app(AuditLogger::class)) extends ElectionContestService
    {
        protected function hasDependentRecords(int $contestId): bool
        {
            return false; // the check "passed" just before the dependent row was committed
        }
    };

    expect(fn () => $racing->deleteContest($this->admin, $election, $contest->getKey()))->toThrow(InvalidArgumentException::class, ElectionContestService::MSG_IN_USE);

    expect(Contest::query()->count())->toBe(1)->and(ContestRule::query()->count())->toBe(1)
        ->and(ctAudit('election.contest_deleted'))->toHaveCount(0);
});

test('the database refuses to delete a contest that has a rule or dependents', function () {
    $election = ctDraft();
    $contest = ctContest($election);

    expect(fn () => DB::table('contests')->where('id', $contest->getKey())->delete())->toThrow(QueryException::class); // contest_rules.contest_id is RESTRICT
});

// ---------------------------------------------------------------------------------------------- the page

test('the page renders its empty state and a blank form with nothing preselected except the database default', function () {
    $election = ctDraft();

    $html = ctPage($election)->assertOk()->assertSee('No contests yet')->html();

    expect($html)->toContain('theme-2k26')->toContain('wire:submit="save"')->toContain('Create contest')
        ->toContain('Who may vote or stand is decided in a later step')->not->toContain('read-only');

    $component = ctPage($election);
    expect($component->get('name'))->toBe('')->and($component->get('seatCount'))->toBe('')->and($component->get('selectionLimit'))->toBe('')
        ->and($component->get('votingMethod'))->toBe('')->and($component->get('representationGroupId'))->toBe('')->and($component->get('allowAbstain'))->toBeTrue();
});

test('the page creates, edits and deletes a contest and reports it', function () {
    $election = ctDraft();
    $group = ctGroup($election, 'Page group');

    $page = ctFillForm(ctPage($election), ['name' => '  Page contest ', 'representationGroupId' => (string) $group->id, 'allowAbstain' => false])
        ->call('save')
        ->assertHasNoErrors()
        ->assertSee('Contest created.')->assertSee('Page contest')->assertSee('Page group')->assertSee('Multiple choice')->assertSee('Not allowed');

    $contest = Contest::query()->sole();
    $rule = ContestRule::query()->sole();
    expect($contest->name)->toBe('Page contest')->and($contest->representation_group_id)->toBe($group->id)
        ->and($rule->seat_count)->toBe(2)->and($rule->selection_limit)->toBe(2)->and($rule->allow_abstain)->toBeFalse()->and($rule->voting_method)->toBe('MULTI_CHOICE')
        ->and($page->get('name'))->toBe('')->and($page->get('editingContestId'))->toBeNull()
        ->and(ctAudit('election.contest_created')->first()->election_id)->toBe($election->id);

    $page->call('editContest', $contest->getKey())
        ->assertSet('name', 'Page contest')->assertSet('editingContestId', $contest->getKey())->assertSet('seatCount', '2')->assertSet('votingMethod', 'MULTI_CHOICE')
        ->assertSet('representationGroupId', (string) $group->id)->assertSet('allowAbstain', false)
        ->set('name', 'Edited contest')->set('votingMethod', 'SINGLE_CHOICE')->set('seatCount', '1')->set('selectionLimit', '1')->set('allowAbstain', true)->set('representationGroupId', '')
        ->call('save')
        ->assertHasNoErrors()->assertSee('Contest updated.')->assertSee('Edited contest');

    expect($contest->fresh()->name)->toBe('Edited contest')->and($contest->fresh()->representation_group_id)->toBeNull()
        ->and(ContestRule::query()->sole()->voting_method)->toBe('SINGLE_CHOICE')
        ->and(ctAudit('election.contest_updated'))->toHaveCount(1);

    $page->call('deleteContest', $contest->getKey())->assertSee('Contest deleted.')->assertSee('No contests yet');
    expect(Contest::query()->count())->toBe(0)->and(ContestRule::query()->count())->toBe(0)->and(ctAudit('election.contest_deleted'))->toHaveCount(1);
});

test('the form explains validation problems in plain words and writes nothing', function () {
    $election = ctDraft();
    $page = ctPage($election);

    $page->call('save')->assertHasErrors(['name' => 'required', 'positionLabel' => 'required', 'seatCount' => 'required', 'selectionLimit' => 'required', 'votingMethod' => 'required'])
        ->assertSee('Enter a contest name.')->assertSee('Choose a voting method.');

    ctFillForm($page, ['name' => str_repeat('n', 256)])->call('save')->assertHasErrors(['name' => 'max']);
    ctFillForm($page, ['seatCount' => '0'])->call('save')->assertHasErrors(['seatCount' => 'min'])->assertSee('Seats must be at least 1.');
    ctFillForm($page, ['seatCount' => '101'])->call('save')->assertHasErrors(['seatCount' => 'max']);
    ctFillForm($page, ['seatCount' => 'two'])->call('save')->assertHasErrors(['seatCount' => 'integer']);
    ctFillForm($page, ['seatCount' => '1.5'])->call('save')->assertHasErrors(['seatCount' => 'integer']);
    ctFillForm($page, ['seatCount' => '2', 'selectionLimit' => '0'])->call('save')->assertHasErrors(['selectionLimit' => 'min']);
    ctFillForm($page, ['selectionLimit' => '101'])->call('save')->assertHasErrors(['selectionLimit' => 'max']);
    ctFillForm($page, ['selectionLimit' => '2', 'votingMethod' => 'RANKED'])->call('save')->assertHasErrors(['votingMethod' => 'in']);
    ctFillForm($page, ['votingMethod' => 'MULTI_CHOICE', 'representationGroupId' => 'abc'])->call('save')->assertHasErrors(['representationGroupId' => 'integer']);

    expect(Contest::query()->count())->toBe(0);
});

test('service rules come back as messages: a foreign group, and a contest with dependents', function () {
    $mine = ctDraft('Mine');
    $theirs = ctDraft('Theirs');
    $foreign = ctGroup($theirs, 'Foreign group');

    $page = ctFillForm(ctPage($mine), ['representationGroupId' => (string) $foreign->id])->call('save');
    expect($page->get('problem'))->toBe(ElectionContestService::MSG_GROUP_NOT_IN_ELECTION)->and(Contest::query()->count())->toBe(0);
    $page->assertSee(ElectionContestService::MSG_GROUP_NOT_IN_ELECTION)->assertDontSee('Foreign group');

    $contest = ctContest($mine);
    DB::table('contest_eligibility_rules')->insert(['contest_id' => $contest->getKey(), 'rule_definition_json' => json_encode(['synthetic' => true]), 'created_at' => now(), 'updated_at' => now()]);

    $page->call('deleteContest', $contest->getKey()); // the button is hidden for this contest, but the action can still be called
    expect($page->get('problem'))->toBe(ElectionContestService::MSG_IN_USE)->and(Contest::query()->count())->toBe(1);

    $html = ctPage($mine)->html();
    expect($html)->toContain('wire:click="editContest('.$contest->id.')"')->not->toContain('wire:click="deleteContest('.$contest->id.')"');
});

test('the options offered for a representation group are only this election\'s groups', function () {
    $mine = ctDraft('Mine');
    $theirs = ctDraft('Theirs');
    ctGroup($mine, 'Visible group');
    ctGroup($theirs, 'Hidden foreign group');

    $html = ctPage($mine)->html();
    expect($html)->toContain('Visible group')->not->toContain('Hidden foreign group');
});

test('the same form submitted twice creates one contest', function () {
    $election = ctDraft();

    $page = ctFillForm(ctPage($election))->call('save');
    $page->call('save'); // a queued second click: the form was already cleared by the first

    expect(Contest::query()->count())->toBe(1)->and($page->get('problem'))->toBeNull();
    $page->assertHasErrors(['name' => 'required']);
});

test('ids and messages cannot be changed from the browser', function () {
    $election = ctDraft();
    $contest = ctContest($election);
    $page = ctPage($election);

    foreach ([['electionId', 99], ['editingContestId', $contest->getKey()], ['status', 'hacked'], ['problem', 'hacked']] as [$property, $value]) {
        expect(fn () => $page->set($property, $value))->toThrow(Exception::class);
    }

    expect($page->get('electionId'))->toBe($election->id)->and($page->get('editingContestId'))->toBeNull();
});

test('a forged contest id from another election changes and reveals nothing', function () {
    $mine = ctDraft('Mine');
    $theirs = ctDraft('Theirs');
    $foreign = ctContest($theirs, ['name' => 'Foreign contest', 'position_label' => 'Foreign position']);

    $page = ctPage($mine)->call('editContest', $foreign->getKey());
    expect($page->get('problem'))->toContain('no longer exists')->and($page->get('name'))->toBe('')->and($page->get('editingContestId'))->toBeNull()
        ->and($page->html())->not->toContain('Foreign contest')->not->toContain('Foreign position');

    $page->call('deleteContest', $foreign->getKey());
    expect($page->get('problem'))->toContain('no longer exists')->and($foreign->fresh()->name)->toBe('Foreign contest');
});

test('a lock timeout or deadlock asks the user to try again; any other database error is not hidden', function () {
    $election = ctDraft();
    $failing = fn (int $code) => new class(app(AuditLogger::class), $code) extends ElectionContestService
    {
        public function __construct(AuditLogger $audit, private readonly int $code)
        {
            parent::__construct($audit);
        }

        public function createContest(AdminUser $admin, Election $election, array $input): Contest
        {
            $pdo = new PDOException('simulated');
            $pdo->errorInfo = ['HY000', $this->code, 'simulated'];

            throw new QueryException('mariadb', 'insert into contests', [], $pdo);
        }
    };

    foreach ([1205, 1213] as $code) {
        app()->bind(ElectionContestService::class, fn () => $failing($code));
        $page = ctFillForm(ctPage($election))->call('save');

        expect($page->get('problem'))->toContain('someone else')->toContain('try again');
    }

    app()->bind(ElectionContestService::class, fn () => $failing(1451));
    $page = ctFillForm(ctPage($election));
    expect(fn () => $page->call('save'))->toThrow(QueryException::class);
});

test('deleting twice (a double click) is a message, not an error page', function () {
    $election = ctDraft();
    $contest = ctContest($election);

    $page = ctPage($election)->call('deleteContest', $contest->getKey())->assertSee('Contest deleted.');
    $page->call('deleteContest', $contest->getKey());

    expect($page->get('problem'))->toContain('no longer exists')->and(Contest::query()->count())->toBe(0);
});

test('if the election leaves the draft state while the page is open, writes are refused with a message and the page turns read-only', function () {
    $election = ctDraft();
    $contest = ctContest($election);
    $page = ctPage($election)->call('editContest', $contest->getKey());

    ctSetState($election, 'READY'); // another administrator, or a later phase

    $page->set('name', 'Too late')->call('save');
    expect($page->get('problem'))->toBe(ElectionContestService::MSG_NOT_EDITABLE)->and($page->get('editingContestId'))->toBeNull();

    $page->call('deleteContest', $contest->getKey());
    expect($page->get('problem'))->toBe(ElectionContestService::MSG_NOT_EDITABLE);
    $page->call('editContest', $contest->getKey());
    expect($page->get('problem'))->toBe(ElectionContestService::MSG_NOT_EDITABLE);

    $page->assertSee('shown read-only')->assertSee('Sample contest')->assertDontSee('Create contest')->assertDontSee('Delete');
    expect($contest->fresh()->name)->toBe('Sample contest')->and(Contest::query()->count())->toBe(1);
});

test('a non-draft election shows its contests read-only, with no form and no actions', function () {
    $election = ctDraft();
    ctContest($election, ['name' => 'Saved contest', 'position_label' => 'Saved position', 'seat_count' => 4, 'selection_limit' => 2, 'voting_method' => 'MULTI_CHOICE']);
    ctSetState($election, 'LOCKED');

    $this->actingAs($this->admin, 'admin');
    $html = $this->get(route('admin.elections.contests', $election->id))->assertOk()->getContent();

    expect($html)->toContain('Saved contest')->toContain('Saved position')->toContain('Multiple choice')->toContain('shown read-only')
        ->not->toContain('wire:submit')->not->toContain('Create contest')
        ->not->toMatch('/wire:click="(editContest|deleteContest)/');

    $empty = ctDraft('Empty');
    ctSetState($empty, 'LOCKED');
    $this->get(route('admin.elections.contests', $empty->id))->assertOk()->assertSee('No contests were configured while this election was a draft.');
});

test('the page lists only the contests of this election and its current version', function () {
    $mine = ctDraft('Mine');
    $theirs = ctDraft('Theirs');
    ctContest($mine, ['name' => 'My contest']);
    ctContest($theirs, ['name' => 'Their contest']);

    $other = ctContest($mine, ['name' => 'Old version contest']);
    DB::table('contests')->where('id', $other->getKey())->update(['election_config_version_id' => ctOtherVersion($mine)]);

    $html = ctPage($mine)->html();
    expect($html)->toContain('My contest')->not->toContain('Their contest')->not->toContain('Old version contest');
});

test('the page exposes no personal data and uses valid icons', function () {
    $election = ctDraft();
    ctContest($election);

    $html = ctPage($election)->html();
    expect($html)->not->toContain($this->admin->authorized_email)->not->toContain('google_subject')->not->toContain('creation_key');

    $icons = [];
    preg_match_all('/icon="([a-z0-9-]+)"/', file_get_contents(resource_path('views/livewire/admin/elections/contests.blade.php')), $icons);
    foreach (array_unique($icons[1]) as $icon) {
        expect(file_exists(base_path("vendor/livewire/flux/stubs/resources/views/flux/icon/{$icon}.blade.php")) || file_exists(resource_path("views/flux/icon/{$icon}.blade.php")))->toBeTrue("icon [{$icon}] must exist");
    }
});

// ---------------------------------------------------------------------------------------------- MariaDB row locks

test('on MySQL/MariaDB every contest write locks the election, its version and the contest row in that order', function () {
    $election = ctDraft();
    $contest = ctContest($election);

    DB::enableQueryLog();
    ctService()->updateContest($this->admin, $election, $contest->getKey(), ctInput(['name' => 'Renamed']));
    $locking = collect(DB::getQueryLog())->pluck('query')->filter(fn ($sql) => str_contains(strtolower($sql), 'for update'))->values();
    DB::disableQueryLog();

    expect($locking)->toHaveCount(4)
        ->and($locking[0])->toContain('`elections`')
        ->and($locking[1])->toContain('`election_config_versions`')
        ->and($locking[2])->toContain('`contests`')
        ->and($locking[3])->toContain('`contest_rules`');

    DB::enableQueryLog();
    ctService()->createContest($this->admin, $election, ctInput(['name' => 'Second']));
    $locking = collect(DB::getQueryLog())->pluck('query')->filter(fn ($sql) => str_contains(strtolower($sql), 'for update'))->values();
    DB::disableQueryLog();

    expect($locking[0])->toContain('`elections`')->and($locking[1])->toContain('`election_config_versions`');
})->skip(fn () => ! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true), 'Row locks (FOR UPDATE) are only emitted by MySQL/MariaDB; SQLite ignores them.');

// ---------------------------------------------------------------------------------------------- the workspace

test('the election workspace links to the contests page and shows the contest count', function () {
    $election = ctDraft();
    $this->actingAs($this->admin, 'admin');

    $html = Volt::test('admin.elections.show', ['election' => $election])->html();
    expect($html)->toContain('Configure contests')->toContain('href="'.route('admin.elections.contests', $election->id).'"')->toContain('No contests yet');

    ctContest($election, ['name' => 'One']);
    ctContest($election, ['name' => 'Two']);
    expect(Volt::test('admin.elections.show', ['election' => $election])->html())->toContain('2 contests');

    ctSetState($election, 'READY');
    $html = Volt::test('admin.elections.show', ['election' => $election])->html();
    expect($html)->toContain('View contests')->not->toContain('Configure contests')->toContain('href="'.route('admin.elections.contests', $election->id).'"');
});

test('the workspace count ignores contests of other elections and other versions', function () {
    $mine = ctDraft('Mine');
    $theirs = ctDraft('Theirs');
    ctContest($mine, ['name' => 'Mine one']);
    ctContest($theirs, ['name' => 'Theirs one']);
    $old = ctContest($mine, ['name' => 'Old']);
    DB::table('contests')->where('id', $old->getKey())->update(['election_config_version_id' => ctOtherVersion($mine)]);

    $this->actingAs($this->admin, 'admin');
    expect(Volt::test('admin.elections.show', ['election' => $mine])->html())->toContain('1 contest')->not->toContain('2 contests');
});

test('the workspace button follows the policy: no editing links once a version is frozen', function () {
    $election = ctDraft();
    DB::table('election_config_versions')->where('id', $election->current_config_version_id)->update(['status' => 'SNAPSHOTTED', 'config_json' => json_encode(['frozen' => true])]);

    $this->actingAs($this->admin, 'admin');
    expect(Volt::test('admin.elections.show', ['election' => $election])->html())->toContain('View contests')->not->toContain('Configure contests');
});

// ---------------------------------------------------------------------------------------------- scope of the change

test('the contest code writes only through the service and ships no institutional values or eligibility behavior', function () {
    $page = file_get_contents(resource_path('views/livewire/admin/elections/contests.blade.php'));

    expect($page)->toContain('ElectionContestService')
        ->not->toMatch('/->(update|insert|insertGetId|delete|upsert|save|forceDelete|truncate|forceFill)\(/')
        ->not->toMatch('/DB::(insert|update|delete|statement)/');

    foreach ([
        app_path('Services/Election/ElectionContestService.php'),
        app_path('Models/Contest.php'),
        app_path('Models/ContestRule.php'),
        resource_path('views/livewire/admin/elections/contests.blade.php'),
    ] as $file) {
        $source = file_get_contents($file);

        expect($source)->not->toMatch('/\b(college|course|program|department|sector|year[_ -]?level|1st year)\b/i', basename($file))
            ->not->toContain('jurisdiction');
    }

    // The service never writes the `scope` column and never touches the eligibility tables.
    expect(file_get_contents(app_path('Services/Election/ElectionContestService.php')))->not->toMatch("/'scope'\s*=>/");
});
