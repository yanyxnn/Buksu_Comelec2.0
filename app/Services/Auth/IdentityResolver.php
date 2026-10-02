<?php

namespace App\Services\Auth;

use App\Models\AdminUser;
use App\Models\Student;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Resolves a verified Google identity to EXACTLY ONE identity domain (student
 * or admin) or denies it. It never creates students or admins, never writes
 * anything from Google into student data, and never lets an account that
 * touches both domains authenticate as either.
 *
 * Google's stable `sub` is the identity once bound. Email is used only for the
 * one-time first-link: of a student (verified institutional email + existing Data
 * Center-loaded row) or of a PRE-AUTHORIZED administrator (verified email matching
 * `admin_users.authorized_email`). Login never creates either kind of account.
 */
class IdentityResolver
{
    public function __construct(private readonly AdminRoster $roster) {}

    public function resolve(GoogleIdentity $google): AuthDecision
    {
        $sub = trim($google->sub);
        $email = mb_strtolower(trim($google->email));

        if ($sub === '' || $email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return AuthDecision::denied(DenialReason::MalformedIdentity);
        }

        if (! $google->emailVerified) {
            return AuthDecision::denied(DenialReason::EmailNotVerified);
        }

        $adminBySub = AdminUser::query()->where('google_subject', $sub)->first();
        $studentBySub = Student::query()->where('google_subject', $sub)->first();
        $studentsByEmail = Student::query()
            ->whereRaw('LOWER(institutional_email) = ?', [$email])
            ->limit(2)
            ->get();

        // One Google account must never resolve to both domains.
        $touchesStudentDomain = $studentBySub !== null || $studentsByEmail->isNotEmpty();

        // Repeat admin login: the bound subject is authoritative; email is never used to rebind.
        if ($adminBySub !== null) {
            if ($touchesStudentDomain) {
                return AuthDecision::denied(DenialReason::IdentityConflict);
            }

            return $this->admittedAdmin($adminBySub);
        }

        // No admin is bound to this subject: is the verified email a PRE-AUTHORIZED administrator?
        $adminsByEmail = AdminUser::query()
            ->whereRaw('LOWER(authorized_email) = ?', [$email])
            ->limit(2)
            ->get();

        if ($adminsByEmail->count() > 1) {
            return AuthDecision::denied(DenialReason::AmbiguousEmail);
        }

        if (($adminByEmail = $adminsByEmail->first()) !== null) {
            $bound = $adminByEmail->google_subject;

            // Authorized email already bound to a DIFFERENT subject: never replace the binding.
            if ($bound !== null && $bound !== $sub) {
                return AuthDecision::denied(DenialReason::AdminEmailBoundToOtherSubject);
            }

            if ($touchesStudentDomain) {
                return AuthDecision::denied(DenialReason::IdentityConflict);
            }

            $admitted = $this->admittedAdmin($adminByEmail);

            // $bound === $sub: a twin request from this same Google account bound it a moment ago.
            return ($bound !== null || $admitted->isDenied()) ? $admitted : $this->firstLinkAdmin($adminByEmail, $sub, $email);
        }

        // Not an administrator (and never created as one): fall through to the student domain.
        return $this->resolveStudent($sub, $email, $studentBySub, $studentsByEmail->all());
    }

    /** Role and current authorized-roster integrity must hold before an admin is admitted. */
    private function admittedAdmin(AdminUser $admin): AuthDecision
    {
        if ($admin->role !== AdminUser::ROLE || ! $this->roster->isIntact()) {
            return AuthDecision::denied(DenialReason::RosterInvalid);
        }

        return AuthDecision::admin($admin);
    }

