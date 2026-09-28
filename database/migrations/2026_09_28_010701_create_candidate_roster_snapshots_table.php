<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wave 7 — Domain C (candidates).
 *
 * Versioned frozen candidate roster. Gives `candidacies.roster_snapshot_id`
 * (Wave 8) somewhere real to point to, consistent with how
 * election_eligibility_snapshots already works.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('candidate_roster_snapshots', function (Blueprint $table) {
            $table->engine = 'InnoDB';

            $table->id();
            $table->foreignId('election_id')->constrained('elections')->restrictOnDelete();
            $table->unsignedBigInteger('election_config_version_id');
            $table->unsignedInteger('version_number');
            $table->enum('status', ['DRAFT', 'LOCKED'])->default('DRAFT');
            $table->timestamp('locked_at')->nullable();
            $table->timestamps();

            $table->unique(['election_id', 'version_number']);

            $table->foreign(['election_config_version_id', 'election_id'], 'crs_cv_same_election_fk')
                ->references(['id', 'election_id'])->on('election_config_versions')
                ->restrictOnDelete();

            // Cross-scope anchor for candidacies.roster_snapshot_id and
            // ballot_structure_snapshots.candidate_roster_snapshot_id.
            $table->unique(['id', 'election_id'], 'crs_id_election_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('candidate_roster_snapshots');
    }
};
