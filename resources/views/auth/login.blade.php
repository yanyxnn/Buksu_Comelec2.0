<x-layouts.auth>
    <div class="flex flex-col gap-6 text-center">
        <div>
            <h1 class="text-xl font-semibold">{{ __('Sign in') }}</h1>
            <p class="mt-1 text-sm text-neutral-500">{{ __('Use your Google account to continue.') }}</p>
        </div>

        {{-- One generic message for every failure; never says why. --}}
        @if (session('login_error'))
            <p role="alert" class="rounded-md border border-red-300 p-3 text-sm text-red-600">{{ session('login_error') }}</p>
        @endif

        <a href="{{ route('auth.google.redirect') }}" class="rounded-md border px-4 py-2 text-sm font-medium">
            {{ __('Continue with Google') }}
        </a>
    </div>
</x-layouts.auth>
