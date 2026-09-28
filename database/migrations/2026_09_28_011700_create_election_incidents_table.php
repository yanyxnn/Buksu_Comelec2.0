<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Wave 17 — Domain operations.
 *
 * Investigation and incident record (docs/DATABASE.md §10).
 *
 * `status` (OPEN / INVESTIGATING / RESOLVED / CLOSED) is an
 * implementation-phase decision. The approved docs fix only that this is
 * an "investigation and incident record" (docs/DATABASE.md §10) and that
 * integrity anomalies are "investigated and reconciled"
 * (docs/NON_NEGOTIABLES.md #21); the value set itself is not listed in
 * docs/DOMAIN_MODEL_PROPOSAL.md or docs/DATABASE.md. INVESTIGATING is
 * derived from that workflow. The RESOLVED/CLOSED split is a
 * migration-author choice, not an approved rule.
 *
 * `severity` is deliberately an unrestricted, nullable triage label. COMELEC
 * has not defined an institutional incident-severity vocabulary, so none is
 * enforced here: no ENUM, no CHECK, no default. Application code must not
 * treat particular strings as meaningful until such a vocabulary is
 * approved.
 */
return new class extends Migration
{
    public function up(): void
    {
        $enums = [
            'status' => ['OPEN', 'INVESTIGATING', 'RESOLVED', 'CLOSED'],
        ];

        Schema::create('election_incidents', function (Blueprint $table) use ($enums) {
            $table->engine = 'InnoDB';

            $table->id();
            $table->foreignId('election_id')->constrained('elections')->restrictOnDelete();
            $table->string('title');
            $table->text('description');
            $table->string('severity')->nullable();
            $table->enum('status', $enums['status'])->default('OPEN');
            $table->foreignId('reported_by')->nullable()->constrained('admin_users')->restrictOnDelete();
            $table->foreignId('resolved_by')->nullable()->constrained('admin_users')->restrictOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['election_id', 'status']);
        });

        // CHECK mirrors the ENUM so an out-of-set value is rejected even under a
        // permissive sql_mode (where a bare ENUM silently stores ''). MySQL/MariaDB
        // only: SQLite already receives an equivalent CHECK from enum() and cannot
        // ALTER ... ADD CONSTRAINT.
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement(
                'ALTER TABLE `election_incidents` ADD CONSTRAINT `chk_election_incidents_status` '
                ."CHECK (`status` IN ('".implode("', '", $enums['status'])."'))"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('election_incidents');
    }
};
