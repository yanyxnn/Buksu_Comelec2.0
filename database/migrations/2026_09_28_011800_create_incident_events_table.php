<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wave 18 — Domain operations.
 *
 * Append-only events within an incident. Kept separate from ballot-specific
 * lifecycle (ballot_events/ballot_dispositions below), so a general
 * incident record can never accidentally introduce a voter/ballot
 * correlation (docs/DATABASE.md §21).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('incident_events', function (Blueprint $table) {
            $table->engine = 'InnoDB';

            $table->id();
            $table->foreignId('election_incident_id')->constrained('election_incidents')->restrictOnDelete();
            $table->string('event_type');
            $table->foreignId('actor_id')->nullable()->constrained('admin_users')->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->useCurrent();

            $table->index('election_incident_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incident_events');
    }
};
