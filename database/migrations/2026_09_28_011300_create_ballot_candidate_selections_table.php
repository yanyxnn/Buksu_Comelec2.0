<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wave 13 — Domain B (ballot secrecy domain).
 *
 * Candidate selections under a VOTE contest response. Auto-increment primary
 * key per the approved identifier strategy (highest-volume child table, always
 * reached via its parent response, never individually exposed).
 *
 * THREE-WAY CONTEST INTEGRITY. Two composite FKs, sharing this row's
 * `contest_id`, force:
 *
 *   response.contest_id  =  selection.contest_id  =  candidacy.contest_id
 *
 *   (ballot_contest_response_id, contest_id) -> ballot_contest_responses(id, contest_id)
 *   (candidacy_id,               contest_id) -> candidacies(id, contest_id)
 *
 * Because `contest_id` is one column participating in both FKs, a selection can
 * neither sit under a response for a different contest, nor name a candidacy
 * that runs in a different contest. Both are declarative, no triggers.
 * The former plain FKs on ballot_contest_response_id and contest_id were
 * removed: each is fully implied by the composite FK that contains it.
 *
 * NOT enforceable here (application transaction + reconciliation): selection
 * count limits, and "ABSTAIN => zero rows".
 *
 * BALLOT SECRECY BOUNDARY: no voter-identifying column exists on this table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ballot_candidate_selections', function (Blueprint $table) {
            $table->engine = 'InnoDB';

            $table->id();
            $table->foreignUlid('ballot_contest_response_id');
            $table->unsignedBigInteger('contest_id');
            $table->unsignedBigInteger('candidacy_id');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['ballot_contest_response_id', 'candidacy_id'], 'bcs_response_candidacy_unique');

            $table->foreign(['ballot_contest_response_id', 'contest_id'], 'bcs_response_contest_match_foreign')
                ->references(['id', 'contest_id'])->on('ballot_contest_responses')
                ->restrictOnDelete();

            $table->foreign(['candidacy_id', 'contest_id'], 'bcs_candidacy_contest_membership_foreign')
                ->references(['id', 'contest_id'])->on('candidacies')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ballot_candidate_selections');
    }
};
