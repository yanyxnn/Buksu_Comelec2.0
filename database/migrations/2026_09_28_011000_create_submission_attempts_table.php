<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Wave 10 — Domain A (voting/participation, idempotency).
 *
 * Idempotency/request tracking. Domain A: may reference student identity.
 * Uses a ULID primary key per the approved identifier strategy
 * (docs/DOMAIN_MODEL_PROPOSAL.md §17) since this table sits in the
 * idempotency/participation path that must not be trivially enumerable.
 * `submission_uuid` is a separate, client-generated, opaque business
 * identifier — not the same value as the row's own ULID primary key.
 */
return new class extends Migration
{
    public function up(): void
    {
        $enums = [
            'status' => ['RECEIVED', 'VALIDATING', 'COMMITTED', 'FAILED', 'REJECTED'],
        ];

        Schema::create('submission_attempts', function (Blueprint $table) use ($enums) {
            $table->engine = 'InnoDB';

            $table->ulid('id')->primary();
            $table->string('submission_uuid')->unique();
            $table->foreignId('election_id')->constrained('elections')->restrictOnDelete();
            $table->foreignId('student_id')->constrained('students')->restrictOnDelete();
            $table->enum('status', $enums['status'])->default('RECEIVED');
            $table->string('failure_reason')->nullable();
            $table->timestamp('committed_at')->nullable();
            $table->timestamps();

            $table->index(['election_id', 'student_id']);
        });

        // CHECK mirrors the ENUM so an out-of-set value is rejected even under a
        // permissive sql_mode (where a bare ENUM silently stores ''). MySQL/MariaDB
        // only: SQLite already receives an equivalent CHECK from enum() and cannot
        // ALTER ... ADD CONSTRAINT.
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement(
                'ALTER TABLE `submission_attempts` ADD CONSTRAINT `chk_submission_attempts_status` '
                ."CHECK (`status` IN ('".implode("', '", $enums['status'])."'))"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('submission_attempts');
    }
};
