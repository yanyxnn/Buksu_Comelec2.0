<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wave 5 — Domain B (election configuration).
 *
 * Rule definition for which eligible voters may participate in the
 * contest. Kept as a JSON rule definition (not fixed columns) so eligible
 * dimensions (college/course/year/sector/status/etc., per
 * docs/ELIGIBILITY_ENGINE.md) remain configurable rather than hard-coded.
 * Not unique per contest — a contest's eligibility may be composed of more
 * than one rule row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contest_eligibility_rules', function (Blueprint $table) {
            $table->engine = 'InnoDB';

            $table->id();
            $table->foreignId('contest_id')->constrained('contests')->restrictOnDelete();
            $table->json('rule_definition_json');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contest_eligibility_rules');
    }
};
