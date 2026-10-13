<?php

namespace App\Services\StudentImport;

use App\Models\ImportBatch;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Applies confirmed import rows in transactional chunks.
 *
 * NEW students receive an institutional email derived from their
 * validated 10-digit ID. Student creation, enrollment history, and
 * the processed marker commit together in the same transaction.
 *
 * Existing students retain their institutional ID, institutional
 * email, and Google identity. Enrollment history remains append-only.
 */
class ImportChunkProcessor
{
    private readonly PlacementRule $placement;

    public function __construct()
    {
        $this->placement = new PlacementRule(
            (string) array_key_first(config('comelec.import.year_levels'))
        );
    }

    /**
     * Process all applicable rows in bounded chunks.
     *
     * @return int Number of rows applied in this call.
     */
    public function processAll(ImportBatch $batch): int
    {
        $batch->refresh();

        if ($batch->status !== BatchStateMachine::PROCESSING) {
            throw new ImportTransitionException(
                (string) $batch->status,
                BatchStateMachine::PROCESSING
            );
        }

        $size = max(
            1,
            min((int) config('comelec.import.chunk_size', 500), 1000)
        );

        $applied = 0;

        while (($count = $this->processNextChunk((int) $batch->id, $size)) > 0) {
            $applied += $count;
        }

        return $applied;
    }

    /**
     * Process one transactionally protected chunk.
     *
     * @return int Rows handled, or zero if no eligible rows remain.
     */
    public function processNextChunk(int $batchId, int $size): int
    {
        return DB::transaction(function () use ($batchId, $size): int {
            // The batch row is the mutex for concurrent workers.
            $batch = DB::table('import_batches')
                ->where('id', $batchId)
                ->lockForUpdate()
                ->first();

            // A worker must stop if the batch is no longer PROCESSING.
            if (
                $batch === null
                || $batch->status !== BatchStateMachine::PROCESSING
            ) {
                return 0;
            }

            $rows = DB::table('import_batch_rows')
                ->where('import_batch_id', $batchId)
                ->whereIn('classification', [
                    RowClassifier::UPDATED,
                    RowClassifier::NEW,
                ])
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

            DB::table('import_batches')
                ->where('id', $batchId)
                ->update([
                    'records_updated' => $this->processedCount(
                        $batchId,
                        RowClassifier::UPDATED
                    ),
                    'records_created' => $this->processedCount(
                        $batchId,
                        RowClassifier::NEW
                    ),
                    'updated_at' => $now,
                ]);

            return $rows->count();
        }, 3);
    }

    private function applyRow(
        object $batch,
        object $row,
        mixed $effectiveFrom,
        mixed $now
    ): void {
        $values = json_decode(
            (string) $row->normalized_json,
            true,
            512,
            JSON_THROW_ON_ERROR
        )['values'];

        $isNew = $row->classification === RowClassifier::NEW;

        if ($isNew) {
            $institutionalId = (string) (
                $values['institutional_id'] ?? ''
            );

            if (
                preg_match('/\A(?:[0-9]{7}|[0-9]{10})\z/', $institutionalId) !== 1
            ) {
                throw new RuntimeException(
                    'New student ID must be a valid 7-digit legacy or 10-digit current institutional ID.'
                );
            }

            // Protect against another batch creating this student
            // after validation but before this batch is processed.
            $existing = DB::table('students')
                ->where('institutional_id', $institutionalId)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                throw new RuntimeException(
                    'A staged new student ID now exists. Revalidate the batch before processing.'
                );
            }

            $email = $institutionalId . '@student.buksu.edu.ph';
            $status = $values['status'] ?? 'ACTIVE';

            // Student creation happens inside the chunk transaction.
            $studentId = (int) DB::table('students')->insertGetId([
                'institutional_id' => $institutionalId,
                'institutional_email' => $email,
                'first_name' => $values['first_name'],
                'middle_name' => $values['middle_name'],
                'last_name' => $values['last_name'],
                'current_college' => $values['college'],
                'current_course' => $values['course'],
                'current_year_level' => $values['year_level'],
                'status' => $status,
                'google_subject' => null,
                'last_import_batch_id' => $batch->id,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            // Persist the resolved ID for this staged row.
            DB::table('import_batch_rows')
                ->where('id', $row->id)
                ->update([
                    'resolved_student_id' => $studentId,
                    'updated_at' => $now,
                ]);

            $resolved = [
                'first_name' => $values['first_name'],
                'middle_name' => $values['middle_name'],
                'last_name' => $values['last_name'],
                'college' => $values['college'],
                'course' => $values['course'],
                'year_level' => $values['year_level'],
                'status' => $status,
            ];
        } else {
            $student = $row->resolved_student_id === null
                ? null
                : DB::table('students')
                    ->where('id', $row->resolved_student_id)
                    ->lockForUpdate()
                    ->first();

            if ($student === null) {
                throw new RuntimeException(
                    'An existing student row has no resolvable student.'
                );
            }
            // A blank source first name must not erase an existing first name.
            $values['first_name'] = $values['first_name'] ?? $student->first_name;

            $studentId = (int) $student->id;

            $resolved = $this->placement
                ->resolve((array) $student, $values)['values'];
        }

        $alreadyApplied = DB::table('student_enrollments')
            ->where('import_batch_id', $batch->id)
            ->where('student_id', $studentId)
            ->exists();

        if (! $alreadyApplied) {
            if (! $isNew) {
                // Existing students retain their identity, email,
                // and Google subject.
                DB::table('students')
                    ->where('id', $studentId)
                    ->update([
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
            }

            // History is append-only and unique per batch/student.
            DB::table('student_enrollments')->insert([
                'student_id' => $studentId,
                'import_batch_id' => $batch->id,
                'college' => $resolved['college'],
                'course' => $resolved['course'],
                'year_level' => $resolved['year_level'],
                'status' => $resolved['status'],
                'effective_from' => $effectiveFrom,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // Creation/update, enrollment, and this marker share the
        // same transaction. A rollback undoes all of them together.
        DB::table('import_batch_rows')
            ->where('id', $row->id)
            ->update([
                'processed_at' => $now,
                'updated_at' => $now,
            ]);
    }

    private function processedCount(
        int $batchId,
        string $classification
    ): int {
        return (int) DB::table('import_batch_rows')
            ->where('import_batch_id', $batchId)
            ->where('classification', $classification)
            ->whereNotNull('processed_at')
            ->count();
    }
}