<?php

namespace App\Services\Auth;

use App\Models\AdminUser;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Operational setup of the PRE-AUTHORIZED administrator records (invoked only by
 * `php artisan comelec:provision-admins`, never by a web route or a login).
 *
 * Each record is identified by the administrator's authorized personal Google email.
 * `google_subject` is NOT part of provisioning: it stays NULL until the administrator's
 * first verified Google login binds it (see IdentityResolver).
 *
 * Idempotent and conservative: it creates missing configured admins, and never updates,
 * rebinds, replaces or deletes an existing one. It never reads or writes student data and
 * is independent of the Data Center import.
 */
class AdminProvisioner
{
    public function __construct(private readonly AuditLogger $audit, private readonly AdminRoster $roster) {}

    /**
     * @param  mixed  $configured  expected: list of ['authorized_email'=>string,'display_name'=>string]
     * @return array{created: int, existing: int, display_name_mismatches: int}
     *
     * @throws RuntimeException on any validation failure (nothing is written)
     */
    public function provision(mixed $configured): array
    {
        $entries = $this->validated($configured);
        $emails = array_column($entries, 'authorized_email');

        return DB::transaction(function () use ($entries, $emails): array {
            $existing = AdminUser::query()->lockForUpdate()->get();

            if ($existing->contains(fn (AdminUser $admin) => $admin->authorized_email === null)) {
                throw new RuntimeException('Existing admin rows without an authorized_email were found. Assign the real authorized email to each one manually first. Refusing to continue.');
            }

            $existingByEmail = $existing->keyBy(fn (AdminUser $admin) => mb_strtolower(trim($admin->authorized_email)));

            if ($existingByEmail->keys()->diff($emails)->isNotEmpty()) {
                throw new RuntimeException('The database already contains an admin that is not in the configured set. Refusing to replace or delete admins.');
            }

            $created = 0;
            $mismatches = 0;

            foreach ($entries as $entry) {
                $current = $existingByEmail->get($entry['authorized_email']);

                if ($current !== null) {
                    if ($current->display_name !== $entry['display_name']) {
                        $mismatches++; // reported, never silently overwritten
                    }

                    continue; // an existing row (and any Google binding it has) is left exactly as it is
                }

                $admin = new AdminUser;
                $admin->forceFill([
                    'authorized_email' => $entry['authorized_email'],
                    'google_subject' => null,
                    'display_name' => $entry['display_name'],
                    'role' => AdminUser::ROLE,
                ])->save();

                $this->audit->record(
                    eventType: 'admin.provisioned',
                    severity: AuditLogger::INFO,
                    actorType: 'SYSTEM',
                    targetType: 'admin_user',
                    targetId: $admin->getKey(),
                    description: 'Pre-authorized administrator record provisioned from deployment configuration.',
                    metadata: ['source' => 'comelec:provision-admins'],
                );

                $created++;
            }

            if (! $this->roster->isIntact()) {
                throw new RuntimeException('Provisioning would not leave the configured authorized roster intact (size and fixed role). Rolled back.');
            }

            return [
                'created' => $created,
                'existing' => count($entries) - $created,
                'display_name_mismatches' => $mismatches,
            ];
        });
    }

    /**
     * @return list<array{authorized_email: string, display_name: string}>
     */
    private function validated(mixed $configured): array
    {
        $size = $this->roster->expectedCount();

        if (! is_array($configured) || ! array_is_list($configured) || $size < 1 || count($configured) !== $size) {
            throw new RuntimeException("The configured admin roster must contain exactly {$size} entries (comelec.admin_roster_size / COMELEC_ADMIN_IDENTITIES).");
        }

        $entries = [];

        foreach ($configured as $entry) {
            if (! is_array($entry) || array_diff(array_keys($entry), ['authorized_email', 'display_name']) !== []) {
                throw new RuntimeException('Each admin entry may contain only authorized_email and display_name (Google subjects are never configured; they are bound at first login).');
            }

            $email = $entry['authorized_email'] ?? null;
            $name = $entry['display_name'] ?? null;

            if (! is_string($email) || ! is_string($name) || trim($name) === '') {
                throw new RuntimeException('Every admin entry needs a non-empty authorized_email and display_name.');
            }

            $email = mb_strtolower(trim($email));

            if (strlen($email) > 255 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                throw new RuntimeException('Every authorized_email must be a valid email address.');
            }

            $entries[] = ['authorized_email' => $email, 'display_name' => trim($name)];
        }

        if (count(array_unique(array_column($entries, 'authorized_email'))) !== count($entries)) {
            throw new RuntimeException('The configured authorized emails must be distinct.');
        }

        return $entries;
    }
}
