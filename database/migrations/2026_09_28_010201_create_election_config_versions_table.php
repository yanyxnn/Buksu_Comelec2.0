<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wave 2 — Domain B (election configuration).
 *
 * Versioned normalized configuration plus frozen serialized snapshot.
 * `config_json` is a derived, frozen artifact generated once at snapshot
 * time (status -> SNAPSHOTTED) — it is never a second editable source of
 * truth; normalized configuration tables remain authoritative while
 * editable (docs/DOMAIN_MODEL_PROPOSAL.md §10).
 *
 * Also attaches the deferred `elections.current_config_version_id` and
 * `elections.locked_config_version_id` foreign keys now that this table
 * exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('election_config_versions', function (Blueprint $table) {
            $table->engine = 'InnoDB';

            $table->id();
            $table->foreignId('election_id')->constrained('elections')->restrictOnDelete();
            $table->unsignedInteger('version_number');
            $table->enum('status', ['DRAFT', 'APPROVED', 'SNAPSHOTTED', 'SUPERSEDED'])->default('DRAFT');
            $table->json('config_json')->nullable();
            $table->foreignId('proposed_by')->constrained('admin_users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('admin_users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['election_id', 'version_number']);

            // Cross-scope anchor: lets child tables prove (config_version, election)
            // belong together via a composite FK, instead of trusting app code.
            $table->unique(['id', 'election_id'], 'ecv_id_election_unique');
        });

        Schema::table('elections', function (Blueprint $table) {
            // Composite (version, election.id) -> ecv(id, election_id): an election can
            // only point at a config version that belongs to itself. NULLs skip the check.
            $table->foreign(['current_config_version_id', 'id'], 'elections_current_cv_own_election_fk')
                ->references(['id', 'election_id'])->on('election_config_versions')
                ->restrictOnDelete();

            $table->foreign(['locked_config_version_id', 'id'], 'elections_locked_cv_own_election_fk')
                ->references(['id', 'election_id'])->on('election_config_versions')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('elections', function (Blueprint $table) {
            $table->dropForeign('elections_current_cv_own_election_fk');
            $table->dropForeign('elections_locked_cv_own_election_fk');
        });

        Schema::dropIfExists('election_config_versions');
    }
};
