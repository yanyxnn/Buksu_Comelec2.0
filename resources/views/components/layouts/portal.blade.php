@props(['title' => null])
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head', ['title' => $title ?? config('app.name')])
    </head>
    <body class="min-h-screen bg-white antialiased dark:bg-neutral-950 text-neutral-900 dark:text-neutral-100">
        @php
            $identity = auth('admin')->user() ?? auth('student')->user();
            $identityName = $identity instanceof \App\Models\AdminUser
                ? $identity->display_name
                : ($identity instanceof \App\Models\Student ? $identity->displayName() : null);
        @endphp
        <header class="flex items-center justify-between border-b border-neutral-200 px-6 py-3 dark:border-neutral-800">
            <span class="font-semibold">{{ config('app.name') }}</span>
            <div class="flex items-center gap-4 text-sm">
                @if ($identityName)
                    <span>{{ $identityName }}</span>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="underline">{{ __('Log out') }}</button>
                    </form>
                @endif
            </div>
        </header>
        <main class="mx-auto max-w-4xl p-6">
            {{ $slot }}
        </main>
        @fluxScripts
    </body>
</html>
