<?php

use App\Models\AdminUser;
use App\Models\Election;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

/*
 * Phase 04A: the read-only election workspace. It shows what was saved and the configuration status, and nothing else:
 * no edit, lock, approve, schedule or delete action exists here (later phases). Only the election's id is kept in
 * component state; the row is read with explicit columns on each render.
 */
new #[Layout('components.layouts.admin')] #[Title('Election')] class extends Component
{
    #[Locked]
    public int $electionId = 0;

    public function mount(Election $election): void
    {
        $admin = Auth::guard('admin')->user();
        abort_unless($admin instanceof AdminUser, 403);
        Gate::forUser($admin)->authorize('view', $election);

        $this->electionId = (int) $election->getKey();
    }

    #[Computed]
    public function election(): object
    {
        return DB::table('elections')
            ->leftJoin('election_config_versions as current_version', 'current_version.id', '=', 'elections.current_config_version_id')
            ->leftJoin('election_config_versions as locked_version', 'locked_version.id', '=', 'elections.locked_config_version_id')
            ->leftJoin('admin_users', 'admin_users.id', '=', 'elections.created_by')
            ->where('elections.id', $this->electionId)
            ->select([
                'elections.id', 'elections.name', 'elections.description', 'elections.type', 'elections.template_key',
                'elections.state', 'elections.timezone', 'elections.opens_at', 'elections.closes_at',
                'elections.created_at', 'elections.updated_at',
                'current_version.version_number as version_number', 'current_version.status as version_status',
                'current_version.config_json as version_config_json',
                'locked_version.version_number as locked_version_number',
                'admin_users.display_name as creator_name',
            ])
            ->selectSub(
                DB::table('representation_groups')->selectRaw('COUNT(*)')->whereColumn('representation_groups.election_id', 'elections.id'),
                'scope_groups_count',
            )
            ->selectSub(
                DB::table('contests')->selectRaw('COUNT(*)')
                    ->whereColumn('contests.election_id', 'elections.id')
                    ->whereColumn('contests.election_config_version_id', 'elections.current_config_version_id'),
                'contests_count',
            )
            ->firstOrFail();
    }

    /** Whether the scope page would let this administrator change scope right now (the policy decides, not this view). */
    #[Computed]
    public function canManageScope(): bool
    {
        $election = Election::query()
            ->select(['id', 'state', 'current_config_version_id', 'locked_config_version_id'])
            ->findOrFail($this->electionId);
        $admin = Auth::guard('admin')->user();

        return $admin instanceof AdminUser && Gate::forUser($admin)->allows('manageScope', $election);
    }

    /** Whether the contests page would let this administrator change contests right now (the policy decides). */
    #[Computed]
    public function canManageContests(): bool
    {
        $election = Election::query()
            ->select(['id', 'state', 'current_config_version_id', 'locked_config_version_id'])
            ->findOrFail($this->electionId);
        $admin = Auth::guard('admin')->user();

        return $admin instanceof AdminUser && Gate::forUser($admin)->allows('manageContests', $election);
    }
}; ?>

@php
    $e = $this->election;
    $types = (array) config('comelec.election_types');
    $templates = (array) config('comelec.election_templates');
    $fmtTime = fn ($t) => $t ? \Illuminate\Support\Carbon::parse($t)->format('M j, Y g:i A') : '—';
    $canScope = $this->canManageScope;
    $canContests = $this->canManageContests;
    $editable = $canScope || $canContests;
    $groupsCount = (int) $e->scope_groups_count;
    $contestsCount = (int) $e->contests_count;
    $templateName = $e->template_key === null ? __('Customize from scratch') : __($templates[$e->template_key] ?? $e->template_key);
@endphp

