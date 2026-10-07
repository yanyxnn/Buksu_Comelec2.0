<?php

namespace App\Services\StudentImport;

use App\Models\ImportBatch;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Applies confirmed, staged rows to the authoritative student master data in chunks.
 *
 * TRANSACTION BOUNDARY: one chunk = one database transaction covering, for every row in it,
 * (1) the append-only student_enrollments row, (2) the students update and
 * (3) the row's `processed_at` marker, plus the batch counters. A failure rolls the WHOLE chunk
 * back; chunks that already committed stay committed and are never redone. There is no
 * cross-chunk atomicity and none is claimed: a half-processed batch is visible as PROCESSING
 * (or FAILED) with unprocessed rows, never as COMPLETED.
 *
 * IDEMPOTENCY: only rows with `processed_at IS NULL` are selected, and a row whose
 * (batch, student) enrollment already exists is marked processed WITHOUT re-applying anything
 * (re-applying would be wrong: after a course change the stored course already equals the
 * incoming one, so the course-change rule would no longer fire). UNIQUE(import_batch_id,
 * student_id) is the final guard.
 *
 * BATCH PRECEDENCE: undecided (OPEN_DECISIONS.md). import_batches.id is an identifier, never chronology, and
 * is never compared. A batch applies to the current placement when an administrator has confirmed and
 * started it; history stays append-only and `last_import_batch_id` names the batch that produced it.
 *
 * This class only ever writes `students` columns it owns for placement/name/status and
 * `last_import_batch_id`. It never touches institutional_email, institutional_id or
 * google_subject, never creates a student, and never reads or writes admin_users.
 */
class ImportChunkProcessor
{
    private readonly PlacementRule $placement;

    public function __construct()
    {
        $this->placement = new PlacementRule((string) array_key_first(config('comelec.import.year_levels')));
    }

    /**
     * Processes chunks until no applicable unprocessed row is left.
     *
     * @return int number of rows applied in this call
     */
    public function processAll(ImportBatch $batch): int
    {
        $batch->refresh();

        if ($batch->status !== BatchStateMachine::PROCESSING) {
            throw new ImportTransitionException((string) $batch->status, BatchStateMachine::PROCESSING);
        }

        $size = max(1, min((int) config('comelec.import.chunk_size', 500), 1000));
        $applied = 0;

        while (($n = $this->processNextChunk((int) $batch->id, $size)) > 0) {
            $applied += $n;
        }

        return $applied;
    }

    /** @return int rows handled in this chunk (0 = nothing left) */
    public function processNextChunk(int $batchId, int $size): int
    {
        // The batch row is the per-batch mutex: every chunk transaction takes it FIRST, so two workers on
        // the same batch take turns instead of interleaving range locks on import_batch_rows (observed as
        // InnoDB deadlocks 1213 under real concurrency on MariaDB). Different batches do not contend.
        // A deadlock that still happens is retried a bounded number of times: a chunk is idempotent
        // (processed_at cursor + UNIQUE(import_batch_id, student_id)), so re-running it is safe.
        return DB::transaction(function () use ($batchId, $size): int {
            $batch = DB::table('import_batches')->where('id', $batchId)->lockForUpdate()->first();

            $rows = DB::table('import_batch_rows')
                ->where('import_batch_id', $batchId)
                ->where('classification', RowClassifier::UPDATED)
                ->whereNull('processed_at')
                ->orderBy('id')
                ->limit($size)
                ->lockForUpdate()
                ->get();

            if ($rows->isEmpty()) {
                return 0;
            }

            $effectiveFrom = $batch->confirmed_at ?? now();
            $now = now();

            foreach ($rows as $row) {
                $this->applyRow($batch, $row, $effectiveFrom, $now);
            }

            DB::table('import_batches')->where('id', $batchId)->update([
                'records_updated' => $this->processedCount($batchId, RowClassifier::UPDATED),
                'records_created' => $this->processedCount($batchId, RowClassifier::NEW),
                'updated_at' => $now,
            ]);

            return $rows->count();
        }, 3);
    }

    private function applyRow(object $batch, object $row, mixed $effectiveFrom, mixed $now): void
    {
        $student = $row->resolved_student_id === null
            ? null
            : DB::table('students')->where('id', $row->resolved_student_id)->lockForUpdate()->first();

        if ($student === null) {
            // An UPDATED row always resolves to an existing student (FK restrict). Anything else is
            // an integrity problem: surface it, never skip silently.
            throw new RuntimeException('Staged row has no resolvable student.');
        }

        $values = json_decode((string) $row->normalized_json, true, 512, JSON_THROW_ON_ERROR)['values'];

        $alreadyApplied = DB::table('student_enrollments')
            ->where('import_batch_id', $batch->id)
            ->where('student_id', $student->id)
            ->exists();

        if (! $alreadyApplied) {
            // PRECEDENCE IS NOT DECIDED. Nothing approved defines which official batch is "newer" (see
            // OPEN_DECISIONS.md), and import_batches.id is only an identifier: a larger auto-increment value
            // is NOT evidence of newer data, so no id is ever compared here. The batch being processed was
            // explicitly confirmed and started by an administrator, and it is applied as the current
            // official placement of its students. Safety does not depend on any ordering: the enrollment
            // history is append-only (one row per batch and student, never edited), students.last_import_batch_id
            // always names the batch that produced the current placement, and replay is a no-op.
            $resolved = $this->placement->resolve((array) $student, $values)['values'];
            $enrollment = [
                'college' => $resolved['college'],
                'course' => $resolved['course'],
                'year_level' => $resolved['year_level'],
                'status' => $resolved['status'],
            ];

            DB::table('students')->where('id', $student->id)->update([
                'first_name' => $resolved['first_name'],
                'middle_name' => $resolved['middle_name'],
                'last_name' => $resolved['last_name'],
                'current_college' => $resolved['college'],
                'current_course' => $resolved['course'],
                'current_year_level' => $resolved['year_level'],
                'status' => $resolved['status'],
                'last_import_batch_id' => $batch->id,
                'updated_at' => $now,
            ]);

            DB::table('student_enrollments')->insert($enrollment + [
                'student_id' => $student->id,
                'import_batch_id' => $batch->id,
                'effective_from' => $effectiveFrom,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        DB::table('import_batch_rows')->where('id', $row->id)->update(['processed_at' => $now, 'updated_at' => $now]);
    }

    private function processedCount(int $batchId, string $classification): int
    {
        return (int) DB::table('import_batch_rows')
            ->where('import_batch_id', $batchId)
            ->where('classification', $classification)
            ->whereNotNull('processed_at')
            ->count();
    }
}
