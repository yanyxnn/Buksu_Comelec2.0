{{--
    ============================================================================================
    TEMPORARY — Phase 03B-1A component demo. Not part of the product.
    Served only when APP_ENV is local or testing, at /_dev/ui-components (routes/web.php).
    REMOVE together with: this folder (resources/views/dev/), the marked route in routes/web.php,
    and tests/Feature/UiComponentsTest.php. No application page depends on it.
    ============================================================================================
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    @include('partials.head', ['title' => 'UI components (temporary demo)'])
</head>
<body class="min-h-screen bg-canvas text-ink antialiased">
    <x-ui.page-container size="narrow">
        <div class="flex items-center justify-between gap-3">
            <div class="flex items-center"><x-app-logo /></div>
            {{-- Light/dark toggle: Flux's own appearance state ($flux.dark), no custom JS. --}}
            <x-ui.button size="sm" icon="moon" x-data x-on:click="$flux.dark = ! $flux.dark">Toggle dark</x-ui.button>
        </div>

        <x-ui.alert tone="warning" title="Temporary demo page">
            Shows the reusable components together. Not a dashboard; not the final design.
        </x-ui.alert>

        <div class="grid grid-cols-2 gap-3">
            <x-ui.stat-card label="Example figure" value="1,234" hint="Static sample value" />
            <x-ui.stat-card label="Another figure" value="56" />
        </div>

        <x-ui.panel title="Buttons and badges" description="Variants share one vocabulary.">
            <x-slot:actions>
                <x-ui.icon-button icon="arrow-path" label="Refresh" />
            </x-slot:actions>

            <div class="flex flex-wrap gap-2">
                <x-ui.button variant="primary">Primary</x-ui.button>
                <x-ui.button>Secondary</x-ui.button>
                <x-ui.button variant="danger">Danger</x-ui.button>
                <x-ui.button variant="ghost">Ghost</x-ui.button>
            </div>

            <div class="mt-4 flex flex-wrap gap-2">
                <x-ui.badge>Neutral</x-ui.badge>
                <x-ui.badge tone="info">Info</x-ui.badge>
                <x-ui.status-badge status="ACTIVE" />
                <x-ui.status-badge status="PENDING" />
                <x-ui.status-badge status="PROCESSING" />
                <x-ui.status-badge status="FAILED" />
                <x-ui.status-badge status="INACTIVE" />
            </div>

            <x-slot:footer>Footer slot</x-slot:footer>
        </x-ui.panel>

        <div class="space-y-2">
            <x-ui.alert tone="info">Informational message.</x-ui.alert>
            <x-ui.alert tone="success" title="Saved">A success message with a title.</x-ui.alert>
            <x-ui.alert tone="danger" title="Something failed">An error message.</x-ui.alert>
        </div>

        <x-ui.card padding="none">
            <x-ui.empty-state title="Nothing here yet" description="Empty-state copy explains what will appear and what to do next.">
                <x-ui.button variant="primary" size="sm" icon="plus">Primary action</x-ui.button>
            </x-ui.empty-state>
        </x-ui.card>
    </x-ui.page-container>
    @fluxScripts
</body>
</html>
