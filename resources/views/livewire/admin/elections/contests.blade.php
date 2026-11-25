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
 *
 * The form is a short guided flow: contest information, voting settings, then a read-only review of what was entered.
 * The review validates the form but saves nothing; only save() writes (through the service).
 */
new #[Layout('components.layouts.admin')] #[Title('Election contests')] class extends Component
{
    #[Locked]
    public int $electionId = 0;

    /** The contest being edited (null = creating). Set only by editContest(); the browser cannot change it. */
    #[Locked]
    public ?int $editingContestId = null;

    /** The saved name of the contest being edited, for the "Editing" heading. Set by editContest() only. */
    #[Locked]
    public ?string $editingContestName = null;

    /** True while the validated form is shown as a read-only review. Set by review() only; nothing is saved by it. */
    #[Locked]
    public bool $reviewing = false;

    /** The contest the delete dialog asks about. Set by confirmDelete() only, after it was found in this election. */
    #[Locked]
    public ?int $confirmingDeleteId = null;

    /** Opened and closed by the server (and by the dialog's own close controls). */
    public bool $showDeleteDialog = false;

    /** Server-set messages. */
    #[Locked]
    public ?string $status = null;

    #[Locked]
    public ?string $problem = null;

    public string $name = '';

    public string $positionLabel = '';

    /** '' = no representation group. */
    public string $representationGroupId = '';

    /** The number of seats. Typed in the Custom input, or set from a preset; validated on the server either way. */
    public string $seatCount = '';

    /** The maximum candidates one voter may select. Independent of $seatCount in every way. */
    public string $selectionLimit = '';

    /**
     * What the preset menu of each control shows: '' (nothing chosen), '1'..'10' (a preset) or 'custom' (the numeric
     * input is shown). A convenience for the form only: the value that is validated and saved is always $seatCount /
     * $selectionLimit, never these. The two controls never read or change each other.
     */
    public string $seatChoice = '';

    public string $selectionChoice = '';

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

    /** The contest the delete dialog is about, read fresh from this election's current version. */
    #[Computed]
    public function confirmingContest(): ?object
    {
        if ($this->confirmingDeleteId === null) {
            return null;
        }

        return $this->contests->firstWhere('id', $this->confirmingDeleteId);
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
            $this->seatChoice = $this->choiceFor($this->seatCount);
            $this->selectionChoice = $this->choiceFor($this->selectionLimit);
            $this->allowAbstain = $contest->allow_abstain === null ? true : (bool) $contest->allow_abstain;
            $this->votingMethod = (string) ($contest->voting_method ?? '');
            $this->editingContestId = (int) $contest->getKey();
            $this->editingContestName = (string) $contest->name;
            $this->reviewing = false;
            $this->dispatch('election-form-clean');
        });
    }

    /** The preset menu of the seats control changed (a browser message, so it is never trusted). */
    public function updatedSeatChoice(string $choice): void
    {
        [$this->seatChoice, $this->seatCount] = $this->applyChoice($choice, $this->seatCount);
        $this->resetErrorBag('seatCount');
    }

    /** The preset menu of the selection-limit control changed. Only its own value is touched. */
    public function updatedSelectionChoice(string $choice): void
    {
        [$this->selectionChoice, $this->selectionLimit] = $this->applyChoice($choice, $this->selectionLimit);
        $this->resetErrorBag('selectionLimit');
    }

    /** A value was set directly (not through the menu): show it as a preset when it is one, otherwise as Custom. */
    public function updatedSeatCount(): void
    {
        if ($this->seatChoice !== 'custom') {
            $this->seatChoice = $this->choiceFor($this->seatCount);
        }
    }

    public function updatedSelectionLimit(): void
    {
        if ($this->selectionChoice !== 'custom') {
            $this->selectionChoice = $this->choiceFor($this->selectionLimit);
        }
    }

    /** Validate the form and show it as a read-only summary. Nothing is created or saved here. */
    public function review(): void
    {
        $this->problem = null;
        $this->status = null;

        $this->normalizeForm();
        $this->validate($this->formRules(), $this->formMessages());

        $this->reviewing = true;
    }

    /** Leave the review and go back to the fields, with everything still filled in. */
    public function backToEdit(): void
    {
        $this->reviewing = false;
    }

    public function save(): void
    {
        $this->problem = null;
        $this->status = null;

        $this->normalizeForm();
        $this->validate($this->formRules(), $this->formMessages());

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
                $message = __('Contest created successfully.');
            } else {
                $service->updateContest($admin, $election, $this->editingContestId, $input);
                $message = __('Changes saved successfully.');
            }

            // Only now, after the service finished its transaction, is success reported.
            $this->resetForm();
            $this->notify($message);
        });
    }

    /** Open the delete dialog for one contest of this election. Deleting still happens only in deleteContest(). */
    public function confirmDelete(int $contestId): void
    {
        $this->problem = null;
        $this->status = null;

        $this->run(function () use ($contestId): void {
            $election = $this->manageableElection();
            Contest::query()->select(['id'])
                ->where('election_id', $election->getKey())
                ->where('election_config_version_id', $election->current_config_version_id)
                ->whereKey($contestId)
                ->firstOrFail();

            if (app(ElectionContestService::class)->contestIdsWithDependents([$contestId]) !== []) {
                $this->problem = ElectionContestService::MSG_IN_USE;

                return;
            }

            $this->confirmingDeleteId = $contestId;
            $this->showDeleteDialog = true;
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

            $this->notify(__('Contest deleted successfully.'));
        });

        // Whatever the outcome, the dialog closes so the result (toast or message) is what the administrator sees.
        $this->showDeleteDialog = false;
        $this->confirmingDeleteId = null;
        unset($this->confirmingContest);
    }

    public function cancelEdit(): void
    {
        $this->resetForm();
    }

    /** Success feedback: the server-confirmed message (read by screen readers) and the toast. */
    private function notify(string $message): void
    {
        $this->status = $message;
        $this->dispatch('election-notice', type: 'success', message: $message);
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

        unset($this->contests, $this->blockedContestIds, $this->groupOptions, $this->canManage, $this->confirmingContest);
    }

    /** @return array<string, list<string>> */
    private function formRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:'.ElectionContestService::MAX_NAME_LENGTH],
            'positionLabel' => ['required', 'string', 'max:'.ElectionContestService::MAX_POSITION_LABEL_LENGTH],
            'representationGroupId' => ['nullable', 'integer', 'min:1'],
            'seatCount' => ['required', 'integer', 'min:'.ElectionContestService::MIN_COUNT, 'max:'.ElectionContestService::MAX_COUNT],
            'selectionLimit' => ['required', 'integer', 'min:'.ElectionContestService::MIN_COUNT, 'max:'.ElectionContestService::MAX_COUNT],
            'allowAbstain' => ['boolean'],
            'votingMethod' => ['required', 'in:'.implode(',', ContestRule::VOTING_METHODS)],
        ];
    }

    /** @return array<string, string> */
    private function formMessages(): array
    {
        return [
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
        ];
    }

    /** What the preset menu shows for a value: nothing, the matching preset 1-10, or Custom for anything else. */
    private function choiceFor(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        return preg_match('/\A(?:[1-9]|10)\z/', $value) === 1 ? $value : 'custom';
    }

    /**
     * Apply a menu choice to one control. Returns [the choice to show, the value to keep]. A preset sets the value,
     * "nothing" clears it, and Custom shows the numeric input: it starts empty after a preset (or nothing) so that
     * no number is entered silently, and keeps a value that already is custom. Unknown choices are ignored.
     *
     * @return array{0: string, 1: string}
     */
    private function applyChoice(string $choice, string $current): array
    {
        $choice = trim($choice);

        if ($choice === '') {
            return ['', ''];
        }

        if ($choice === 'custom') {
            return ['custom', $this->choiceFor($current) === 'custom' ? $current : ''];
        }

        if (preg_match('/\A(?:[1-9]|10)\z/', $choice) === 1) {
            return [$choice, $choice];
        }

        return [$this->choiceFor($current), $current];
    }

    /** Trim what was typed; the same normalization for review and save. */
    private function normalizeForm(): void
    {
        $this->name = trim($this->name);
        $this->positionLabel = trim($this->positionLabel);
        $this->seatCount = trim($this->seatCount);
        $this->selectionLimit = trim($this->selectionLimit);
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
        $this->seatChoice = '';
        $this->selectionChoice = '';
        $this->allowAbstain = true;
        $this->votingMethod = '';
        $this->editingContestId = null;
        $this->editingContestName = null;
        $this->reviewing = false;
        $this->showDeleteDialog = false;
        $this->confirmingDeleteId = null;
        $this->dispatch('election-form-clean');
    }
}; ?>

