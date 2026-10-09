{{--
    welcome.art-tile — one piece of the hero collage: an approved image when its files exist, otherwise an
    intentional branded fallback block. Nothing is drawn or invented here: the fallback is a flat palette
    colour with a thin gold inset rule, so a collage with missing artwork still reads as a deliberate
    editorial composition rather than an unfinished page. It carries no text in production; outside
    production it also shows `label`, so a developer can see which asset is missing.

    ASSET READINESS: the approved artwork should ship before launch. Until then production shows the
    fallback blocks. Missing assets never produce a broken image and never an error.

    Expected files (public/images/welcome/): {name}-800.webp and {name}-1400.webp (same crop, two widths).
    name    basename without width/extension, e.g. "campus-main"
    alt     meaningful description for the real image (the fallback is hidden from assistive tech)
    label   which asset belongs here; shown outside production only
    fit     cover (photography, cropped to the frame) | contain (artwork that must never be cropped;
            it sits on deep plum so any letterbox edge blends with the artwork's own dark plum ground)
    width/height   intrinsic pixel size of the 1400w file (reserves space, prevents layout shift)
    priority       true only for the one image that is visible on first paint
--}}
@props([
    'name',
    'alt',
    'label',
    'fit' => 'cover',
    'width' => 1400,
    'height' => 1050,
    'priority' => false,
])

@php
    $dir = 'images/welcome/';
    $ready = file_exists(public_path("{$dir}{$name}-800.webp")) && file_exists(public_path("{$dir}{$name}-1400.webp"));
    $fitClass = $fit === 'contain' ? 'object-contain' : 'object-cover';
@endphp

<div {{ $attributes->class('relative min-w-0 overflow-hidden rounded-sm '.(! $ready ? 'bg-plum' : ($fit === 'contain' ? 'bg-plum-dark' : 'bg-paper'))) }}>
    @if ($ready)
        <img
            src="{{ asset("{$dir}{$name}-1400.webp") }}"
            srcset="{{ asset("{$dir}{$name}-800.webp") }} 800w, {{ asset("{$dir}{$name}-1400.webp") }} 1400w"
            sizes="(min-width: 1024px) 40vw, 100vw"
            width="{{ $width }}" height="{{ $height }}"
            alt="{{ $alt }}"
            decoding="async"
            @if ($priority) fetchpriority="high" @else loading="lazy" @endif
            class="absolute inset-0 size-full {{ $fitClass }}"
        />
    @else
        <div data-art-fallback aria-hidden="true" class="absolute inset-3 flex items-center justify-center border border-gold/40 p-3 text-center">
            @unless (app()->isProduction())
                <span class="text-xs font-medium tracking-wide text-parchment/80">{{ $label }}</span>
            @endunless
        </div>
    @endif
</div>
