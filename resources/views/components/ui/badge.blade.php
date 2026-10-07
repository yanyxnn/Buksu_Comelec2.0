{{--
    x-ui.badge — a small label pill. tone: neutral | info | success | warning | danger
    Tone colours come from the semantic tokens in app.css (light + dark handled there).
--}}
@props([
    'tone' => 'neutral',
])

@php
    $toneClasses = match ($tone) {
        'info' => 'border-info-border bg-info-bg text-info-text',
        'success' => 'border-success-border bg-success-bg text-success-text',
        'warning' => 'border-warning-border bg-warning-bg text-warning-text',
        'danger' => 'border-danger-border bg-danger-bg text-danger-text',
        default => 'border-line bg-surface-sunken text-muted',
    };
@endphp

<span {{ $attributes->class("inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-xs font-medium whitespace-nowrap {$toneClasses}") }}>{{ $slot }}</span>
