{{--
    x-ui.empty-state — "nothing here yet" block. The default slot holds the optional call-to-action.
    <x-ui.empty-state title="No imports yet" description="..." icon="inbox"><x-ui.button variant="primary">Start</x-ui.button></x-ui.empty-state>
--}}
@props([
    'title',
    'description' => null,
    'icon' => 'inbox',
])

<div {{ $attributes->class('flex flex-col items-center px-6 py-10 text-center') }}>
    <div class="flex size-10 items-center justify-center rounded-ui border border-line bg-surface-sunken text-muted">
        <flux:icon :name="$icon" class="size-5" />
    </div>
    <h3 class="mt-4 ui-section-title">{{ $title }}</h3>
    @if ($description)
        <p class="mt-1 max-w-sm text-sm text-muted">{{ $description }}</p>
    @endif
    @if (! $slot->isEmpty())
        <div class="mt-4 flex flex-wrap items-center justify-center gap-2">{{ $slot }}</div>
    @endif
</div>
