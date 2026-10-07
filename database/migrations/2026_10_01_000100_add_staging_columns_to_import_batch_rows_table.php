<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 03A — Data Center import staging detail.
 *
 * `import_batch_rows` (Phase 01B) stores the classification and the raw source row only. The
 * preview/review step and restartable chunk processing need four more facts per staged row:
 *
 *  - source_row_number : the row's position in the uploaded file (review + deterministic order).
 *  - normalized_json   : the deterministic normalization of the row (trimmed/canonicalised values).
 *  - issues_json       : machine-readable reasons behind the classification ([{code, field}]).
 *  - processed_at      : set in the SAME transaction that applies the row to authoritative data.
 *                        It is the restart cursor: a retry only ever selects rows where it is NULL.
 *
 * Additive and nullable only: no existing column, constraint or ENUM/CHECK is touched, so the
 * Phase 01B ENUM/CHECK guarantees are unchanged. UNIQUE(import_batch_id, source_row_number) makes
 * a re-run of validation unable to stage the same file row twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_batch_rows', function (Blueprint $table) {
            $table->unsignedInteger('source_row_number')->nullable()->after('import_batch_id');
            $table->json('normalized_json')->nullable()->after('raw_row_json');
            $table->json('issues_json')->nullable()->after('normalized_json');
            $table->timestamp('processed_at')->nullable()->after('resolved_student_id');

            $table->unique(['import_batch_id', 'source_row_number'], 'import_batch_rows_batch_rownum_unique');
            $table->index(['import_batch_id', 'classification', 'processed_at'], 'import_batch_rows_processing_idx');
        });
    }

    public function down(): void
    {
        Schema::table('import_batch_rows', function (Blueprint $table) {
            $table->dropIndex('import_batch_rows_processing_idx');
            $table->dropUnique('import_batch_rows_batch_rownum_unique');
            $table->dropColumn(['source_row_number', 'normalized_json', 'issues_json', 'processed_at']);
        });
    }
};
