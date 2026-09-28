<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Wave 14 — Domain B (operational).
 *
 * Transactional outbox for post-commit side effects (e.g. aggregate
 * realtime updates, notifications) — docs/DATABASE.md §6. Dispatch logic is
 * out of scope for Phase 1B; this migration only creates the durable
 * structure.
 *
 * `status` (PENDING / DISPATCHED / FAILED) is an implementation-phase
 * decision. The approved docs describe an outbox written inside the vote
 * transaction and processed after commit (docs/ARCHITECTURE.md §4;
 * docs/NON_NEGOTIABLES.md #19, #28) but do not list its columns or status
 * values. PENDING/DISPATCHED are the minimum states any transactional
 * outbox needs; FAILED is a migration-author choice.
 *
 * `payload_json` must only ever carry aggregate-level data; it must never
 * carry candidate selections or voter-identity-linked content
 * (docs/DATABASE.md §6). That is a standing review rule for every future
 * writer of this table, not something a schema constraint can enforce.
 */
return new class extends Migration
{
    public function up(): void
    {
        $enums = [
            'status' => ['PENDING', 'DISPATCHED', 'FAILED'],
        ];

        Schema::create('outbox_events', function (Blueprint $table) use ($enums) {
            $table->engine = 'InnoDB';

            $table->id();
            $table->string('event_type');
            $table->json('payload_json');
            $table->foreignId('election_id')->nullable()->constrained('elections')->restrictOnDelete();
            $table->enum('status', $enums['status'])->default('PENDING');
            $table->timestamp('occurred_at');
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'occurred_at']);
        });

        // CHECK mirrors the ENUM so an out-of-set value is rejected even under a
        // permissive sql_mode (where a bare ENUM silently stores ''). MySQL/MariaDB
        // only: SQLite already receives an equivalent CHECK from enum() and cannot
        // ALTER ... ADD CONSTRAINT.
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement(
                'ALTER TABLE `outbox_events` ADD CONSTRAINT `chk_outbox_events_status` '
                ."CHECK (`status` IN ('".implode("', '", $enums['status'])."'))"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_events');
    }
};
