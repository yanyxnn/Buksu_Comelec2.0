<?php

namespace App\Services\Auth;

/** What we accept from Google: the stable subject, the email and whether Google verified it. */
final class GoogleIdentity
{
    public function __construct(
        public readonly string $sub,
        public readonly string $email,
        public readonly bool $emailVerified,
    ) {}
}
