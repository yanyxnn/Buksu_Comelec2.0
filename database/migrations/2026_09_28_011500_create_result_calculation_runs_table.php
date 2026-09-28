<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wave 15 — Domain results.
 *
 * Records one calculation execution. `status` values are not fixed by the
 * approved domain model beyond "status" being present — this migration
 * picks a maintainable, minimal set (implementation-phase decision, in the
 * spirit of checklist item #4/#9: low-stakes, does not affect surrounding
 * schema correctness, reasonably decided at migration-authoring time).
 * Calculation *logic* is explicitly out of scope for Phase 1B.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('result_calculation_runs', function (Blueprint $table) {
            $table->engine = 'InnoDB';

            $table->id();
            $table->foreignId('election_id')->constrained('elections')->restrictOnDelete();
            $table->string('algorithm_version');
            $table->unsignedInteger('ballot_count_at_run')->default(0);
            $table->enum('status', ['PENDING', 'RUNNING', 'COMPLETED', 'FAILED'])->default('PENDING');
            $table->string('result_digest')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['election_id', 'status']);

            // Cross-scope anchor for result_aggregates and result_snapshots.
            $table->unique(['id', 'election_id'], 'rcr_id_election_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('result_calculation_runs');
    }
};
