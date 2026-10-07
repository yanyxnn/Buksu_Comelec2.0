{{--
    x-ui.icon-button — icon-only button. `label` is REQUIRED: it becomes the accessible name and tooltip text.
    <x-ui.icon-button icon="x-mark" label="Dismiss" wire:click="..." />
--}}
@props([
    'icon',
    'label',
    'variant' => 'ghost',
    'size' => 'sm',
])

<x-ui.button :variant="$variant" :size="$size" :icon="$icon" :aria-label="$label" :title="$label" {{ $attributes }} />
