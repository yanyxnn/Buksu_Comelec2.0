<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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
        Schema::create('result_snapshots', function (Blueprint $table) {
            $table->engine = 'InnoDB';

            $table->id();
            $table->foreignId('election_id')->constrained('elections')->restrictOnDelete();
            $table->unsignedBigInteger('result_calculation_run_id');
            $table->enum('status', [
                'PENDING_VALIDATION', 'VALIDATED', 'OFFICIALLY_ANNOUNCED', 'FINALIZED',
            ])->default('PENDING_VALIDATION');
            $table->json('snapshot_json');
            $table->timestamps();

            $table->index(['election_id', 'status']);

            // Cross-election integrity: the snapshot's run must belong to the same election.
            $table->foreign(['result_calculation_run_id', 'election_id'], 'rs_run_same_election_fk')
                ->references(['id', 'election_id'])->on('result_calculation_runs')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('result_snapshots');
    }
};
