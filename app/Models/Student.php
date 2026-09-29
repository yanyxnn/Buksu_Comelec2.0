<?php

namespace App\Models;

use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Permanent student identity (institutional/student ID) and authentication
 * subject for the `student` guard.
 *
 * Authentication is by Google `sub` (students.google_subject) once linked.
 * `status` (ACTIVE/INACTIVE) is NOT a credential: eligibility to vote is
 * decided by the election's locked eligibility snapshot, never here.
 */
class Student extends Model implements AuthenticatableContract
{
    use Authenticatable, HasFactory;

    protected $table = 'students';

    /**
     * Institutional email is protected from ordinary editing and the Google
     * subject can only be set by the guarded first-link path (StudentLinker in
     * IdentityResolver), never by mass assignment.
     */
    protected $guarded = ['id', 'institutional_email', 'google_subject'];

    protected $hidden = ['google_subject'];

    /**
     * Only students that came from the official Data Center import (which stamps
     * `last_import_batch_id`) may authenticate. Google login never creates students.
     */
    public function isDataCenterLoaded(): bool
    {
        return $this->last_import_batch_id !== null;
    }

    public function displayName(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }

    public function getAuthPassword(): string
    {
        return '';
    }

    public function getRememberTokenName(): string
    {
        return '';
    }
}
