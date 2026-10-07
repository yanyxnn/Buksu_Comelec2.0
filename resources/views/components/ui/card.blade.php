{{--
    x-ui.card — the base surface (border, background, radius). Everything boxed (panel, stat-card, empty-state
    wrappers) builds on this, so the surface look is changed in one place (and its tokens in app.css).

    padding: none | sm | base | lg      Renders an <a> when `href` is given, otherwise a <div>.
--}}
@props([
    'padding' => 'base',
    'href' => null,
])

@php
    $pad = match ($padding) {
        'none' => '',
        'sm' => 'p-3',
        'lg' => 'p-6',
        default => 'p-4',
    };
    $classes = "block rounded-ui border border-line bg-surface text-ink {$pad}"
        .($href ? ' transition-colors hover:border-line-strong focus-ui' : '');
@endphp

@if ($href)
    <a href="{{ $href }}" {{ $attributes->class($classes) }}>{{ $slot }}</a>
@else
    <div {{ $attributes->class($classes) }}>{{ $slot }}</div>
@endif
