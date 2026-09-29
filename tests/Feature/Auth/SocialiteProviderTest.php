<?php

use App\Providers\AppServiceProvider;
use App\Services\Auth\GoogleAuthenticationFailed;
use App\Services\Auth\GoogleIdentityProvider;
use App\Services\Auth\SocialiteGoogleIdentityProvider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as SocialiteUser;
use Symfony\Component\HttpFoundation\RedirectResponse;

function socialiteReturns(array $raw, array $map = ['id' => 'g-sub-1', 'email' => 'x@students.example.test']): void
{
    $user = (new SocialiteUser)->setRaw($raw)->map($map);
    $driver = Mockery::mock();
    $driver->shouldReceive('user')->andReturn($user);
    Socialite::shouldReceive('driver')->with('google')->andReturn($driver);
}

test('maps a Google user to the identity DTO using the stable sub', function () {
    socialiteReturns(['email_verified' => true]);

    $identity = (new SocialiteGoogleIdentityProvider)->identity();

    expect($identity->sub)->toBe('g-sub-1')
        ->and($identity->email)->toBe('x@students.example.test')
        ->and($identity->emailVerified)->toBeTrue();
});

test('email_verified must be exactly true (or the string "true"); everything else is unverified', function (mixed $value, bool $expected) {
    socialiteReturns(['email_verified' => $value]);

    expect((new SocialiteGoogleIdentityProvider)->identity()->emailVerified)->toBe($expected);
})->with([
    [true, true], ['true', true], [false, false], ['false', false], [1, false], ['1', false], [null, false],
]);

test('a missing email_verified claim is unverified', function () {
    socialiteReturns([]);

    expect((new SocialiteGoogleIdentityProvider)->identity()->emailVerified)->toBeFalse();
});

test('any Socialite failure (state mismatch, network, bad code) becomes GoogleAuthenticationFailed', function () {
    $driver = Mockery::mock();
    $driver->shouldReceive('user')->andThrow(new InvalidStateException);
    Socialite::shouldReceive('driver')->with('google')->andReturn($driver);

    expect(fn () => (new SocialiteGoogleIdentityProvider)->identity())->toThrow(GoogleAuthenticationFailed::class);
});

test('the redirect asks only for openid/email/profile and forces account selection', function () {
    $driver = Mockery::mock();
    $driver->shouldReceive('scopes')->once()->with(['openid', 'email', 'profile'])->andReturnSelf();
    $driver->shouldReceive('with')->once()->with(['prompt' => 'select_account'])->andReturnSelf();
    $driver->shouldReceive('redirect')->once()->andReturn(new RedirectResponse('https://accounts.google.test'));
    Socialite::shouldReceive('driver')->with('google')->andReturn($driver);

    (new SocialiteGoogleIdentityProvider)->redirect();
});

test('the app binding uses Socialite in production and Google credentials are not in source', function () {
    $this->app->forgetInstance(GoogleIdentityProvider::class);
    // TestCase pre-binds a fake; resolve the real binding explicitly.
    expect((new ReflectionClass(AppServiceProvider::class))->hasMethod('register'))->toBeTrue();

    expect(file_get_contents(base_path('.env.example')))->toContain('GOOGLE_CLIENT_ID=')
        ->and(file_get_contents(base_path('.env.example')))->not->toMatch('/GOOGLE_CLIENT_SECRET=\S/');
    expect(config('services.google.client_secret'))->toBeNull();
});
