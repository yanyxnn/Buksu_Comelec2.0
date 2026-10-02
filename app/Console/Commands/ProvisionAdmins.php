<?php

namespace App\Console\Commands;

use App\Services\Auth\AdminProvisioner;
use Illuminate\Console\Command;
use RuntimeException;

class ProvisionAdmins extends Command
{
    protected $signature = 'comelec:provision-admins';

    protected $description = 'Provision the pre-authorized COMELEC IT administrator records (authorized email, no Google subject) from COMELEC_ADMIN_IDENTITIES. Idempotent and additive only.';

    public function handle(AdminProvisioner $provisioner): int
    {
        try {
            $result = $provisioner->provision(config('comelec.admin_identities'));
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Authorized admin roster OK: {$result['created']} created, {$result['existing']} already present.");

        if ($result['display_name_mismatches'] > 0) {
            $this->warn("{$result['display_name_mismatches']} existing admin(s) have a different display name than configured; NOT changed.");
        }

        return self::SUCCESS;
    }
}
