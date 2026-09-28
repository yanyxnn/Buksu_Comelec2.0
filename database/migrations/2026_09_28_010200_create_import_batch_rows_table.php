<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Wave 2 — Domain A (Data Center import).
 *
 * Thin staging/classification layer for the Preview/Review step, populated
 * during Validation and meaningful only until the batch is confirmed or
 * discarded. Deliberately not a second copy of student_enrollments.
 */
return new class extends Migration
{
    public function up(): void
    {
        $enums = [
            'classification' => ['NEW', 'UPDATED', 'DUPLICATE_IN_FILE', 'INVALID', 'NEEDS_EXCEPTION_REVIEW'],
        ];

        Schema::create('import_batch_rows', function (Blueprint $table) use ($enums) {
            $table->engine = 'InnoDB';

            $table->id();
            $table->foreignId('import_batch_id')->constrained('import_batches')->restrictOnDelete();
            $table->string('institutional_id');
            $table->enum('classification', $enums['classification']);
            $table->json('raw_row_json');
            $table->foreignId('resolved_student_id')->nullable()->constrained('students')->restrictOnDelete();
            $table->timestamps();

            $table->index(['import_batch_id', 'institutional_id']);
            $table->index('classification');
        });

        // CHECK mirrors the ENUM so an out-of-set value is rejected even under a
        // permissive sql_mode (where a bare ENUM silently stores ''). MySQL/MariaDB
        // only: SQLite already receives an equivalent CHECK from enum() and cannot
        // ALTER ... ADD CONSTRAINT.
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement(
                'ALTER TABLE `import_batch_rows` ADD CONSTRAINT `chk_import_batch_rows_classification` '
                ."CHECK (`classification` IN ('".implode("', '", $enums['classification'])."'))"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('import_batch_rows');
    }
};
