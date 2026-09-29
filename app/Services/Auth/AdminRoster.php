<?php

namespace App\Services\Auth;

use App\Models\AdminUser;

/**
 * "Exactly three authorized IT admins" is an operational/provisioning control
 * (DOMAIN_MODEL §12, DATABASE.md) that the schema deliberately does not enforce
 * as a cardinality constraint. It is therefore enforced here, at runtime, on
 * every admin authentication and every admin request: if the roster is not
 * exactly three admins that all carry the fixed role, admin access fails closed.
 */
class AdminRoster
{
    public const REQUIRED_COUNT = 3;

    public function isIntact(): bool
    {
        $roles = AdminUser::query()->pluck('role');

        return $roles->count() === self::REQUIRED_COUNT
            && $roles->every(fn ($role) => $role === AdminUser::ROLE);
    }
}
