<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wave 1 — Domain B (election configuration).
 *
 * Election event and lifecycle root. `current_config_version_id` and
 * `locked_config_version_id` are created here as plain nullable columns
 * without their foreign keys, because `election_config_versions` does not
 * exist yet (Wave 2). Those FK constraints are added onto this table from
 * the election_config_versions migration once that table exists.
 *
 * `state` uses the full lifecycle from docs/CLAUDE.md — this is a locked
 * architecture decision, not an open/configurable value set, so it is
 * expressed as a native MySQL enum rather than a free-text column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('elections', function (Blueprint $table) {
            $table->engine = 'InnoDB';

            $table->id();
            $table->string('name');
            $table->string('type');
            $table->enum('state', [
                'DRAFT', 'READY', 'APPROVED', 'SNAPSHOTTED', 'LOCKED', 'SCHEDULED',
                'OPEN', 'PAUSED', 'CLOSED', 'RECONCILING', 'RESULTS_PENDING_VALIDATION',
                'VALIDATED', 'OFFICIALLY_ANNOUNCED', 'FINALIZED',
            ])->default('DRAFT');

            // FKs added later in 2026_09_28_010201_create_election_config_versions_table.php
            $table->unsignedBigInteger('current_config_version_id')->nullable();
            $table->unsignedBigInteger('locked_config_version_id')->nullable();

            $table->string('timezone')->default('Asia/Manila');
            $table->timestamp('opens_at')->nullable();
            $table->timestamp('closes_at')->nullable();
            $table->foreignId('created_by')->constrained('admin_users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('admin_users')->restrictOnDelete();
            $table->timestamps();

            $table->index('state');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('elections');
    }
};
