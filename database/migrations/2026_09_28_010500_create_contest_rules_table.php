<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
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
        $enums = [
            'voting_method' => ['SINGLE_CHOICE', 'MULTI_CHOICE'],
        ];

        Schema::create('contest_rules', function (Blueprint $table) use ($enums) {
            $table->engine = 'InnoDB';

            $table->id();
            $table->foreignId('contest_id')->unique()->constrained('contests')->restrictOnDelete();
            $table->unsignedInteger('seat_count');
            $table->unsignedInteger('selection_limit');
            $table->boolean('allow_abstain')->default(true);
            $table->enum('voting_method', $enums['voting_method']);
            $table->timestamps();
        });

        // CHECK mirrors the ENUM so an out-of-set value is rejected even under a
        // permissive sql_mode (where a bare ENUM silently stores ''). MySQL/MariaDB
        // only: SQLite already receives an equivalent CHECK from enum() and cannot
        // ALTER ... ADD CONSTRAINT.
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement(
                'ALTER TABLE `contest_rules` ADD CONSTRAINT `chk_contest_rules_voting_method` '
                ."CHECK (`voting_method` IN ('".implode("', '", $enums['voting_method'])."'))"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('contest_rules');
    }
};
