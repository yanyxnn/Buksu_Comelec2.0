<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Wave 11 — Domain B (ballot secrecy domain).
 *
 * Anonymous cast ballot. ULID primary key per the approved identifier
 * strategy (opaque, non-enumerable).
 *
 * BALLOT SECRECY BOUNDARY: this table MUST NOT contain student_id,
 * institutional_id, voter_participation_id, submission_uuid, receipt_code,
 * Google identity/subject, or any equivalent voter identifier. `status` is a
 * single-value enum (`CAST` only): there is no VOIDED row-level state, and no
 * normal UPDATE/DELETE path exists. Exceptional handling is additive, via
 * `ballot_dispositions` — the original cast ballot is never edited or deleted.
 *
 * CROSS-ELECTION INTEGRITY: (ballot_structure_snapshot_id, election_id) is a
 * composite FK to ballot_structure_snapshots(id, election_id), so a ballot can
 * never point at another election's structure snapshot.
 * UNIQUE(id, election_id) is the anchor that lets ballot_contest_responses and
 * ballot_reporting_contexts prove they share the ballot's election.
 */
return new class extends Migration
{
    public function up(): void
    {
        $enums = [
            'status' => ['CAST'],
        ];

        Schema::create('ballots', function (Blueprint $table) use ($enums) {
            $table->engine = 'InnoDB';

            $table->ulid('id')->primary();
            $table->foreignId('election_id')->constrained('elections')->restrictOnDelete();
            $table->unsignedBigInteger('ballot_structure_snapshot_id');
            $table->timestamp('cast_at');
            $table->enum('status', $enums['status'])->default('CAST');
            $table->timestamp('created_at')->useCurrent();

            $table->foreign(['ballot_structure_snapshot_id', 'election_id'], 'ballots_bss_same_election_fk')
                ->references(['id', 'election_id'])->on('ballot_structure_snapshots')
                ->restrictOnDelete();

            $table->unique(['id', 'election_id'], 'ballots_id_election_unique');
        });

        // CHECK mirrors the ENUM so an out-of-set value is rejected even under a
        // permissive sql_mode (where a bare ENUM silently stores ''). MySQL/MariaDB
        // only: SQLite already receives an equivalent CHECK from enum() and cannot
        // ALTER ... ADD CONSTRAINT.
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement(
                'ALTER TABLE `ballots` ADD CONSTRAINT `chk_ballots_status` '
                ."CHECK (`status` IN ('".implode("', '", $enums['status'])."'))"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ballots');
    }
};
