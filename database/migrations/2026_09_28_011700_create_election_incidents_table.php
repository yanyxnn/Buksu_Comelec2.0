<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wave 17 — Domain operations.
 *
 * Investigation and incident record. `severity`/`status` value sets are not
 * fixed by the approved domain model beyond the table's existence — this
 * migration picks a minimal, maintainable set (implementation-phase
 * decision), since the concrete institutional workflow around incidents is
 * not specified and must not be invented beyond what is needed for later
 * phases to build on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('election_incidents', function (Blueprint $table) {
            $table->engine = 'InnoDB';

            $table->id();
            $table->foreignId('election_id')->constrained('elections')->restrictOnDelete();
            $table->string('title');
            $table->text('description');
            $table->enum('severity', ['LOW', 'MEDIUM', 'HIGH', 'CRITICAL'])->default('MEDIUM');
            $table->enum('status', ['OPEN', 'INVESTIGATING', 'RESOLVED', 'CLOSED'])->default('OPEN');
            $table->foreignId('reported_by')->nullable()->constrained('admin_users')->restrictOnDelete();
            $table->foreignId('resolved_by')->nullable()->constrained('admin_users')->restrictOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['election_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('election_incidents');
    }
};
