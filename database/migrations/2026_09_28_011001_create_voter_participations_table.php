<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wave 10 — Domain A (voting/participation).
 *
 * Records that a student successfully participated. Domain A: may
 * reference student identity. ULID primary key per the approved identifier
 * strategy.
 *
 * CRITICAL BALLOT-SECRECY BOUNDARY: this table must NEVER carry a foreign
 * key to `ballots` (or to any Domain B ballot-lifecycle table). The
 * correspondence between a participation row and its ballot exists only in
 * application memory during the single atomic vote-casting transaction and
 * is never persisted as a joinable key in either direction
 * (docs/DATABASE.md §5, §10).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voter_participations', function (Blueprint $table) {
            $table->engine = 'InnoDB';

            $table->ulid('id')->primary();
            $table->foreignId('election_id')->constrained('elections')->restrictOnDelete();
            $table->foreignId('student_id')->constrained('students')->restrictOnDelete();
            $table->string('submission_uuid');
            $table->timestamp('participated_at');
            $table->string('receipt_code')->unique();
            $table->boolean('is_full_election_abstention')->default(false);
            $table->timestamps();

            $table->unique(['election_id', 'student_id']);
            $table->unique('submission_uuid');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voter_participations');
    }
};
