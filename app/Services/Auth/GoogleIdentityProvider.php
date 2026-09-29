<?php

namespace App\Services\Auth;

use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Seam between the application and Google. Production binds the Socialite
 * implementation; automated tests bind a fake so no real Google call is made.
 */
interface GoogleIdentityProvider
{
    public function redirect(): RedirectResponse;

    /**
     * @throws GoogleAuthenticationFailed on any failure
     */
    public function identity(): GoogleIdentity;
}
