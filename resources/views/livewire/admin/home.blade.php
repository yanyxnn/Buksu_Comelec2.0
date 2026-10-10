<?php

use App\Models\AccessIssueReport;
use App\Models\ChangeRequest;
use App\Models\Student;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('components.layouts.admin')] #[Title('Dashboard')] class extends Component {
    #[Computed]
    public function pendingCount(): int
    {
        return ChangeRequest::query()->where('status', ChangeRequest::STATUS_PENDING)->count();
    }

    /** Oldest first: the request that has waited longest is the one to look at first. */
    #[Computed]
    public function pendingRequests()
    {
        return ChangeRequest::query()
            ->with('requester')
            ->where('status', ChangeRequest::STATUS_PENDING)
            ->oldest('id')
            ->limit(5)
            ->get();
    }

    #[Computed]
    public function notifications()
    {
        return Auth::guard('admin')->user()->unreadNotifications()->latest()->limit(20)->get();
    }

    #[Computed]
    public function unreadCount(): int
    {
        return Auth::guard('admin')->user()->unreadNotifications()->count();
    }

    #[Computed]
    public function accessIssueCount(): int
    {
        return AccessIssueReport::query()->count();
    }

    /** @return array{total:int, active:int, inactive:int} */
    #[Computed]
    public function studentCounts(): array
    {
        $byStatus = Student::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return [
            'total' => (int) $byStatus->sum(),
            'active' => (int) ($byStatus['ACTIVE'] ?? 0),
            'inactive' => (int) ($byStatus['INACTIVE'] ?? 0),
        ];
    }
}; ?>

<div class="space-y-8">
    <section aria-labelledby="overview-title" class="space-y-4">
        <div>
            <h2 id="overview-title" class="ui-section-title">{{ __('Overview') }}</h2>
            <p class="text-sm text-muted">{{ __('Live counts from the system records.') }}</p>
        </div>

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <x-ui.stat-card :href="route('admin.approvals')" :label="__('Pending approvals')" :value="number_format($this->pendingCount)"
                :hint="$this->pendingCount > 0 ? __('Waiting for a decision') : __('Nothing waiting')"
                class="hover:-translate-y-0.5 motion-safe:transition-transform" />
            <x-ui.stat-card :label="__('Unread notifications')" :value="number_format($this->unreadCount)"
                :hint="__('For your account')" />
            <x-ui.stat-card :href="route('admin.access-issues')" :label="__('Access issue reports')" :value="number_format($this->accessIssueCount)"
                :hint="__('Submitted by students')" class="hover:-translate-y-0.5 motion-safe:transition-transform" />
            <x-ui.stat-card :label="__('Students in Data Center')" :value="number_format($this->studentCounts['total'])"
                :hint="__(':active active · :inactive inactive', ['active' => number_format($this->studentCounts['active']), 'inactive' => number_format($this->studentCounts['inactive'])])" />
        </div>
    </section>

    <div class="grid items-start gap-6 xl:grid-cols-5">
        <x-ui.panel class="xl:col-span-3" :title="__('Pending approvals')" :description="__('Oldest requests first.')">
            <x-slot:actions>
                <x-ui.button size="sm" :href="route('admin.approvals')">{{ __('Open approvals') }}</x-ui.button>
            </x-slot:actions>

            @if ($this->pendingRequests->isEmpty())
                <x-ui.empty-state icon="clipboard-document-check" :title="__('No pending approvals')" :description="__('Change requests that need a decision will appear here.')" />
            @else
                <ul class="-my-1 divide-y divide-line">
                    @foreach ($this->pendingRequests as $request)
                        <li class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 py-3" wire:key="pending-{{ $request->id }}">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-semibold">{{ __('Request #:id', ['id' => $request->id]) }} · {{ $request->action_type }}</p>
                                <p class="text-xs text-muted">{{ __('Proposed by :name', ['name' => $request->requester?->display_name ?? '?']) }} · {{ $request->created_at?->diffForHumans() }}</p>
                            </div>
                            <x-ui.status-badge :status="$request->status" />
                        </li>
                    @endforeach
                </ul>
                @if ($this->pendingCount > $this->pendingRequests->count())
                    <p class="mt-3 text-xs text-muted">{{ __('Showing :shown of :total pending.', ['shown' => $this->pendingRequests->count(), 'total' => $this->pendingCount]) }}</p>
                @endif
            @endif
        </x-ui.panel>

        <x-ui.panel class="xl:col-span-2" :title="__('Notifications')" :description="__('Unread, newest first.')">
            @if ($this->notifications->isEmpty())
                <x-ui.empty-state icon="bell" :title="__('No new notifications.')" />
            @else
                <ul class="-my-1 divide-y divide-line text-sm">
                    @foreach ($this->notifications as $notification)
                        <li class="flex gap-3 py-3">
                            <span class="mt-1.5 size-2 shrink-0 rounded-full bg-accent" aria-hidden="true"></span>
                            <div class="min-w-0">
                                <p>{{ __('Change request #:id (:type) proposed by :name', [
                                    'id' => $notification->data['change_request_id'] ?? '?',
                                    'type' => $notification->data['action_type'] ?? '?',
                                    'name' => $notification->data['requested_by_name'] ?? '?',
                                ]) }}</p>
                                <p class="mt-0.5 text-xs text-subtle">{{ $notification->created_at?->diffForHumans() }}</p>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-ui.panel>
    </div>
</div>
