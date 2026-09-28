<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Wave 9 — Domain B (election configuration).
 *
 * Frozen definition of what voters actually receive. Depends on all three
 * of election_config_versions, candidate_roster_snapshots, and
 * election_eligibility_snapshots being locked before this itself can lock
 * (docs/DOMAIN_MODEL_PROPOSAL.md §9). `structure_json` is a frozen,
 * denormalized reproducibility artifact, the same role config_json plays
 * for configuration.
 */
return new class extends Migration
{
    public function up(): void
    {
        $enums = [
            'status' => ['DRAFT', 'LOCKED'],
        ];

        Schema::create('ballot_structure_snapshots', function (Blueprint $table) use ($enums) {
            $table->engine = 'InnoDB';

            $table->id();
            $table->foreignId('election_id')->constrained('elections')->restrictOnDelete();
            $table->unsignedBigInteger('election_config_version_id');
            $table->unsignedBigInteger('candidate_roster_snapshot_id');
            $table->unsignedBigInteger('eligibility_snapshot_id');
            $table->unsignedInteger('version_number');
            $table->enum('status', $enums['status'])->default('DRAFT');
            $table->timestamp('locked_at')->nullable();
            $table->json('structure_json')->nullable();
            $table->timestamps();

            $table->unique(['election_id', 'version_number']);

            // Cross-scope integrity: config version, roster snapshot and eligibility
            // snapshot must all belong to the SAME election as this structure snapshot.
            $table->foreign(['election_config_version_id', 'election_id'], 'bss_cv_same_election_fk')
                ->references(['id', 'election_id'])->on('election_config_versions')
                ->restrictOnDelete();
            $table->foreign(['candidate_roster_snapshot_id', 'election_id'], 'bss_roster_same_election_fk')
                ->references(['id', 'election_id'])->on('candidate_roster_snapshots')
                ->restrictOnDelete();
            $table->foreign(['eligibility_snapshot_id', 'election_id'], 'bss_eligibility_same_election_fk')
                ->references(['id', 'election_id'])->on('election_eligibility_snapshots')
                ->restrictOnDelete();

            // Cross-scope anchor for ballots (structure snapshot, election).
            $table->unique(['id', 'election_id'], 'bss_id_election_unique');
        });

        // CHECK mirrors the ENUM so an out-of-set value is rejected even under a
        // permissive sql_mode (where a bare ENUM silently stores ''). MySQL/MariaDB
        // only: SQLite already receives an equivalent CHECK from enum() and cannot
        // ALTER ... ADD CONSTRAINT.
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement(
                'ALTER TABLE `ballot_structure_snapshots` ADD CONSTRAINT `chk_ballot_structure_snapshots_status` '
                ."CHECK (`status` IN ('".implode("', '", $enums['status'])."'))"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ballot_structure_snapshots');
    }
};
