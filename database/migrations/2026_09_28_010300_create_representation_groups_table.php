<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wave 3 — Domain B (election configuration).
 *
 * Reusable grouping of contests around configured dimensions (e.g.
 * college + year). docs/DATABASE.md explicitly defers the "distinct table
 * vs. columns on contests" choice to Phase 1B implementation. This
 * migration implements it as a distinct table with a JSON scope
 * definition, so the specific grouping dimensions (college, year level,
 * sector, or any future combination) remain configuration data rather than
 * hard-coded columns — per NON_NEGOTIABLES.md #2, nothing that can
 * reasonably vary between elections is hard-coded into table structure.
 * This is an implementation-phase decision (checklist item #8).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('representation_groups', function (Blueprint $table) {
            $table->engine = 'InnoDB';

            $table->id();
            $table->foreignId('election_id')->constrained('elections')->restrictOnDelete();
            $table->string('name');
            $table->json('scope_definition_json');
            $table->timestamps();

            // Cross-scope anchor for contests.representation_group_id.
            $table->unique(['id', 'election_id'], 'rg_id_election_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('representation_groups');
    }
};
