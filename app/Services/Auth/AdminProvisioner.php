<?php

namespace App\Services\Auth;

use App\Models\AdminUser;
use App\Models\Student;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Operational provisioning of the exactly-three admin identities (invoked only
 * by `php artisan comelec:provision-admins`, never by a web route).
 *
 * Idempotent, additive and conservative: it creates missing configured admins,
 * never updates, replaces or deletes an existing one, and refuses to proceed if
 * the database already contains an admin that is not in the configured set.
 */
class AdminProvisioner
{
    public function __construct(private readonly AuditLogger $audit, private readonly AdminRoster $roster) {}

    /**
     * @param  mixed  $configured  expected: list of ['google_subject'=>string,'display_name'=>string] x3
     * @return array{created: int, existing: int, display_name_mismatches: int}
     *
     * @throws RuntimeException on any validation failure (nothing is written)
     */
    public function provision(mixed $configured): array
    {
        $entries = $this->validated($configured);
        $subjects = array_column($entries, 'google_subject');

        if (Student::query()->whereIn('google_subject', $subjects)->exists()) {
            throw new RuntimeException('A configured admin Google subject is already linked to a student. Refusing to provision.');
        }

        return DB::transaction(function () use ($entries, $subjects): array {
            $existing = AdminUser::query()->lockForUpdate()->get()->keyBy('google_subject');

            if ($existing->keys()->diff($subjects)->isNotEmpty()) {
                throw new RuntimeException('The database already contains an admin that is not in the configured set. Refusing to replace or delete admins.');
            }

            $created = 0;
            $mismatches = 0;

            foreach ($entries as $entry) {
                $current = $existing->get($entry['google_subject']);

                if ($current !== null) {
                    if ($current->display_name !== $entry['display_name']) {
                        $mismatches++; // reported, never silently overwritten
                    }

                    continue;
                }

                $admin = new AdminUser;
                $admin->forceFill([
                    'google_subject' => $entry['google_subject'],
                    'display_name' => $entry['display_name'],
                    'role' => AdminUser::ROLE,
                ])->save();

                $this->audit->record(
                    eventType: 'admin.provisioned',
                    severity: AuditLogger::INFO,
                    actorType: 'SYSTEM',
                    targetType: 'admin_user',
                    targetId: $admin->getKey(),
                    description: 'Admin identity provisioned from deployment configuration.',
                    metadata: ['source' => 'comelec:provision-admins'],
                );

                $created++;
            }

            if (! $this->roster->isIntact()) {
                throw new RuntimeException('Provisioning would not leave exactly three admins with the fixed role. Rolled back.');
            }

            return [
                'created' => $created,
                'existing' => count($entries) - $created,
                'display_name_mismatches' => $mismatches,
            ];
        });
    }

    /**
     * @return list<array{google_subject: string, display_name: string}>
     */
    private function validated(mixed $configured): array
    {
        if (! is_array($configured) || ! array_is_list($configured) || count($configured) !== AdminRoster::REQUIRED_COUNT) {
            throw new RuntimeException('Exactly '.AdminRoster::REQUIRED_COUNT.' admin identities must be configured (COMELEC_ADMIN_IDENTITIES).');
        }

        $entries = [];

        foreach ($configured as $entry) {
            $sub = is_array($entry) ? ($entry['google_subject'] ?? null) : null;
            $name = is_array($entry) ? ($entry['display_name'] ?? null) : null;

            if (! is_string($sub) || trim($sub) === '' || ! is_string($name) || trim($name) === '') {
                throw new RuntimeException('Every admin identity needs a non-empty google_subject and display_name.');
            }

            $entries[] = ['google_subject' => trim($sub), 'display_name' => trim($name)];
        }

        if (count(array_unique(array_column($entries, 'google_subject'))) !== AdminRoster::REQUIRED_COUNT) {
            throw new RuntimeException('The three configured admin Google subjects must be distinct.');
        }

        return $entries;
    }
}
