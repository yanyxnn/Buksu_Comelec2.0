<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Wave 18 — Domain B (ballot secrecy domain, traceability).
 *
 * Append-only exceptional handling for a ballot. A normal, uncontested
 * ballot has NO row here at all — absence of a row means "included". ULID
 * primary key per the approved identifier strategy. The original
 * ballots/ballot_contest_responses/ballot_candidate_selections rows are
 * never edited or deleted to give effect to a disposition — this table is
 * purely additive.
 *
 * BALLOT SECRECY BOUNDARY: no voter-identifying column exists on this
 * table — verified absent below.
 */
return new class extends Migration
{
    public function up(): void
    {
        $enums = [
            'disposition' => ['EXCLUDED_FROM_CALCULATION', 'VOIDED', 'REINSTATED'],
        ];

        Schema::create('ballot_dispositions', function (Blueprint $table) use ($enums) {
            $table->engine = 'InnoDB';

            $table->ulid('id')->primary();
            $table->foreignUlid('ballot_id')->constrained('ballots')->restrictOnDelete();
            $table->enum('disposition', $enums['disposition']);
            $table->text('reason')->nullable();
            $table->foreignId('related_incident_id')->nullable()->constrained('election_incidents')->restrictOnDelete();
            $table->foreignId('decided_by')->constrained('admin_users')->restrictOnDelete();
            $table->timestamp('decided_at');
            $table->timestamp('created_at')->useCurrent();

            $table->index('ballot_id');
        });

        // CHECK mirrors the ENUM so an out-of-set value is rejected even under a
        // permissive sql_mode (where a bare ENUM silently stores ''). MySQL/MariaDB
        // only: SQLite already receives an equivalent CHECK from enum() and cannot
        // ALTER ... ADD CONSTRAINT.
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement(
                'ALTER TABLE `ballot_dispositions` ADD CONSTRAINT `chk_ballot_dispositions_disposition` '
                ."CHECK (`disposition` IN ('".implode("', '", $enums['disposition'])."'))"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ballot_dispositions');
    }
};
