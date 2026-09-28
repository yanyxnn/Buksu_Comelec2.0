<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wave 0 — Domain A (identity).
 *
 * Authorized COMELEC IT administrators. Provisioning of the (exactly three,
 * per NON_NEGOTIABLES.md) admin accounts is an operational control outside
 * this schema, not a table-cardinality constraint enforced here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_users', function (Blueprint $table) {
            $table->engine = 'InnoDB';

            $table->id();
            $table->string('google_subject')->unique();
            $table->string('display_name');
            $table->string('role')->default('BUKSU_COMELEC_IT_ADMIN');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_users');
    }
};
