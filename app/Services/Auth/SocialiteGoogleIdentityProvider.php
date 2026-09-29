<?php

namespace App\Services\Auth;

use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Throwable;

/**
 * Socialite-backed Google provider. Stateful (state parameter verified by
 * Socialite). Access/refresh tokens are read by Socialite but never stored or
 * logged by the application.
 */
class SocialiteGoogleIdentityProvider implements GoogleIdentityProvider
{
    public function redirect(): RedirectResponse
    {
        return Socialite::driver('google')
            ->scopes(['openid', 'email', 'profile'])
            ->with(['prompt' => 'select_account'])
            ->redirect();
    }

    public function identity(): GoogleIdentity
    {
        try {
            $user = Socialite::driver('google')->user();
            $raw = is_array($user->user ?? null) ? $user->user : [];

            $sub = $user->getId();
            $email = $user->getEmail();

            if (! is_string($sub) && ! is_int($sub)) {
                throw new GoogleAuthenticationFailed('Missing subject.');
            }

            // Google returns a boolean; tolerate the string form but nothing looser.
            $verified = ($raw['email_verified'] ?? null);
            $verified = $verified === true || $verified === 'true';

            return new GoogleIdentity((string) $sub, is_string($email) ? $email : '', $verified);
        } catch (GoogleAuthenticationFailed $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new GoogleAuthenticationFailed('Google authentication failed.', 0, $e);
        }
    }
}
