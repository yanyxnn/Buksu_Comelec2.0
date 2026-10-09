<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark bg-plum-deep">
    <head>
        @include('partials.head', ['title' => config('app.name')])
    </head>
    <body class="bg-plum-deep text-paper antialiased">
        {{-- Mobile: one column, content first (headline + action stay near the top), collage below.
             Desktop (lg): content on the left, collage on the right, full viewport height. --}}
        <div class="grid min-h-svh grid-cols-1 lg:grid-cols-[minmax(0,5fr)_minmax(0,6fr)]">

            <div class="flex min-w-0 flex-col px-6 py-6 sm:px-10 lg:px-14 lg:py-10">
                <header>
                    <a href="{{ route('home') }}" class="inline-block rounded-sm font-heading text-lg font-semibold tracking-wide text-paper focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-gold">
                        {{ config('app.name') }}
                    </a>
                </header>

                <main class="flex flex-1 flex-col justify-center py-12 lg:py-16">
                    @if (session('status'))
                        <x-ui.alert tone="success" class="mb-8 max-w-md">{{ session('status') }}</x-ui.alert>
                    @endif

                    <div class="w-12 border-t-2 border-gold" aria-hidden="true"></div>
                    <p class="mt-5 text-xs font-semibold tracking-[0.14em] text-gold uppercase">{{ __('Student election platform') }}</p>

                    <h1 class="mt-4 font-heading text-4xl leading-[1.08] font-semibold tracking-tight text-balance sm:text-5xl xl:text-6xl">
                        <span class="block">{{ __('Your Voice.') }}</span>
                        <span class="block">{{ __('Your Vote.') }}</span>
                        <span class="block text-gold">{{ __('Our Future.') }}</span>
                    </h1>

                    <p class="mt-6 max-w-md text-base leading-relaxed text-parchment/80">
                        {{ __('The official student election platform of Bukidnon State University, run by the BukSU COMELEC. Sign in with Google to continue.') }}
                    </p>

                    <a href="{{ route('auth.google.redirect') }}"
                       class="mt-8 inline-flex min-h-12 w-full items-center justify-center rounded-sm bg-gold px-6 text-base font-semibold text-warm-ink hover:bg-paper focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-gold motion-safe:transition-colors sm:w-auto sm:self-start">
                        {{ __('Continue with Google') }}
                    </a>
                </main>
            </div>

            {{-- Editorial collage. Decorative composition: every tile is either an approved image or an empty frame. --}}
            <div class="min-w-0 bg-parchment p-4 sm:p-6 lg:p-8">
                <div class="grid h-full grid-cols-2 gap-3 sm:gap-4 lg:grid-rows-[3fr_2fr]">
                    <x-welcome.art-tile name="campus-main" fit="cover" :priority="true"
                        label="Approved BukSU campus photograph (main)"
                        alt="{{ __('A view of the BukSU campus') }}"
                        class="col-span-2 aspect-[4/3] lg:aspect-auto" />
                    <x-welcome.art-tile name="campus-detail" fit="cover"
                        label="Approved BukSU campus photograph (detail)"
                        alt="{{ __('A scene from the BukSU campus') }}"
                        class="aspect-square lg:aspect-auto" />
                    <x-welcome.art-tile name="comelec-ballot" fit="contain"
                        label="Approved COMELEC ballot artwork"
                        alt="{{ __('COMELEC ballot artwork') }}"
                        class="aspect-square lg:aspect-auto" />
                </div>
            </div>
        </div>
        @fluxScripts
    </body>
</html>
