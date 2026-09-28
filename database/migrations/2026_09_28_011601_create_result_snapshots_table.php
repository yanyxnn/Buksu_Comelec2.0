<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Wave 16 — Domain results.
 *
 * Frozen official result record. `snapshot_json` becomes, at FINALIZED, the
 * durable self-contained official record — result generation logic itself
 * is explicitly out of scope for Phase 1B.
 */
return new class extends Migration
{
    public function up(): void
    {
        $enums = [
            'status' => ['PENDING_VALIDATION', 'VALIDATED', 'OFFICIALLY_ANNOUNCED', 'FINALIZED'],
        ];

        Schema::create('result_snapshots', function (Blueprint $table) use ($enums) {
            $table->engine = 'InnoDB';

            $table->id();
            $table->foreignId('election_id')->constrained('elections')->restrictOnDelete();
            $table->unsignedBigInteger('result_calculation_run_id');
            $table->enum('status', $enums['status'])->default('PENDING_VALIDATION');
            $table->json('snapshot_json');
            $table->timestamps();

            $table->index(['election_id', 'status']);

            // Cross-election integrity: the snapshot's run must belong to the same election.
            $table->foreign(['result_calculation_run_id', 'election_id'], 'rs_run_same_election_fk')
                ->references(['id', 'election_id'])->on('result_calculation_runs')
                ->restrictOnDelete();
        });

        // CHECK mirrors the ENUM so an out-of-set value is rejected even under a
        // permissive sql_mode (where a bare ENUM silently stores ''). MySQL/MariaDB
        // only: SQLite already receives an equivalent CHECK from enum() and cannot
        // ALTER ... ADD CONSTRAINT.
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement(
                'ALTER TABLE `result_snapshots` ADD CONSTRAINT `chk_result_snapshots_status` '
                ."CHECK (`status` IN ('".implode("', '", $enums['status'])."'))"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('result_snapshots');
    }
};
