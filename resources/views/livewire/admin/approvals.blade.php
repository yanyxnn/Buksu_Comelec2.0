<?php

use App\Models\AdminUser;
use App\Models\ChangeRequest;
use App\Services\Approval\ChangeRequestConflict;
use App\Services\Approval\ChangeRequestService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.portal')] class extends Component {
    public string $notice = '';

    /**
     * Rows with a precomputed "can this admin decide it" flag (policy-driven).
     * The buttons are a convenience only: every action re-authorizes server-side.
     */
    #[Computed]
    public function rows(): array
    {
        $admin = Auth::guard('admin')->user();

        return ChangeRequest::query()
            ->with(['requester', 'decider'])
            ->latest('id')
            ->limit(50)
            ->get()
            ->map(fn (ChangeRequest $request) => [
                'request' => $request,
                'can_decide' => Gate::forUser($admin)->allows('decide', $request),
            ])
            ->all();
    }

    public function approve(int $id): void
    {
        $this->decide($id, ChangeRequest::STATUS_APPROVED);
    }

    public function reject(int $id): void
    {
        $this->decide($id, ChangeRequest::STATUS_REJECTED);
    }

    private function decide(int $id, string $decision): void
    {
        $admin = Auth::guard('admin')->user();
        abort_unless($admin instanceof AdminUser, 403);

        $request = ChangeRequest::query()->findOrFail($id);

        try {
            // Authorization (incl. "requester cannot decide") happens in the service; a
            // failure surfaces as AuthorizationException => HTTP 403.
            app(ChangeRequestService::class)->decide($admin, $request, $decision);
            $this->notice = __('Request #:id :decision.', ['id' => $id, 'decision' => strtolower($decision)]);
        } catch (ChangeRequestConflict) {
            $this->notice = __('Request #:id was already decided.', ['id' => $id]);
        }

        unset($this->rows);
    }
}; ?>

<div>
    <h1 class="text-xl font-semibold">{{ __('Approvals') }}</h1>

    @if ($notice !== '')
        <p role="status" class="mt-3 text-sm">{{ $notice }}</p>
    @endif

    <table class="mt-4 w-full text-left text-sm">
        <thead>
            <tr class="border-b">
                <th class="py-2">#</th><th>{{ __('Action') }}</th><th>{{ __('Requested by') }}</th><th>{{ __('Status') }}</th><th></th>
            </tr>
        </thead>
        <tbody>
            @forelse ($this->rows as $row)
                <tr class="border-b" wire:key="cr-{{ $row['request']->id }}">
                    <td class="py-2">{{ $row['request']->id }}</td>
                    <td>{{ $row['request']->action_type }}</td>
                    <td>{{ $row['request']->requester->display_name }}</td>
                    <td>
                        {{ $row['request']->status }}
                        @if ($row['request']->decider)
                            ({{ $row['request']->decider->display_name }})
                        @endif
                    </td>
                    <td class="space-x-2">
                        @if ($row['can_decide'])
                            <button type="button" wire:click="approve({{ $row['request']->id }})" class="underline">{{ __('Approve') }}</button>
                            <button type="button" wire:click="reject({{ $row['request']->id }})" class="underline">{{ __('Reject') }}</button>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="py-3 text-neutral-500">{{ __('No change requests.') }}</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
