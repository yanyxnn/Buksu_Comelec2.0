<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wave 12 — Domain B (ballot secrecy domain).
 *
 * Exactly one explicit response per contest on a committed ballot
 * (UNIQUE(ballot_id, contest_id)). `response_type` is VOTE or ABSTAIN only —
 * there is no SKIP domain value.
 *
 * CROSS-ELECTION INTEGRITY: `election_id` is denormalized onto this row purely
 * so two composite FKs can prove that the ballot and the contest belong to the
 * SAME election:
 *   (ballot_id,  election_id) -> ballots(id, election_id)
 *   (contest_id, election_id) -> contests(id, election_id)
 * `election_id` here is a copy of a non-identifying value already on `ballots`.
 *
 * UNIQUE(id, contest_id) is the anchor for ballot_candidate_selections, so a
 * selection's contest must equal its parent response's contest.
 *
 * BALLOT SECRECY BOUNDARY: no voter-identifying column exists on this table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ballot_contest_responses', function (Blueprint $table) {
            $table->engine = 'InnoDB';

            $table->ulid('id')->primary();
            $table->foreignUlid('ballot_id');
            $table->unsignedBigInteger('contest_id');
            $table->foreignId('election_id')->constrained('elections')->restrictOnDelete();
            $table->enum('response_type', ['VOTE', 'ABSTAIN']);
            $table->timestamp('created_at')->useCurrent();

            $table->foreign(['ballot_id', 'election_id'], 'bcr_ballot_same_election_fk')
                ->references(['id', 'election_id'])->on('ballots')
                ->restrictOnDelete();
            $table->foreign(['contest_id', 'election_id'], 'bcr_contest_same_election_fk')
                ->references(['id', 'election_id'])->on('contests')
                ->restrictOnDelete();

            $table->unique(['ballot_id', 'contest_id']);

            // Anchor for ballot_candidate_selections' response-contest FK.
            $table->unique(['id', 'contest_id'], 'bcr_id_contest_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ballot_contest_responses');
    }
};
