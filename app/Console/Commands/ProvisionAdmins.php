<?php

namespace App\Console\Commands;

use App\Services\Auth\AdminProvisioner;
use Illuminate\Console\Command;
use RuntimeException;

class ProvisionAdmins extends Command
{
    protected $signature = 'comelec:provision-admins';

    protected $description = 'Provision the exactly-three COMELEC IT admin identities from COMELEC_ADMIN_IDENTITIES (idempotent, additive only).';

    public function handle(AdminProvisioner $provisioner): int
    {
        try {
            $result = $provisioner->provision(config('comelec.admin_identities'));
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Admin roster OK: {$result['created']} created, {$result['existing']} already present.");

        if ($result['display_name_mismatches'] > 0) {
            $this->warn("{$result['display_name_mismatches']} existing admin(s) have a different display name than configured; NOT changed.");
        }

        return self::SUCCESS;
    }
}
