<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 02 — minimal access-assistance record.
 *
 * Created when Google authentication succeeded but the person is not recognised
 * as a student (or otherwise cannot be let in). It is only a note for an admin:
 * it never creates or modifies a student and never grants access. The broader
 * Student Reports workflow is a later (Phase 03) concern.
 *
 * Two different questions are kept apart:
 *  - `denial_reason`: SYSTEM-generated, why authentication denied the login.
 *  - `problem_type`: what the STUDENT says they need help with (allowed values live in
 *    config('comelec.access_issue_problem_types'); a plain string, deliberately not a DB enum).
 *
 * Deliberately NOT stored: Google subject, OAuth tokens, or any unverified email.
 * `subject_fingerprint` is a keyed 16-hex hash used only to correlate repeats.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access_issue_reports', function (Blueprint $table) {
            $table->engine = 'InnoDB';

            $table->id();
            $table->string('google_email')->nullable();          // verified institutional Google email only
            $table->string('problem_type', 60);                    // student-chosen, validated against config
            $table->string('subject_fingerprint', 16);
            $table->string('reported_student_id', 50)->nullable(); // typed by the person, unverified
            $table->text('description');
            $table->string('denial_reason', 50);                   // system-generated internal code, admin-only
            $table->timestamps();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_issue_reports');
    }
};
