{{--
    x-ui.alert — an inline message. tone: info | success | warning | danger
    Warning/danger use role="alert" (announced immediately); info/success use role="status" (polite).
    <x-ui.alert tone="warning" title="Roster is out of date">Body text...</x-ui.alert>
--}}
@props([
    'tone' => 'info',
    'title' => null,
])

@php
    [$toneClasses, $icon] = match ($tone) {
        'success' => ['border-success-border bg-success-bg text-success-text', 'check-circle'],
        'warning' => ['border-warning-border bg-warning-bg text-warning-text', 'exclamation-triangle'],
        'danger' => ['border-danger-border bg-danger-bg text-danger-text', 'x-circle'],
        default => ['border-info-border bg-info-bg text-info-text', 'information-circle'],
    };
    $role = in_array($tone, ['warning', 'danger'], true) ? 'alert' : 'status';
@endphp

<div role="{{ $role }}" {{ $attributes->class("flex gap-3 rounded-ui border p-3 text-sm {$toneClasses}") }}>
    <flux:icon :name="$icon" variant="mini" class="mt-0.5 size-5 shrink-0" />
    <div class="min-w-0">
        @if ($title)
            <p class="font-semibold">{{ $title }}</p>
        @endif
        @if (! $slot->isEmpty())
            <div @class(['mt-0.5' => $title])>{{ $slot }}</div>
        @endif
    </div>
</div>
