<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 02 administrator identity lifecycle (step 1 of 2): ADDITIVE and always safe.
 *
 * Administrators are pre-authorized records that exist BEFORE first login:
 *  - `authorized_email`: the pre-authorized personal Google email (unique). Added
 *    nullable here so existing rows can be handled by the tightening migration.
 *  - `google_subject`: now nullable (NULL until the first verified Google login binds
 *    Google's stable `sub`). The existing UNIQUE index is kept, so it is unique when set.
 *
 * The historical `create_admin_users_table` migration is intentionally not edited.
 * No CHECK is added here. The number of admin rows is NOT constrained by the schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admin_users', function (Blueprint $table) {
            $table->string('authorized_email')->nullable()->unique()->after('id');
            $table->string('google_subject')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Never fabricate subjects: once a pre-authorized admin has not logged in yet,
        // the subject-first (NOT NULL) schema cannot be restored.
        if (DB::table('admin_users')->whereNull('google_subject')->exists()) {
            throw new RuntimeException('Cannot roll back: pre-authorized admins without a bound google_subject exist. Nothing was changed.');
        }

        Schema::table('admin_users', function (Blueprint $table) {
            $table->dropUnique(['authorized_email']);
            $table->dropColumn('authorized_email');
            $table->string('google_subject')->nullable(false)->change();
        });
    }
};
