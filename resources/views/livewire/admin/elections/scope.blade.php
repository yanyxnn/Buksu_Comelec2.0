<?php

use App\Models\AdminUser;
use App\Models\Election;
use App\Models\RepresentationGroup;
use App\Services\Election\ElectionScopeService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

/*
 * Phase 04B: an election's scope = its representation groups (named sets of administrator-configured dimensions).
 * Any authorized administrator can READ it in any election state; creating, editing and deleting groups is offered only
 * while the election is an editable draft (ElectionPolicy::manageScope), and every action re-checks that on the server.
 * Groups organize contests; they are NOT voter or candidate eligibility rules. Writes go only through
 * ElectionScopeService. Only ids are kept in component state, and the ids are #[Locked].
 *
 * The values of a category are entered one at a time and shown as removable tags. They are still kept in the form as
 * one text per category (one value per line), so the saved scope_definition_json is unchanged: an object of internal
 * category key to a list of text values. Nothing here is saved until the administrator saves the group.
 */
new #[Layout('components.layouts.admin')] #[Title('Election scope')] class extends Component
{
    #[Locked]
    public int $electionId = 0;

    /** The group being edited (null = creating). Set only by editGroup(); the browser cannot change it. */
    #[Locked]
    public ?int $editingGroupId = null;

    /** Server-set messages. */
    #[Locked]
    public ?string $status = null;

    #[Locked]
    public ?string $problem = null;

    /** The saved name of the group being edited, for the "Editing" heading. Set by editGroup() only. */
    #[Locked]
    public ?string $editingGroupName = null;

    /** The group the delete dialog is asking about. Set by confirmDelete() only, after the group was found in this election. */
    #[Locked]
    public ?int $confirmingDeleteId = null;

    /** Opened and closed by the server (and by the dialog's own close controls). */
    public bool $showDeleteDialog = false;

    public string $name = '';

    /** @var array<int, array{key: string, values: string}> values = one value per line */
    public array $dimensions = [['key' => '', 'values' => '']];

    /** The value being typed for each category (by row position); added to the tags with Add or Enter. @var array<int, string> */
    public array $drafts = [''];

    /**
     * Readable label => stored internal key, set only when a saved group is opened for editing. It lets a stored key
     * that its readable label cannot reproduce exactly (for example one with a double underscore) be saved back
     * unchanged. Not writable from the browser.
     *
     * @var array<string, string>
     */
    #[Locked]
    public array $storedKeys = [];

    public function mount(Election $election): void
    {
        Gate::forUser($this->admin())->authorize('viewScope', $election);

        $this->electionId = (int) $election->getKey();
    }

    #[Computed]
    public function election(): Election
    {
        $election = $this->loadElection();
        Gate::forUser($this->admin())->authorize('viewScope', $election);

        return $election;
    }

    /** Whether the administrator may change scope right now (re-evaluated on every render). */
    #[Computed]
    public function canManage(): bool
    {
        return Gate::forUser($this->admin())->allows('manageScope', $this->election);
    }

    #[Computed]
    public function groups()
    {
        return RepresentationGroup::query()
            ->select(['representation_groups.id', 'representation_groups.name', 'representation_groups.scope_definition_json'])
            ->selectSub(
                DB::table('contests')
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('contests.representation_group_id', 'representation_groups.id')
                    ->whereColumn('contests.election_id', 'representation_groups.election_id'),
                'contests_count',
            )
            ->where('representation_groups.election_id', $this->electionId)
            ->orderBy('representation_groups.name')
            ->orderBy('representation_groups.id')
            ->get();
    }

    /** The group the delete dialog is about (name + how many contests use it), read fresh from this election. */
    #[Computed]
    public function confirmingGroup(): ?object
    {
        if ($this->confirmingDeleteId === null) {
            return null;
        }

        return $this->groups->firstWhere('id', $this->confirmingDeleteId);
    }

    public function addDimension(): void
    {
        $this->problem = null;

        if (count($this->dimensions) >= ElectionScopeService::MAX_DIMENSIONS) {
            $this->problem = __('A group can have at most :max categories.', ['max' => ElectionScopeService::MAX_DIMENSIONS]);

            return;
        }

        $this->dimensions[] = ['key' => '', 'values' => ''];
        $this->drafts[count($this->dimensions) - 1] = '';
    }

    public function removeDimension(int $index): void
    {
        if (! array_key_exists($index, $this->dimensions)) {
            return;
        }

        unset($this->dimensions[$index]);
        $this->dimensions = array_values($this->dimensions);

        if ($this->dimensions === []) {
            $this->dimensions = [['key' => '', 'values' => '']];
        }

        // Typed-but-not-added values follow their categories to their new positions.
        $drafts = [];
        foreach (array_keys($this->dimensions) as $position) {
            $drafts[$position] = $this->draftText($position >= $index ? $position + 1 : $position);
        }
        $this->drafts = $drafts;
        $this->resetErrorBag();
    }

    /** Add the value typed for one category to its tags (the Add button and the Enter key). */
    public function addValue(int $index): void
    {
        $this->problem = null;

        if (array_key_exists($index, $this->dimensions)) {
            $this->commitDraft($index);
        }
    }

    public function removeValue(int $index, int $valueIndex): void
    {
        $this->problem = null;
        $values = $this->valueList($index);

        if (! array_key_exists($valueIndex, $values)) {
            return;
        }

        unset($values[$valueIndex]);
        $this->dimensions[$index]['values'] = implode("\n", array_values($values));
        $this->resetErrorBag();
    }

    /** Move one saved tag back into the input so it can be corrected. */
    public function editValue(int $index, int $valueIndex): void
    {
        $this->problem = null;
        $values = $this->valueList($index);

        if (! array_key_exists($valueIndex, $values)) {
            return;
        }

        if (trim($this->draftText($index)) !== '') {
            $this->addError("drafts.{$index}", __('Add or clear the value you are typing before editing another one.'));

            return;
        }

        $this->resetErrorBag();
        $this->drafts[$index] = $values[$valueIndex];
        unset($values[$valueIndex]);
        $this->dimensions[$index]['values'] = implode("\n", array_values($values));
    }

    public function editGroup(int $groupId): void
    {
        $this->problem = null;
        $this->status = null;

        $this->run(function () use ($groupId): void {
            $election = $this->manageableElection();
            $group = RepresentationGroup::query()
                ->select(['id', 'name', 'scope_definition_json'])
                ->where('election_id', $election->getKey())
                ->whereKey($groupId)
                ->firstOrFail();

            // Saved keys are shown as readable category names. $storedKeys remembers which stored key each label came
            // from, so saving without touching a category keeps its stored key exactly as it was.
            $rows = [];
            $storedKeys = [];
            foreach ((array) $group->scope_definition_json as $key => $values) {
                $key = (string) $key;
                $label = Str::headline($key);
                $label = array_key_exists($label, $storedKeys) ? $key : $label; // two keys with the same readable form: keep them apart
                $storedKeys[$label] = $key;
                $rows[] = ['key' => $label, 'values' => implode("\n", array_map('strval', (array) $values))];
            }

            $this->resetValidation();
            $this->name = (string) $group->name;
            $this->dimensions = $rows === [] ? [['key' => '', 'values' => '']] : $rows;
            $this->drafts = array_fill(0, count($this->dimensions), '');
            $this->storedKeys = $storedKeys;
            $this->editingGroupId = (int) $group->getKey();
            $this->editingGroupName = (string) $group->name;
            $this->dispatch('election-form-clean');
        });
    }

    public function save(): void
    {
        $this->problem = null;
        $this->status = null;

        $this->normalizeForm();

        // A value that was typed but not yet added with Add/Enter is added now, so nothing typed is lost.
        $pending = false;
        foreach (array_keys($this->dimensions) as $index) {
            if (trim($this->draftText($index)) !== '' && ! $this->commitDraft($index)) {
                $pending = true;
            }
        }

        if ($pending) {
            return;
        }

        // The category names are validated through their generated internal keys (which is what is saved), while the
        // fields keep showing what the administrator typed.
        Validator::make(
            ['name' => $this->name, 'dimensions' => $this->derivedRows()],
            [
                'name' => ['required', 'string', 'max:'.ElectionScopeService::MAX_GROUP_NAME_LENGTH],
                'dimensions' => ['required', 'array', 'min:1', 'max:'.ElectionScopeService::MAX_DIMENSIONS],
                'dimensions.*.key' => ['required', 'string', 'max:'.ElectionScopeService::MAX_DIMENSION_KEY_LENGTH, 'regex:/\A[a-z][a-z0-9_]*\z/', 'distinct'],
                'dimensions.*.values' => ['required', 'string'],
            ],
            [
                'name.required' => __('Enter a group name.'),
                'name.max' => __('The group name may have at most :max characters.', ['max' => ElectionScopeService::MAX_GROUP_NAME_LENGTH]),
                'dimensions.max' => __('A group can have at most :max categories.', ['max' => ElectionScopeService::MAX_DIMENSIONS]),
                'dimensions.*.key.required' => __('Enter a category name using letters or numbers.'),
                'dimensions.*.key.max' => __('A category name may have at most :max characters.', ['max' => ElectionScopeService::MAX_DIMENSION_KEY_LENGTH]),
                'dimensions.*.key.regex' => __('Enter a category name using letters and numbers.'),
                'dimensions.*.key.distinct' => __('Each category name must be unique within this group.'),
                'dimensions.*.values.required' => __('Add at least one value to this category.'),
            ],
        )->validate();

        $this->run(function (): void {
            $admin = $this->admin();
            $election = $this->manageableElection();
            $definition = $this->scopeDefinitionFromForm();
            $service = app(ElectionScopeService::class);

            if ($this->editingGroupId === null) {
                $service->createGroup($admin, $election, $this->name, $definition);
                $message = __('Representation group created successfully.');
            } else {
                $service->updateGroup($admin, $election, $this->editingGroupId, $this->name, $definition);
                $message = __('Changes saved successfully.');
            }

            // Only now, after the service finished its transaction, is success reported.
            $this->resetForm();
            $this->notify($message);
        });
    }

    /** Open the delete dialog for one group of this election. Deleting still happens only in deleteGroup(). */
    public function confirmDelete(int $groupId): void
    {
        $this->problem = null;
        $this->status = null;

        $this->run(function () use ($groupId): void {
            $election = $this->manageableElection();
            RepresentationGroup::query()->select(['id'])->where('election_id', $election->getKey())->whereKey($groupId)->firstOrFail();

            $inUse = DB::table('contests')->where('election_id', $election->getKey())->where('representation_group_id', $groupId)->exists();

            if ($inUse) {
                $this->problem = ElectionScopeService::MSG_IN_USE;

                return;
            }

            $this->confirmingDeleteId = $groupId;
            $this->showDeleteDialog = true;
        });
    }

    public function deleteGroup(int $groupId): void
    {
        $this->problem = null;
        $this->status = null;

        $this->run(function () use ($groupId): void {
            $admin = $this->admin();
            $election = $this->manageableElection();

            app(ElectionScopeService::class)->deleteGroup($admin, $election, $groupId);

            if ($this->editingGroupId === $groupId) {
                $this->resetForm();
            }

            $this->notify(__('Representation group deleted successfully.'));
        });

        // Whatever the outcome, the dialog closes so the result (toast or message) is what the administrator sees.
        $this->showDeleteDialog = false;
        $this->confirmingDeleteId = null;
        unset($this->confirmingGroup);
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
     * invalid input / group in use / duplicate name, the election no longer being editable, a group that is gone.
     */
    private function run(Closure $action): void
    {
        try {
            $action();
        } catch (InvalidArgumentException $exception) {
            $this->problem = $exception->getMessage();
        } catch (AuthorizationException) {
            $this->problem = ElectionScopeService::MSG_NOT_EDITABLE;
            $this->resetForm();
        } catch (ModelNotFoundException) {
            $this->problem = __('That representation group no longer exists. The list has been refreshed.');
            $this->resetForm();
        } catch (QueryException $exception) {
            // MySQL/MariaDB 1205 = lock wait timeout, 1213 = deadlock: another administrator is changing this election.
            if (! in_array((int) ($exception->errorInfo[1] ?? 0), [1205, 1213], true)) {
                throw $exception;
            }

            $this->problem = __('This election is being changed by someone else right now. Please try again in a moment.');
        }

        unset($this->groups, $this->canManage, $this->confirmingGroup);
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
        Gate::forUser($this->admin())->authorize('manageScope', $election);

        return $election;
    }

    /** Make the submitted rows predictable before validating: trimmed category names, text-only cells, bounded size. */
    private function normalizeForm(): void
    {
        $rows = [];

        foreach (array_values($this->dimensions) as $row) {
            $row = is_array($row) ? $row : [];
            $rows[] = [
                'key' => is_string($row['key'] ?? null) ? trim($row['key']) : '',
                'values' => is_string($row['values'] ?? null) ? $row['values'] : '',
            ];
        }

        $this->dimensions = array_slice($rows, 0, ElectionScopeService::MAX_DIMENSIONS + 1);
    }

    /**
     * The rows with each category name turned into its internal key (what is validated and saved).
     *
     * @return list<array{key: string, values: string}>
     */
    private function derivedRows(): array
    {
        return array_map(
            fn (array $row) => ['key' => $this->internalKey($row['key']), 'values' => $row['values']],
            array_values($this->dimensions),
        );
    }

    /**
     * A readable category name becomes a lowercase underscore key ("Representation Type" -> representation_type).
     * A label that came from a saved key keeps that key. Keys must start with a letter, so a name that starts with a
     * digit gets a "category_" prefix. An empty result means the name had no letters or numbers.
     */
    private function internalKey(string $label): string
    {
        $label = trim($label);
        $key = $this->storedKeys[$label] ?? Str::slug($label, '_');

        if ($key !== '' && preg_match('/\A[a-z]/', $key) !== 1) {
            $key = 'category_'.$key;
        }

        return $key;
    }

    /** @return array<string, list<string>> */
    private function scopeDefinitionFromForm(): array
    {
        $scope = [];

        foreach ($this->derivedRows() as $row) {
            $key = $row['key'];

            if (array_key_exists($key, $scope)) {
                throw new InvalidArgumentException("The category [{$key}] appears more than once.");
            }

            $lines = preg_split('/\r\n|\r|\n/', $row['values']) ?: [];
            $values = array_values(array_filter(array_map('trim', $lines), fn (string $value) => $value !== ''));

            if ($values === []) {
                throw new InvalidArgumentException("Add at least one value for the [{$key}] category.");
            }

            $scope[$key] = $values;
        }

        return $scope;
    }

    private function resetForm(): void
    {
        $this->resetValidation();
        $this->name = '';
        $this->dimensions = [['key' => '', 'values' => '']];
        $this->drafts = [''];
        $this->storedKeys = [];
        $this->editingGroupId = null;
        $this->editingGroupName = null;
        $this->showDeleteDialog = false;
        $this->confirmingDeleteId = null;
        $this->dispatch('election-form-clean');
    }

    /** What is typed in the value input of one category (text only, whatever the browser sent). */
    private function draftText(int $index): string
    {
        $draft = $this->drafts[$index] ?? '';

        return is_string($draft) ? $draft : '';
    }

    /** The values of one category, one per line in the form, as a list. @return list<string> */
    private function valueList(int $index): array
    {
        $text = $this->dimensions[$index]['values'] ?? '';
        $lines = preg_split('/\r\n|\r|\n/', is_string($text) ? $text : '') ?: [];

        return array_values(array_filter(array_map('trim', $lines), fn (string $value) => $value !== ''));
    }

    /**
     * Add what was typed for one category to its values. A pasted block with several lines adds one value per line.
     * Returns false (with a message on the input) when nothing valid was added.
     */
    private function commitDraft(int $index): bool
    {
        $this->resetErrorBag("drafts.{$index}");

        $lines = preg_split('/\r\n|\r|\n/', $this->draftText($index)) ?: [];
        $candidates = array_values(array_filter(
            array_map(fn (string $line) => (string) preg_replace('/\A[\s\p{Z}]+|[\s\p{Z}]+\z/u', '', $line), $lines),
            fn (string $value) => $value !== '',
        ));

        if ($candidates === []) {
            $this->addError("drafts.{$index}", __('Type a value first, then choose Add.'));

            return false;
        }

        $values = $this->valueList($index);
        $added = 0;

        foreach ($candidates as $candidate) {
            if (! mb_check_encoding($candidate, 'UTF-8') || preg_match('/[\p{Cc}\p{Cf}]/u', $candidate) === 1) {
                $this->addError("drafts.{$index}", __('A value cannot contain control characters.'));

                return false;
            }

            if (mb_strlen($candidate) > ElectionScopeService::MAX_DIMENSION_VALUE_LENGTH) {
                $this->addError("drafts.{$index}", __('A value may have at most :max characters.', ['max' => ElectionScopeService::MAX_DIMENSION_VALUE_LENGTH]));

                return false;
            }

            if (in_array($candidate, $values, true)) {
                continue; // exact duplicates are ignored, like the service does
            }

            if (count($values) >= ElectionScopeService::MAX_VALUES_PER_DIMENSION) {
                $this->addError("drafts.{$index}", __('A category can have at most :max values.', ['max' => ElectionScopeService::MAX_VALUES_PER_DIMENSION]));

                return false;
            }

            $values[] = $candidate;
            $added++;
        }

        if ($added === 0) {
            $this->addError("drafts.{$index}", __('That value is already in this category.'));

            return false;
        }

        $this->dimensions[$index]['values'] = implode("\n", $values);
        $this->drafts[$index] = '';

        return true;
    }
}; ?>

@php
    $chips = fn (?string $values): array => array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', (string) $values) ?: []), fn (string $v) => $v !== ''));
    $editing = $editingGroupId !== null;
@endphp

<div class="theme-2k26 space-y-6"
     x-data="{ dirty: false, leaveMessage: @js(__('You have unsaved changes. Leave this page without saving?')), discardMessage: @js(__('Discard your unsaved changes?')), discard() { if (! this.dirty || confirm(this.discardMessage)) this.$wire.cancelEdit() }, switchMessage: @js(__('You have unsaved changes. Discard them and open this group for editing?')), guardSwitch(event) { if (this.dirty && event.target.closest('[data-switch-record]') && ! confirm(this.switchMessage)) { event.stopPropagation(); event.preventDefault() } } }"
     x-on:click.capture="guardSwitch($event)"
     x-on:input="dirty = true"
     x-on:click="if ($event.target.closest('[data-dirty-action]')) dirty = true"
     x-on:election-form-clean.window="dirty = false"
     x-on:beforeunload.window="if (dirty) { $event.preventDefault(); $event.returnValue = '' }"
     x-on:livewire:navigate.window="if (dirty && ! confirm(leaveMessage)) $event.preventDefault()">
    {{-- Header --}}
    <div class="flex flex-wrap items-start justify-between gap-4">
        <section class="min-w-0 max-w-3xl space-y-2">
            <p class="ui-eyebrow">{{ __('Representation groups') }}</p>
            <h2 class="ui-section-title break-words">{{ $this->election->name }}</h2>
            <p class="text-sm text-muted">{{ __('Use groups to organize the contests in this election. Groups organize contests only; they do not decide who may vote or stand. Eligibility is configured separately.') }}</p>
        </section>
        <div class="flex flex-wrap items-center gap-2">
            <x-ui.badge :tone="$this->canManage ? 'success' : 'neutral'">{{ $this->canManage ? __('Editable draft') : __('Read-only') }}</x-ui.badge>
            <x-ui.button icon="arrow-left" :href="route('admin.elections.show', $electionId)" wire:navigate>{{ __('Election workspace') }}</x-ui.button>
        </div>
    </div>

    <nav aria-label="{{ __('Election configuration') }}" class="flex flex-wrap gap-1 rounded-ui border border-line bg-surface p-1 text-sm">
        <a href="{{ route('admin.elections.show', $electionId) }}" wire:navigate class="focus-ui rounded-ui px-3 py-1.5 text-muted hover:bg-surface-subtle hover:text-ink">{{ __('Workspace') }}</a>
        <a href="{{ route('admin.elections.scope', $electionId) }}" wire:navigate aria-current="true" class="focus-ui rounded-ui bg-[var(--ui-accent)] px-3 py-1.5 font-semibold text-[var(--ui-accent-foreground)]">{{ __('Representation groups') }}</a>
        <a href="{{ route('admin.elections.contests', $electionId) }}" wire:navigate class="focus-ui rounded-ui px-3 py-1.5 text-muted hover:bg-surface-subtle hover:text-ink">{{ __('Contests') }}</a>
    </nav>

    {{-- Screen readers hear the server-confirmed result here; the toast below is the visual copy. --}}
    <div role="status" aria-live="polite" class="sr-only">{{ $status }}</div>

    @if ($problem)
        <x-ui.alert tone="danger" wire:key="scope-problem">{{ $problem }}</x-ui.alert>
    @endif

    @if (! $this->canManage)
        <x-ui.alert tone="info">{{ __('This election is not an editable draft, so its scope is shown read-only.') }}</x-ui.alert>
    @endif

    {{-- How it works --}}
    <section aria-labelledby="scope-steps">
        <h3 id="scope-steps" class="sr-only">{{ __('How this works') }}</h3>
        <ol class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            @foreach ([
                __('Give the representation group a recognizable name.'),
                __('Add the categories that describe the group.'),
                __('Add the values that belong to each category.'),
                __('Assign the group to related contests later.'),
            ] as $step)
                <li class="flex items-start gap-3 rounded-ui border border-line bg-surface p-3 text-sm">
                    <span class="flex size-6 shrink-0 items-center justify-center rounded-full bg-[var(--ui-accent)] text-xs font-bold text-[var(--ui-accent-foreground)]" aria-hidden="true">{{ $loop->iteration }}</span>
                    <span class="text-muted"><span class="sr-only">{{ __('Step :n:', ['n' => $loop->iteration]) }} </span>{{ $step }}</span>
                </li>
            @endforeach
        </ol>
        <p class="mt-3 flex items-start gap-2 text-sm text-muted">
            <flux:icon name="information-circle" variant="mini" class="mt-0.5 size-4 shrink-0" />
            <span>{{ __('Only enter categories and values confirmed for this election. Representation groups organize contests; they do not set voter or candidate eligibility.') }}</span>
        </p>
    </section>

    <div @class(['grid items-start gap-6', 'xl:grid-cols-[minmax(0,1.15fr)_minmax(0,1fr)]' => $this->canManage])>
        @if ($this->canManage)
            {{-- Builder --}}
            <x-ui.panel :title="$editing ? __('Edit representation group') : __('Create representation group')" :description="__('Give the group a name, then add the categories and values that describe it. You can assign the group to related contests later.')">
                <form wire:submit="save" class="space-y-6" novalidate>

                    <div class="flex flex-wrap items-center gap-2">
                        @if ($editing)
                            <x-ui.badge tone="info">{{ __('Editing') }}</x-ui.badge>
                            <span class="text-sm break-words">{{ $editingGroupName }}</span>
                        @endif
                        <span x-show="dirty" x-cloak class="inline-flex items-center gap-1 text-xs font-semibold text-warning-text">
                            <flux:icon name="exclamation-triangle" variant="mini" class="size-4" />{{ __('Unsaved changes') }}
                        </span>
                    </div>

                    <flux:field>
                        <flux:label>{{ __('Group name') }}</flux:label>
                        <flux:input wire:model="name" maxlength="255" autocomplete="off" placeholder="{{ __('Enter a name administrators will recognize') }}" />
                        <p class="mt-1 text-xs text-muted">{{ __('This is the name you will see when assigning a group to a contest.') }}</p>
                        <flux:error name="name" />
                    </flux:field>

                    <div class="space-y-3">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <h3 class="text-sm font-semibold">{{ __('Categories and values') }}</h3>
                                <p class="text-xs text-muted">{{ __('A category describes what the values below have in common.') }}</p>
                            </div>
                            <x-ui.button type="button" size="sm" icon="plus" wire:click="addDimension" data-dirty-action>{{ __('Add category') }}</x-ui.button>
                        </div>

                        @foreach ($dimensions as $index => $dimension)
                            @php $tags = $chips($dimension['values'] ?? ''); @endphp
                            <fieldset wire:key="scope-dimension-{{ $index }}" class="space-y-4 rounded-ui border border-line bg-surface-sunken/40 p-4">
                                <legend class="sr-only">{{ __('Category :n', ['n' => $index + 1]) }}</legend>

                                <div class="flex items-start gap-3">
                                    <flux:field class="min-w-0 grow">
                                        <flux:label>{{ __('Category name') }}</flux:label>
                                        <flux:input wire:model="dimensions.{{ $index }}.key" autocomplete="off" placeholder="{{ __('Enter a category name') }}" />
                                        <p class="mt-1 text-xs text-muted">{{ __('Use ordinary words. The system creates the internal identifier automatically.') }}</p>
                                        <flux:error name="dimensions.{{ $index }}.key" />
                                    </flux:field>
                                    @if (count($dimensions) > 1)
                                        <x-ui.button type="button" size="sm" variant="ghost" icon="trash" class="mt-6 shrink-0" wire:click="removeDimension({{ $index }})" data-dirty-action aria-label="{{ __('Remove category :n', ['n' => $index + 1]) }}">{{ __('Remove category') }}</x-ui.button>
                                    @endif
                                </div>

                                <div class="space-y-2">
                                    <div class="flex items-baseline justify-between gap-3">
                                        <span class="text-sm font-medium" id="values-label-{{ $index }}">{{ __('Values') }}</span>
                                        <span class="text-xs text-muted">{{ trans_choice(':count value|:count values', count($tags), ['count' => count($tags)]) }}</span>
                                    </div>

                                    @if ($tags === [])
                                        <p class="rounded-ui border border-dashed border-line px-3 py-3 text-sm text-muted">{{ __('No values yet. Type one value below, then press Enter or choose Add.') }}</p>
                                    @else
                                        <ul aria-labelledby="values-label-{{ $index }}" class="flex flex-wrap gap-2">
                                            @foreach ($tags as $tagIndex => $tag)
                                                <li wire:key="scope-value-{{ $index }}-{{ $tagIndex }}" class="inline-flex max-w-full items-center overflow-hidden rounded-full border border-line bg-surface-subtle text-sm">
                                                    <button type="button" wire:click="editValue({{ $index }}, {{ $tagIndex }})" data-dirty-action class="focus-ui min-w-0 truncate py-1 pr-2 pl-3 hover:bg-surface-sunken" title="{{ __('Edit this value') }}" aria-label="{{ __('Edit value: :value', ['value' => $tag]) }}">{{ $tag }}</button>
                                                    <button type="button" wire:click="removeValue({{ $index }}, {{ $tagIndex }})" data-dirty-action class="focus-ui flex items-center border-l border-line px-2 py-1 text-muted hover:bg-surface-sunken hover:text-ink" aria-label="{{ __('Remove value: :value', ['value' => $tag]) }}">
                                                        <flux:icon name="x-mark" variant="micro" class="size-3.5" />
                                                    </button>
                                                </li>
                                            @endforeach
                                        </ul>
                                    @endif

                                    <div class="flex gap-2">
                                        <div class="min-w-0 grow">
                                            <flux:input wire:model="drafts.{{ $index }}" wire:keydown.enter.prevent="addValue({{ $index }})" autocomplete="off" aria-label="{{ __('New value for category :n', ['n' => $index + 1]) }}" placeholder="{{ __('Type one value, then press Enter') }}" />
                                        </div>
                                        <x-ui.button type="button" icon="plus" class="shrink-0" wire:click="addValue({{ $index }})" data-dirty-action>{{ __('Add') }}</x-ui.button>
                                    </div>
                                    <p class="text-xs text-muted">{{ __('Add one value at a time. Select a tag to edit it, or use its x to remove it. Leave out any value that has not been confirmed for this election.') }}</p>
                                    <flux:error name="drafts.{{ $index }}" />
                                    <flux:error name="dimensions.{{ $index }}.values" />
                                </div>
                            </fieldset>
                        @endforeach
                        <flux:error name="dimensions" />
                    </div>

                    <div class="flex flex-wrap items-center gap-2 border-t border-line pt-4">
                        <x-ui.button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="save">
                            <span wire:loading.remove wire:target="save">{{ $editing ? __('Save changes') : __('Create group') }}</span>
                            <span wire:loading wire:target="save" class="inline-flex items-center gap-2"><flux:icon name="loading" variant="micro" class="size-4" />{{ __('Saving...') }}</span>
                        </x-ui.button>
                        @if ($editing)
                            <x-ui.button type="button" x-on:click="discard()">{{ __('Cancel') }}</x-ui.button>
                        @endif
                    </div>
                </form>
            </x-ui.panel>
        @endif

        {{-- Saved groups --}}
        <x-ui.panel :title="__('Saved groups')" :description="$this->canManage ? __('Groups you can assign to contests. A group already used by a contest cannot be deleted.') : __('Groups saved for this election.')">
            @if ($this->groups->isEmpty())
                <x-ui.empty-state icon="square-3-stack-3d" :title="__('No representation groups yet')" :description="$this->canManage ? __('Create a group to organize this election’s contests.') : __('No groups were configured while this election was a draft.')" />
            @else
                <ul class="space-y-3">
                    @foreach ($this->groups as $group)
                        @php $inUse = (int) $group->contests_count > 0; @endphp
                        <li wire:key="representation-group-{{ $group->id }}">
                            <article @class(['space-y-4 rounded-ui border bg-surface-subtle/40 p-4', 'border-[var(--ui-accent)]' => $editingGroupId === (int) $group->id, 'border-line' => $editingGroupId !== (int) $group->id])>
                                <div class="flex flex-wrap items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <h3 class="font-semibold break-words">{{ $group->name }}</h3>
                                        <p class="mt-1 flex flex-wrap items-center gap-2 text-xs text-muted">
                                            <x-ui.badge :tone="$inUse ? 'info' : 'neutral'">{{ trans_choice(':count contest assigned|:count contests assigned', (int) $group->contests_count, ['count' => (int) $group->contests_count]) }}</x-ui.badge>
                                            @if ($editingGroupId === (int) $group->id)<x-ui.badge tone="warning">{{ __('Being edited') }}</x-ui.badge>@endif
                                        </p>
                                    </div>
                                    @if ($this->canManage)
                                        <div class="flex flex-wrap gap-2">
                                            <x-ui.button type="button" size="sm" icon="pencil-square" wire:click="editGroup({{ $group->id }})" data-switch-record>{{ __('Edit') }}</x-ui.button>
                                            @if (! $inUse)
                                                <x-ui.button type="button" size="sm" icon="trash" wire:click="confirmDelete({{ $group->id }})">{{ __('Delete') }}</x-ui.button>
                                            @endif
                                        </div>
                                    @endif
                                </div>

                                <dl class="space-y-3 text-sm">
                                    @foreach ((array) $group->scope_definition_json as $dimensionKey => $values)
                                        <div>
                                            <dt class="text-xs font-semibold tracking-wide text-muted uppercase">{{ Str::headline((string) $dimensionKey) }}</dt>
                                            <dd class="mt-1.5 flex flex-wrap gap-1.5">
                                                @foreach ((array) $values as $value)
                                                    <span class="rounded-full border border-line bg-surface px-2.5 py-0.5 break-words">{{ $value }}</span>
                                                @endforeach
                                            </dd>
                                        </div>
                                    @endforeach
                                </dl>

                                @if ($this->canManage && $inUse)
                                    <p class="flex items-start gap-2 text-xs text-muted">
                                        <flux:icon name="lock-closed" variant="micro" class="mt-0.5 size-3.5 shrink-0" />
                                        {{ __('This group is used by a contest, so it cannot be deleted. You can still edit it.') }}
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
            @if ($this->confirmingGroup)
                <div class="space-y-4">
                    <div class="space-y-2">
                        <h3 class="ui-section-title">{{ __('Delete this representation group?') }}</h3>
                        <p class="text-sm">{{ __('You are about to delete:') }} <strong class="break-words">{{ $this->confirmingGroup->name }}</strong></p>
                        <p class="text-sm text-muted">{{ __('This cannot be undone. Deletion is blocked when a contest already uses the group; in that case nothing is deleted and you will be told why.') }}</p>
                    </div>
                    <div class="flex flex-wrap justify-end gap-2">
                        <flux:modal.close><x-ui.button type="button">{{ __('Keep group') }}</x-ui.button></flux:modal.close>
                        <x-ui.button type="button" variant="danger" wire:click="deleteGroup({{ $this->confirmingGroup->id }})" wire:loading.attr="disabled" wire:target="deleteGroup">
                            <span wire:loading.remove wire:target="deleteGroup">{{ __('Delete group') }}</span>
                            <span wire:loading wire:target="deleteGroup">{{ __('Deleting...') }}</span>
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
