<?php

use Illuminate\Support\Facades\Route;

/*
 * Phase 03B-1A: the reusable <x-ui.*> components render, and the TEMPORARY demo route stays development-only.
 * Remove the demo test with resources/views/dev/ when the demo is removed (the component tests below stay).
 */

test('the temporary demo page renders every ui component', function () {
    $html = $this->get('/_dev/ui-components')->assertOk()->getContent();

    expect($html)
        ->toContain('Temporary demo page')          // alert
        ->toContain('Example figure')               // stat-card
        ->toContain('Buttons and badges')           // panel title
        ->toContain('Footer slot')                  // panel footer slot
        ->toContain('Nothing here yet')             // empty-state
        ->toContain('role="alert"')                 // warning/danger alert role
        ->toContain('role="status"')                // info/success alert role
        ->toContain('aria-label="Refresh"')         // icon-button accessible name
        ->toContain('Processing')                   // status-badge label derived from PROCESSING
        ->not->toContain('Laravel Starter Kit');
});

test('the demo route is not registered outside local and testing', function () {
    expect(Route::has('dev.ui-components'))->toBeTrue(); // testing env: registered

    // The gate lives in routes/web.php; assert its condition rather than re-booting the app.
    $source = file_get_contents(base_path('routes/web.php'));
    expect($source)->toContain("app()->environment(['local', 'testing'])");
});

test('status-badge maps statuses to tones and falls back to neutral', function (string $status, string $toneClass) {
    $html = (string) $this->blade('<x-ui.status-badge :status="$status" />', ['status' => $status]);

    expect($html)->toContain($toneClass);
})->with([
    ['ACTIVE', 'bg-success-bg'],
    ['PENDING', 'bg-warning-bg'],
    ['PROCESSING', 'bg-info-bg'],
    ['FAILED', 'bg-danger-bg'],
    ['INACTIVE', 'bg-surface-sunken'],
    ['SOMETHING_UNMAPPED', 'bg-surface-sunken'],
]);

test('components pass through attributes and slots', function () {
    $html = (string) $this->blade('<x-ui.panel title="T" class="extra-class" data-x="1">Body</x-ui.panel>');

    expect($html)->toContain('extra-class')->toContain('data-x="1"')->toContain('Body');
});

test('the application name comes from config, not from starter-kit branding', function () {
    config(['app.name' => 'Name From Config']);

    $html = (string) $this->blade('<x-app-logo />');
    expect($html)->toContain('Name From Config')->not->toContain('Laravel Starter Kit');
});
