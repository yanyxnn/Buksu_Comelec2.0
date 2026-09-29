<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head', ['title' => config('app.name')])
    </head>
    <body class="flex min-h-screen items-center justify-center bg-white antialiased dark:bg-neutral-950 text-neutral-900 dark:text-neutral-100">
        <div class="text-center">
            <h1 class="text-2xl font-semibold">{{ config('app.name') }}</h1>
            <p class="mt-2 text-sm text-neutral-500">{{ __('Student election platform') }}</p>
            @if (session('status'))
                <p role="status" class="mt-4 text-sm">{{ session('status') }}</p>
            @endif
            <a href="{{ route('login') }}" class="mt-6 inline-block rounded-md border px-4 py-2 text-sm">{{ __('Sign in') }}</a>
        </div>
    </body>
</html>
