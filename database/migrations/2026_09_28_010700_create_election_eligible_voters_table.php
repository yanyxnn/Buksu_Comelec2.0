<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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
        Schema::create('election_eligible_voters', function (Blueprint $table) {
            $table->engine = 'InnoDB';

            $table->id();
            $table->foreignId('eligibility_snapshot_id')->constrained('election_eligibility_snapshots')->restrictOnDelete();
            $table->foreignId('student_id')->constrained('students')->restrictOnDelete();
            $table->string('college', 100);
            $table->string('course', 100);
            $table->string('year_level', 100);
            $table->enum('status', ['ACTIVE', 'INACTIVE']);
            $table->string('sector', 100)->nullable();
            $table->string('source');
            $table->boolean('is_exception')->default(false);
            $table->string('exception_reference')->nullable();
            $table->timestamps();

            $table->unique(['eligibility_snapshot_id', 'student_id'], 'eev_snapshot_student_unique');
            $table->index(['eligibility_snapshot_id', 'college', 'course', 'year_level'], 'eev_snapshot_dimensions_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('election_eligible_voters');
    }
};
