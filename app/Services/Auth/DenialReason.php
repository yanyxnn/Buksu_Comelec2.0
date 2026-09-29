<?php

namespace App\Services\Auth;

use App\Services\Audit\AuditLogger;

/**
 * Internal, audit-only reasons. NEVER shown to the user: the user-facing
 * message is identical for every denial so the response cannot reveal whether a
 * Google identity is an admin, a student or unknown.
 */
enum DenialReason: string
{
    case MalformedIdentity = 'MALFORMED_IDENTITY';
    case EmailNotVerified = 'EMAIL_NOT_VERIFIED';
    case DomainNotAllowed = 'DOMAIN_NOT_ALLOWED';
    case NoStudentRecord = 'NO_STUDENT_RECORD';
    case NotDataCenterLoaded = 'NOT_DATA_CENTER_LOADED';
    case AmbiguousEmail = 'AMBIGUOUS_EMAIL';
    case EmailBoundToOtherSubject = 'EMAIL_BOUND_TO_OTHER_SUBJECT';
    case IdentityMismatch = 'IDENTITY_MISMATCH';
    case IdentityConflict = 'IDENTITY_CONFLICT';
    case RosterInvalid = 'ADMIN_ROSTER_INVALID';
    case LinkFailed = 'LINK_FAILED';
    case ProviderError = 'PROVIDER_ERROR';

    public function severity(): string
    {
        return match ($this) {
            self::IdentityConflict, self::RosterInvalid, self::IdentityMismatch, self::EmailBoundToOtherSubject => AuditLogger::SECURITY,
            default => AuditLogger::WARNING,
        };
    }
}
