<?php

use App\Models\AdminUser;
use App\Models\Election;
use App\Models\ElectionConfigVersion;
use App\Models\RepresentationGroup;
use App\Models\Student;
use App\Services\Audit\AuditLogger;
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
 * Phase 04B: election scope = representation groups. All data is synthetic and every dimension key / value below is
 * an arbitrary test string - the application ships none. Groups organize contests; they are not eligibility rules.
 */

beforeEach(function () {
    $this->admin = makeAdminRoster()->first();
});

function scopeService(): ElectionScopeService
{
    return app(ElectionScopeService::class);
}

function scopeDraft(string $name = 'Scope test election'): Election
{
    return app(ElectionDraftService::class)->createDraft(test()->admin, $name, null, 'UNIVERSITY_WIDE', null, (string) Str::uuid())[0];
}

/** @param array<string, list<string>> $definition */
function scopeGroup(Election $election, string $name = 'Group A', array $definition = ['dimension_a' => ['one', 'two']]): RepresentationGroup
{
    return scopeService()->createGroup(test()->admin, $election, $name, $definition);
}

function scopePage(Election $election)
{
    test()->actingAs(test()->admin, 'admin');

    return Volt::test('admin.elections.scope', ['election' => $election]);
}

function scopeSetState(Election $election, string $state): void
{
    DB::table('elections')->where('id', $election->getKey())->update(['state' => $state]);
}

