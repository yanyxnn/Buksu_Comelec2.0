<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wave 18 — Domain B (ballot secrecy domain, traceability).
 *
 * Ballot lifecycle/traceability event history. Answers "what happened to
 * ballot X" without ever being joinable to a student. ULID primary key per
 * the approved identifier strategy. `actor_id` may reference `admin_users`
 * — an admin investigating an incident is not a secrecy violation — but a
 * student identity must never appear on this table.
 *
 * BALLOT SECRECY BOUNDARY: no voter-identifying column exists on this
 * table — verified absent below.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ballot_events', function (Blueprint $table) {
            $table->engine = 'InnoDB';

            $table->ulid('id')->primary();
            $table->foreignUlid('ballot_id')->constrained('ballots')->restrictOnDelete();
            $table->string('event_type');
            $table->foreignId('related_incident_id')->nullable()->constrained('election_incidents')->restrictOnDelete();
            $table->foreignId('related_result_calculation_run_id')->nullable()->constrained('result_calculation_runs')->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('admin_users')->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->useCurrent();

            $table->index('ballot_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ballot_events');
    }
};
