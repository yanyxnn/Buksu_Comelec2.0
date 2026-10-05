<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 02 administrator identity lifecycle (step 2 of 2): require `authorized_email`.
 *
 * MANUAL LEGACY-DATA PREREQUISITE: an `admin_users` row created before this correction
 * has a Google subject but no authorized email. This migration will NOT invent or infer
 * an email (for example from the subject), will NOT delete or replace the row, and will
 * NOT touch its Google subject. If any row lacks an authorized email it fails safely,
 * changes nothing, and asks for the REAL authorized email to be assigned manually
 * (for example: UPDATE admin_users SET authorized_email = '<real email>' WHERE id = <id>).
 * Once every admin has one, re-run `php artisan migrate`.
 */
return new class extends Migration
{
    public function up(): void
    {
        $missing = DB::table('admin_users')->whereNull('authorized_email')->count();

        if ($missing > 0) {
            throw new RuntimeException(
                "{$missing} admin_users row(s) have no authorized_email. Assign the real authorized email to each one manually, then re-run the migration. Nothing was deleted, invented or changed."
            );
        }

        Schema::table('admin_users', function (Blueprint $table) {
            $table->string('authorized_email')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('admin_users', function (Blueprint $table) {
            $table->string('authorized_email')->nullable()->change();
        });
    }
};
