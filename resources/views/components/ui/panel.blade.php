{{--
    x-ui.panel — a titled section: optional heading + description, optional `actions` slot (top right),
    body (default slot), optional `footer` slot.

    <x-ui.panel title="Recent activity" description="Last 7 days">
        <x-slot:actions><x-ui.button size="sm">Export</x-ui.button></x-slot:actions>
        ...body...
        <x-slot:footer>...</x-slot:footer>
    </x-ui.panel>
--}}
@props([
    'title' => null,
    'description' => null,
    'actions' => null,
    'footer' => null,
])

<x-ui.card padding="none" {{ $attributes }}>
    @if ($title || $actions)
        <div class="flex items-start justify-between gap-4 border-b border-line px-4 py-3">
            <div class="min-w-0">
                @if ($title)
                    <h2 class="font-heading text-base font-semibold text-ink">{{ $title }}</h2>
                @endif
                @if ($description)
                    <p class="mt-0.5 text-sm text-muted">{{ $description }}</p>
                @endif
            </div>
            @if ($actions)
                <div class="flex shrink-0 items-center gap-2">{{ $actions }}</div>
            @endif
        </div>
    @endif

    <div class="p-4">{{ $slot }}</div>

    @if ($footer)
        <div class="rounded-b-ui border-t border-line bg-surface-subtle px-4 py-3 text-sm text-muted">{{ $footer }}</div>
    @endif
</x-ui.card>