function scopeContest(Election $election, RepresentationGroup $group): int
{
    return DB::table('contests')->insertGetId([
        'election_id' => $election->getKey(),
        'election_config_version_id' => $election->fresh()->current_config_version_id,
        'name' => 'A contest', 'position_label' => 'A position', 'scope' => null,
        'representation_group_id' => $group->getKey(),
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

function scopeAudit(string $event): Collection
{
    return DB::table('audit_logs')->where('event_type', $event)->get();
}

// ---------------------------------------------------------------------------------------------- authorization

test('only an administrator can open the scope page', function () {
    $election = scopeDraft();
    $url = route('admin.elections.scope', $election->getKey());

    auth('admin')->logout();
    $this->get($url)->assertRedirect(route('login'));

    $this->actingAs(Student::factory()->linked()->create(), 'student')->get($url)->assertForbidden();
    auth('student')->logout();

    $this->actingAs($this->admin, 'admin')->get($url)->assertOk();
    $this->get(route('admin.elections.scope', 987654))->assertNotFound();
    $this->get('/admin/elections/not-a-number/scope')->assertNotFound();
});

test('the Livewire component refuses a guest and a student session', function () {
    $election = scopeDraft();

    Volt::test('admin.elections.scope', ['election' => $election])->assertForbidden();

    $this->actingAs(Student::factory()->linked()->create(), 'student');
    Volt::test('admin.elections.scope', ['election' => $election])->assertForbidden();
});

test('every action re-checks the administrator: a session that is no longer an admin cannot write', function () {
    $election = scopeDraft();
    $group = scopeGroup($election);
    $form = fn () => scopePage($election)->set('name', 'Another')->set('dimensions', [['key' => 'dimension_b', 'values' => 'x']]);
    [$saving, $editing, $deleting] = [$form(), $form(), $form()];

    auth('admin')->logout();

    $saving->call('save')->assertForbidden();
    $editing->call('editGroup', $group->getKey())->assertForbidden();
    $deleting->call('deleteGroup', $group->getKey())->assertForbidden();

    expect(RepresentationGroup::query()->count())->toBe(1)->and($group->fresh()->name)->toBe('Group A');
});

test('the scope policies: reading needs an administrator, changing also needs an editable draft', function () {
    $election = scopeDraft();
    $student = Student::factory()->linked()->create();

    expect(Gate::forUser($student)->denies('viewScope', $election))->toBeTrue()
        ->and(Gate::forUser($student)->denies('manageScope', $election))->toBeTrue()
        ->and(Gate::forUser($this->admin)->allows('viewScope', $election))->toBeTrue()
        ->and(Gate::forUser($this->admin)->allows('manageScope', $election))->toBeTrue();

    scopeSetState($election, 'READY');
    expect(Gate::forUser($this->admin)->allows('viewScope', $election->fresh()))->toBeTrue()
        ->and(Gate::forUser($this->admin)->denies('manageScope', $election->fresh()))->toBeTrue();
});

test('each way an election stops being editable denies changes but not reading', function (Closure $break) {
    $election = scopeDraft();
    $break($election);
    $election = $election->fresh();

    expect(Gate::forUser($this->admin)->denies('manageScope', $election))->toBeTrue()
        ->and(Gate::forUser($this->admin)->allows('viewScope', $election))->toBeTrue()
        ->and($election->allowsScopeEditing($election->currentConfigVersion))->toBeFalse();
})->with([
    'a later lifecycle state' => [fn (Election $e) => scopeSetState($e, 'APPROVED')],
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

test('the editable-state decision: DRAFT only, READY is read-only until a later phase decides otherwise', function () {
    expect(Election::SCOPE_EDITABLE_STATES)->toBe(['DRAFT']);

    $election = scopeDraft();
    $group = scopeGroup($election);
    scopeSetState($election, 'READY');

    expect(fn () => scopeService()->createGroup($this->admin, $election->fresh(), 'Blocked', ['dimension_a' => ['x']]))->toThrow(AuthorizationException::class)
        ->and(fn () => scopeService()->updateGroup($this->admin, $election->fresh(), $group->getKey(), 'Blocked', ['dimension_a' => ['x']]))->toThrow(AuthorizationException::class)
        ->and(fn () => scopeService()->deleteGroup($this->admin, $election->fresh(), $group->getKey()))->toThrow(AuthorizationException::class);

    expect(RepresentationGroup::query()->count())->toBe(1)->and($group->fresh()->name)->toBe('Group A');
});

test('the editability predicate refuses a missing version row and a version of another election', function () {
    $election = scopeDraft();
    $version = $election->currentConfigVersion;

    expect($election->allowsScopeEditing($version))->toBeTrue()
        ->and($election->allowsScopeEditing(null))->toBeFalse(); // current_config_version_id set, but no such row

    $foreign = new ElectionConfigVersion;
    $foreign->forceFill($version->getAttributes());
    $foreign->election_id = (int) $election->getKey() + 1000; // a version that belongs to another election
    expect($election->allowsScopeEditing($foreign))->toBeFalse();

    $other = new ElectionConfigVersion;
    $other->forceFill($version->getAttributes());
    $other->id = (int) $version->getKey() + 1000; // the right election, but not the CURRENT version
    expect($election->allowsScopeEditing($other))->toBeFalse();
});

test('a broken administrator roster cannot change scope', function () {
    $election = scopeDraft();
    config(['comelec.admin_roster_size' => 4]); // the roster no longer matches the configured size, so it is not intact

    expect(fn () => scopeService()->createGroup($this->admin, $election, 'Blocked', ['dimension_a' => ['x']]))->toThrow(AuthorizationException::class)
        ->and(RepresentationGroup::query()->count())->toBe(0);
});

// ---------------------------------------------------------------------------------------------- the service

test('create, update and delete are transactional, audited with the election id, and leak no scope values', function () {
    $election = scopeDraft();

    $group = scopeService()->createGroup($this->admin, $election, '  First   group ', [
        'Dimension_B' => [' beta one ', 'beta two', 'beta one'],
        'dimension_a' => ['7', 'alpha'], // numeric text is still text
    ]);

    expect($group->election_id)->toBe($election->id)
        ->and($group->name)->toBe('First group')
        ->and($group->scope_definition_json)->toBe(['dimension_a' => ['7', 'alpha'], 'dimension_b' => ['beta one', 'beta two']]);

    $updated = scopeService()->updateGroup($this->admin, $election, $group->getKey(), 'Renamed', ['axis_c' => ['gamma']]);
    expect($updated->name)->toBe('Renamed')->and($updated->scope_definition_json)->toBe(['axis_c' => ['gamma']]);

    scopeService()->deleteGroup($this->admin, $election, $group->getKey());
    expect(RepresentationGroup::query()->count())->toBe(0);

    foreach (['created', 'updated', 'deleted'] as $verb) {
        $rows = scopeAudit("election.scope_group_{$verb}");
        expect($rows)->toHaveCount(1);
        $row = $rows->first();

        expect($row->election_id)->toBe($election->id)                       // the dedicated column, not just metadata
            ->and($row->actor_type)->toBe('ADMIN')->and($row->actor_id)->toBe((string) $this->admin->id)
            ->and($row->target_type)->toBe('representation_group')->and($row->target_id)->toBe((string) $group->id);

        $everything = json_encode($row);
        foreach (['alpha', 'beta', 'gamma', 'dimension_a', 'dimension_b', 'axis_c', 'First group', 'Renamed'] as $secret) {
            expect($everything)->not->toContain($secret);
        }
    }

    expect(json_decode(scopeAudit('election.scope_group_created')->first()->metadata_json, true))
        ->toBe(['representation_group_id' => $group->id, 'dimension_count' => 2, 'value_count' => 4])
        ->and(json_decode(scopeAudit('election.scope_group_updated')->first()->metadata_json, true))
        ->toBe(['representation_group_id' => $group->id, 'dimension_count' => 1, 'value_count' => 1])
        ->and(json_decode(scopeAudit('election.scope_group_deleted')->first()->metadata_json, true))
        ->toBe(['representation_group_id' => $group->id]);
});

test('a failure while auditing rolls the whole change back', function () {
    $election = scopeDraft();
    $existing = scopeGroup($election);
    $failing = new class extends AuditLogger
    {
        public function record(string $eventType, string $severity = self::INFO, ?string $actorType = null, int|string|null $actorId = null, ?string $targetType = null, int|string|null $targetId = null, ?string $description = null, array $metadata = [], ?string $correlationId = null, ?int $electionId = null): void
        {
            throw new RuntimeException('audit failed');
        }
    };
    $service = new ElectionScopeService($failing);

    expect(fn () => $service->createGroup($this->admin, $election, 'New', ['dimension_a' => ['x']]))->toThrow(RuntimeException::class)
        ->and(fn () => $service->updateGroup($this->admin, $election, $existing->getKey(), 'Changed', ['dimension_a' => ['y']]))->toThrow(RuntimeException::class)
        ->and(fn () => $service->deleteGroup($this->admin, $election, $existing->getKey()))->toThrow(RuntimeException::class);

    expect(RepresentationGroup::query()->count())->toBe(1)
        ->and($existing->fresh()->name)->toBe('Group A')
        ->and($existing->fresh()->scope_definition_json)->toBe(['dimension_a' => ['one', 'two']]);
});

test('scope edits never create or change a configuration version', function () {
    $election = scopeDraft();
    $versionBefore = DB::table('election_config_versions')->where('election_id', $election->id)->first();

    $group = scopeGroup($election);
    scopeService()->updateGroup($this->admin, $election, $group->getKey(), 'Renamed', ['dimension_a' => ['x']]);
    scopeService()->deleteGroup($this->admin, $election, $group->getKey());

    expect(DB::table('election_config_versions')->where('election_id', $election->id)->get())->toHaveCount(1)
        ->and(DB::table('election_config_versions')->where('election_id', $election->id)->first())->toEqual($versionBefore)
        ->and($election->fresh()->current_config_version_id)->toBe($versionBefore->id);
});

test('invalid input is rejected before anything is written', function (string $name, array $definition) {
    $election = scopeDraft();

    expect(fn () => scopeService()->createGroup($this->admin, $election, $name, $definition))->toThrow(InvalidArgumentException::class);
    expect(RepresentationGroup::query()->count())->toBe(0)->and(DB::table('audit_logs')->where('event_type', 'like', 'election.scope_%')->count())->toBe(0);
})->with([
    'empty name' => ['   ', ['dimension_a' => ['x']]],
    'name too long' => [str_repeat('n', 256), ['dimension_a' => ['x']]],
    'control character in the name' => ["Bad\x07name", ['dimension_a' => ['x']]],
    'no dimensions' => ['Group', []],
    'too many dimensions' => ['Group', array_combine(array_map(fn ($i) => "dimension_{$i}", range(1, 21)), array_fill(0, 21, ['x']))],
    'key with a space' => ['Group', ['year level' => ['x']]],
    'key starting with a digit' => ['Group', ['1key' => ['x']]],
    'key with punctuation' => ['Group', ['a-b' => ['x']]],
    'key too long' => ['Group', [str_repeat('k', 81) => ['x']]],
    'numeric key' => ['Group', [5 => ['x']]],
    'the same key in two cases' => ['Group', ['dimension_a' => ['x'], 'Dimension_A' => ['y']]],
    'a dimension with no values' => ['Group', ['dimension_a' => []]],
    'a blank value' => ['Group', ['dimension_a' => ['  ']]],
    'values that are not a list' => ['Group', ['dimension_a' => ['k' => 'x']]],
    'values that are not text' => ['Group', ['dimension_a' => [1.5]]],
    'an integer value' => ['Group', ['dimension_a' => [5]]],
    'an integer among text values' => ['Group', ['dimension_a' => ['x', 7]]],
    'a boolean or null value' => ['Group', ['dimension_a' => [true, null]]],
    'a nested value' => ['Group', ['dimension_a' => [['x']]]],
    'a value that is too long' => ['Group', ['dimension_a' => [str_repeat('v', 256)]]],
    'too many values' => ['Group', ['dimension_a' => array_map(fn ($i) => "value {$i}", range(1, 101))]],
    'a control character in a value' => ['Group', ['dimension_a' => ["a\x00b"]]],
    'invalid UTF-8 in a value' => ['Group', ['dimension_a' => ["a\xC3\x28b"]]],
]);

test('the documented limits are accepted exactly at the boundary', function () {
    $election = scopeDraft();
    $definition = [];
    foreach (range(1, ElectionScopeService::MAX_DIMENSIONS) as $i) {
        $definition[sprintf('d%02d', $i)] = ['v'];
    }
    $definition['d01'] = array_map(fn ($i) => str_pad((string) $i, ElectionScopeService::MAX_DIMENSION_VALUE_LENGTH, 'x'), range(1, ElectionScopeService::MAX_VALUES_PER_DIMENSION));

    $group = scopeService()->createGroup($this->admin, $election, str_repeat('n', ElectionScopeService::MAX_GROUP_NAME_LENGTH), $definition);

    expect($group->scope_definition_json)->toHaveCount(20)->and($group->scope_definition_json['d01'])->toHaveCount(100);
});

test('non-breaking and other Unicode spaces around values are trimmed', function () {
    $election = scopeDraft();
    $group = scopeGroup($election, 'Group', ['dimension_a' => ["\u{00A0}spaced\u{2003}", 'plain']]);

    expect($group->scope_definition_json)->toBe(['dimension_a' => ['spaced', 'plain']]);
});

test('group names are unique within an election, ignoring case, and free again in another election', function () {
    $first = scopeDraft('First election');
    $second = scopeDraft('Second election');
    $group = scopeGroup($first, 'Shared Name');
    $other = scopeGroup($first, 'Other name');

    expect(fn () => scopeGroup($first, 'shared name'))->toThrow(InvalidArgumentException::class, ElectionScopeService::MSG_DUPLICATE_NAME)
        ->and(fn () => scopeService()->updateGroup($this->admin, $first, $other->getKey(), 'SHARED NAME', ['dimension_a' => ['x']]))->toThrow(InvalidArgumentException::class, ElectionScopeService::MSG_DUPLICATE_NAME);

    scopeService()->updateGroup($this->admin, $first, $group->getKey(), 'SHARED name', ['dimension_a' => ['x']]); // its own name, new case
    scopeGroup($second, 'Shared Name');                                                                          // another election

    expect(RepresentationGroup::query()->count())->toBe(3);
});

test('a group cannot be read, updated or deleted through a different election', function () {
    $first = scopeDraft('First election');
    $second = scopeDraft('Second election');
    $group = scopeGroup($first);

    expect(fn () => scopeService()->updateGroup($this->admin, $second, $group->getKey(), 'Hijack', ['dimension_a' => ['x']]))->toThrow(ModelNotFoundException::class)
        ->and(fn () => scopeService()->deleteGroup($this->admin, $second, $group->getKey()))->toThrow(ModelNotFoundException::class);

    expect($group->fresh()->name)->toBe('Group A')->and($group->fresh()->election_id)->toBe($first->id);
});

test('a group assigned to a contest cannot be deleted', function () {
    $election = scopeDraft();
    $group = scopeGroup($election);
    scopeContest($election, $group);

    expect(fn () => scopeService()->deleteGroup($this->admin, $election, $group->getKey()))->toThrow(InvalidArgumentException::class, ElectionScopeService::MSG_IN_USE);

    expect(RepresentationGroup::query()->whereKey($group->getKey())->exists())->toBeTrue()
        ->and(scopeAudit('election.scope_group_deleted'))->toHaveCount(0);
});

test('if a contest appears after the application check, the database still refuses and the user gets the same message', function () {
    $election = scopeDraft();
    $group = scopeGroup($election);
    scopeContest($election, $group);

    $racing = new class(app(AuditLogger::class)) extends ElectionScopeService
    {
        protected function isGroupAssigned(int $electionId, int $groupId): bool
        {
            return false; // the check "passed" just before the contest was committed
        }
    };

    expect(fn () => $racing->deleteGroup($this->admin, $election, $group->getKey()))->toThrow(InvalidArgumentException::class, ElectionScopeService::MSG_IN_USE);

    expect(RepresentationGroup::query()->whereKey($group->getKey())->exists())->toBeTrue()
        ->and(scopeAudit('election.scope_group_deleted'))->toHaveCount(0);
});

test('the database refuses a contest that points at another election\'s group', function () {
    $first = scopeDraft('First election');
    $second = scopeDraft('Second election');
    $group = scopeGroup($first);

    expect(fn () => scopeContest($second, $group))->toThrow(QueryException::class);
});

test('the audit logger stays backward compatible and fills election_id only when given', function () {
    $audit = app(AuditLogger::class);
    $election = scopeDraft();

    $audit->record('test.without_election', actorType: 'ADMIN', actorId: 1);
    $audit->record('test.with_election', actorType: 'ADMIN', actorId: 1, electionId: $election->id);

    expect(scopeAudit('test.without_election')->first()->election_id)->toBeNull()
        ->and(scopeAudit('test.with_election')->first()->election_id)->toBe($election->id)
        ->and(fn () => $audit->record('test.bad', metadata: ['candidate_id' => 1], electionId: $election->id))->toThrow(InvalidArgumentException::class);
});

test('on MySQL/MariaDB every scope write locks the election, its version and the group row', function () {
    $election = scopeDraft();
    $group = scopeGroup($election);

    DB::enableQueryLog();
    scopeService()->updateGroup($this->admin, $election, $group->getKey(), 'Renamed', ['dimension_a' => ['x']]);
    $locking = collect(DB::getQueryLog())->pluck('query')->filter(fn ($sql) => str_contains(strtolower($sql), 'for update'))->values();
    DB::disableQueryLog();

    expect($locking)->toHaveCount(3)
        ->and($locking[0])->toContain('`elections`')
        ->and($locking[1])->toContain('`election_config_versions`')
        ->and($locking[2])->toContain('`representation_groups`');
})->skip(fn () => ! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true), 'Row locks (FOR UPDATE) are only emitted by MySQL/MariaDB; SQLite ignores them.');

// ---------------------------------------------------------------------------------------------- the scope page

test('the page renders its empty state and a blank form with nothing preselected', function () {
    $election = scopeDraft();

    $html = scopePage($election)->assertOk()->assertSee('No representation groups yet')->html();

    expect($html)->toContain('theme-2k26')->toContain('wire:submit="save"')->toContain('Create representation group')->toContain('Add category')
        ->toContain('do not decide who may vote or stand')
        ->toContain('How this works')->toContain('Category name')->toContain('internal identifier automatically')
        ->toContain('Add category')->not->toContain('Dimension key')
        ->not->toContain('read-only')
        ->not->toMatch('/value="[^"]+"[^>]*wire:model="dimensions\.0\.key"/');

    $component = scopePage($election);
    expect($component->get('dimensions'))->toBe([['key' => '', 'values' => '']])->and($component->get('name'))->toBe('');
});

test('the form accepts the largest allowed dimension: 100 values of 255 characters each', function () {
    $election = scopeDraft();
    $values = array_map(fn (int $i) => str_pad(sprintf('v%03d-', $i), 255, 'x'), range(1, 100));
    expect(mb_strlen(implode("\n", $values)))->toBeGreaterThan(10000);

    scopePage($election)
        ->set('name', 'Largest group')
        ->set('dimensions', [['key' => 'dimension_a', 'values' => implode("\n", $values)]])
        ->call('save')
        ->assertHasNoErrors()
        ->assertSee('Representation group created successfully.');

    $stored = RepresentationGroup::query()->sole()->scope_definition_json['dimension_a'];
    expect($stored)->toHaveCount(100)->and($stored)->toBe($values)
        ->and(array_unique(array_map('mb_strlen', $stored)))->toBe([255]);
});

test('the page creates, edits and deletes a group and reports it', function () {
    $election = scopeDraft();

    $page = scopePage($election)
        ->set('name', 'Page group')
        ->set('dimensions', [['key' => '  Dimension B ', 'values' => "beta one\nbeta two\n\nbeta one"], ['key' => 'Dimension A', 'values' => 'alpha']])
        ->call('save')
        ->assertHasNoErrors()
        ->assertSee('Representation group created successfully.')->assertSee('Page group')->assertSee('beta one')->assertSee('beta two');

    $group = RepresentationGroup::query()->sole();
    expect($group->scope_definition_json)->toBe(['dimension_a' => ['alpha'], 'dimension_b' => ['beta one', 'beta two']])
        ->and($page->get('name'))->toBe('')->and($page->get('editingGroupId'))->toBeNull()
        ->and(scopeAudit('election.scope_group_created')->first()->election_id)->toBe($election->id);

    $page->call('editGroup', $group->getKey())
        ->assertSet('name', 'Page group')->assertSet('editingGroupId', $group->getKey())
        ->assertSet('dimensions', [['key' => 'Dimension A', 'values' => 'alpha'], ['key' => 'Dimension B', 'values' => "beta one\nbeta two"]])
        ->assertSee('Edit representation group')
        ->set('name', 'Renamed group')->set('dimensions', [['key' => 'Dimension C', 'values' => 'gamma']])
        ->call('save')->assertHasNoErrors()->assertSee('Changes saved successfully.')->assertSee('Renamed group');

    expect($group->fresh()->scope_definition_json)->toBe(['dimension_c' => ['gamma']])
        ->and(scopeAudit('election.scope_group_updated')->first()->election_id)->toBe($election->id);

    $page->call('deleteGroup', $group->getKey())->assertSee('Representation group deleted successfully.')->assertSee('No representation groups yet');
    expect(RepresentationGroup::query()->count())->toBe(0)
        ->and(scopeAudit('election.scope_group_deleted')->first()->election_id)->toBe($election->id);
});

test('the form explains validation problems in plain words', function () {
    $election = scopeDraft();

    scopePage($election)->set('name', '')->call('save')->assertHasErrors(['name' => 'required'])->assertSee('Enter a group name.');
    scopePage($election)->set('name', str_repeat('n', 256))->set('dimensions', [['key' => 'dimension_a', 'values' => 'x']])->call('save')->assertHasErrors(['name' => 'max']);
    scopePage($election)->set('name', 'G')->set('dimensions', [['key' => str_repeat('a', 81), 'values' => 'x']])->call('save')
        ->assertHasErrors(['dimensions.0.key' => 'max'])->assertSee('A category name may have at most 80 characters.');
    scopePage($election)->set('name', 'G')->set('dimensions', [['key' => '', 'values' => 'x']])->call('save')
        ->assertHasErrors(['dimensions.0.key' => 'required'])->assertSee('Enter a category name using letters or numbers.');
    scopePage($election)->set('name', 'G')->set('dimensions', [['key' => '!!! ???', 'values' => 'x']])->call('save')
        ->assertHasErrors(['dimensions.0.key' => 'required']); // nothing left to build a key from
    scopePage($election)->set('name', 'G')->set('dimensions', [['key' => 'dimension_a', 'values' => " \n "]])->call('save')->assertHasErrors(['dimensions.0.values' => 'required']);
    scopePage($election)->set('name', 'G')->set('dimensions', [['key' => 'dimension_a', 'values' => str_repeat('v', 10001)]])->call('save')
        ->assertHasNoErrors() // no artificial form cap: the service enforces the per-value limit and explains it
        ->assertSee('Each scope value must contain 1 to 255 characters');

    expect(RepresentationGroup::query()->count())->toBe(0);
});

test('the same category name twice (after normalization) is a validation error, not a server error', function () {
    $election = scopeDraft();

    foreach ([' dimension a ', 'DIMENSION-A', 'Dimension_A', 'dimension   a'] as $variant) {
        scopePage($election)->set('name', 'G')
            ->set('dimensions', [['key' => 'Dimension A', 'values' => 'x'], ['key' => $variant, 'values' => 'y']])
            ->call('save')
            ->assertHasErrors(['dimensions.0.key' => 'distinct'])
            ->assertSee('Each category name must be unique within this group.');
    }

    expect(RepresentationGroup::query()->count())->toBe(0);
});

test('readable category names become internal keys automatically and the form keeps what was typed', function () {
    $election = scopeDraft();

    $page = scopePage($election)->set('name', 'Key group')->set('dimensions', [
        ['key' => 'Representation Type', 'values' => 'one'],
        ['key' => '  Academic   Category ', 'values' => 'two'],
        ['key' => '2nd Level', 'values' => 'three'],
        ['key' => 'Año Étude', 'values' => 'four'],
        ['key' => 'Bad Key!', 'values' => 'five'],
    ])->call('save')->assertHasNoErrors();

    expect(RepresentationGroup::query()->sole()->scope_definition_json)->toBe([
        'academic_category' => ['two'], 'ano_etude' => ['four'], 'bad_key' => ['five'], 'category_2nd_level' => ['three'], 'representation_type' => ['one'],
    ]);

    // A failed save must not replace what the administrator typed with the technical key.
    $failed = scopePage($election)->set('name', '')->set('dimensions', [['key' => 'Representation Type', 'values' => 'x']])->call('save');
    expect($failed->get('dimensions'))->toBe([['key' => 'Representation Type', 'values' => 'x']]);
});

test('saved keys are shown as readable names in the list and when editing, and are saved back unchanged', function () {
    $election = scopeDraft();
    // Keys as an earlier version (or a direct service call) could have stored them, including shapes a label cannot reproduce.
    $group = app(ElectionScopeService::class)->createGroup($this->admin, $election, 'Stored group', [
        'representation_type' => ['value one', 'Value Two'], 'a__b' => ['x'], 'a_b' => ['y'], 'trailing_' => ['z'], 'item_2' => ['w'],
    ]);
    $before = DB::table('representation_groups')->where('id', $group->getKey())->value('scope_definition_json');

    $page = scopePage($election);
    $html = $page->html();
    expect($html)->toContain('Representation Type')->toContain('Item 2')->toContain('>value one<')->toContain('>Value Two<')->not->toContain('representation_type');

    $page->call('editGroup', $group->getKey());
    expect(array_column($page->get('dimensions'), 'key'))->toContain('Representation Type')->toContain('Item 2')
        ->and(array_column($page->get('dimensions'), 'key'))->toHaveCount(5)->and(count(array_unique(array_column($page->get('dimensions'), 'key'))))->toBe(5);

    $page->call('save')->assertHasNoErrors()->assertSee('Changes saved successfully.');
    expect(DB::table('representation_groups')->where('id', $group->getKey())->value('scope_definition_json'))->toBe($before)
        ->and($group->fresh()->scope_definition_json)->toHaveKeys(['representation_type', 'a__b', 'a_b', 'trailing_', 'item_2']);

    // Renaming a category on the form really does change its stored key; untouched ones keep theirs.
    $rows = $page->call('editGroup', $group->getKey())->get('dimensions');
    $index = array_search('Representation Type', array_column($rows, 'key'), true);
    $rows[$index]['key'] = 'Renamed Category';
    $page->set('dimensions', $rows)->call('save')->assertHasNoErrors();
    $keys = array_keys($group->fresh()->scope_definition_json);
    expect($keys)->toContain('renamed_category')->toContain('a__b')->not->toContain('representation_type');
});

test('the map of saved keys cannot be changed from the browser', function () {
    $election = scopeDraft();
    $page = scopePage($election);

    expect(fn () => $page->set('storedKeys', ['Anything' => 'a__b']))->toThrow(Exception::class)
        ->and($page->get('storedKeys'))->toBe([]);
});

test('too many dimensions, and rows forged into the wrong shape, are handled', function () {
    $election = scopeDraft();

    $many = array_map(fn ($i) => ['key' => "dimension_{$i}", 'values' => 'x'], range(1, 21));
    scopePage($election)->set('name', 'G')->set('dimensions', $many)->call('save')->assertHasErrors(['dimensions' => 'max']);

    $page = scopePage($election)->set('name', 'G')->set('dimensions', ['nonsense', ['key' => ['array'], 'values' => 5], ['key' => 'dimension_a']]);
    $page->call('save')->assertHasErrors();

    $page = scopePage($election);
    foreach (range(1, 25) as $unused) {
        $page->call('addDimension');
    }
    expect(count($page->get('dimensions')))->toBe(ElectionScopeService::MAX_DIMENSIONS)->and($page->get('problem'))->toContain('at most 20');

    expect(RepresentationGroup::query()->count())->toBe(0);
});

test('service rules come back as messages: duplicate name, and deleting a group that a contest uses', function () {
    $election = scopeDraft();
    $group = scopeGroup($election, 'Taken');
    scopeContest($election, $group);

    $page = scopePage($election)->set('name', 'taken')->set('dimensions', [['key' => 'dimension_a', 'values' => 'x']])->call('save');
    expect($page->get('problem'))->toBe(ElectionScopeService::MSG_DUPLICATE_NAME);
    $page->assertSee(ElectionScopeService::MSG_DUPLICATE_NAME);

    $page->call('deleteGroup', $group->getKey()); // the button is hidden for this group, but the action can still be called
    expect($page->get('problem'))->toBe(ElectionScopeService::MSG_IN_USE)->and(RepresentationGroup::query()->count())->toBe(1);

    $html = scopePage($election)->html();
    expect($html)->toContain('1 contest assigned')->not->toContain('wire:click="deleteGroup('.$group->id.')"');
});

test('the same form submitted twice creates one group', function () {
    $election = scopeDraft();

    $page = scopePage($election)->set('name', 'Once')->set('dimensions', [['key' => 'dimension_a', 'values' => 'x']])->call('save');
    $page->call('save'); // a queued second click: the form was already cleared by the first

    expect(RepresentationGroup::query()->count())->toBe(1);

    $page->set('name', 'once')->set('dimensions', [['key' => 'dimension_a', 'values' => 'x']])->call('save'); // a re-typed copy
    expect(RepresentationGroup::query()->count())->toBe(1)->and($page->get('problem'))->toBe(ElectionScopeService::MSG_DUPLICATE_NAME);
});

test('ids and messages cannot be changed from the browser', function () {
    $election = scopeDraft();
    $page = scopePage($election);

    foreach (['electionId' => 1, 'editingGroupId' => 1, 'status' => 'x', 'problem' => 'x'] as $property => $value) {
        expect(fn () => $page->set($property, $value))->toThrow(Exception::class, 'Cannot update locked property');
    }
});

test('a forged group id from another election changes and reveals nothing', function () {
    $mine = scopeDraft('Mine');
    $theirs = scopeDraft('Theirs');
    $foreign = scopeGroup($theirs, 'Foreign group', ['secret_key' => ['secret value']]);

    $page = scopePage($mine)->call('editGroup', $foreign->getKey());
    expect($page->get('problem'))->toContain('no longer exists')->and($page->get('name'))->toBe('')->and($page->get('editingGroupId'))->toBeNull()
        ->and($page->html())->not->toContain('Foreign group')->not->toContain('secret value');

    $page->call('deleteGroup', $foreign->getKey());
    expect($page->get('problem'))->toContain('no longer exists')->and($foreign->fresh()->name)->toBe('Foreign group');
});

test('a lock timeout or deadlock asks the user to try again; any other database error is not hidden', function () {
    $election = scopeDraft();
    $failing = fn (int $code) => new class(app(AuditLogger::class), $code) extends ElectionScopeService
    {
        public function __construct(AuditLogger $audit, private readonly int $code)
        {
            parent::__construct($audit);
        }

        public function createGroup(AdminUser $admin, Election $election, string $name, array $scopeDefinition): RepresentationGroup
        {
            $pdo = new PDOException('simulated');
            $pdo->errorInfo = ['HY000', $this->code, 'simulated'];

            throw new QueryException('mariadb', 'insert into representation_groups', [], $pdo);
        }
    };

    foreach ([1205, 1213] as $code) {
        app()->bind(ElectionScopeService::class, fn () => $failing($code));
        $page = scopePage($election)->set('name', 'G')->set('dimensions', [['key' => 'dimension_a', 'values' => 'x']])->call('save');

        expect($page->get('problem'))->toContain('someone else')->toContain('try again');
    }

    app()->bind(ElectionScopeService::class, fn () => $failing(1451));
    $page = scopePage($election)->set('name', 'G')->set('dimensions', [['key' => 'dimension_a', 'values' => 'x']]);
    expect(fn () => $page->call('save'))->toThrow(QueryException::class);
});

test('deleting twice (a double click) is a message, not an error page', function () {
    $election = scopeDraft();
    $group = scopeGroup($election);

    $page = scopePage($election)->call('deleteGroup', $group->getKey())->assertSee('Representation group deleted successfully.');
    $page->call('deleteGroup', $group->getKey());

    expect($page->get('problem'))->toContain('no longer exists')->and(RepresentationGroup::query()->count())->toBe(0);
});

test('if the election leaves the draft state while the page is open, writes are refused with a message and the page turns read-only', function () {
    $election = scopeDraft();
    $group = scopeGroup($election);
    $page = scopePage($election)->call('editGroup', $group->getKey());

    scopeSetState($election, 'READY'); // another administrator, or a later phase

    $page->set('name', 'Too late')->call('save');
    expect($page->get('problem'))->toBe(ElectionScopeService::MSG_NOT_EDITABLE)->and($page->get('editingGroupId'))->toBeNull();

    $page->call('deleteGroup', $group->getKey());
    expect($page->get('problem'))->toBe(ElectionScopeService::MSG_NOT_EDITABLE);
    $page->call('editGroup', $group->getKey());
    expect($page->get('problem'))->toBe(ElectionScopeService::MSG_NOT_EDITABLE);

    $page->assertSee('shown read-only')->assertSee('Group A')->assertDontSee('Create representation group')->assertDontSee('Delete');
    expect($group->fresh()->name)->toBe('Group A')->and(RepresentationGroup::query()->count())->toBe(1);
});

test('a non-draft election shows its scope read-only, with no form and no actions', function () {
    $election = scopeDraft();
    scopeGroup($election, 'Saved group', ['dimension_a' => ['one', 'two']]);
    scopeSetState($election, 'LOCKED');

    $this->actingAs($this->admin, 'admin');
    $html = $this->get(route('admin.elections.scope', $election->id))->assertOk()->getContent();

    expect($html)->toContain('Saved group')->toContain('>one<')->toContain('>two<')->toContain('shown read-only')
        ->not->toContain('wire:submit')->not->toContain('Create representation group')
        ->not->toMatch('/wire:click="(editGroup|deleteGroup|confirmDelete|addDimension|addValue|removeValue|editValue)/');

    $empty = scopeDraft('Empty');
    scopeSetState($empty, 'LOCKED');
    $this->get(route('admin.elections.scope', $empty->id))->assertOk()->assertSee('No groups were configured while this election was a draft.');
});

test('the page exposes no personal data and uses valid icons', function () {
    $election = scopeDraft();
    scopeGroup($election);

    $html = scopePage($election)->html();
    expect($html)->not->toContain($this->admin->authorized_email)->not->toContain('google_subject')->not->toContain('creation_key');

    $icons = [];
    preg_match_all('/icon="([a-z0-9-]+)"/', file_get_contents(resource_path('views/livewire/admin/elections/scope.blade.php')), $icons);
    foreach (array_unique($icons[1]) as $icon) {
        expect(file_exists(base_path("vendor/livewire/flux/stubs/resources/views/flux/icon/{$icon}.blade.php")) || file_exists(resource_path("views/flux/icon/{$icon}.blade.php")))->toBeTrue("icon [{$icon}] does not exist");
    }
});

// ---------------------------------------------------------------------------------------------- adding values as tags

test('values are added one at a time, shown as tags, and still saved as the same list of text values', function () {
    $election = scopeDraft();

    $page = scopePage($election)->set('name', 'Tag group')->set('dimensions.0.key', 'Tag Category')
        ->set('drafts.0', '  first value ')->call('addValue', 0)
        ->assertHasNoErrors()->assertSet('drafts.0', '')
        ->set('drafts.0', 'second value')->call('addValue', 0);

    expect($page->get('dimensions.0.values'))->toBe("first value\nsecond value");
    $html = $page->html();
    expect($html)->toContain('>first value<')->toContain('>second value<')->toContain('2 values')
        ->toContain('Remove value: first value')->toContain('Edit value: first value')->toContain('wire:keydown.enter.prevent="addValue(0)"');
    expect(DB::table('representation_groups')->count())->toBe(0); // adding a tag saves nothing

    $page->call('save')->assertHasNoErrors();
    expect(RepresentationGroup::query()->sole()->scope_definition_json)->toBe(['tag_category' => ['first value', 'second value']]);
});

test('tags can be removed and edited before saving', function () {
    $election = scopeDraft();
    $page = scopePage($election)->set('dimensions.0.key', 'Tags')->set('dimensions.0.values', "one\ntwo\nthree");

    $page->call('removeValue', 0, 1);
    expect($page->get('dimensions.0.values'))->toBe("one\nthree");

    $page->call('editValue', 0, 0)->assertSet('drafts.0', 'one');
    expect($page->get('dimensions.0.values'))->toBe('three');
    $page->set('drafts.0', 'one, corrected')->call('addValue', 0);
    expect($page->get('dimensions.0.values'))->toBe("three\none, corrected");

    // Out-of-range positions change nothing and raise nothing.
    $page->call('removeValue', 0, 99)->call('editValue', 0, 99)->call('removeValue', 7, 0)->call('addValue', 7);
    expect($page->get('dimensions.0.values'))->toBe("three\none, corrected");

    // Editing a tag while something else is being typed does not throw that text away.
    $page->set('drafts.0', 'typing')->call('editValue', 0, 0)->assertHasErrors('drafts.0')->assertSee('Add or clear the value you are typing');
    expect($page->get('drafts.0'))->toBe('typing')->and($page->get('dimensions.0.values'))->toBe("three\none, corrected");
});

test('an empty, duplicate, over-long or over-numerous tag is refused with a plain message and changes nothing', function () {
    $election = scopeDraft();
    $page = scopePage($election)->set('dimensions.0.key', 'Tags')->set('dimensions.0.values', 'kept');

    $page->set('drafts.0', '   ')->call('addValue', 0)->assertHasErrors('drafts.0')->assertSee('Type a value first, then choose Add.');
    $page->set('drafts.0', 'kept')->call('addValue', 0)->assertHasErrors('drafts.0')->assertSee('That value is already in this category.');
    $page->set('drafts.0', str_repeat('x', 256))->call('addValue', 0)->assertHasErrors('drafts.0')->assertSee('A value may have at most 255 characters.');
    $page->set('drafts.0', "bad\u{0007}value")->call('addValue', 0)->assertHasErrors('drafts.0')->assertSee('A value cannot contain control characters.');
    expect($page->get('dimensions.0.values'))->toBe('kept');

    $page->set('dimensions.0.values', implode("\n", array_map(fn ($i) => "v{$i}", range(1, 100))))->set('drafts.0', 'one more')->call('addValue', 0)
        ->assertHasErrors('drafts.0')->assertSee('A category can have at most 100 values.');
    expect(substr_count($page->get('dimensions.0.values'), "\n"))->toBe(99);

    // A successful add clears an earlier message.
    $page->set('dimensions.0.values', 'kept')->set('drafts.0', 'fresh')->call('addValue', 0)->assertHasNoErrors();
});

test('a pasted block adds one tag per line, and a value typed but not added is not lost on save', function () {
    $election = scopeDraft();

    $page = scopePage($election)->set('dimensions.0.key', 'Pasted')->set('drafts.0', "alpha\n\nbeta\r\nalpha")->call('addValue', 0);
    expect($page->get('dimensions.0.values'))->toBe("alpha\nbeta");

    $page->set('name', 'Pending group')->set('drafts.0', 'typed last')->call('save')->assertHasNoErrors();
    expect(RepresentationGroup::query()->sole()->scope_definition_json)->toBe(['pasted' => ['alpha', 'beta', 'typed last']]);

    // A pending value that is not valid stops the save, with the message on its input and nothing written.
    $page = scopePage($election)->set('name', 'Other group')->set('dimensions', [['key' => 'Other', 'values' => 'x']])->set('drafts', [str_repeat('y', 300)])->call('save');
    $page->assertHasErrors('drafts.0');
    expect(RepresentationGroup::query()->count())->toBe(1);
});

test('removing a category moves the typed text of the others with them, and rows stay in step', function () {
    $election = scopeDraft();

    $page = scopePage($election)->call('addDimension')->call('addDimension')
        ->set('dimensions.0.key', 'First')->set('dimensions.1.key', 'Second')->set('dimensions.2.key', 'Third')
        ->set('drafts.0', 'draft one')->set('drafts.1', 'draft two')->set('drafts.2', 'draft three');

    $page->call('removeDimension', 1);
    expect(array_column($page->get('dimensions'), 'key'))->toBe(['First', 'Third'])->and($page->get('drafts'))->toBe(['draft one', 'draft three']);

    $page->call('removeDimension', 0)->call('removeDimension', 0);
    expect($page->get('dimensions'))->toBe([['key' => '', 'values' => '']])->and(count($page->get('drafts')))->toBe(1);
});

test('forged draft shapes are harmless', function () {
    $election = scopeDraft();
    $page = scopePage($election)->set('dimensions.0.key', 'Tags')->set('drafts', [['not', 'text']])->call('addValue', 0)->assertHasErrors('drafts.0');
    expect($page->get('dimensions.0.values'))->toBe('');
});

// ---------------------------------------------------------------------------------------------- feedback, editing state, delete dialog

test('saving reports success through a toast event and a status line only after the service finished', function () {
    $election = scopeDraft();

    $page = scopePage($election)->set('name', '')->call('save')->assertHasErrors('name');
    $page->assertNotDispatched('election-notice');
    expect($page->get('status'))->toBeNull();

    $page = scopePage($election)->set('name', 'Notified')->set('dimensions', [['key' => 'Cat', 'values' => 'v']])->call('save')
        ->assertDispatched('election-notice', type: 'success', message: 'Representation group created successfully.')
        ->assertDispatched('election-form-clean');
    expect($page->get('status'))->toBe('Representation group created successfully.');

    $html = $page->html();
    expect($html)->toContain('role="status"')->toContain('aria-live="polite"')->toContain('x-on:election-notice.window')->toContain('motion-reduce')->toContain('aria-hidden="true"');

    $group = RepresentationGroup::query()->sole();
    scopePage($election)->call('editGroup', $group->getKey())->set('name', 'Notified again')->call('save')
        ->assertDispatched('election-notice', type: 'success', message: 'Changes saved successfully.');

    // A service refusal is a message, never a success toast.
    $page = scopePage($election)->set('name', 'notified again')->set('dimensions', [['key' => 'Cat', 'values' => 'v']])->call('save');
    $page->assertNotDispatched('election-notice');
    expect($page->get('problem'))->toBe(ElectionScopeService::MSG_DUPLICATE_NAME)->and($page->get('status'))->toBeNull();
});

test('the form shows the saving state, the unsaved-changes warning and the editing state, and keeps input after a failed save', function () {
    $election = scopeDraft();
    $group = scopeGroup($election, 'Editable group', ['dimension_a' => ['one']]);

    $html = scopePage($election)->html();
    expect($html)->toContain('Saving...')->toContain('wire:loading.attr="disabled"')->toContain('Unsaved changes')->toContain('beforeunload')->toContain('livewire:navigate')
        ->not->toContain('Editing')->not->toContain('Being edited');

    $page = scopePage($election)->call('editGroup', $group->getKey())->assertDispatched('election-form-clean');
    $html = $page->html();
    expect($html)->toContain('Editing')->toContain('Editable group')->toContain('Edit representation group')->toContain('Being edited')->toContain('Save changes')->toContain('Cancel');
    expect(fn () => $page->set('editingGroupName', 'x'))->toThrow(Exception::class, 'Cannot update locked property');

    $page->set('name', '')->set('dimensions', [['key' => 'Kept Category', 'values' => 'kept value']])->call('save')
        ->assertHasErrors('name')->assertSet('editingGroupId', $group->getKey())->assertSet('dimensions', [['key' => 'Kept Category', 'values' => 'kept value']]);

    $page->call('cancelEdit')->assertSet('editingGroupId', null)->assertSet('name', '')->assertDontSee('Being edited');
    expect($group->fresh()->name)->toBe('Editable group');
});

test('switching to another saved group is guarded by the page-wide unsaved-changes state', function () {
    $election = scopeDraft();
    $first = scopeGroup($election, 'First saved');
    $second = scopeGroup($election, 'Second saved');

    $html = scopePage($election)->call('editGroup', $first->getKey())->html();

    // One dirty flag at the page root sees both the form and the saved cards.
    expect($html)->toContain('guardSwitch(event)')->toContain('x-on:click.capture="guardSwitch($event)"')
        ->toContain('Discard them and open this group for editing?')
        ->and($html)->toMatch('/wire:click="editGroup\('.$first->id.'\)"[^>]*data-switch-record/')->toMatch('/wire:click="editGroup\('.$second->id.'\)"[^>]*data-switch-record/');

    // The guard is not on Delete (a separate confirmation dialog) and the form no longer owns the dirty state.
    expect($html)->not->toMatch('/wire:click="confirmDelete\([0-9]+\)"[^>]*data-switch-record/')->not->toMatch('/<form[^>]*x-data/');

    // Server side, loading a record is still a plain action: it replaces the form and tells the page it is clean again.
    $page = scopePage($election)->call('editGroup', $first->getKey())->set('name', 'Half typed')->call('editGroup', $second->getKey())
        ->assertSet('editingGroupId', $second->getKey())->assertSet('name', 'Second saved')->assertDispatched('election-form-clean');
    expect($first->fresh()->name)->toBe('First saved');
});

test('delete asks first, names the group, and reports only after the transaction succeeded', function () {
    $election = scopeDraft();
    $keep = scopeGroup($election, 'Keep me');
    $gone = scopeGroup($election, 'Delete me');

    $page = scopePage($election)->call('confirmDelete', $gone->getKey());
    expect($page->get('showDeleteDialog'))->toBeTrue()->and($page->get('confirmingDeleteId'))->toBe($gone->id)->and(RepresentationGroup::query()->count())->toBe(2);
    $html = $page->html();
    expect($html)->toContain('Delete this representation group?')->toContain('Delete me')->toContain('Deletion is blocked when a contest already uses the group')
        ->toContain('wire:click="deleteGroup('.$gone->id.')"');
    $page->assertNotDispatched('election-notice');

    $page->call('deleteGroup', $gone->getKey())
        ->assertDispatched('election-notice', type: 'success', message: 'Representation group deleted successfully.')
        ->assertSet('showDeleteDialog', false)->assertSet('confirmingDeleteId', null);
    expect(RepresentationGroup::query()->pluck('id')->all())->toBe([$keep->id]);

    expect(fn () => $page->set('confirmingDeleteId', $keep->id))->toThrow(Exception::class, 'Cannot update locked property');
});

test('a group used by a contest never reaches the delete dialog, and a failed delete closes it with a message', function () {
    $election = scopeDraft();
    $group = scopeGroup($election, 'Protected');
    scopeContest($election, $group);

    $page = scopePage($election);
    expect($page->html())->not->toContain('wire:click="confirmDelete('.$group->id.')"')->toContain('it cannot be deleted. You can still edit it.');

    $page->call('confirmDelete', $group->getKey());
    expect($page->get('showDeleteDialog'))->toBeFalse()->and($page->get('problem'))->toBe(ElectionScopeService::MSG_IN_USE);

    $page->call('deleteGroup', $group->getKey())->assertNotDispatched('election-notice')->assertSet('showDeleteDialog', false);
    expect($page->get('problem'))->toBe(ElectionScopeService::MSG_IN_USE)->and(RepresentationGroup::query()->count())->toBe(1);
});

test('delete confirmation refuses foreign groups and elections that stopped being editable', function () {
    $mine = scopeDraft('Mine');
    $theirs = scopeDraft('Theirs');
    $foreign = scopeGroup($theirs, 'Foreign group');
    $own = scopeGroup($mine, 'Own group');

    $page = scopePage($mine)->call('confirmDelete', $foreign->getKey());
    expect($page->get('showDeleteDialog'))->toBeFalse()->and($page->get('problem'))->toContain('no longer exists')->and($page->html())->not->toContain('Foreign group');

    scopeSetState($mine, 'READY');
    $page->call('confirmDelete', $own->getKey());
    expect($page->get('problem'))->toBe(ElectionScopeService::MSG_NOT_EDITABLE)->and($page->get('showDeleteDialog'))->toBeFalse()->and(RepresentationGroup::query()->count())->toBe(2);
});

test('saved groups are cards with readable categories, tags and the number of contests using them, and no internal keys', function () {
    $election = scopeDraft();
    $group = scopeGroup($election, 'Card group', ['representation_type' => ['value one', 'value two'], 'second_category' => ['value three']]);
    scopeContest($election, $group);
    scopeGroup($election, 'Unused group', ['unused_category' => ['u']]);

    $html = scopePage($election)->html();
    expect($html)->toContain('Card group')->toContain('Representation Type')->toContain('Second Category')->toContain('>value one<')->toContain('>value three<')
        ->toContain('1 contest assigned')->toContain('0 contests assigned')
        ->toContain('wire:click="confirmDelete(')->toContain('wire:click="editGroup(')
        ->not->toContain('representation_type')->not->toContain('second_category')->not->toContain('Dimension key');

    // The same group in a read-only election: cards without actions.
    scopeSetState($election, 'READY');
    $html = scopePage($election)->html();
    expect($html)->toContain('Card group')->toContain('>value two<')->toContain('Read-only')->not->toContain('Editable draft')->not->toContain('confirmDelete')->not->toContain('wire:click="editGroup(');
});

test('the page explains the four steps and that groups do not set eligibility', function () {
    $html = scopePage(scopeDraft())->html();

    expect($html)->toContain('Give the representation group a recognizable name.')->toContain('Add the categories that describe the group.')
        ->toContain('Add the values that belong to each category.')->toContain('Assign the group to related contests later.')
        ->toContain('they do not set voter or candidate eligibility')->toContain('Group name')->toContain('Category name')->toContain('Values');
});

test('saving through the tag interface changes no stored shape: the stored contract is an object of key to a list of text', function () {
    $election = scopeDraft();

    scopePage($election)->set('name', 'Contract group')->set('dimensions.0.key', '3rd Category')->set('drafts.0', 'x')->call('addValue', 0)->set('drafts.0', 'y')->call('addValue', 0)->call('save')->assertHasNoErrors();

    $raw = DB::table('representation_groups')->value('scope_definition_json');
    expect(json_decode($raw, true))->toBe(['category_3rd_category' => ['x', 'y']])
        ->and(array_is_list(json_decode($raw, true)['category_3rd_category']))->toBeTrue();
});

// ---------------------------------------------------------------------------------------------- the workspace

test('the election workspace links to the scope page and shows the scope summary', function () {
    $election = scopeDraft();
    $this->actingAs($this->admin, 'admin');

    $html = Volt::test('admin.elections.show', ['election' => $election])->html();
    expect($html)->toContain('Configure representation groups')->toContain('href="'.route('admin.elections.scope', $election->id).'"')
        ->toContain('No representation groups yet')->not->toContain('Not configured yet');

    scopeGroup($election, 'One');
    scopeGroup($election, 'Two');
    expect(Volt::test('admin.elections.show', ['election' => $election])->html())->toContain('2 representation groups');

    scopeSetState($election, 'READY');
    $html = Volt::test('admin.elections.show', ['election' => $election])->html();
    expect($html)->toContain('View representation groups')->not->toContain('Configure representation groups')->toContain('href="'.route('admin.elections.scope', $election->id).'"');
});

test('the workspace button follows the policy: no editing links once a version is frozen', function () {
    $election = scopeDraft();
    DB::table('election_config_versions')->where('id', $election->current_config_version_id)->update(['status' => 'SNAPSHOTTED', 'config_json' => json_encode(['frozen' => true])]);

    $this->actingAs($this->admin, 'admin');
    expect(Volt::test('admin.elections.show', ['election' => $election])->html())->toContain('View representation groups')->not->toContain('Configure representation groups');
});

// ---------------------------------------------------------------------------------------------- scope of the change

test('the scope code only writes through the service and ships no institutional values', function () {
    $page = file_get_contents(resource_path('views/livewire/admin/elections/scope.blade.php'));

    expect($page)->toContain('ElectionScopeService')
        ->not->toMatch('/->(update|insert|insertGetId|delete|upsert|save|forceDelete|truncate|forceFill)\(/')
        ->not->toMatch('/DB::(insert|update|delete|statement)/');

    foreach ([
        app_path('Services/Election/ElectionScopeService.php'),
        app_path('Models/RepresentationGroup.php'),
        resource_path('views/livewire/admin/elections/scope.blade.php'),
    ] as $file) {
        expect(file_get_contents($file))->not->toMatch('/\b(college|course|program|department|sector|year[_ -]?level|1st year)\b/i', basename($file));
    }
});

test('no migration, dependency or ballot table is part of this change', function () {
    expect(glob(database_path('migrations/*04b*')))->toBe([])
        ->and(glob(database_path('migrations/*scope*')))->toBe([]);
});