<div class="theme-2k26 space-y-6">
    {{-- Election header --}}
    <div class="flex flex-wrap items-start justify-between gap-4">
        <section class="min-w-0 max-w-3xl space-y-3">
            <p class="ui-eyebrow">{{ __('Election') }}</p>
            <h2 class="ui-section-title break-words">{{ $e->name }}</h2>
            <p class="flex flex-wrap items-center gap-x-3 gap-y-2 text-sm text-muted">
                <x-ui.status-badge :status="$e->state" />
                <span>{{ __($types[$e->type] ?? $e->type) }}</span>
                <x-ui.badge :tone="$editable ? 'success' : 'neutral'">{{ $editable ? __('Editable draft') : __('Read-only') }}</x-ui.badge>
            </p>
        </section>
        <x-ui.button icon="arrow-left" :href="route('admin.elections')" wire:navigate>{{ __('All elections') }}</x-ui.button>
    </div>

    <nav aria-label="{{ __('Election configuration') }}" class="flex flex-wrap gap-1 rounded-ui border border-line bg-surface p-1 text-sm">
        <a href="{{ route('admin.elections.show', $e->id) }}" wire:navigate aria-current="true" class="focus-ui rounded-ui bg-[var(--ui-accent)] px-3 py-1.5 font-semibold text-[var(--ui-accent-foreground)]">{{ __('Workspace') }}</a>
        <a href="{{ route('admin.elections.scope', $e->id) }}" wire:navigate class="focus-ui rounded-ui px-3 py-1.5 text-muted hover:bg-surface-subtle hover:text-ink">{{ __('Representation groups') }}</a>
        <a href="{{ route('admin.elections.contests', $e->id) }}" wire:navigate class="focus-ui rounded-ui px-3 py-1.5 text-muted hover:bg-surface-subtle hover:text-ink">{{ __('Contests') }}</a>
    </nav>

    @if (session('election_status'))
        <x-ui.alert tone="success" wire:key="flash">{{ session('election_status') }}</x-ui.alert>
    @endif

    <x-ui.alert tone="info">
        @if ($editable)
            {{ __('This workspace is read-only: it shows what has been saved. Representation groups and contests are configured on their own pages while the election is an editable draft.') }}
        @else
            {{ __('This workspace is read-only, and this election can no longer be edited. Representation groups and contests can still be viewed.') }}
        @endif
    </x-ui.alert>

    {{-- Configuration overview --}}
    <section aria-labelledby="config-overview" class="space-y-3">
        <div>
            <h3 id="config-overview" class="ui-section-title">{{ __('Configuration overview') }}</h3>
            <p class="text-sm text-muted">{{ __('Set up representation groups first if you want to organize contests, then set up the contests of this election.') }}</p>
        </div>

        <div class="grid items-stretch gap-4 lg:grid-cols-2">
            <x-ui.card class="flex flex-col gap-4 ui-rule-brass">
                <div class="flex items-start gap-3">
                    <span class="flex size-9 shrink-0 items-center justify-center rounded-ui border border-line bg-surface-subtle"><flux:icon name="square-3-stack-3d" class="size-5" /></span>
                    <div class="min-w-0">
                        <p class="text-xs font-semibold tracking-wide text-muted uppercase">{{ __('Step 1') }}</p>
                        <h4 class="font-semibold">{{ __('Representation groups') }}</h4>
                    </div>
                </div>
                <p class="text-2xl font-semibold">
                    @if ($groupsCount === 0)
                        {{ __('No representation groups yet') }}
                    @else
                        {{ trans_choice(':count representation group|:count representation groups', $groupsCount, ['count' => $groupsCount]) }}
                    @endif
                </p>
                <p class="text-sm text-muted">{{ __('Named sets of categories and values that organize the contests of this election. They do not decide who may vote or stand.') }}</p>
                <div class="mt-auto">
                    @if ($canScope)
                        <x-ui.button variant="primary" icon-trailing="arrow-right" :href="route('admin.elections.scope', $e->id)" wire:navigate>{{ __('Configure representation groups') }}</x-ui.button>
                    @else
                        <x-ui.button icon-trailing="arrow-right" :href="route('admin.elections.scope', $e->id)" wire:navigate>{{ __('View representation groups') }}</x-ui.button>
                    @endif
                </div>
            </x-ui.card>

            <x-ui.card class="flex flex-col gap-4 ui-rule-brass">
                <div class="flex items-start gap-3">
                    <span class="flex size-9 shrink-0 items-center justify-center rounded-ui border border-line bg-surface-subtle"><flux:icon name="rectangle-stack" class="size-5" /></span>
                    <div class="min-w-0">
                        <p class="text-xs font-semibold tracking-wide text-muted uppercase">{{ __('Step 2') }}</p>
                        <h4 class="font-semibold">{{ __('Contests') }}</h4>
                    </div>
                </div>
                <p class="text-2xl font-semibold">
                    @if ($contestsCount === 0)
                        {{ __('No contests yet') }}
                    @else
                        {{ trans_choice(':count contest|:count contests', $contestsCount, ['count' => $contestsCount]) }}
                    @endif
                </p>
                <p class="text-sm text-muted">{{ __('The positions or offices being filled, with their available seats and basic voting settings. A contest can optionally use a representation group.') }}</p>
                <div class="mt-auto">
                    @if ($canContests)
                        <x-ui.button variant="primary" icon-trailing="arrow-right" :href="route('admin.elections.contests', $e->id)" wire:navigate>{{ __('Configure contests') }}</x-ui.button>
                    @else
                        <x-ui.button icon-trailing="arrow-right" :href="route('admin.elections.contests', $e->id)" wire:navigate>{{ __('View contests') }}</x-ui.button>
                    @endif
                </div>
            </x-ui.card>
        </div>

        <p class="flex items-start gap-2 text-sm text-muted">
            <flux:icon name="information-circle" variant="mini" class="mt-0.5 size-4 shrink-0" />
            <span>{{ __('Voter eligibility, candidate eligibility, candidates, ballot rules, scheduling and results are separate configuration stages and are not part of this page.') }}</span>
        </p>
    </section>

    <div class="grid items-start gap-6 lg:grid-cols-2">
        <x-ui.panel :title="__('Saved details')" class="min-w-0">
            <dl class="space-y-4 text-sm">
                <div><dt class="text-xs font-semibold tracking-wide text-muted uppercase">{{ __('Name') }}</dt><dd class="mt-0.5 break-words">{{ $e->name }}</dd></div>
                <div><dt class="text-xs font-semibold tracking-wide text-muted uppercase">{{ __('Description') }}</dt><dd class="mt-0.5 break-words whitespace-pre-line">{{ $e->description ?? '—' }}</dd></div>
                <div><dt class="text-xs font-semibold tracking-wide text-muted uppercase">{{ __('Election type') }}</dt><dd class="mt-0.5">{{ __($types[$e->type] ?? $e->type) }}</dd></div>
                <div>
                    <dt class="text-xs font-semibold tracking-wide text-muted uppercase">{{ __('Starting point') }}</dt>
                    <dd class="mt-0.5">{{ $templateName }}</dd>
                    <dd class="mt-1 text-xs text-muted">
                        @if ($e->template_key === null)
                            {{ __('No template was selected.') }}
                        @else
                            {{ __('Recorded for reference only. Template contents are not defined yet, so no template configuration has been applied to this draft.') }}
                        @endif
                    </dd>
                </div>
                <div><dt class="text-xs font-semibold tracking-wide text-muted uppercase">{{ __('Schedule') }}</dt><dd class="mt-0.5">@if ($e->opens_at || $e->closes_at) {{ $fmtTime($e->opens_at) }} → {{ $fmtTime($e->closes_at) }} @else {{ __('Not scheduled yet') }} @endif <span class="text-xs text-muted">({{ $e->timezone }})</span></dd></div>
                <div class="grid gap-4 sm:grid-cols-3">
                    <div><dt class="text-xs font-semibold tracking-wide text-muted uppercase">{{ __('Created by') }}</dt><dd class="mt-0.5 break-words">{{ $e->creator_name ?? '—' }}</dd></div>
                    <div><dt class="text-xs font-semibold tracking-wide text-muted uppercase">{{ __('Created') }}</dt><dd class="mt-0.5">{{ $fmtTime($e->created_at) }}</dd></div>
                    <div><dt class="text-xs font-semibold tracking-wide text-muted uppercase">{{ __('Last updated') }}</dt><dd class="mt-0.5">{{ $fmtTime($e->updated_at) }}</dd></div>
                </div>
            </dl>
        </x-ui.panel>

        <x-ui.panel :title="__('Configuration status')" class="min-w-0">
            <dl class="space-y-4 text-sm">
                <div><dt class="text-xs font-semibold tracking-wide text-muted uppercase">{{ __('Election state') }}</dt><dd class="mt-1"><x-ui.status-badge :status="$e->state" /></dd></div>
                <div>
                    <dt class="text-xs font-semibold tracking-wide text-muted uppercase">{{ __('Current configuration version') }}</dt>
                    <dd class="mt-0.5">
                        @if ($e->version_number === null)
                            —
                        @else
                            {{ __('Version :n', ['n' => $e->version_number]) }} · <x-ui.status-badge :status="$e->version_status" />
                        @endif
                    </dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold tracking-wide text-muted uppercase">{{ __('Configuration content') }}</dt>
                    <dd class="mt-0.5">{{ __('None yet') }} <span class="text-xs text-muted">({{ $e->version_config_json === null ? __('not snapshotted') : __('snapshotted') }})</span></dd>
                </div>
                <div><dt class="text-xs font-semibold tracking-wide text-muted uppercase">{{ __('Locked configuration version') }}</dt><dd class="mt-0.5">{{ $e->locked_version_number === null ? __('None') : __('Version :n', ['n' => $e->locked_version_number]) }}</dd></div>
            </dl>
            <p class="mt-5 border-t border-line pt-4 text-xs text-muted">{{ __('These values are read from the saved election. Nothing here is a readiness check or an approval.') }}</p>
        </x-ui.panel>
    </div>
</div>
