{{--
    x-ui.stat-card — one labelled figure. Presentation only: the caller passes the already-computed value.
    <x-ui.stat-card label="Registered students" :value="number_format($n)" hint="As of last import" />
--}}
@props([
    'label',
    'value',
    'hint' => null,
])

<x-ui.card {{ $attributes }}>
    <p class="text-sm text-muted">{{ $label }}</p>
    <p class="mt-1 font-heading text-2xl font-semibold tabular-nums text-ink">{{ $value }}</p>
    @if ($hint)
        <p class="mt-1 text-xs text-subtle">{{ $hint }}</p>
    @endif
</x-ui.card>
