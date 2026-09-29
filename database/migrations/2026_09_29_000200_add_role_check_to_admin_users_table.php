<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 02 — the only admin role is BUKSU_COMELEC_IT_ADMIN (no Super Admin).
 * Mirrors the existing enum-CHECK pattern: MySQL/MariaDB only, because SQLite
 * cannot ALTER ... ADD CONSTRAINT. The application also re-checks the role on
 * every admin authentication and request (AdminRoster / EnsureAdmin).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE `admin_users` ADD CONSTRAINT `chk_admin_users_role` CHECK (`role` = 'BUKSU_COMELEC_IT_ADMIN')");
        }
    }

    public function down(): void
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE `admin_users` DROP CONSTRAINT `chk_admin_users_role`');
        }
    }
};
