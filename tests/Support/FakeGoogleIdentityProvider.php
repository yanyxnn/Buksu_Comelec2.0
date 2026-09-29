<?php

namespace Tests\Support;

use App\Services\Auth\GoogleAuthenticationFailed;
use App\Services\Auth\GoogleIdentity;
use App\Services\Auth\GoogleIdentityProvider;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Throwable;

/** Controlled stand-in for Google/Socialite. Makes no network calls. */
class FakeGoogleIdentityProvider implements GoogleIdentityProvider
{
    public function __construct(
        private readonly ?GoogleIdentity $identity = null,
        private readonly ?Throwable $failure = null,
    ) {}

    public function redirect(): RedirectResponse
    {
        return new RedirectResponse('https://accounts.google.test/o/oauth2/auth');
    }

    public function identity(): GoogleIdentity
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }

        return $this->identity ?? throw new GoogleAuthenticationFailed('No fake identity configured.');
    }
}