    /**
     * Atomic first-link of a pre-authorized administrator: sets google_subject only while it
     * is still NULL (and the row still carries the matching authorized email), re-checks the
     * student table inside the transaction, and relies on the UNIQUE index on google_subject.
     * A competing first-link can never overwrite the winner.
     */
    private function firstLinkAdmin(AdminUser $admin, string $sub, string $email): AuthDecision
    {
        try {
            $linked = DB::transaction(function () use ($admin, $sub, $email): AdminUser {
                $affected = AdminUser::query()
                    ->whereKey($admin->getKey())
                    ->whereNull('google_subject')
                    ->whereRaw('LOWER(authorized_email) = ?', [$email])
                    ->update(['google_subject' => $sub]);

                if ($affected !== 1) {
                    throw new RuntimeException('First-link rejected.');
                }

                if (Student::query()->where('google_subject', $sub)->exists()) {
                    throw new RuntimeException('First-link rejected.');
                }

                return $admin->refresh();
            });
        } catch (QueryException|RuntimeException) {
            // Lost the race (another identity bound it first) or the cross-table re-check failed.
            return AuthDecision::denied(DenialReason::LinkFailed);
        }

        return AuthDecision::admin($linked, firstLink: true);
    }

    /**
     * @param  list<Student>  $byEmail
     */
    private function resolveStudent(string $sub, string $email, ?Student $bySub, array $byEmail): AuthDecision
    {
        if (! $this->isInstitutionalEmail($email)) {
            return AuthDecision::denied(DenialReason::DomainNotAllowed);
        }

        if (count($byEmail) > 1) {
            return AuthDecision::denied(DenialReason::AmbiguousEmail);
        }

        $matchedByEmail = $byEmail[0] ?? null;

        if ($bySub !== null) {
            // Returning student: identity is the stable subject. If the email
            // also names a DIFFERENT student record, something is wrong: deny.
            if ($matchedByEmail !== null && $matchedByEmail->getKey() !== $bySub->getKey()) {
                return AuthDecision::denied(DenialReason::IdentityMismatch);
            }

            if (! $bySub->isDataCenterLoaded()) {
                return AuthDecision::denied(DenialReason::NotDataCenterLoaded);
            }

            return AuthDecision::student($bySub);
        }

        if ($matchedByEmail === null) {
            return AuthDecision::denied(DenialReason::NoStudentRecord);
        }

        // Email matches a student that is already bound to some other subject.
        if ($matchedByEmail->google_subject !== null) {
            return AuthDecision::denied(DenialReason::EmailBoundToOtherSubject);
        }

        // A row that did not come from the Data Center import is not a student for login purposes.
        if (! $matchedByEmail->isDataCenterLoaded()) {
            return AuthDecision::denied(DenialReason::NotDataCenterLoaded);
        }

        return $this->firstLink($matchedByEmail, $sub);
    }

    /**
     * Atomic first-link: sets google_subject only while it is still NULL,
     * re-checks the admin table inside the transaction, and relies on the
     * UNIQUE index for "one subject, one student".
     */
    private function firstLink(Student $student, string $sub): AuthDecision
    {
        try {
            $linked = DB::transaction(function () use ($student, $sub): Student {
                $affected = Student::query()
                    ->whereKey($student->getKey())
                    ->whereNull('google_subject')
                    ->update(['google_subject' => $sub]);

                if ($affected !== 1) {
                    throw new RuntimeException('First-link rejected.');
                }

                if (AdminUser::query()->where('google_subject', $sub)->exists()) {
                    throw new RuntimeException('First-link rejected.');
                }

                return $student->refresh();
            });
        } catch (QueryException|RuntimeException) {
            return AuthDecision::denied(DenialReason::LinkFailed);
        }

        return AuthDecision::student($linked, firstLink: true);
    }

    /** Email belongs to the configured institutional domain (unset domain => false, fail closed). */
    public function isInstitutionalEmail(string $email): bool
    {
        $email = mb_strtolower(trim($email));

        $configured = strtolower(trim((string) config('comelec.student_email_domain')));

        if ($configured === '') {
            return false; // unconfigured => fail closed
        }

        $domain = substr(strrchr($email, '@') ?: '', 1);

        return $domain !== '' && strtolower($domain) === $configured;
    }
}
