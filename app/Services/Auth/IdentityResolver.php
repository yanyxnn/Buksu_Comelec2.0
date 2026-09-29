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
 * Google's stable `sub` is the identity. Email is used only for the one-time
 * first-link of a student.
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

        $admin = AdminUser::query()->where('google_subject', $sub)->first();
        $studentBySub = Student::query()->where('google_subject', $sub)->first();
        $studentsByEmail = Student::query()
            ->whereRaw('LOWER(institutional_email) = ?', [$email])
            ->limit(2)
            ->get();

        // One Google account must never resolve to both domains.
        if ($admin !== null && ($studentBySub !== null || $studentsByEmail->isNotEmpty())) {
            return AuthDecision::denied(DenialReason::IdentityConflict);
        }

        if ($admin !== null) {
            if ($admin->role !== AdminUser::ROLE || ! $this->roster->isIntact()) {
                return AuthDecision::denied(DenialReason::RosterInvalid);
            }

            return AuthDecision::admin($admin);
        }

        return $this->resolveStudent($sub, $email, $studentBySub, $studentsByEmail->all());
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
