<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Wave 1 — Domain A (identity).
 *
 * Append-only history of the official academic placement reported by each
 * Data Center import. Course-change/year-level rules are applied by the
 * (later-phase) import service when a row is recorded here; this migration
 * only stores the resulting frozen placement.
 *
 * UNIQUE(import_batch_id, student_id): a batch reports exactly ONE placement
 * per student. Basis: DATABASE.md defines this table as the placement
 * "reported by each Data Center import", and DATA_IMPORT.md routes duplicate
 * IDs inside a file to `import_batch_rows` (DUPLICATE_IN_FILE) rather than
 * into enrollments. The constraint also makes the chunked import idempotent:
 * a retried chunk cannot double-insert history. This is an inferred rule (the
 * docs do not state the constraint verbatim) and is intentionally easy to
 * relax if an institutional case ever requires two placements per batch.
 * Column order puts import_batch_id first so this key also serves the
 * import_batch_id FK and batch-processing lookups; student_id is served by
 * the (student_id, effective_from) history index.
 */
return new class extends Migration
{
    public function up(): void
    {
        $enums = [
            'status' => ['ACTIVE', 'INACTIVE'],
        ];

        Schema::create('student_enrollments', function (Blueprint $table) use ($enums) {
            $table->engine = 'InnoDB';

            $table->id();
            $table->foreignId('student_id')->constrained('students')->restrictOnDelete();
            $table->foreignId('import_batch_id')->constrained('import_batches')->restrictOnDelete();
            $table->string('college', 100);
            $table->string('course', 100);
            $table->string('year_level', 100);
            $table->enum('status', $enums['status']);
            $table->timestamp('effective_from');
            $table->timestamps();

            $table->unique(['import_batch_id', 'student_id']);
            $table->index(['student_id', 'effective_from']);
        });

        // CHECK mirrors the ENUM so an out-of-set value is rejected even under a
        // permissive sql_mode (where a bare ENUM silently stores ''). MySQL/MariaDB
        // only: SQLite already receives an equivalent CHECK from enum() and cannot
        // ALTER ... ADD CONSTRAINT.
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement(
                'ALTER TABLE `student_enrollments` ADD CONSTRAINT `chk_student_enrollments_status` '
                ."CHECK (`status` IN ('".implode("', '", $enums['status'])."'))"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('student_enrollments');
    }
};
