<?php

use App\Models\AdminUser;
use App\Models\Contest;
use App\Models\ContestRule;
use App\Models\Election;
use App\Models\RepresentationGroup;
use App\Services\Election\ElectionContestService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

/*
 * Phase 04C: an election's contests and their basic voting rules. Any authorized administrator can READ them in any
 * election state; creating, editing and deleting is offered only while the election is an editable draft
 * (ElectionPolicy::manageContests), and every action re-checks that on the server. Contests are bound to the election's
 * CURRENT configuration version. Writes go only through ElectionContestService. Only ids are kept in component state,
 * and the ids are #[Locked]. This page decides nobody's eligibility and lists no institutional contests.
 */
new #[Layout('components.layouts.admin')] #[Title('Election contests')] class extends Component
{
    #[Locked]
    public int $electionId = 0;

    /** The contest being edited (null = creating). Set only by editContest(); the browser cannot change it. */
    #[Locked]
    public ?int $editingContestId = null;

    /** Server-set messages. */
    #[Locked]
    public ?string $status = null;

    #[Locked]
    public ?string $problem = null;

    public string $name = '';

    public string $positionLabel = '';

    /** '' = no representation group. */
    public string $representationGroupId = '';

    public string $seatCount = '';

    public string $selectionLimit = '';

    public bool $allowAbstain = true;

    public string $votingMethod = '';

    public function mount(Election $election): void
    {
        Gate::forUser($this->admin())->authorize('viewContests', $election);

        $this->electionId = (int) $election->getKey();
    }

    #[Computed]
    public function election(): Election
    {
        $election = $this->loadElection();
        Gate::forUser($this->admin())->authorize('viewContests', $election);

        return $election;
    }

    /** Whether the administrator may change contests right now (re-evaluated on every render). */
    #[Computed]
    public function canManage(): bool
    {
        return Gate::forUser($this->admin())->allows('manageContests', $this->election);
    }

    /** Contests of this election bound to its current configuration version, with their rule and group name. */
    #[Computed]
    public function contests()
    {
        $versionId = $this->election->current_config_version_id;

        if ($versionId === null) {
            return collect();
        }

        return Contest::query()
            ->select([
                'contests.id', 'contests.name', 'contests.position_label', 'contests.representation_group_id',
                'contest_rules.seat_count', 'contest_rules.selection_limit', 'contest_rules.allow_abstain', 'contest_rules.voting_method',
                'representation_groups.name as group_name',
            ])
            ->leftJoin('contest_rules', 'contest_rules.contest_id', '=', 'contests.id')
            ->leftJoin('representation_groups', function ($join) {
                $join->on('representation_groups.id', '=', 'contests.representation_group_id')
                    ->on('representation_groups.election_id', '=', 'contests.election_id');
            })
            ->where('contests.election_id', $this->electionId)
            ->where('contests.election_config_version_id', $versionId)
            ->orderBy('contests.name')
            ->orderBy('contests.id')
            ->get();
    }

    /** Contests that cannot be deleted because other records point at them. */
    #[Computed]
    public function blockedContestIds(): array
    {
        return app(ElectionContestService::class)->contestIdsWithDependents($this->contests->pluck('id')->map(fn ($id) => (int) $id)->all());
    }

    /** Representation groups of THIS election only: the only choices offered. */
    #[Computed]
    public function groupOptions()
    {
        return RepresentationGroup::query()
            ->select(['id', 'name'])
            ->where('election_id', $this->electionId)
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    public function editContest(int $contestId): void
    {
        $this->problem = null;
        $this->status = null;

        $this->run(function () use ($contestId): void {
            $election = $this->manageableElection();
            $contest = Contest::query()
                ->select([
                    'contests.id', 'contests.name', 'contests.position_label', 'contests.representation_group_id',
                    'contest_rules.seat_count', 'contest_rules.selection_limit', 'contest_rules.allow_abstain', 'contest_rules.voting_method',
                ])
                ->leftJoin('contest_rules', 'contest_rules.contest_id', '=', 'contests.id')
                ->where('contests.election_id', $election->getKey())
                ->where('contests.election_config_version_id', $election->current_config_version_id)
                ->where('contests.id', $contestId)
                ->firstOrFail();

            $this->resetValidation();
            $this->name = (string) $contest->name;
            $this->positionLabel = (string) $contest->position_label;
            $this->representationGroupId = $contest->representation_group_id === null ? '' : (string) $contest->representation_group_id;
            $this->seatCount = $contest->seat_count === null ? '' : (string) $contest->seat_count;
            $this->selectionLimit = $contest->selection_limit === null ? '' : (string) $contest->selection_limit;
            $this->allowAbstain = $contest->allow_abstain === null ? true : (bool) $contest->allow_abstain;
            $this->votingMethod = (string) ($contest->voting_method ?? '');
            $this->editingContestId = (int) $contest->getKey();
        });
    }

    public function save(): void
    {
        $this->problem = null;
        $this->status = null;

        $this->name = trim($this->name);
        $this->positionLabel = trim($this->positionLabel);
        $this->seatCount = trim($this->seatCount);
        $this->selectionLimit = trim($this->selectionLimit);

        $this->validate(
            [
                'name' => ['required', 'string', 'max:'.ElectionContestService::MAX_NAME_LENGTH],
                'positionLabel' => ['required', 'string', 'max:'.ElectionContestService::MAX_POSITION_LABEL_LENGTH],
                'representationGroupId' => ['nullable', 'integer', 'min:1'],
                'seatCount' => ['required', 'integer', 'min:'.ElectionContestService::MIN_COUNT, 'max:'.ElectionContestService::MAX_COUNT],
                'selectionLimit' => ['required', 'integer', 'min:'.ElectionContestService::MIN_COUNT, 'max:'.ElectionContestService::MAX_COUNT],
                'allowAbstain' => ['boolean'],
                'votingMethod' => ['required', 'in:'.implode(',', ContestRule::VOTING_METHODS)],
            ],
            [
                'name.required' => __('Enter a contest name.'),
                'name.max' => __('The contest name may have at most :max characters.', ['max' => ElectionContestService::MAX_NAME_LENGTH]),
                'positionLabel.required' => __('Enter a position label.'),
                'positionLabel.max' => __('The position label may have at most :max characters.', ['max' => ElectionContestService::MAX_POSITION_LABEL_LENGTH]),
                'representationGroupId.integer' => __('Choose a representation group from the list.'),
                'representationGroupId.min' => __('Choose a representation group from the list.'),
                'seatCount.required' => __('Enter the number of seats.'),
                'seatCount.integer' => __('Seats must be a whole number.'),
                'seatCount.min' => __('Seats must be at least :min.', ['min' => ElectionContestService::MIN_COUNT]),
                'seatCount.max' => __('Seats may be at most :max.', ['max' => ElectionContestService::MAX_COUNT]),
                'selectionLimit.required' => __('Enter the maximum number of selections.'),
                'selectionLimit.integer' => __('The selection limit must be a whole number.'),
                'selectionLimit.min' => __('The selection limit must be at least :min.', ['min' => ElectionContestService::MIN_COUNT]),
                'selectionLimit.max' => __('The selection limit may be at most :max.', ['max' => ElectionContestService::MAX_COUNT]),
                'votingMethod.required' => __('Choose a voting method.'),
                'votingMethod.in' => __('Choose a voting method from the list.'),
            ],
        );

        $this->run(function (): void {
            $admin = $this->admin();
            $election = $this->manageableElection();
            $service = app(ElectionContestService::class);
            $input = [
                'name' => $this->name,
                'position_label' => $this->positionLabel,
                'representation_group_id' => $this->representationGroupId === '' ? null : (int) $this->representationGroupId,
                'seat_count' => (int) $this->seatCount,
                'selection_limit' => (int) $this->selectionLimit,
                'allow_abstain' => $this->allowAbstain,
                'voting_method' => $this->votingMethod,
            ];

            if ($this->editingContestId === null) {
                $service->createContest($admin, $election, $input);
                $this->status = __('Contest created.');
            } else {
                $service->updateContest($admin, $election, $this->editingContestId, $input);
                $this->status = __('Contest updated.');
            }

            $this->resetForm();
        });
    }

    public function deleteContest(int $contestId): void
    {
        $this->problem = null;
        $this->status = null;

        $this->run(function () use ($contestId): void {
            $admin = $this->admin();
            $election = $this->manageableElection();

            app(ElectionContestService::class)->deleteContest($admin, $election, $contestId);

            if ($this->editingContestId === $contestId) {
                $this->resetForm();
            }

            $this->status = __('Contest deleted.');
        });
    }

    public function cancelEdit(): void
    {
        $this->resetForm();
    }

    /**
     * Run a state-changing action and turn every expected failure into a message instead of an error page:
     * invalid input / foreign group / contest in use, the election no longer being editable, a contest that is gone.
     */
    private function run(Closure $action): void
    {
        try {
            $action();
        } catch (InvalidArgumentException $exception) {
            $this->problem = $exception->getMessage();
        } catch (AuthorizationException) {
            $this->problem = ElectionContestService::MSG_NOT_EDITABLE;
            $this->resetForm();
        } catch (ModelNotFoundException) {
            $this->problem = __('That contest no longer exists. The list has been refreshed.');
            $this->resetForm();
        } catch (QueryException $exception) {
            // MySQL/MariaDB 1205 = lock wait timeout, 1213 = deadlock: another administrator is changing this election.
            if (! in_array((int) ($exception->errorInfo[1] ?? 0), [1205, 1213], true)) {
                throw $exception;
            }

            $this->problem = __('This election is being changed by someone else right now. Please try again in a moment.');
        }

        unset($this->contests, $this->blockedContestIds, $this->groupOptions, $this->canManage);
    }

    private function admin(): AdminUser
    {
        $admin = Auth::guard('admin')->user();
        abort_unless($admin instanceof AdminUser, 403);

        return $admin;
    }

    private function loadElection(): Election
    {
        return Election::query()
            ->select(['id', 'name', 'state', 'current_config_version_id', 'locked_config_version_id'])
            ->findOrFail($this->electionId);
    }

    /** A fresh read + authorization for the CURRENT state (never a cached computed property). */
    private function manageableElection(): Election
    {
        $election = $this->loadElection();
        Gate::forUser($this->admin())->authorize('manageContests', $election);

        return $election;
    }

    private function resetForm(): void
    {
        $this->resetValidation();
        $this->name = '';
        $this->positionLabel = '';
        $this->representationGroupId = '';
        $this->seatCount = '';
        $this->selectionLimit = '';
        $this->allowAbstain = true;
        $this->votingMethod = '';
        $this->editingContestId = null;
    }
}; ?>

<div class="theme-2k26 space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <section class="min-w-0 space-y-2">
            <p class="ui-eyebrow">{{ __('Election contests') }}</p>
            <h2 class="ui-section-title break-words">{{ $this->election->name }}</h2>
            <p class="text-sm text-muted">{{ __('Define the contests (positions) of this election and how each one is voted. Seats and the maximum number of selections are separate settings. Who may vote or stand is decided in a later step.') }}</p>
        </section>
        <div class="flex flex-wrap gap-2">
            <x-ui.button :href="route('admin.elections.scope', $electionId)" wire:navigate>{{ __('Scope') }}</x-ui.button>
            <x-ui.button icon="arrow-left" :href="route('admin.elections.show', $electionId)" wire:navigate>{{ __('Election workspace') }}</x-ui.button>
        </div>
    </div>

    @if ($status)
        <x-ui.alert tone="success" wire:key="contest-status">{{ $status }}</x-ui.alert>
    @endif

    @if ($problem)
        <x-ui.alert tone="danger" wire:key="contest-problem">{{ $problem }}</x-ui.alert>
    @endif

    @if (! $this->canManage)
        <x-ui.alert tone="info">{{ __('This election is not an editable draft, so its contests are shown read-only.') }}</x-ui.alert>
    @endif

    <div @class(['grid items-start gap-6', 'xl:grid-cols-[minmax(0,1fr)_minmax(20rem,0.9fr)]' => $this->canManage])>
        @if ($this->canManage)
            <x-ui.panel :title="$editingContestId === null ? __('Create contest') : __('Edit contest')" :description="__('Enter the contest details and its voting rule. Nothing is preselected except that abstaining is allowed, which is the database default.')">
                <form wire:submit="save" class="space-y-4" novalidate>
                    <flux:field>
                        <flux:label>{{ __('Contest name') }}</flux:label>
                        <flux:input wire:model="name" maxlength="255" autocomplete="off" />
                        <flux:error name="name" />
                    </flux:field>

                    <flux:field>
                        <flux:label>{{ __('Position label') }}</flux:label>
                        <flux:input wire:model="positionLabel" maxlength="255" autocomplete="off" />
                        <flux:error name="positionLabel" />
                    </flux:field>

                    <flux:field>
                        <flux:label>{{ __('Representation group') }} <span class="font-normal text-muted">({{ __('optional') }})</span></flux:label>
                        <flux:select wire:model="representationGroupId">
                            <flux:select.option value="">{{ __('No representation group') }}</flux:select.option>
                            @foreach ($this->groupOptions as $group)
                                <flux:select.option value="{{ $group->id }}" wire:key="group-option-{{ $group->id }}">{{ $group->name }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:error name="representationGroupId" />
                    </flux:field>

                    <div class="grid gap-3 sm:grid-cols-2">
                        <flux:field>
                            <flux:label>{{ __('Seats') }}</flux:label>
                            <flux:input wire:model="seatCount" inputmode="numeric" autocomplete="off" />
                            <flux:error name="seatCount" />
                        </flux:field>
                        <flux:field>
                            <flux:label>{{ __('Maximum selections per voter') }}</flux:label>
                            <flux:input wire:model="selectionLimit" inputmode="numeric" autocomplete="off" />
                            <flux:error name="selectionLimit" />
                        </flux:field>
                    </div>

                    <flux:field>
                        <flux:label>{{ __('Voting method') }}</flux:label>
                        <flux:select wire:model="votingMethod">
                            <flux:select.option value="">{{ __('Choose a voting method') }}</flux:select.option>
                            <flux:select.option value="SINGLE_CHOICE">{{ __('Single choice') }}</flux:select.option>
                            <flux:select.option value="MULTI_CHOICE">{{ __('Multiple choice') }}</flux:select.option>
                        </flux:select>
                        <flux:error name="votingMethod" />
                    </flux:field>

                    <flux:field>
                        <flux:checkbox wire:model="allowAbstain" :label="__('Voters may abstain in this contest')" />
                        <flux:error name="allowAbstain" />
                    </flux:field>

                    <div class="flex flex-wrap gap-2">
                        <x-ui.button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="save">
                            {{ $editingContestId === null ? __('Create contest') : __('Save changes') }}
                        </x-ui.button>
                        @if ($editingContestId !== null)
                            <x-ui.button type="button" wire:click="cancelEdit">{{ __('Cancel') }}</x-ui.button>
                        @endif
                    </div>
                </form>
            </x-ui.panel>
        @endif

        <x-ui.panel :title="__('Contests')" :description="$this->canManage ? __('Contests saved for this election. A contest with dependent records cannot be deleted.') : __('Contests saved for this election.')">
            @if ($this->contests->isEmpty())
                <x-ui.empty-state icon="square-3-stack-3d" :title="__('No contests yet')" :description="$this->canManage ? __('Create the first contest of this election.') : __('No contests were configured while this election was a draft.')" />
            @else
                <div class="divide-y divide-line">
                    @foreach ($this->contests as $contest)
                        <article wire:key="contest-{{ $contest->id }}" class="space-y-3 py-4 first:pt-0 last:pb-0">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <h3 class="font-semibold break-words">{{ $contest->name }}</h3>
                                    <p class="text-xs text-muted break-words">{{ $contest->position_label }}</p>
                                </div>
                                @if ($this->canManage)
                                    <div class="flex flex-wrap gap-2">
                                        <x-ui.button type="button" size="sm" wire:click="editContest({{ $contest->id }})">{{ __('Edit') }}</x-ui.button>
                                        @if (! in_array((int) $contest->id, $this->blockedContestIds, true))
                                            <x-ui.button type="button" size="sm" wire:click="deleteContest({{ $contest->id }})" wire:confirm="{{ __('Delete this contest?') }}">{{ __('Delete') }}</x-ui.button>
                                        @endif
                                    </div>
                                @endif
                            </div>
                            <dl class="grid gap-2 text-sm sm:grid-cols-2">
                                <div>
                                    <dt class="text-xs font-semibold text-muted">{{ __('Representation group') }}</dt>
                                    <dd class="break-words">{{ $contest->group_name ?? __('None') }}</dd>
                                </div>
                                <div>
                                    <dt class="text-xs font-semibold text-muted">{{ __('Voting method') }}</dt>
                                    <dd>{{ $contest->voting_method === 'MULTI_CHOICE' ? __('Multiple choice') : ($contest->voting_method === 'SINGLE_CHOICE' ? __('Single choice') : __('Not set')) }}</dd>
                                </div>
                                <div>
                                    <dt class="text-xs font-semibold text-muted">{{ __('Seats') }}</dt>
                                    <dd>{{ $contest->seat_count ?? __('Not set') }}</dd>
                                </div>
                                <div>
                                    <dt class="text-xs font-semibold text-muted">{{ __('Maximum selections per voter') }}</dt>
                                    <dd>{{ $contest->selection_limit ?? __('Not set') }}</dd>
                                </div>
                                <div>
                                    <dt class="text-xs font-semibold text-muted">{{ __('Abstaining') }}</dt>
                                    <dd>{{ $contest->allow_abstain === null ? __('Not set') : ($contest->allow_abstain ? __('Allowed') : __('Not allowed')) }}</dd>
                                </div>
                            </dl>
                        </article>
                    @endforeach
                </div>
            @endif
        </x-ui.panel>
    </div>
</div>
