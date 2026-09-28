<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Wave 7 — Domain D (eligibility).
 *
 * Materialized locked voter set used by the election — not a live query
 * against current student master data (docs/DATABASE.md §5). Frozen
 * college/course/year/status/sector attributes are copied by value at
 * snapshot time, so later Data Center imports never silently change an
 * active election's eligibility.
 */
return new class extends Migration
{
    public function up(): void
    {
        $enums = [
            'status' => ['ACTIVE', 'INACTIVE'],
        ];

        Schema::create('election_eligible_voters', function (Blueprint $table) use ($enums) {
            $table->engine = 'InnoDB';

            $table->id();
            $table->foreignId('eligibility_snapshot_id')->constrained('election_eligibility_snapshots')->restrictOnDelete();
            $table->foreignId('student_id')->constrained('students')->restrictOnDelete();
            $table->string('college', 100);
            $table->string('course', 100);
            $table->string('year_level', 100);
            $table->enum('status', $enums['status']);
            $table->string('sector', 100)->nullable();
            $table->string('source');
            $table->boolean('is_exception')->default(false);
            $table->string('exception_reference')->nullable();
            $table->timestamps();

            $table->unique(['eligibility_snapshot_id', 'student_id'], 'eev_snapshot_student_unique');
            $table->index(['eligibility_snapshot_id', 'college', 'course', 'year_level'], 'eev_snapshot_dimensions_idx');
        });

        // CHECK mirrors the ENUM so an out-of-set value is rejected even under a
        // permissive sql_mode (where a bare ENUM silently stores ''). MySQL/MariaDB
        // only: SQLite already receives an equivalent CHECK from enum() and cannot
        // ALTER ... ADD CONSTRAINT.
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement(
                'ALTER TABLE `election_eligible_voters` ADD CONSTRAINT `chk_election_eligible_voters_status` '
                ."CHECK (`status` IN ('".implode("', '", $enums['status'])."'))"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('election_eligible_voters');
    }
};
