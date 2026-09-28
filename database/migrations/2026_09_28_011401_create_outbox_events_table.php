<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wave 14 — Domain B (operational).
 *
 * Transactional outbox for post-commit side effects (e.g. aggregate
 * realtime updates, notifications). Dispatch logic is explicitly out of
 * scope for Phase 1B — this migration only creates the durable structure.
 * `payload_json` must only ever carry aggregate-level data; it must never
 * carry candidate selections or voter-identity-linked content
 * (docs/DATABASE.md §18). That is a standing review rule for every future
 * writer of this table, not something a schema constraint can enforce.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbox_events', function (Blueprint $table) {
            $table->engine = 'InnoDB';

            $table->id();
            $table->string('event_type');
            $table->json('payload_json');
            $table->foreignId('election_id')->nullable()->constrained('elections')->restrictOnDelete();
            $table->enum('status', ['PENDING', 'DISPATCHED', 'FAILED'])->default('PENDING');
            $table->timestamp('occurred_at');
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_events');
    }
};
