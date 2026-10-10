@php
    /*
     * Live election data is NOT available yet: there is no election/results data source in the application.
     * These stay null until an approved, authorized source exists; the markup below then fills in by itself.
     * Never hardcode election dates, counts, percentages, statuses or candidates here.
     */
    $electionStatus = null;   // string|null
    $votesCast = null;        // int|null
    $eligibleVoters = null;   // int|null
    $standings = null;        // array|null  (approved public results only)

    $fmt = fn ($v) => is_numeric($v) ? number_format($v) : '—';
    $participation = (is_numeric($votesCast) && is_numeric($eligibleVoters) && $eligibleVoters > 0)
        ? round($votesCast / $eligibleVoters * 100, 1) : null;

    // Navigation: only Home has a real route. In-page anchors for the two sections; no route exists yet for the rest.
    $nav = [
        ['label' => 'Home', 'href' => route('home'), 'current' => true],
        ['label' => 'Election', 'href' => '#election'],
        ['label' => 'Candidates', 'href' => null],
        ['label' => 'Results', 'href' => '#standings'],
        ['label' => 'About', 'href' => null],
    ];

    // Shared class strings (kept in one place so the look stays consistent).
    $card = 'welcome-rise relative overflow-hidden rounded-3xl border border-white/10 bg-gradient-to-b from-white/[0.06] to-white/[0.02] shadow-[0_24px_60px_-24px_rgba(0,0,0,0.65)] ring-1 ring-inset ring-white/5 backdrop-blur-sm';
    $focus = 'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-civic-gold';
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark scroll-smooth">
    <head>
        @include('partials.head', ['title' => config('app.name')])
        <style>
            [x-cloak] { display: none !important; }

            /* Page atmosphere: soft light pools + fine grain. Decorative only. */
            .welcome-bg {
                background-image:
                    radial-gradient(60rem 32rem at 85% -10%, rgb(255 255 255 / 0.07), transparent 60%),
                    radial-gradient(48rem 28rem at -10% 20%, rgb(255 255 255 / 0.05), transparent 60%);
            }
            .welcome-grain::before {
                content: "";
                position: fixed;
                inset: 0;
                z-index: 0;
                pointer-events: none;
                opacity: .06;
                mix-blend-mode: overlay;
                background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='160' height='160'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='.9' numOctaves='2' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)'/%3E%3C/svg%3E");
            }
            .welcome-shine {
                background-image: linear-gradient(115deg, transparent 35%, rgb(255 255 255 / .28) 50%, transparent 65%);
                background-size: 250% 100%;
                background-position: 120% 0;
            }

            /* Motion: only when the visitor has not asked to reduce it. */
            @media (prefers-reduced-motion: no-preference) {
                @keyframes welcome-rise { from { opacity: 0; transform: translateY(14px); } to { opacity: 1; transform: none; } }
                @keyframes welcome-pulse { 0% { transform: scale(1); opacity: .7; } 100% { transform: scale(2.6); opacity: 0; } }
                @keyframes welcome-float { 0%, 100% { transform: translateY(0); } 50% { transform: translateY(-6px); } }
                .welcome-rise { animation: welcome-rise .7s cubic-bezier(.2,.7,.2,1) both; animation-delay: var(--d, 0ms); }
                .welcome-ping { animation: welcome-pulse 2.2s ease-out infinite; }
                .welcome-float { animation: welcome-float 7s ease-in-out infinite; }
                .welcome-btn:hover .welcome-shine { background-position: -20% 0; transition: background-position .9s ease; }
            }
        </style>
    </head>
    <body class="welcome-bg welcome-grain relative min-h-screen bg-plum font-sans text-warm-light antialiased">
        <div class="relative z-10 select-none">
            {{-- Header --}}
            <header class="sticky top-0 z-30 border-b border-white/10 bg-plum/80 backdrop-blur-xl supports-[backdrop-filter]:bg-plum/70">
                <div class="mx-auto flex max-w-7xl flex-wrap items-center gap-x-6 gap-y-3 px-4 py-3.5 sm:px-6 lg:px-8">
                    {{-- Institutional identity. Official BukSU / COMELEC logo assets are not in the repository: text identity only. --}}
                    <a href="{{ route('home') }}" class="group min-w-0 max-w-[17rem] rounded-lg {{ $focus }} focus-visible:outline-offset-4">
                        <span class="block text-xl leading-tight font-extrabold tracking-[0.08em] uppercase transition-colors group-hover:text-civic-gold">{{ __('BUKSU COMELEC') }}</span>
                        <span class="block text-[0.68rem] leading-snug font-semibold tracking-wide text-civic-gold uppercase">{{ __('Bukidnon State University') }} <span aria-hidden="true">•</span> {{ __('Student Commission on Elections') }}</span>
                    </a>

                    <nav aria-label="{{ __('Main') }}" class="order-3 w-full lg:order-none lg:w-auto lg:flex-1">
                        <ul class="flex flex-wrap items-center gap-1 text-sm lg:mx-auto lg:flex-nowrap lg:whitespace-nowrap lg:w-fit lg:rounded-full lg:border lg:border-white/10 lg:bg-white/[0.04] lg:p-1">
                            @foreach ($nav as $item)
                                <li>
                                    @if ($item['href'])
                                        <a href="{{ $item['href'] }}"
                                           @if ($item['current'] ?? false) aria-current="page" @endif
                                           class="inline-flex min-h-10 items-center rounded-full px-3.5 font-medium transition-colors {{ $focus }} {{ ($item['current'] ?? false) ? 'bg-civic-gold text-plum' : 'text-warm-light hover:bg-white/10 hover:text-civic-gold' }}">{{ __($item['label']) }}</a>
                                    @else
                                        <span aria-disabled="true" title="{{ __('Not available yet') }}" class="inline-flex min-h-10 cursor-not-allowed items-center rounded-full px-3.5 font-medium text-warm-muted/60">{{ __($item['label']) }}</span>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    </nav>

                    <div class="ml-auto flex flex-wrap items-center gap-3">
                        <span class="inline-flex items-center gap-2 rounded-full border border-white/10 bg-white/[0.04] px-3.5 py-1.5 text-xs font-semibold tracking-wide text-warm-muted uppercase">
                            <span class="relative flex size-2" aria-hidden="true">
                                @if ($electionStatus)
                                    <span class="welcome-ping absolute inline-flex size-full rounded-full bg-civic-gold"></span>
                                    <span class="relative inline-flex size-2 rounded-full bg-civic-gold"></span>
                                @else
                                    <span class="relative inline-flex size-2 rounded-full bg-warm-muted/60"></span>
                                @endif
                            </span>
                            {{ __('Election status') }}: {{ $electionStatus ? __($electionStatus) : __('unavailable') }}
                        </span>
                        <a href="{{ route('auth.google.redirect') }}"
                           class="welcome-btn relative inline-flex min-h-10 items-center overflow-hidden rounded-full bg-civic-gold px-5 text-sm font-semibold text-plum shadow-[0_8px_24px_-8px] shadow-civic-gold/50 transition hover:-translate-y-0.5 hover:shadow-civic-gold/70 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-warm-light">
                            <span class="welcome-shine pointer-events-none absolute inset-0" aria-hidden="true"></span>
                            <span class="relative">{{ __('Continue with Google') }}</span>
                        </a>
                    </div>
                </div>
            </header>

            <main class="mx-auto max-w-7xl px-4 py-12 sm:px-6 sm:py-16 lg:px-8">
                @if (session('status'))
                    <p role="status" class="welcome-rise mb-8 flex items-start gap-3 rounded-2xl border border-civic-gold/60 bg-civic-gold/10 px-4 py-3 text-sm text-warm-light">
                        <flux:icon.information-circle class="mt-0.5 size-5 shrink-0 text-civic-gold" />
                        <span>{{ session('status') }}</span>
                    </p>
                @endif

                {{-- Hero --}}
                <section aria-labelledby="hero-title" class="grid items-stretch gap-12 lg:grid-cols-2 lg:gap-16">
                    <div>
                        <p class="welcome-rise inline-flex items-center gap-2 rounded-full border border-civic-gold/40 bg-civic-gold/10 px-4 py-1.5 text-xs font-semibold tracking-[0.2em] text-civic-gold uppercase" style="--d: 0ms">
                            <span class="size-1.5 rounded-full bg-civic-gold" aria-hidden="true"></span>
                            {{ __('BUKSU Student COMELEC 2026') }}
                        </p>
                        <h1 id="hero-title" class="welcome-rise mt-6 font-serif text-5xl leading-[1.02] font-bold tracking-tight sm:text-6xl lg:text-7xl" style="--d: 90ms">
                            <span class="block">{{ __('Your Voice.') }}</span>
                            <span class="block">{{ __('Your Vote.') }}</span>
                            <span class="block text-civic-gold">{{ __('Our Future.') }}</span>
                        </h1>
                        <p class="welcome-rise mt-7 max-w-md text-lg leading-relaxed text-warm-light" style="--d: 180ms">{{ __('A secure, transparent, and inclusive election system for a stronger BukSU tomorrow.') }}</p>

                        <div class="welcome-rise mt-9 flex flex-wrap gap-3" style="--d: 270ms">
                            <a href="{{ route('auth.google.redirect') }}"
                               class="welcome-btn group relative inline-flex min-h-12 items-center gap-2 overflow-hidden rounded-full bg-civic-gold px-7 text-base font-semibold text-plum shadow-[0_14px_34px_-12px] shadow-civic-gold/60 transition hover:-translate-y-0.5 hover:shadow-civic-gold/80 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-warm-light">
                                <span class="welcome-shine pointer-events-none absolute inset-0" aria-hidden="true"></span>
                                <span class="relative">{{ __('Continue with Google') }}</span>
                                <flux:icon.arrow-right class="relative size-4 transition-transform motion-safe:group-hover:translate-x-0.5" />
                            </a>
                            <a href="#election"
                               class="group inline-flex min-h-12 items-center gap-2 rounded-full border border-white/20 bg-white/[0.04] px-7 text-base font-semibold text-warm-light backdrop-blur-sm transition hover:-translate-y-0.5 hover:border-civic-gold hover:bg-white/10 {{ $focus }}">
                                {{ __('Explore Election') }}
                                <flux:icon.arrow-down class="size-4 text-civic-gold transition-transform motion-safe:group-hover:translate-y-0.5" />
                            </a>
                        </div>

                        <dl class="mt-12 grid gap-4 sm:grid-cols-3">
                            @foreach ([
                                ['Transparent', 'Open and accountable election process.', 'eye'],
                                ['Secure', 'Protected voting and data privacy.', 'shield-check'],
                                ['Student-Powered', 'Built by students, for a stronger BukSU.', 'user-group'],
                            ] as $i => [$term, $text, $icon])
                                <div class="welcome-rise group rounded-2xl border border-white/10 bg-white/[0.04] p-4 transition hover:-translate-y-0.5 hover:border-civic-gold/50 hover:bg-white/[0.07]" style="--d: {{ 360 + $i * 80 }}ms">
                                    <span class="inline-flex size-9 items-center justify-center rounded-xl bg-civic-gold/15 text-civic-gold ring-1 ring-civic-gold/30" aria-hidden="true">
                                        <flux:icon :name="$icon" class="size-5" />
                                    </span>
                                    <dt class="mt-3 font-semibold text-civic-gold">{{ __($term) }}</dt>
                                    <dd class="mt-1 text-sm leading-relaxed text-warm-muted">{{ __($text) }}</dd>
                                </div>
                            @endforeach
                        </dl>
                    </div>

                    {{-- Text-only panel: intentionally no imagery. Decorative rings are pure CSS. --}}
                    <div class="welcome-rise relative flex min-h-72 flex-col items-center justify-center overflow-hidden rounded-[2rem] border border-white/10 bg-gradient-to-br from-comelec/50 via-comelec/20 to-transparent p-8 text-center shadow-[0_30px_80px_-30px_rgba(0,0,0,0.7)] ring-1 ring-inset ring-white/5" style="--d: 200ms">
                        <div class="pointer-events-none absolute inset-0" aria-hidden="true">
                            <div class="welcome-float absolute top-1/2 left-1/2 size-[26rem] -translate-x-1/2 -translate-y-1/2 rounded-full border border-civic-gold/15"></div>
                            <div class="absolute top-1/2 left-1/2 size-[18rem] -translate-x-1/2 -translate-y-1/2 rounded-full border border-civic-gold/20"></div>
                            <div class="absolute top-1/2 left-1/2 size-[10rem] -translate-x-1/2 -translate-y-1/2 rounded-full bg-civic-gold/10 blur-3xl"></div>
                        </div>
                        <span class="relative inline-flex size-14 items-center justify-center rounded-2xl bg-civic-gold/15 text-civic-gold ring-1 ring-civic-gold/40" aria-hidden="true">
                            <flux:icon.check-badge class="size-8" />
                        </span>
                        <p class="relative mt-6 text-3xl font-extrabold tracking-[0.08em] uppercase sm:text-4xl">{{ __('BUKSU COMELEC') }} <span class="text-civic-gold">2.0</span></p>
                        <p class="relative mt-3 text-lg text-warm-muted">{{ __('Student elections, made transparent.') }}</p>
                    </div>
                </section>

                {{-- Lower panels --}}
                <div class="mt-16 grid gap-6 lg:grid-cols-2">
                    <section id="election" aria-labelledby="election-title" class="{{ $card }} scroll-mt-24 p-6 sm:p-8" style="--d: 100ms">
                        <div class="flex items-center gap-4">
                            <span class="inline-flex size-11 shrink-0 items-center justify-center rounded-2xl bg-civic-gold/15 text-civic-gold ring-1 ring-civic-gold/30" aria-hidden="true"><flux:icon.chart-bar class="size-6" /></span>
                            <div>
                                <h2 id="election-title" class="text-2xl font-bold tracking-tight">{{ __('Live Election') }}</h2>
                                <p class="text-sm text-warm-muted">{{ $electionStatus ? __($electionStatus) : __('Election status will appear here when available.') }}</p>
                            </div>
                        </div>

                        <dl class="mt-7 grid grid-cols-1 gap-3 text-center sm:grid-cols-3">
                            @foreach ([['Votes Cast', $fmt($votesCast)], ['Eligible Voters', $fmt($eligibleVoters)], ['Participation', $participation !== null ? $participation.'%' : '—']] as [$term, $value])
                                <div class="flex flex-col rounded-2xl border border-white/10 bg-white/[0.04] px-3 py-4">
                                    <dd class="order-first text-3xl font-semibold tabular-nums tracking-tight">{{ $value }}</dd>
                                    <dt class="mt-1 text-sm text-warm-muted">{{ __($term) }}</dt>
                                </div>
                            @endforeach
                        </dl>

                        @if ($participation !== null)
                            <div class="mt-6" role="progressbar" aria-label="{{ __('Participation') }}" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ min(100, $participation) }}">
                                <div class="h-2.5 overflow-hidden rounded-full bg-white/10"><div class="h-full rounded-full bg-gradient-to-r from-civic-gold/70 to-civic-gold shadow-[0_0_14px] shadow-civic-gold/50 transition-[width] duration-700" style="width: {{ min(100, $participation) }}%"></div></div>
                            </div>
                        @endif

                        <p class="mt-6 flex items-start gap-2 text-sm text-warm-muted">
                            <flux:icon.lock-closed class="mt-0.5 size-4 shrink-0 text-civic-gold" aria-hidden="true" />
                            {{ $electionStatus ? __('Figures shown are from the official election record.') : __('Election information will appear when available.') }}
                        </p>
                    </section>

                    <section id="standings" aria-labelledby="standings-title" class="{{ $card }} scroll-mt-24 p-6 sm:p-8" style="--d: 180ms" x-data="{ tab: 'college' }">
                        <div class="flex flex-wrap items-start justify-between gap-x-4 gap-y-2">
                            <div class="flex items-center gap-4">
                                <span class="inline-flex size-11 shrink-0 items-center justify-center rounded-2xl bg-civic-gold/15 text-civic-gold ring-1 ring-civic-gold/30" aria-hidden="true"><flux:icon.trophy class="size-6" /></span>
                                <div>
                                    <h2 id="standings-title" class="text-2xl font-bold tracking-tight">{{ __('Live Standings') }}</h2>
                                    <p class="text-sm text-warm-muted">{{ __('Official results only.') }}</p>
                                </div>
                            </div>
                            {{-- No public results route exists yet, so this is a label, not a link. --}}
                            <span aria-disabled="true" title="{{ __('Not available yet') }}" class="shrink-0 cursor-not-allowed text-sm sm:mt-2 font-semibold text-warm-muted/60">{{ __('View Full Results') }}</span>
                        </div>

                        <div role="tablist" aria-label="{{ __('Representative category') }}" class="mt-7 grid gap-1 rounded-2xl border border-white/10 bg-black/20 p-1 sm:grid-cols-2">
                            @foreach (['college' => 'College Representatives', 'sectoral' => 'Sectoral Representatives'] as $key => $label)
                                <button type="button" role="tab" id="tab-{{ $key }}" aria-controls="panel-{{ $key }}"
                                        x-on:click="tab = '{{ $key }}'" x-bind:aria-selected="tab === '{{ $key }}'"
                                        x-bind:class="tab === '{{ $key }}' ? 'bg-comelec text-warm-light shadow-md ring-1 ring-white/15' : 'bg-transparent text-civic-gold hover:bg-white/5'"
                                        class="min-h-11 rounded-xl px-4 py-2 text-sm font-semibold transition {{ $focus }}">{{ __($label) }}</button>
                            @endforeach
                        </div>

                        @foreach (['college' => 'Students vote for representatives within their own college or department.', 'sectoral' => 'Voting is university-wide. Seven sectoral positions are planned; their names are not yet confirmed.'] as $key => $scope)
                            <div role="tabpanel" id="panel-{{ $key }}" aria-labelledby="tab-{{ $key }}" tabindex="0"
                                 @if ($key !== 'college') x-cloak x-bind:hidden="tab !== '{{ $key }}'" hidden @else x-bind:hidden="tab !== '{{ $key }}'" @endif
                                 class="mt-5 rounded-xl focus-visible:outline-2 focus-visible:outline-civic-gold">
                                <p class="mb-3 text-sm text-warm-muted">{{ __($scope) }}</p>
                                @if (! empty($standings[$key]))
                                    <ol class="space-y-2">
                                        @foreach ($standings[$key] as $i => $row)
                                            <li class="flex items-center justify-between gap-3 rounded-2xl border border-white/10 bg-white/[0.04] px-4 py-3 text-sm transition hover:border-civic-gold/40 hover:bg-white/[0.07]">
                                                <span class="flex items-center gap-3">
                                                    <span class="inline-flex size-7 items-center justify-center rounded-full {{ $i === 0 ? 'bg-civic-gold text-plum' : 'bg-white/10 text-civic-gold' }} text-xs font-bold tabular-nums">{{ $i + 1 }}</span>
                                                    <span class="font-medium">{{ $row['name'] }}</span>
                                                </span>
                                                <span class="tabular-nums text-warm-muted">{{ $fmt($row['votes'] ?? null) }} · {{ isset($row['percent']) ? $row['percent'].'%' : '—' }}</span>
                                            </li>
                                        @endforeach
                                    </ol>
                                @else
                                    <div class="rounded-2xl border border-dashed border-white/20 px-4 py-8 text-center">
                                        <span class="mx-auto inline-flex size-11 items-center justify-center rounded-full bg-white/[0.06] text-civic-gold" aria-hidden="true"><flux:icon.clock class="size-5" /></span>
                                        <p class="mt-3 text-lg font-bold">{{ __('No standings published') }}</p>
                                        <p class="mx-auto mt-1 max-w-xs text-sm text-warm-muted">{{ __('Candidate rankings will appear when official results data is available.') }}</p>
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </section>
                </div>
            </main>
        </div>
        @fluxScripts
    </body>
</html>