@php
    $editing = $editingContestId !== null;
    $methodLabels = ['SINGLE_CHOICE' => __('Single choice - choose one candidate'), 'MULTI_CHOICE' => __('Multiple choice - choose more than one candidate')];
    $reviewGroup = $representationGroupId === '' ? null : $this->groupOptions->firstWhere('id', (int) $representationGroupId);
@endphp

<div class="theme-2k26 space-y-6"
     x-data="{ dirty: false, leaveMessage: @js(__('You have unsaved changes. Leave this page without saving?')), discardMessage: @js(__('Discard your unsaved changes?')), discard() { if (! this.dirty || confirm(this.discardMessage)) this.$wire.cancelEdit() }, switchMessage: @js(__('You have unsaved changes. Discard them and open this contest for editing?')), guardSwitch(event) { if (this.dirty && event.target.closest('[data-switch-record]') && ! confirm(this.switchMessage)) { event.stopPropagation(); event.preventDefault() } } }"
     x-on:click.capture="guardSwitch($event)"
     x-on:input="dirty = true"
     x-on:change="dirty = true"
     x-on:election-form-clean.window="dirty = false"
     x-on:beforeunload.window="if (dirty) { $event.preventDefault(); $event.returnValue = '' }"
     x-on:livewire:navigate.window="if (dirty && ! confirm(leaveMessage)) $event.preventDefault()">
    {{-- Header --}}
    <div class="flex flex-wrap items-start justify-between gap-4">
        <section class="min-w-0 max-w-3xl space-y-2">
            <p class="ui-eyebrow">{{ __('Contests and voting rules') }}</p>
            <h2 class="ui-section-title break-words">{{ $this->election->name }}</h2>
            <p class="text-sm text-muted">{{ __('Create one contest for each position or group of seats being filled in this election. Set the available seats and the maximum number of candidates a voter may select. Who may vote or stand is decided in a later step.') }}</p>
        </section>
        <div class="flex flex-wrap items-center gap-2">
            <x-ui.badge :tone="$this->canManage ? 'success' : 'neutral'">{{ $this->canManage ? __('Editable draft') : __('Read-only') }}</x-ui.badge>
            <x-ui.button icon="arrow-left" :href="route('admin.elections.show', $electionId)" wire:navigate>{{ __('Election workspace') }}</x-ui.button>
        </div>
    </div>

    <nav aria-label="{{ __('Election configuration') }}" class="flex flex-wrap gap-1 rounded-ui border border-line bg-surface p-1 text-sm">
        <a href="{{ route('admin.elections.show', $electionId) }}" wire:navigate class="focus-ui rounded-ui px-3 py-1.5 text-muted hover:bg-surface-subtle hover:text-ink">{{ __('Workspace') }}</a>
        <a href="{{ route('admin.elections.scope', $electionId) }}" wire:navigate class="focus-ui rounded-ui px-3 py-1.5 text-muted hover:bg-surface-subtle hover:text-ink">{{ __('Representation groups') }}</a>
        <a href="{{ route('admin.elections.contests', $electionId) }}" wire:navigate aria-current="true" class="focus-ui rounded-ui bg-[var(--ui-accent)] px-3 py-1.5 font-semibold text-[var(--ui-accent-foreground)]">{{ __('Contests') }}</a>
    </nav>

    {{-- Screen readers hear the server-confirmed result here; the toast below is the visual copy. --}}
    <div role="status" aria-live="polite" class="sr-only">{{ $status }}</div>

    @if ($problem)
        <x-ui.alert tone="danger" wire:key="contest-problem">{{ $problem }}</x-ui.alert>
    @endif

    @if (! $this->canManage)
        <x-ui.alert tone="info">{{ __('This election is not an editable draft, so its contests are shown read-only.') }}</x-ui.alert>
    @endif

    <div @class(['grid items-start gap-6', 'xl:grid-cols-[minmax(0,1.15fr)_minmax(0,1fr)]' => $this->canManage])>
        @if ($this->canManage)
            <x-ui.panel :title="$editing ? __('Edit contest') : __('Create contest')" :description="__('Describe what is being elected, then enter the voting settings confirmed for this contest.')">
                <div class="space-y-6">

                    <ol class="flex flex-wrap gap-x-5 gap-y-2 text-sm" aria-label="{{ __('Steps') }}">
                        @foreach ([__('Contest information'), __('Voting settings'), __('Review before saving')] as $step)
                            @php $current = $reviewing ? $loop->last : ! $loop->last; @endphp
                            <li class="flex items-center gap-2" aria-current="{{ $reviewing && $loop->last ? 'step' : 'false' }}">
                                <span @class(['flex size-6 items-center justify-center rounded-full text-xs font-bold', 'bg-[var(--ui-accent)] text-[var(--ui-accent-foreground)]' => $current, 'border border-line text-muted' => ! $current])>{{ $loop->iteration }}</span>
                                <span @class(['font-semibold' => $current, 'text-muted' => ! $current])>{{ $step }}</span>
                            </li>
                        @endforeach
                    </ol>

                    <div class="flex flex-wrap items-center gap-2">
                        @if ($editing)
                            <x-ui.badge tone="info">{{ __('Editing') }}</x-ui.badge>
                            <span class="text-sm break-words">{{ $editingContestName }}</span>
                        @endif
                        <span x-show="dirty" x-cloak class="inline-flex items-center gap-1 text-xs font-semibold text-warning-text">
                            <flux:icon name="exclamation-triangle" variant="mini" class="size-4" />{{ __('Unsaved changes') }}
                        </span>
                    </div>

                    @if ($reviewing)
                        {{-- Step 3: a read-only summary of what was entered. Nothing is saved until Save is chosen. --}}
                        <section class="space-y-4" aria-labelledby="contest-review">
                            <div>
                                <h3 id="contest-review" class="text-sm font-semibold">{{ __('Review before saving') }}</h3>
                                <p class="text-xs text-muted">{{ __('Check the details below. Nothing is saved until you choose Save.') }}</p>
                            </div>
                            <dl class="grid gap-x-6 gap-y-4 rounded-ui border border-line bg-surface-subtle/40 p-4 text-sm sm:grid-cols-2">
                                <div class="sm:col-span-2"><dt class="text-xs font-semibold tracking-wide text-muted uppercase">{{ __('Contest name') }}</dt><dd class="mt-1 break-words">{{ $name }}</dd></div>
                                <div class="sm:col-span-2"><dt class="text-xs font-semibold tracking-wide text-muted uppercase">{{ __('Position or office') }}</dt><dd class="mt-1 break-words">{{ $positionLabel }}</dd></div>
                                <div class="sm:col-span-2"><dt class="text-xs font-semibold tracking-wide text-muted uppercase">{{ __('Representation group') }}</dt><dd class="mt-1 break-words">{{ $reviewGroup?->name ?? __('None - this contest is not grouped') }}</dd></div>
                                <div><dt class="text-xs font-semibold tracking-wide text-muted uppercase">{{ __('Available seats') }}</dt><dd class="mt-1">{{ $seatCount }}</dd></div>
                                <div><dt class="text-xs font-semibold tracking-wide text-muted uppercase">{{ __('Maximum candidates a voter may select') }}</dt><dd class="mt-1">{{ $selectionLimit }}</dd></div>
                                <div><dt class="text-xs font-semibold tracking-wide text-muted uppercase">{{ __('How voters can choose') }}</dt><dd class="mt-1">{{ $methodLabels[$votingMethod] ?? $votingMethod }}</dd></div>
                                <div><dt class="text-xs font-semibold tracking-wide text-muted uppercase">{{ __('Abstention') }}</dt><dd class="mt-1">{{ $allowAbstain ? __('Voters may abstain') : __('Voters may not abstain') }}</dd></div>
                            </dl>
                            <div class="flex flex-wrap items-center gap-2 border-t border-line pt-4">
                                <x-ui.button type="button" variant="primary" wire:click="save" wire:loading.attr="disabled" wire:target="save">
                                    <span wire:loading.remove wire:target="save">{{ $editing ? __('Save changes') : __('Save contest') }}</span>
                                    <span wire:loading wire:target="save" class="inline-flex items-center gap-2"><flux:icon name="loading" variant="micro" class="size-4" />{{ __('Saving...') }}</span>
                                </x-ui.button>
                                <x-ui.button type="button" icon="pencil-square" wire:click="backToEdit" wire:loading.attr="disabled" wire:target="save">{{ __('Edit') }}</x-ui.button>
                                @if ($editing)
                                    <x-ui.button type="button" variant="ghost" x-on:click="discard()">{{ __('Cancel') }}</x-ui.button>
                                @endif
                            </div>
                        </section>
                    @else
                        <form wire:submit="review" class="space-y-6" novalidate>
                            <section class="space-y-3" aria-labelledby="contest-details">
                                <div>
                                    <h3 id="contest-details" class="text-sm font-semibold">{{ __('Contest details') }}</h3>
                                    <p class="text-xs text-muted">{{ __('Start with the name and position being elected.') }}</p>
                                </div>
                                <flux:field>
                                    <flux:label>{{ __('Contest name') }}</flux:label>
                                    <flux:input wire:model="name" maxlength="255" autocomplete="off" placeholder="{{ __('Enter a name for this contest') }}" />
                                    <p class="mt-1 text-xs text-muted">{{ __('A name administrators can use to recognize this contest. It is saved exactly as you type it.') }}</p>
                                    <flux:error name="name" />
                                </flux:field>

                                <flux:field>
                                    <flux:label>{{ __('Position or office') }}</flux:label>
                                    <flux:input wire:model="positionLabel" maxlength="255" autocomplete="off" placeholder="{{ __('Enter the position title') }}" />
                                    <p class="mt-1 text-xs text-muted">{{ __('The position or office that will be filled through this contest. It is saved separately from the contest name.') }}</p>
                                    <flux:error name="positionLabel" />
                                </flux:field>

                                <flux:field>
                                    <flux:label>{{ __('Representation group') }} <span class="font-normal text-muted">({{ __('optional') }})</span></flux:label>
                                    <flux:select wire:model="representationGroupId">
                                        <flux:select.option value="">{{ __('No group - this contest is not grouped') }}</flux:select.option>
                                        @foreach ($this->groupOptions as $group)
                                            <flux:select.option value="{{ $group->id }}" wire:key="group-option-{{ $group->id }}">{{ $group->name }}</flux:select.option>
                                        @endforeach
                                    </flux:select>
                                    <p class="mt-1 text-xs text-muted">{{ __('Connects this contest to a group created on the Representation groups page. This organizes the contest; it does not determine eligibility.') }}</p>
                                    <flux:error name="representationGroupId" />
                                </flux:field>
                            </section>

                            <section class="space-y-3 border-t border-line pt-5" aria-labelledby="contest-voting">
                                <div>
                                    <h3 id="contest-voting" class="text-sm font-semibold">{{ __('Voting settings') }}</h3>
                                    <p class="text-xs text-muted">{{ __('Use the voting rules approved for this contest.') }}</p>
                                </div>

                                <div class="grid gap-3 sm:grid-cols-2">
                                    <div class="rounded-ui border border-line bg-surface-subtle/40 p-3">
                                        <flux:field>
                                            <flux:label>{{ __('Available seats') }}</flux:label>
                                            <flux:select wire:model.live="seatChoice">
                                                <flux:select.option value="">{{ __('Choose a number') }}</flux:select.option>
                                                @foreach (range(1, 10) as $preset)
                                                    <flux:select.option value="{{ $preset }}" wire:key="seatChoice-{{ $preset }}">{{ $preset }}</flux:select.option>
                                                @endforeach
                                                <flux:select.option value="custom">{{ __('Custom number...') }}</flux:select.option>
                                            </flux:select>
                                            @if ($seatChoice === 'custom')
                                                <div class="mt-3">
                                                    <flux:label>{{ __('Custom number of available seats') }}</flux:label>
                                                    <flux:input wire:model="seatCount" inputmode="numeric" autocomplete="off" aria-label="{{ __('Custom number of available seats') }}" placeholder="{{ __('Whole number from 1 to 100') }}" />
                                                </div>
                                            @endif
                                            <p class="mt-1 text-xs text-muted">{{ __('How many seats are being filled in this contest?') }} {{ __('Choose a number from 1 to 10, or Custom for any whole number from 1 to 100.') }}</p>
                                            <flux:error name="seatCount" />
                                        </flux:field>
                                    </div>
                                    <div class="rounded-ui border border-line bg-surface-subtle/40 p-3">
                                        <flux:field>
                                            <flux:label>{{ __('Maximum candidates a voter may select') }}</flux:label>
                                            <flux:select wire:model.live="selectionChoice">
                                                <flux:select.option value="">{{ __('Choose a number') }}</flux:select.option>
                                                @foreach (range(1, 10) as $preset)
                                                    <flux:select.option value="{{ $preset }}" wire:key="selectionChoice-{{ $preset }}">{{ $preset }}</flux:select.option>
                                                @endforeach
                                                <flux:select.option value="custom">{{ __('Custom number...') }}</flux:select.option>
                                            </flux:select>
                                            @if ($selectionChoice === 'custom')
                                                <div class="mt-3">
                                                    <flux:label>{{ __('Custom maximum number of selections') }}</flux:label>
                                                    <flux:input wire:model="selectionLimit" inputmode="numeric" autocomplete="off" aria-label="{{ __('Custom maximum number of selections') }}" placeholder="{{ __('Whole number from 1 to 100') }}" />
                                                </div>
                                            @endif
                                            <p class="mt-1 text-xs text-muted">{{ __('How many candidates one voter may choose in this contest.') }} {{ __('Choose a number from 1 to 10, or Custom for any whole number from 1 to 100.') }}</p>
                                            <flux:error name="selectionLimit" />
                                        </flux:field>
                                    </div>
                                </div>
                                <p class="flex items-start gap-2 text-xs text-muted">
                                    <flux:icon name="information-circle" variant="micro" class="mt-0.5 size-4 shrink-0" />
                                    <span>{{ __('These are two separate settings. Available seats is how many people will be elected in this contest; the maximum selection limit is how many candidates one voter may choose. The system does not calculate one from the other. Enter the values from the approved voting rules.') }}</span>
                                </p>

                                <flux:radio.group wire:model="votingMethod" variant="cards" :label="__('How voters can choose')" class="max-sm:flex-col">
                                    <flux:radio value="SINGLE_CHOICE" :label="__('Single choice - choose one candidate')" />
                                    <flux:radio value="MULTI_CHOICE" :label="__('Multiple choice - choose more than one candidate')" />
                                </flux:radio.group>
                                <p class="text-xs text-muted">{{ __('The maximum selection limit is recorded separately. Follow the voting rules approved for this contest.') }}</p>
                                <flux:error name="votingMethod" />

                                <flux:field>
                                    <flux:checkbox wire:model="allowAbstain" :label="__('Allow voters to abstain')" />
                                    <p class="mt-1 text-xs text-muted">{{ __('When enabled, a voter may choose not to select a candidate in this contest.') }}</p>
                                    <flux:error name="allowAbstain" />
                                </flux:field>
                            </section>

                            <div class="flex flex-wrap items-center gap-2 border-t border-line pt-4">
                                <x-ui.button type="submit" variant="primary" icon-trailing="arrow-right" wire:loading.attr="disabled" wire:target="review">
                                    {{ __('Review contest') }}
                                </x-ui.button>
                                @if ($editing)
                                    <x-ui.button type="button" x-on:click="discard()">{{ __('Cancel') }}</x-ui.button>
                                @endif
                                <span class="text-xs text-muted">{{ __('You will see a summary before anything is saved.') }}</span>
                            </div>
                        </form>
                    @endif
                </div>
            </x-ui.panel>
        @endif

        {{-- Saved contests --}}
        <x-ui.panel :title="__('Contests')" :description="$this->canManage ? __('Saved contests and their voting settings. A contest with dependent records cannot be deleted.') : __('Saved contests and their voting settings.')">
            @if ($this->contests->isEmpty())
                <x-ui.empty-state icon="square-3-stack-3d" :title="__('No contests yet')" :description="$this->canManage ? __('Create the first contest of this election.') : __('No contests were configured while this election was a draft.')" />
            @else
                <ul class="space-y-3">
                    @foreach ($this->contests as $contest)
                        @php $blocked = in_array((int) $contest->id, $this->blockedContestIds, true); @endphp
                        <li wire:key="contest-{{ $contest->id }}">
                            <article @class(['space-y-4 rounded-ui border bg-surface-subtle/40 p-4', 'border-[var(--ui-accent)]' => $editingContestId === (int) $contest->id, 'border-line' => $editingContestId !== (int) $contest->id])>
                                <div class="flex flex-wrap items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <h3 class="font-semibold break-words">{{ $contest->name }}</h3>
                                        <p class="text-sm text-muted break-words">{{ $contest->position_label }}</p>
                                        @if ($editingContestId === (int) $contest->id)<x-ui.badge tone="warning" class="mt-1">{{ __('Being edited') }}</x-ui.badge>@endif
                                    </div>
                                    @if ($this->canManage)
                                        <div class="flex flex-wrap gap-2">
                                            <x-ui.button type="button" size="sm" icon="pencil-square" wire:click="editContest({{ $contest->id }})" data-switch-record>{{ __('Edit') }}</x-ui.button>
                                            @if (! $blocked)
                                                <x-ui.button type="button" size="sm" icon="trash" wire:click="confirmDelete({{ $contest->id }})">{{ __('Delete') }}</x-ui.button>
                                            @endif
                                        </div>
                                    @endif
                                </div>
                                <dl class="grid gap-3 text-sm sm:grid-cols-2">
                                    <div>
                                        <dt class="text-xs font-semibold tracking-wide text-muted uppercase">{{ __('Representation group') }}</dt>
                                        <dd class="mt-0.5 break-words">{{ $contest->group_name ?? __('None') }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-xs font-semibold tracking-wide text-muted uppercase">{{ __('Voting method') }}</dt>
                                        <dd class="mt-0.5">{{ $contest->voting_method === 'MULTI_CHOICE' ? __('Multiple choice') : ($contest->voting_method === 'SINGLE_CHOICE' ? __('Single choice') : __('Not set')) }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-xs font-semibold tracking-wide text-muted uppercase">{{ __('Available seats') }}</dt>
                                        <dd class="mt-0.5">{{ $contest->seat_count ?? __('Not set') }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-xs font-semibold tracking-wide text-muted uppercase">{{ __('Max. candidate selections') }}</dt>
                                        <dd class="mt-0.5">{{ $contest->selection_limit ?? __('Not set') }}</dd>
                                    </div>
                                    <div>
                                        <dt class="text-xs font-semibold tracking-wide text-muted uppercase">{{ __('Abstention allowed') }}</dt>
                                        <dd class="mt-0.5">{{ $contest->allow_abstain === null ? __('Not set') : ($contest->allow_abstain ? __('Allowed') : __('Not allowed')) }}</dd>
                                    </div>
                                </dl>
                                @if ($this->canManage && $blocked)
                                    <p class="flex items-start gap-2 text-xs text-muted">
                                        <flux:icon name="lock-closed" variant="micro" class="mt-0.5 size-3.5 shrink-0" />
                                        {{ __('This contest has dependent records, so it cannot be deleted. You can still edit it.') }}
                                    </p>
                                @endif
                            </article>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-ui.panel>
    </div>

    {{-- Delete confirmation --}}
    @if ($this->canManage)
        <flux:modal wire:model.self="showDeleteDialog" class="max-w-md bg-surface! text-ink! border! border-line!">
            @if ($this->confirmingContest)
                <div class="space-y-4">
                    <div class="space-y-2">
                        <h3 class="ui-section-title">{{ __('Delete this contest?') }}</h3>
                        <p class="text-sm">{{ __('You are about to delete:') }} <strong class="break-words">{{ $this->confirmingContest->name }}</strong></p>
                        <p class="text-sm text-muted">{{ __('This cannot be undone. Deletion is blocked when the contest already has dependent records; in that case nothing is deleted and you will be told why.') }}</p>
                    </div>
                    <div class="flex flex-wrap justify-end gap-2">
                        <flux:modal.close><x-ui.button type="button">{{ __('Keep contest') }}</x-ui.button></flux:modal.close>
                        <x-ui.button type="button" variant="danger" wire:click="deleteContest({{ $this->confirmingContest->id }})" wire:loading.attr="disabled" wire:target="deleteContest">
                            <span wire:loading.remove wire:target="deleteContest">{{ __('Delete contest') }}</span>
                            <span wire:loading wire:target="deleteContest">{{ __('Deleting...') }}</span>
                        </x-ui.button>
                    </div>
                </div>
            @endif
        </flux:modal>
    @endif

    {{-- Toast (visual copy of the status above; it dismisses itself, and the message stays available to screen readers) --}}
    <div wire:ignore aria-hidden="true" class="pointer-events-none fixed inset-x-4 bottom-4 z-50 flex flex-col items-center gap-2 sm:inset-x-auto sm:right-6 sm:items-end"
         x-data="{ toasts: [], add(detail) { const d = Array.isArray(detail) ? detail[0] : detail; if (! d || ! d.message) return; const id = Date.now() + Math.random(); this.toasts.push({ id, message: d.message }); setTimeout(() => this.remove(id), 6000) }, remove(id) { this.toasts = this.toasts.filter((t) => t.id !== id) } }"
         x-on:election-notice.window="add($event.detail)">
        <template x-for="toast in toasts" :key="toast.id">
            <div x-data="{ show: false }" x-init="$nextTick(() => show = true)" x-show="show"
                 x-transition:enter="transition duration-200 ease-out motion-reduce:duration-0" x-transition:enter-start="translate-y-2 opacity-0 motion-reduce:translate-y-0" x-transition:enter-end="translate-y-0 opacity-100"
                 class="pointer-events-auto flex max-w-sm items-start gap-3 rounded-ui border border-success-border bg-success-bg p-3 text-sm text-success-text shadow-lg">
                <flux:icon name="check-circle" variant="mini" class="mt-0.5 size-5 shrink-0" />
                <p class="min-w-0 break-words" x-text="toast.message"></p>
                <button type="button" tabindex="-1" class="ml-1 shrink-0 opacity-70 hover:opacity-100" x-on:click="remove(toast.id)"><flux:icon name="x-mark" variant="micro" class="size-4" /></button>
            </div>
        </template>
    </div>
</div>
