<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Wave 15 — Domain results.
 *
 * Records one calculation execution (docs/DATABASE.md §9;
 * docs/RESULTS_AND_ANALYTICS.md "Result calculation runs").
 *
 * The approved docs require a `status` plus `started_at`/`completed_at`
 * but do not enumerate its values. PENDING / RUNNING / COMPLETED / FAILED
 * is an implementation-phase decision derived from that workflow: RUNNING
 * follows from started-but-not-completed timestamps; PENDING follows from
 * result processing being queued post-commit (docs/ARCHITECTURE.md §4).
 * It is not covered by any numbered item in the Phase 1A approval
 * checklist (docs/DOMAIN_MODEL_PROPOSAL.md §20).
 *
 * Calculation *logic* is out of scope for Phase 1B.
 */
return new class extends Migration
{
    public function up(): void
    {
        $enums = [
            'status' => ['PENDING', 'RUNNING', 'COMPLETED', 'FAILED'],
        ];

        Schema::create('result_calculation_runs', function (Blueprint $table) use ($enums) {
            $table->engine = 'InnoDB';

            $table->id();
            $table->foreignId('election_id')->constrained('elections')->restrictOnDelete();
            $table->string('algorithm_version');
            $table->unsignedInteger('ballot_count_at_run')->default(0);
            $table->enum('status', $enums['status'])->default('PENDING');
            $table->string('result_digest')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['election_id', 'status']);

            // Cross-scope anchor for result_aggregates and result_snapshots.
            $table->unique(['id', 'election_id'], 'rcr_id_election_unique');
        });

        // CHECK mirrors the ENUM so an out-of-set value is rejected even under a
        // permissive sql_mode (where a bare ENUM silently stores ''). MySQL/MariaDB
        // only: SQLite already receives an equivalent CHECK from enum() and cannot
        // ALTER ... ADD CONSTRAINT.
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement(
                'ALTER TABLE `result_calculation_runs` ADD CONSTRAINT `chk_result_calculation_runs_status` '
                ."CHECK (`status` IN ('".implode("', '", $enums['status'])."'))"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('result_calculation_runs');
    }
};
