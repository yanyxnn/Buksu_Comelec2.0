<?php

namespace App\Services\Auth;

use App\Models\AdminUser;
use App\Models\Student;

/** Outcome of identity resolution: exactly one of admin / student / denied. */
final class AuthDecision
{
    private function __construct(
        public readonly ?AdminUser $admin,
        public readonly ?Student $student,
        public readonly ?DenialReason $reason,
        public readonly bool $firstLink = false,
    ) {}

    public static function admin(AdminUser $admin): self
    {
        return new self($admin, null, null);
    }

    public static function student(Student $student, bool $firstLink = false): self
    {
        return new self(null, $student, null, $firstLink);
    }

    public static function denied(DenialReason $reason): self
    {
        return new self(null, null, $reason);
    }

    public function isDenied(): bool
    {
        return $this->reason !== null;
    }
}
