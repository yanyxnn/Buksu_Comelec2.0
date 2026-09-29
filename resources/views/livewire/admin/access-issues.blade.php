<?php

use App\Models\AccessIssueReport;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.portal')] class extends Component {
    /** Read-only list. Reports never grant access and never create or change students. */
    #[Computed]
    public function reports()
    {
        return AccessIssueReport::query()->latest('id')->limit(50)->get();
    }
}; ?>

<div>
    <h1 class="text-xl font-semibold">{{ __('Access issue reports') }}</h1>
    <p class="mt-1 text-sm text-neutral-500">{{ __('Login / access reports from verified institutional accounts. Read-only: reports never grant access or change student records.') }}</p>

    <table class="mt-4 w-full text-left text-sm">
        <thead>
            <tr class="border-b">
                <th class="py-2">{{ __('Submitted at') }}</th>
                <th>{{ __('Problem type') }}</th>
                <th>{{ __('Student ID') }}</th>
                <th>{{ __('Institutional Google email') }}</th>
                <th>{{ __('System denial reason') }}</th>
                <th>{{ __('Description') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($this->reports as $report)
                <tr class="border-b align-top" wire:key="air-{{ $report->id }}">
                    <td class="py-2">{{ $report->created_at }}</td>
                    <td>{{ __(config('comelec.access_issue_problem_types.'.$report->problem_type, $report->problem_type)) }}</td>
                    <td>{{ $report->reported_student_id ?? '—' }}</td>
                    <td>{{ $report->google_email ?? '—' }}</td>
                    <td>{{ $report->denial_reason }}</td>
                    <td>{{ $report->description }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="py-3 text-neutral-500">{{ __('No access issue reports.') }}</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
