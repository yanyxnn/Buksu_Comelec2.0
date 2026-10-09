<?php

/*
 * Frontend Build 01: public header + hero. Presentation only; the single primary action is the existing
 * named Google redirect route. Tests here must pass both before and after the approved artwork is added.
 */

test('the welcome page shows the approved headline and the application name', function () {
    $this->get(route('home'))
        ->assertOk()
        ->assertSeeText('Your Voice.')
        ->assertSeeText('Your Vote.')
        ->assertSeeText('Our Future.')
        ->assertSeeText(config('app.name'));
});

test('the single primary action links to the Google redirect route', function () {
    $html = $this->get(route('home'))->assertOk()->getContent();

    expect(substr_count($html, 'Continue with Google'))->toBe(1);
    expect(substr_count($html, 'href="'.route('auth.google.redirect').'"'))->toBe(1);
    expect($html)->not->toContain('href="'.route('login').'"');
});

test('the welcome page still displays a one-time status message', function () {
    $this->withSession(['status' => 'Your access issue was submitted.'])
        ->get(route('home'))
        ->assertOk()
        ->assertSee('Your access issue was submitted.');
});

test('every collage tile is exactly one of: an image or a branded fallback (independent of which assets exist)', function () {
    $html = $this->get(route('home'))->assertOk()->getContent();

    expect(substr_count($html, '<img') + substr_count($html, 'data-art-fallback'))->toBe(3);
});

test('a tile whose artwork is missing renders the fallback, never an image', function () {
    $html = (string) $this->blade('<x-welcome.art-tile name="__no_such_artwork__" alt="x" label="Missing asset label" />');

    expect($html)->not->toContain('<img')->toContain('data-art-fallback')->toContain('aria-hidden="true"');
});

test('the fallback shows its diagnostic label outside production only', function () {
    $tile = '<x-welcome.art-tile name="__no_such_artwork__" alt="x" label="Missing asset label" />';

    expect((string) $this->blade($tile))->toContain('Missing asset label');

    $this->app->detectEnvironment(fn () => 'production');

    expect((string) $this->blade($tile))->toContain('data-art-fallback')->not->toContain('Missing asset label');
});

test('a tile whose artwork exists renders a responsive image', function () {
    $dir = public_path('images/welcome');
    $files = ["{$dir}/__fixture_art-800.webp", "{$dir}/__fixture_art-1400.webp"];
    array_map(fn ($f) => file_put_contents($f, 'x'), $files);

    try {
        $html = (string) $this->blade('<x-welcome.art-tile name="__fixture_art" alt="Fixture alt" label="L" />');
    } finally {
        array_map('unlink', $files);
    }

    expect($html)->toContain('<img')->toContain('alt="Fixture alt"')->toContain('800w')->toContain('1400w')->not->toContain('data-art-fallback');
});
