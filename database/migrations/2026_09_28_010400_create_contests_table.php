<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wave 4 — Domain B (election configuration).
 *
 * Election contest/position definition. `scope` is kept as a free-text
 * configuration label (e.g. describing a college/sector restriction),
 * consistent with `type` on `elections` — the concrete scoping rules for
 * SBO/SSC/sectoral contests are election-configuration data
 * (docs/ELECTION_RULES.md), never hard-coded here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contests', function (Blueprint $table) {
            $table->engine = 'InnoDB';

            $table->id();
            $table->foreignId('election_id')->constrained('elections')->restrictOnDelete();
            $table->unsignedBigInteger('election_config_version_id');
            $table->string('name');
            $table->string('position_label');
            $table->string('scope')->nullable();
            $table->unsignedBigInteger('representation_group_id')->nullable();
            $table->timestamps();

            // Cross-scope integrity: config version and representation group must
            // belong to the SAME election as the contest.
            $table->foreign(['election_config_version_id', 'election_id'], 'contests_cv_same_election_fk')
                ->references(['id', 'election_id'])->on('election_config_versions')
                ->restrictOnDelete();
            $table->foreign(['representation_group_id', 'election_id'], 'contests_rg_same_election_fk')
                ->references(['id', 'election_id'])->on('representation_groups')
                ->restrictOnDelete();

            // Cross-scope anchor for candidacies (contest, election) and
            // ballot_contest_responses / result_aggregates (contest, election).
            $table->unique(['id', 'election_id'], 'contests_id_election_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contests');
    }
};
