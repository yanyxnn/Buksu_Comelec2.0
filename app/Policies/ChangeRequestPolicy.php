<?php

namespace App\Policies;

use App\Models\AdminUser;
use App\Models\ChangeRequest;
use App\Services\Auth\AdminRoster;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Parameters are typed as the generic Authenticatable on purpose: a student
 * reaching this policy must get `false`, not a TypeError.
 */
class ChangeRequestPolicy
{
    public function __construct(private readonly AdminRoster $roster) {}

    public function viewAny(Authenticatable $actor): bool
    {
        return $this->isAuthorizedAdmin($actor);
    }

    public function create(Authenticatable $actor): bool
    {
        return $this->isAuthorizedAdmin($actor);
    }

    public function decide(Authenticatable $actor, ChangeRequest $request): bool
    {
        return $this->isAuthorizedAdmin($actor)
            && $request->status === ChangeRequest::STATUS_PENDING
            && (int) $request->requested_by !== (int) $actor->getAuthIdentifier();
    }

    private function isAuthorizedAdmin(Authenticatable $actor): bool
    {
        return $actor instanceof AdminUser
            && $actor->role === AdminUser::ROLE
            && $this->roster->isIntact();
    }
}
