<?php

namespace App\Services\Auth;

use App\Models\AdminUser;

/**
 * Current authorized-roster integrity check (an OPERATIONAL policy, not a data-model rule).
 *
 * The expected roster size comes from `comelec.admin_roster_size` (currently 3). It is
 * deliberately not a schema constraint and not an application-wide maximum: the
 * database allows any number of admin rows, and changing the operational roster is a
 * configuration change, not a code change.
 *
 * On every admin authentication and every admin request the roster must match the
 * configured size and every row must carry the fixed role; otherwise admin access
 * fails closed.
 */
class AdminRoster
{
    /** The currently configured operational roster size (0 or invalid => no roster is valid). */
    public function expectedCount(): int
    {
        return max(0, (int) config('comelec.admin_roster_size', 3));
    }

    public function isIntact(): bool
    {
        $expected = $this->expectedCount();

        if ($expected < 1) {
            return false; // misconfiguration fails closed
        }

        $roles = AdminUser::query()->pluck('role');

        return $roles->count() === $expected
            && $roles->every(fn ($role) => $role === AdminUser::ROLE);
    }
}
