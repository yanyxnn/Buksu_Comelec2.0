<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wave 0 — Domain A (identity).
 *
 * Permanent student identity and current official master-data projection.
 * `last_import_batch_id` is created here as a plain nullable column without
 * its foreign key, because `import_batches` does not exist yet (Wave 1).
 * The FK constraint is added onto this table from the import_batches
 * migration once that table exists, to respect strict dependency order
 * while keeping the column where it conceptually belongs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('students', function (Blueprint $table) {
            $table->engine = 'InnoDB';

            $table->id();
            $table->string('institutional_id')->unique();
            $table->string('institutional_email')->unique();
            $table->string('first_name');
            $table->string('middle_name')->nullable();
            $table->string('last_name');
            $table->string('current_college', 100);
            $table->string('current_course', 100);
            $table->string('current_year_level', 100);
            $table->enum('status', ['ACTIVE', 'INACTIVE']);
            $table->string('google_subject')->nullable()->unique();

            // FK added later in 2026_09_28_010102_create_import_batches_table.php
            $table->unsignedBigInteger('last_import_batch_id')->nullable();

            $table->timestamps();

            $table->index('status');
            $table->index(['current_college', 'current_course', 'current_year_level']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('students');
    }
};
