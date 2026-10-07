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
    <p class="ui-eyebrow">{{ $label }}</p>
    <p class="mt-2 ui-figure">{{ $value }}</p>
    @if ($hint)
        <p class="mt-2 text-xs text-subtle">{{ $hint }}</p>
    @endif
</x-ui.card>
