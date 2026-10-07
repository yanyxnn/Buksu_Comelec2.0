{{--
    x-ui.status-badge — a badge whose tone is derived from a generic status word, so every page shows the same
    status the same way. Presentation only: it does not know any domain's state machine. A status that is not
    in the map renders neutral, so adding a domain status never breaks a page (extend the map when it matters).
    The label is the status itself ("IN_PROGRESS" -> "In progress") unless a slot is given.
--}}
@props([
    'status',
])

@php
    $key = strtolower((string) $status);
    $tone = match ($key) {
        'active', 'approved', 'completed', 'open', 'validated' => 'success',
        'pending', 'draft', 'paused', 'previewed', 'staged' => 'warning',
        'processing', 'validating', 'scheduled', 'confirmed' => 'info',
        'failed', 'rejected', 'error', 'blocked' => 'danger',
        default => 'neutral',
    };
    $label = ucfirst(strtolower(str_replace('_', ' ', (string) $status)));
@endphp

<x-ui.badge :tone="$tone" {{ $attributes }}>{{ $slot->isEmpty() ? $label : $slot }}</x-ui.badge>
