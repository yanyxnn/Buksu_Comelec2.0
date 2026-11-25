<?php

namespace App\Services\Election;

use App\Models\AdminUser;
use App\Models\Contest;
use App\Models\ContestRule;
use App\Models\Election;
use App\Models\ElectionConfigVersion;
use App\Models\RepresentationGroup;
use App\Services\Audit\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * Phase 04C: the single write path for an election's contests and their voting rules.
 *
 * A contest is a generic, administrator-defined position (name, position label, optional representation group of the
 * SAME election) plus one rule row (seat count, selection limit, abstention allowed, voting method). This class ships
 * no contest list, no seat counts and no eligibility rule, and it does not relate selection_limit to seat_count: no
 * approved document does (docs/DATABASE.md distinguishes seats from maximum selections).
 *
 * Every write:
 *  - runs in ONE transaction that first locks the election row, then its CURRENT configuration version row, then (for
 *    update/delete) the contest row - lock order: election -> config version -> contest;
 *  - re-checks, on those locked rows, that the administrator is authorized and the election still allows contest edits
 *    (Election::allowsContestEditing: editable state, nothing locked, current version DRAFT with no frozen JSON);
 *  - binds a new contest to that locked current version, and only touches contests of that election AND version;
 *  - accepts a representation group only when it belongs to the locked election (the composite foreign key
 *    contests_rg_same_election_fk stays as the database backstop). Group deletion (ElectionScopeService) also takes the
 *    election row lock first, so a group cannot disappear between this check and the write;
 *  - writes its audit event in the same transaction, with audit_logs.election_id set and ids/counts only in metadata;
 *  - never creates or alters a configuration version and never writes `contests.scope`.
 *
 * Exceptions: InvalidArgumentException = invalid input / group of another election / contest in use (message is safe to
 * show); AuthorizationException = not an authorized administrator, or the election no longer allows edits;
 * ModelNotFoundException = the election or the contest does not exist (for this election and its current version).
 */
class ElectionContestService
{
    public const MAX_NAME_LENGTH = 255;

    public const MAX_POSITION_LABEL_LENGTH = 255;

    /** Technical sanity bounds for the two counts (the columns are unsigned integers); not an institutional rule. */
    public const MIN_COUNT = 1;

    public const MAX_COUNT = 100;

    public const MSG_NOT_EDITABLE = 'The contests of this election can no longer be edited.';

    public const MSG_IN_USE = 'This contest has dependent records (eligibility rules, candidacies, ballot responses or results) and cannot be deleted.';

    public const MSG_GROUP_NOT_IN_ELECTION = 'Choose a representation group that belongs to this election.';

    /**
     * Tables whose rows point at a contest. This phase writes none of them; any row there blocks deletion, and the
     * composite/simple foreign keys remain the database backstop.
     *
     * @var list<string>
     */
    private const DEPENDENT_TABLES = ['contest_eligibility_rules', 'candidacies', 'ballot_contest_responses', 'result_aggregates'];

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{name: string, position_label: string, representation_group_id: int|null, seat_count: int, selection_limit: int, allow_abstain: bool, voting_method: string}  $input
     */
    public function createContest(AdminUser $admin, Election $election, array $input): Contest
    {
        $data = $this->normalizeInput($input);

        return DB::transaction(function () use ($admin, $election, $data): Contest {
            [$locked, $version] = $this->lockEditableElection($admin, (int) $election->getKey());
            $this->assertGroupBelongsToElection($data['representation_group_id'], (int) $locked->getKey());

            $contest = new Contest;
            $contest->forceFill([
                'election_id' => $locked->getKey(),
                'election_config_version_id' => $version->getKey(),
                'name' => $data['name'],
                'position_label' => $data['position_label'],
                'representation_group_id' => $data['representation_group_id'],
            ])->save();

            $this->ruleFor($contest)->forceFill($this->ruleValues($data))->save();

            $this->recordAudit('election.contest_created', 'Election contest created.', $admin, $locked, $contest, $data);

            return $contest;
        });
    }

