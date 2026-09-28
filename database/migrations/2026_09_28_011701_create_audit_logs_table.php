<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wave 17 — Domain operations.
 *
 * General-purpose, system-wide operational trail, per the field list
 * suggested in docs/AUDIT_LOGGING.md. `actor_id`/`target_id` are stored as
 * strings rather than typed foreign keys because the actor/target can be
 * an admin, a student, an import batch, a Domain B ULID-keyed row, etc. —
 * a single polymorphic FK type cannot span both bigint and ULID primary
 * keys. This table must NEVER be designed to store candidate selections
 * (docs/DATABASE.md §21) — that is a standing review rule for every future
 * writer, not something this schema can itself enforce. Append-only:
 * `created_at` only, no `updated_at`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->engine = 'InnoDB';

            $table->id();
            $table->string('event_type');
            $table->string('severity')->default('INFO');
            $table->string('actor_type')->nullable();
            $table->string('actor_id')->nullable();
            $table->foreignId('election_id')->nullable()->constrained('elections')->restrictOnDelete();
            $table->string('target_type')->nullable();
            $table->string('target_id')->nullable();
            $table->text('description')->nullable();
            $table->json('metadata_json')->nullable();
            $table->string('correlation_id')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['event_type', 'created_at']);
            $table->index(['target_type', 'target_id']);
            $table->index('correlation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
