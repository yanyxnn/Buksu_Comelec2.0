<?php

use App\Models\ChangeRequest;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.portal')] class extends Component {
    #[Computed]
    public function pendingCount(): int
    {
        return ChangeRequest::query()->where('status', ChangeRequest::STATUS_PENDING)->count();
    }

    #[Computed]
    public function notifications()
    {
        return Auth::guard('admin')->user()->unreadNotifications()->latest()->limit(20)->get();
    }
}; ?>

<div>
    <h1 class="text-xl font-semibold">{{ __('Admin') }}</h1>
    <p class="mt-2 text-sm">
        <a href="{{ route('admin.approvals') }}" class="underline">{{ __('Approvals') }}</a>
        — {{ $this->pendingCount }} {{ __('pending') }}
    </p>
    <p class="mt-1 text-sm"><a href="{{ route('admin.access-issues') }}" class="underline">{{ __('Access issue reports') }}</a></p>

    <h2 class="mt-6 font-medium">{{ __('Notifications') }}</h2>
    <ul class="mt-2 space-y-1 text-sm">
        @forelse ($this->notifications as $notification)
            <li>
                {{ __('Change request #:id (:type) proposed by :name', [
                    'id' => $notification->data['change_request_id'] ?? '?',
                    'type' => $notification->data['action_type'] ?? '?',
                    'name' => $notification->data['requested_by_name'] ?? '?',
                ]) }}
            </li>
        @empty
            <li class="text-neutral-500">{{ __('No new notifications.') }}</li>
        @endforelse
    </ul>
</div>
