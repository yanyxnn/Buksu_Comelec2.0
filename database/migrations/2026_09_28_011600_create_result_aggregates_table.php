<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Wave 16 — Domain results.
 *
 * Configurable aggregate model: one row per
 * metric_type x dimension_type x dimension_value slice, so every required
 * breakdown (overall/college/course/year/sector x candidate vote/abstain/
 * participation counts) is a row, not a bespoke table or column
 * (docs/DOMAIN_MODEL_PROPOSAL.md §2.6).
 *
 * MySQL NULLABLE-UNIQUE-KEY NOTE (per Phase 1B instruction #19): the
 * documented uniqueness rule is
 *   UNIQUE(result_calculation_run_id, contest_id, candidacy_id,
 *          metric_type, dimension_type, dimension_value)
 * but `contest_id`, `candidacy_id`, and `dimension_value` are all nullable
 * (null for election-wide / non-candidate / OVERALL rows respectively).
 * MySQL unique indexes treat NULL as distinct from every other NULL, so a
 * naive UNIQUE constraint directly on those nullable columns would silently
 * allow duplicate "OVERALL" rows for the same run/metric — the exact
 * double-counting this constraint exists to prevent. To keep the
 * uniqueness rule real, this migration adds three generated, stored,
 * NOT NULL "key" columns that coalesce NULL to a fixed sentinel
 * (0 / 0 / '') and puts the actual unique index on those instead. The
 * nullable original columns remain the ones the application reads/writes
 * and carry their natural NULL semantics; the *_key columns exist purely
 * to make the uniqueness constraint MySQL-correct.
 */
return new class extends Migration
{
    public function up(): void
    {
        $enums = [
            'metric_type' => ['CANDIDATE_VOTE_COUNT', 'CONTEST_ABSTAIN_COUNT', 'CONTEST_PARTICIPATION_COUNT', 'OVERALL_CAST_COUNT', 'OVERALL_PARTICIPATION_COUNT'],
            'dimension_type' => ['OVERALL', 'COLLEGE', 'COURSE', 'YEAR_LEVEL', 'SECTOR'],
            'denominator_type' => ['ELIGIBLE_ELECTION', 'ELIGIBLE_CONTEST', 'PARTICIPATING_CONTEST', 'VOTING_CONTEST'],
        ];

        Schema::create('result_aggregates', function (Blueprint $table) use ($enums) {
            $table->engine = 'InnoDB';

            $table->id();
            $table->unsignedBigInteger('result_calculation_run_id');
            $table->foreignId('election_id')->constrained('elections')->restrictOnDelete();
            $table->unsignedBigInteger('contest_id')->nullable();
            $table->unsignedBigInteger('candidacy_id')->nullable();
            $table->enum('metric_type', $enums['metric_type']);
            $table->enum('dimension_type', $enums['dimension_type']);
            $table->string('dimension_value')->nullable();
            $table->unsignedInteger('count');
            $table->enum('denominator_type', $enums['denominator_type'])->nullable();
            $table->unsignedInteger('denominator_value')->nullable();
            $table->timestamps();

            // NULL-safe surrogate columns for the composite uniqueness rule (see note above).
            $table->unsignedBigInteger('contest_id_key')->storedAs('COALESCE(contest_id, 0)');
            $table->unsignedBigInteger('candidacy_id_key')->storedAs('COALESCE(candidacy_id, 0)');
            $table->string('dimension_value_key', 255)->storedAs("COALESCE(dimension_value, '')");

            $table->unique([
                'result_calculation_run_id', 'contest_id_key', 'candidacy_id_key',
                'metric_type', 'dimension_type', 'dimension_value_key',
            ], 'result_aggregates_slice_unique');

            $table->index(['result_calculation_run_id', 'dimension_type', 'dimension_value'], 'result_aggregates_run_dimension_idx');

            // CROSS-ELECTION INTEGRITY. `election_id` is denormalized onto the row so
            // composite FKs can prove run, contest and candidacy all belong to the
            // same election. NULL contest/candidacy (election-wide metrics) skip the check.
            $table->foreign(['result_calculation_run_id', 'election_id'], 'ra_run_same_election_fk')
                ->references(['id', 'election_id'])->on('result_calculation_runs')
                ->restrictOnDelete();
            $table->foreign(['contest_id', 'election_id'], 'ra_contest_same_election_fk')
                ->references(['id', 'election_id'])->on('contests')
                ->restrictOnDelete();
            $table->foreign(['candidacy_id', 'election_id'], 'ra_candidacy_same_election_fk')
                ->references(['id', 'election_id'])->on('candidacies')
                ->restrictOnDelete();
            // A candidate metric's candidacy must actually run in its stated contest.
            $table->foreign(['candidacy_id', 'contest_id'], 'ra_candidacy_contest_match_fk')
                ->references(['id', 'contest_id'])->on('candidacies')
                ->restrictOnDelete();
        });

        // CHECK mirrors the ENUM so an out-of-set value is rejected even under a
        // permissive sql_mode (where a bare ENUM silently stores ''). MySQL/MariaDB
        // only: SQLite already receives an equivalent CHECK from enum() and cannot
        // ALTER ... ADD CONSTRAINT.
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement(
                'ALTER TABLE `result_aggregates` ADD CONSTRAINT `chk_result_aggregates_metric_type` '
                ."CHECK (`metric_type` IN ('".implode("', '", $enums['metric_type'])."'))"
            );
            DB::statement(
                'ALTER TABLE `result_aggregates` ADD CONSTRAINT `chk_result_aggregates_dimension_type` '
                ."CHECK (`dimension_type` IN ('".implode("', '", $enums['dimension_type'])."'))"
            );
            DB::statement(
                'ALTER TABLE `result_aggregates` ADD CONSTRAINT `chk_result_aggregates_denominator_type` '
                ."CHECK (`denominator_type` IN ('".implode("', '", $enums['denominator_type'])."'))"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('result_aggregates');
    }
};
