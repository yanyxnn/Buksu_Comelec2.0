<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wave 5 — Domain B (election configuration).
 *
 * Contest-specific voting rules. One row per contest (unique on
 * contest_id) — a multi-seat contest may allow fewer than the configured
 * maximum selections; that remains a valid VOTE, not an abstention
 * (docs/DATABASE.md §3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contest_rules', function (Blueprint $table) {
            $table->engine = 'InnoDB';

            $table->id();
            $table->foreignId('contest_id')->unique()->constrained('contests')->restrictOnDelete();
            $table->unsignedInteger('seat_count');
            $table->unsignedInteger('selection_limit');
            $table->boolean('allow_abstain')->default(true);
            $table->enum('voting_method', ['SINGLE_CHOICE', 'MULTI_CHOICE']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contest_rules');
    }
};
