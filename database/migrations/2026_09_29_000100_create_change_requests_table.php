<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 02 (AUTH-034) — generic proposal/approval mechanism.
 *
 * One shared table (DOMAIN_MODEL_PROPOSAL §20 item 9: "design once, generically").
 * `action_type` is a free-form string validated against a config registry that
 * ships EMPTY: no institutional action types are invented here.
 *
 * Integrity in the database, not only in PHP (CLAUDE.md rule 6). On MySQL/MariaDB:
 *  - a decided request must record decider + time, a pending one must not;
 *  - the decider can never be the requester.
 * SQLite cannot ALTER ... ADD CONSTRAINT (same limitation as the existing enum
 * CHECK migrations), so those two CHECKs are MySQL/MariaDB only. The atomic
 * decision UPDATE in ChangeRequestService also carries `requested_by <> decider`.
 */
return new class extends Migration
{
    public function up(): void
    {
        $enums = ['status' => ['PENDING', 'APPROVED', 'REJECTED']];

        Schema::create('change_requests', function (Blueprint $table) use ($enums) {
            $table->engine = 'InnoDB';

            $table->id();
            $table->string('action_type', 100);
            $table->string('subject_type', 100)->nullable();
            $table->string('subject_id', 100)->nullable();
            $table->json('payload_json')->nullable();
            $table->enum('status', $enums['status'])->default('PENDING');
            $table->foreignId('requested_by')->constrained('admin_users')->restrictOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('admin_users')->restrictOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });

        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement(
                'ALTER TABLE `change_requests` ADD CONSTRAINT `chk_change_requests_status` '
                ."CHECK (`status` IN ('".implode("', '", $enums['status'])."'))"
            );
            DB::statement(
                'ALTER TABLE `change_requests` ADD CONSTRAINT `chk_change_requests_decision_state` CHECK ('
                ."(`status` = 'PENDING' AND `decided_by` IS NULL AND `decided_at` IS NULL) OR "
                ."(`status` IN ('APPROVED', 'REJECTED') AND `decided_by` IS NOT NULL AND `decided_at` IS NOT NULL))"
            );
            DB::statement(
                'ALTER TABLE `change_requests` ADD CONSTRAINT `chk_change_requests_no_self_decision` '
                .'CHECK (`decided_by` IS NULL OR `decided_by` <> `requested_by`)'
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('change_requests');
    }
};
