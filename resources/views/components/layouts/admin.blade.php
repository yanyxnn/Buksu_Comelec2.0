{{--
    components.layouts.admin — the administrator shell: sidebar + top header + content slot.
    Used only by admin pages that opt in (currently the dashboard). The shared portal layout (used by the
    student pages) is untouched. Pure presentation: it adds no routes, no authorization and no data writes.

    $title is the page title (Livewire passes #[Title] here); it feeds <title> and the header.
--}}
@props(['title' => null])
@php
    $pageTitle = $title ?: __('Dashboard');
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark admin-shell">
    <head>
        @include('partials.head', ['title' => $pageTitle.' · '.config('app.name')])
    </head>
    <body class="admin-backdrop min-h-screen bg-canvas font-sans text-ink antialiased" x-data="{ nav: false }" x-on:keydown.escape.window="nav = false">
        <a href="#admin-main" class="sr-only z-50 rounded-ui bg-accent px-4 py-2 font-semibold text-accent-foreground focus:not-sr-only focus:fixed focus:top-3 focus:left-3 focus-ui">{{ __('Skip to content') }}</a>

        {{-- Desktop sidebar (fixed) --}}
        <div class="fixed inset-y-0 left-0 z-30 hidden w-72 border-r border-line bg-canvas/90 backdrop-blur-xl lg:block">
            <x-admin.sidebar />
        </div>

        {{-- Mobile sidebar (off-canvas). display:none while closed, so it is out of the tab order and hidden from AT. --}}
        <div class="lg:hidden">
            <div class="fixed inset-0 z-40 bg-black/60 backdrop-blur-sm" x-cloak x-show="nav" x-transition.opacity x-on:click="nav = false" aria-hidden="true"></div>
            <div class="fixed inset-y-0 left-0 z-50 w-72 max-w-[85vw] border-r border-line bg-canvas shadow-ui" role="dialog" aria-modal="true" aria-label="{{ __('Navigation') }}"
                 x-cloak x-show="nav" x-transition:enter="transition ease-out duration-200" x-transition:enter-start="-translate-x-full" x-transition:enter-end="translate-x-0"
                 x-transition:leave="transition ease-in duration-150" x-transition:leave-start="translate-x-0" x-transition:leave-end="-translate-x-full">
                <x-admin.sidebar closable />
            </div>
        </div>

        <div class="lg:pl-72">
            <x-admin.header :title="$pageTitle" />

            <main id="admin-main" tabindex="-1" class="mx-auto w-full max-w-7xl px-4 py-6 focus:outline-none sm:px-6 sm:py-8 lg:px-8">
                {{ $slot }}
            </main>
        </div>

        @fluxScripts
    </body>
</html>