    /**
     * @param  array{name: string, position_label: string, representation_group_id: int|null, seat_count: int, selection_limit: int, allow_abstain: bool, voting_method: string}  $input
     */
    public function updateContest(AdminUser $admin, Election $election, int $contestId, array $input): Contest
    {
        $data = $this->normalizeInput($input);

        return DB::transaction(function () use ($admin, $election, $contestId, $data): Contest {
            [$locked, $version] = $this->lockEditableElection($admin, (int) $election->getKey());
            $contest = $this->lockContest($locked, $version, $contestId);
            $this->assertGroupBelongsToElection($data['representation_group_id'], (int) $locked->getKey());

            $contest->forceFill([
                'name' => $data['name'],
                'position_label' => $data['position_label'],
                'representation_group_id' => $data['representation_group_id'],
            ])->save();

            $this->ruleFor($contest)->forceFill($this->ruleValues($data))->save();

            $this->recordAudit('election.contest_updated', 'Election contest updated.', $admin, $locked, $contest, $data);

            return $contest;
        });
    }

    public function deleteContest(AdminUser $admin, Election $election, int $contestId): void
    {
        DB::transaction(function () use ($admin, $election, $contestId): void {
            [$locked, $version] = $this->lockEditableElection($admin, (int) $election->getKey());
            $contest = $this->lockContest($locked, $version, $contestId);

            if ($this->hasDependentRecords((int) $contest->getKey())) {
                throw new InvalidArgumentException(self::MSG_IN_USE);
            }

            $id = (int) $contest->getKey();

            try {
                ContestRule::query()->where('contest_id', $id)->delete(); // contest_rules.contest_id is RESTRICT: the rule goes first
                $contest->delete();
            } catch (QueryException $exception) {
                // Backstop: a foreign key refuses to orphan a dependent row even if the check above raced. Thrown out of
                // the closure, so the rule deletion above rolls back with the transaction.
                if ((string) $exception->getCode() === '23000') {
                    throw new InvalidArgumentException(self::MSG_IN_USE, 0, $exception);
                }

                throw $exception;
            }

            $this->audit->record(
                eventType: 'election.contest_deleted',
                actorType: 'ADMIN',
                actorId: $admin->getKey(),
                targetType: 'contest',
                targetId: $id,
                description: 'Election contest deleted.',
                metadata: ['contest_id' => $id],
                electionId: (int) $locked->getKey(),
            );
        });
    }

    /**
     * Which of these contests have dependent records (so the page can offer Delete only where it can work). Read-only.
     *
     * @param  list<int>  $contestIds
     * @return list<int>
     */
    public function contestIdsWithDependents(array $contestIds): array
    {
        $blocked = [];

        foreach (self::DEPENDENT_TABLES as $table) {
            foreach (DB::table($table)->whereIn('contest_id', $contestIds)->distinct()->pluck('contest_id') as $id) {
                $blocked[(int) $id] = true;
            }
        }

        return array_keys($blocked);
    }

    /** Overridable seam (tests reproduce the "check passed, then a dependent row appeared" race through it). */
    protected function hasDependentRecords(int $contestId): bool
    {
        return $this->contestIdsWithDependents([$contestId]) !== [];
    }

    /**
     * Lock the election row, then its current configuration version row; refuse unless the administrator is
     * authorized and the (locked, fresh) rows still allow contest edits.
     *
     * @return array{0: Election, 1: ElectionConfigVersion}
     */
    private function lockEditableElection(AdminUser $admin, int $electionId): array
    {
        $election = Election::query()->whereKey($electionId)->lockForUpdate()->firstOrFail();

        $version = $election->current_config_version_id === null
            ? null
            : ElectionConfigVersion::query()
                ->select(['id', 'election_id', 'status', 'config_json'])
                ->whereKey($election->current_config_version_id)
                ->where('election_id', $election->getKey())
                ->lockForUpdate()
                ->first();

        Gate::forUser($admin)->authorize('viewContests', $election); // authorized administrator on an intact roster

        if (! $election->allowsContestEditing($version)) {
            throw new AuthorizationException(self::MSG_NOT_EDITABLE);
        }

        return [$election, $version];
    }

