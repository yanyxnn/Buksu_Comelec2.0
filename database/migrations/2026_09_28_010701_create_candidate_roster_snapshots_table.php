<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
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
        $enums = [
            'status' => ['DRAFT', 'LOCKED'],
        ];

        Schema::create('candidate_roster_snapshots', function (Blueprint $table) use ($enums) {
            $table->engine = 'InnoDB';

            $table->id();
            $table->foreignId('election_id')->constrained('elections')->restrictOnDelete();
            $table->unsignedBigInteger('election_config_version_id');
            $table->unsignedInteger('version_number');
            $table->enum('status', $enums['status'])->default('DRAFT');
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

        // CHECK mirrors the ENUM so an out-of-set value is rejected even under a
        // permissive sql_mode (where a bare ENUM silently stores ''). MySQL/MariaDB
        // only: SQLite already receives an equivalent CHECK from enum() and cannot
        // ALTER ... ADD CONSTRAINT.
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement(
                'ALTER TABLE `candidate_roster_snapshots` ADD CONSTRAINT `chk_candidate_roster_snapshots_status` '
                ."CHECK (`status` IN ('".implode("', '", $enums['status'])."'))"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('candidate_roster_snapshots');
    }
};
