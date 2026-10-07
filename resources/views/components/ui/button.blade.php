{{--
    x-ui.button — the one button vocabulary for the app. A thin alias over <flux:button>
    (Flux owns sizing, loading state, focus and disabled behaviour); this only fixes the variant names.

    variant: primary | secondary | danger | ghost      size: base | sm | xs
    Everything else (href, type, icon, wire:click, wire:loading, disabled, ...) passes straight through.
--}}
@props([
    'variant' => 'secondary',
    'size' => 'base',
])

@php
    $uiVariant = in_array($variant, ['primary', 'danger', 'ghost'], true) ? $variant : 'secondary';
    $fluxVariant = match ($variant) {
        'primary' => 'primary',
        'danger' => 'danger',
        'ghost' => 'ghost',
        default => 'outline',
    };
@endphp

<flux:button :variant="$fluxVariant" :size="$size" data-ui-button="{{ $uiVariant }}" {{ $attributes }}>{{ $slot }}</flux:button>
