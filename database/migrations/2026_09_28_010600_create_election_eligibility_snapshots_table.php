<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wave 6 — Domain D (eligibility).
 *
 * Frozen eligibility computation for an election. `locked_at` is included
 * for consistency with the other three snapshot tables
 * (candidate_roster_snapshots, ballot_structure_snapshots), which all share
 * the same "status -> LOCKED" freeze moment
 * (docs/DOMAIN_MODEL_PROPOSAL.md §9, §4).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('election_eligibility_snapshots', function (Blueprint $table) {
            $table->engine = 'InnoDB';

            $table->id();
            $table->foreignId('election_id')->constrained('elections')->restrictOnDelete();
            $table->unsignedBigInteger('election_config_version_id');
            $table->enum('status', ['DRAFT', 'LOCKED'])->default('DRAFT');
            $table->json('rule_definition_json');
            $table->timestamp('locked_at')->nullable();
            $table->timestamps();

            $table->index(['election_id', 'status']);

            $table->foreign(['election_config_version_id', 'election_id'], 'ees_cv_same_election_fk')
                ->references(['id', 'election_id'])->on('election_config_versions')
                ->restrictOnDelete();

            // Cross-scope anchor for ballot_structure_snapshots.
            $table->unique(['id', 'election_id'], 'ees_id_election_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('election_eligibility_snapshots');
    }
};
