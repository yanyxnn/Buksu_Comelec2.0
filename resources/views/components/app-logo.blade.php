{{-- Logo lock-up: mark + application name (from config('app.name'), never hard-coded). Final treatment: later phase. --}}
<div class="flex aspect-square size-8 items-center justify-center rounded-md bg-accent-content text-accent-foreground">
    <x-app-logo-icon class="size-5" />
</div>
<div class="ml-1 grid flex-1 text-left text-sm">
    <span class="mb-0.5 truncate leading-none font-semibold">{{ config('app.name') }}</span>
</div>
