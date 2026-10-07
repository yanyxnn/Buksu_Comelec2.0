{{--
    x-ui.page-container — centred, width-limited content wrapper with consistent gutters and vertical rhythm.
    Not a layout or shell (those come later); it only stops pages repeating `mx-auto max-w-* px-* py-*`.
    size: narrow | base | wide
--}}
@props([
    'size' => 'base',
])

@php
    $max = match ($size) {
        'narrow' => 'max-w-2xl',
        'wide' => 'max-w-7xl',
        default => 'max-w-4xl',
    };
@endphp

<div {{ $attributes->class("mx-auto w-full space-y-6 px-4 py-6 sm:px-6 {$max}") }}>{{ $slot }}</div>
