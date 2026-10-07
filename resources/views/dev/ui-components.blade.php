{{--
    ============================================================================================
    TEMPORARY — Phase 03B-1A/1B component and visual-system demo. Not part of the product.
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

        <div>
            <p class="ui-eyebrow">Visual system</p>
            <h1 class="ui-page-title mt-1">Page title sample</h1>
            <p class="mt-2 max-w-prose text-base text-muted">Body text in the sans face; muted text for supporting detail. <span class="text-subtle">Subtle text for hints.</span></p>
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
            <div class="mt-2 flex flex-wrap gap-2">
                <x-ui.button variant="primary" disabled>Primary disabled</x-ui.button>
                <x-ui.button disabled>Secondary disabled</x-ui.button>
                <x-ui.button variant="danger" disabled>Danger disabled</x-ui.button>
            </div>
            <p class="mt-2 text-xs text-subtle">Press Tab to check the keyboard focus ring on every control.</p>

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

        <x-ui.panel title="Form foundation" description="Inputs, select, textarea, validation. Visual sample only.">
            <div class="grid gap-4">
                <flux:input label="Text input" placeholder="Placeholder text" />
                <flux:input label="Disabled input" value="Not editable" disabled />
                <flux:input label="Invalid input" value="bad value" invalid />
                <flux:error message="This field has a validation message." />
                <flux:select label="Select" placeholder="Choose one">
                    <flux:select.option>First option</flux:select.option>
                    <flux:select.option>Second option</flux:select.option>
                </flux:select>
                <flux:textarea label="Textarea" rows="3" />
            </div>
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