    private function lockContest(Election $election, ElectionConfigVersion $version, int $contestId): Contest
    {
        return Contest::query()
            ->where('election_id', $election->getKey())
            ->where('election_config_version_id', $version->getKey())
            ->whereKey($contestId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function assertGroupBelongsToElection(?int $groupId, int $electionId): void
    {
        if ($groupId === null) {
            return;
        }

        $belongs = RepresentationGroup::query()
            ->where('election_id', $electionId)
            ->whereKey($groupId)
            ->exists();

        if (! $belongs) {
            throw new InvalidArgumentException(self::MSG_GROUP_NOT_IN_ELECTION);
        }
    }

    /** The contest's single rule row (created when missing), keyed by contest. */
    private function ruleFor(Contest $contest): ContestRule
    {
        $rule = ContestRule::query()->where('contest_id', $contest->getKey())->lockForUpdate()->first();

        if ($rule === null) {
            $rule = new ContestRule;
            $rule->forceFill(['contest_id' => $contest->getKey()]);
        }

        return $rule;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function ruleValues(array $data): array
    {
        return [
            'seat_count' => $data['seat_count'],
            'selection_limit' => $data['selection_limit'],
            'allow_abstain' => $data['allow_abstain'],
            'voting_method' => $data['voting_method'],
        ];
    }

    /**
     * Ids and counts only - never the contest name or position label.
     *
     * @param  array<string, mixed>  $data
     */
    private function recordAudit(string $event, string $description, AdminUser $admin, Election $election, Contest $contest, array $data): void
    {
        $this->audit->record(
            eventType: $event,
            actorType: 'ADMIN',
            actorId: $admin->getKey(),
            targetType: 'contest',
            targetId: $contest->getKey(),
            description: $description,
            metadata: [
                'contest_id' => (int) $contest->getKey(),
                'representation_group_id' => $data['representation_group_id'],
                'seat_count' => $data['seat_count'],
            ],
            electionId: (int) $election->getKey(),
        );
    }

    /**
     * Strict, side-effect free validation. Counts must already be integers and the flag a boolean: nothing is coerced.
     *
     * @param  array<string, mixed>  $input
     * @return array{name: string, position_label: string, representation_group_id: int|null, seat_count: int, selection_limit: int, allow_abstain: bool, voting_method: string}
     */
    private function normalizeInput(array $input): array
    {
        $name = $this->cleanLine($input['name'] ?? null, self::MAX_NAME_LENGTH);
        $label = $this->cleanLine($input['position_label'] ?? null, self::MAX_POSITION_LABEL_LENGTH);

        if ($name === null) {
            throw new InvalidArgumentException('Enter a contest name of 1 to '.self::MAX_NAME_LENGTH.' characters, without control characters.');
        }

        if ($label === null) {
            throw new InvalidArgumentException('Enter a position label of 1 to '.self::MAX_POSITION_LABEL_LENGTH.' characters, without control characters.');
        }

        $groupId = $input['representation_group_id'] ?? null;

        if ($groupId !== null && (! is_int($groupId) || $groupId < 1)) {
            throw new InvalidArgumentException(self::MSG_GROUP_NOT_IN_ELECTION);
        }

        foreach (['seat_count' => 'seat count', 'selection_limit' => 'selection limit'] as $field => $fieldLabel) {
            $value = $input[$field] ?? null;

            if (! is_int($value) || $value < self::MIN_COUNT || $value > self::MAX_COUNT) {
                throw new InvalidArgumentException("The {$fieldLabel} must be a whole number from ".self::MIN_COUNT.' to '.self::MAX_COUNT.'.');
            }
        }

        if (! is_bool($input['allow_abstain'] ?? null)) {
            throw new InvalidArgumentException('Choose whether abstaining is allowed.');
        }

        $method = $input['voting_method'] ?? null;

        if (! is_string($method) || ! in_array($method, ContestRule::VOTING_METHODS, true)) {
            throw new InvalidArgumentException('Choose a voting method: '.implode(' or ', ContestRule::VOTING_METHODS).'.');
        }

        return [
            'name' => $name,
            'position_label' => $label,
            'representation_group_id' => $groupId,
            'seat_count' => $input['seat_count'],
            'selection_limit' => $input['selection_limit'],
            'allow_abstain' => $input['allow_abstain'],
            'voting_method' => $method,
        ];
    }

    /** Single line of text: trimmed (incl. Unicode spaces), inner whitespace collapsed; null when empty/too long/invalid. */
    private function cleanLine(mixed $value, int $max): ?string
    {
        if (! is_string($value) || ! mb_check_encoding($value, 'UTF-8') || preg_match('/[\p{Cc}\p{Cf}]/u', $value) === 1) {
            return null;
        }

        $value = trim((string) preg_replace('/[\s\p{Z}]+/u', ' ', $value));

        return $value === '' || mb_strlen($value) > $max ? null : $value;
    }
}
