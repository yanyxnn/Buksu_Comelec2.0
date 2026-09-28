<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Wave 8 — Domain C (candidates).
 *
 * Election-specific candidacy. Carries the composite unique key
 * `(id, contest_id)` required by `ballot_candidate_selections` (Wave 13) to
 * express the candidate-membership rule as a genuine composite foreign key
 * — MySQL allows a foreign key against any unique key, not only the
 * primary key (docs/DOMAIN_MODEL_PROPOSAL.md §16/§13.3), so a selection can
 * never reference a candidacy that isn't actually running in that contest,
 * enforced declaratively rather than transactionally.
 */
return new class extends Migration
{
    public function up(): void
    {
        $enums = [
            'status' => ['DRAFT', 'FOR_REVIEW', 'VERIFIED', 'APPROVED', 'PUBLISHED', 'LOCKED', 'WITHDRAWN', 'DISQUALIFIED'],
        ];

        Schema::create('candidacies', function (Blueprint $table) use ($enums) {
            $table->engine = 'InnoDB';

            $table->id();
            $table->foreignId('candidate_id')->constrained('candidates')->restrictOnDelete();
            $table->foreignId('election_id')->constrained('elections')->restrictOnDelete();
            $table->unsignedBigInteger('contest_id');
            $table->unsignedBigInteger('party_id')->nullable();
            $table->string('display_name');
            $table->string('photo_path')->nullable();
            $table->text('bio')->nullable();
            $table->enum('status', $enums['status'])->default('DRAFT');
            $table->unsignedBigInteger('roster_snapshot_id')->nullable();
            $table->timestamps();

            // Cross-scope integrity: contest, party and roster snapshot must all
            // belong to the SAME election as the candidacy (nullable ones skip when NULL).
            $table->foreign(['contest_id', 'election_id'], 'candidacies_contest_same_election_fk')
                ->references(['id', 'election_id'])->on('contests')
                ->restrictOnDelete();
            $table->foreign(['party_id', 'election_id'], 'candidacies_party_same_election_fk')
                ->references(['id', 'election_id'])->on('parties')
                ->restrictOnDelete();
            $table->foreign(['roster_snapshot_id', 'election_id'], 'candidacies_roster_same_election_fk')
                ->references(['id', 'election_id'])->on('candidate_roster_snapshots')
                ->restrictOnDelete();

            // Cross-scope anchor for result_aggregates (candidacy, election).
            $table->unique(['id', 'election_id'], 'candidacies_id_election_unique');

            // Required by ballot_candidate_selections' composite candidate-membership FK.
            $table->unique(['id', 'contest_id']);

            $table->unique(['candidate_id', 'contest_id']);
            $table->index(['election_id', 'contest_id', 'status']);
        });

        // CHECK mirrors the ENUM so an out-of-set value is rejected even under a
        // permissive sql_mode (where a bare ENUM silently stores ''). MySQL/MariaDB
        // only: SQLite already receives an equivalent CHECK from enum() and cannot
        // ALTER ... ADD CONSTRAINT.
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement(
                'ALTER TABLE `candidacies` ADD CONSTRAINT `chk_candidacies_status` '
                ."CHECK (`status` IN ('".implode("', '", $enums['status'])."'))"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('candidacies');
    }
};
