<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
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
        Schema::create('submission_attempts', function (Blueprint $table) {
            $table->engine = 'InnoDB';

            $table->ulid('id')->primary();
            $table->string('submission_uuid')->unique();
            $table->foreignId('election_id')->constrained('elections')->restrictOnDelete();
            $table->foreignId('student_id')->constrained('students')->restrictOnDelete();
            $table->enum('status', ['RECEIVED', 'VALIDATING', 'COMMITTED', 'FAILED', 'REJECTED'])->default('RECEIVED');
            $table->string('failure_reason')->nullable();
            $table->timestamp('committed_at')->nullable();
            $table->timestamps();

            $table->index(['election_id', 'student_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('submission_attempts');
    }
};
