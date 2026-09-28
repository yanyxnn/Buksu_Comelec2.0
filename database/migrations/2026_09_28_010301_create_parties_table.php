<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wave 3 — Domain C (candidates).
 *
 * Election-scoped party/group configuration, per the approved
 * recommendation in docs/DOMAIN_MODEL_PROPOSAL.md §14. If COMELEC later
 * confirms parties must be a persistent, institutionally-accredited
 * registry across elections, that is a REQUIRES INSTITUTIONAL DECISION
 * item (checklist #12) requiring a schema change — not assumed here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parties', function (Blueprint $table) {
            $table->engine = 'InnoDB';

            $table->id();
            $table->foreignId('election_id')->constrained('elections')->restrictOnDelete();
            $table->string('name');
            $table->string('abbreviation')->nullable();
            $table->timestamps();

            $table->unique(['election_id', 'name']);

            // Cross-scope anchor for candidacies.party_id.
            $table->unique(['id', 'election_id'], 'parties_id_election_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parties');
    }
};
