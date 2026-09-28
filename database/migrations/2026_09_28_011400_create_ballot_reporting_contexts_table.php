<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wave 14 — Domain B (ballot secrecy domain).
 *
 * One immutable non-identifying reporting context per ballot, copied by
 * value (not by reference) from the voter's locked eligibility-snapshot row
 * at cast time. Auto-increment primary key — not privacy-relevant in the
 * ULID sense, since this row carries no identity to protect by opacity;
 * the secrecy boundary here is about *columns*, not key enumerability.
 *
 * BALLOT SECRECY BOUNDARY: this table MUST NOT contain student_id,
 * institutional_id, voter_participation_id, submission_uuid, receipt_code,
 * Google identity, or any key back to the eligibility-voter row — verified
 * absent below.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ballot_reporting_contexts', function (Blueprint $table) {
            $table->engine = 'InnoDB';

            $table->id();
            $table->foreignUlid('ballot_id')->unique();
            $table->foreignId('election_id')->constrained('elections')->restrictOnDelete();
            $table->string('frozen_college', 100)->nullable();
            $table->string('frozen_course', 100)->nullable();
            $table->string('frozen_year_level', 100)->nullable();
            $table->string('frozen_sector', 100)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['election_id', 'frozen_college', 'frozen_course', 'frozen_year_level', 'frozen_sector'], 'brc_election_dimensions_idx');

            // Cross-election integrity: the context's election must equal its ballot's.
            $table->foreign(['ballot_id', 'election_id'], 'brc_ballot_same_election_fk')
                ->references(['id', 'election_id'])->on('ballots')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ballot_reporting_contexts');
    }
};
