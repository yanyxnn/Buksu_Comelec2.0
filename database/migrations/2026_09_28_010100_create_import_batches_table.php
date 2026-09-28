<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Wave 1 — Domain A (Data Center import).
 *
 * Audit/history for official Data Center imports. Created before
 * `elections`/`student_enrollments` in file order (though listed alongside
 * them in the same architecture wave) because `students.last_import_batch_id`
 * needs this table to exist so its deferred foreign key (declared as a plain
 * column back in the students migration) can be attached here, and because
 * `student_enrollments` (also Wave 1) references this table directly.
 */
return new class extends Migration
{
    public function up(): void
    {
        $enums = [
            'status' => ['STAGED', 'VALIDATING', 'PREVIEWED', 'CONFIRMED', 'PROCESSING', 'COMPLETED', 'FAILED'],
        ];

        Schema::create('import_batches', function (Blueprint $table) use ($enums) {
            $table->engine = 'InnoDB';

            $table->id();
            $table->string('source_filename');
            $table->string('academic_year');
            $table->string('semester');
            $table->foreignId('uploaded_by')->constrained('admin_users')->restrictOnDelete();
            $table->enum('status', $enums['status'])->default('STAGED');
            $table->unsignedInteger('records_received')->default(0);
            $table->unsignedInteger('records_created')->default(0);
            $table->unsignedInteger('records_updated')->default(0);
            $table->unsignedInteger('records_errored')->default(0);
            $table->string('checksum')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });

        // CHECK mirrors the ENUM so an out-of-set value is rejected even under a
        // permissive sql_mode (where a bare ENUM silently stores ''). MySQL/MariaDB
        // only: SQLite already receives an equivalent CHECK from enum() and cannot
        // ALTER ... ADD CONSTRAINT.
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement(
                'ALTER TABLE `import_batches` ADD CONSTRAINT `chk_import_batches_status` '
                ."CHECK (`status` IN ('".implode("', '", $enums['status'])."'))"
            );
        }

        // Deferred FK from Wave 0's students table, now that import_batches exists.
        Schema::table('students', function (Blueprint $table) {
            $table->foreign('last_import_batch_id')
                ->references('id')->on('import_batches')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropForeign(['last_import_batch_id']);
        });

        Schema::dropIfExists('import_batches');
    }
};
