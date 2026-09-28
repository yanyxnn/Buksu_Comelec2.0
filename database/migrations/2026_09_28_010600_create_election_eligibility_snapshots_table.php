<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
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
        $enums = [
            'status' => ['DRAFT', 'LOCKED'],
        ];

        Schema::create('election_eligibility_snapshots', function (Blueprint $table) use ($enums) {
            $table->engine = 'InnoDB';

            $table->id();
            $table->foreignId('election_id')->constrained('elections')->restrictOnDelete();
            $table->unsignedBigInteger('election_config_version_id');
            $table->enum('status', $enums['status'])->default('DRAFT');
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

        // CHECK mirrors the ENUM so an out-of-set value is rejected even under a
        // permissive sql_mode (where a bare ENUM silently stores ''). MySQL/MariaDB
        // only: SQLite already receives an equivalent CHECK from enum() and cannot
        // ALTER ... ADD CONSTRAINT.
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement(
                'ALTER TABLE `election_eligibility_snapshots` ADD CONSTRAINT `chk_election_eligibility_snapshots_status` '
                ."CHECK (`status` IN ('".implode("', '", $enums['status'])."'))"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('election_eligibility_snapshots');
    }
};
