<?php

use App\Models\Student;
use App\Services\StudentImport\BatchStateMachine;
use App\Services\StudentImport\ImportChunkProcessor;
use Database\Factories\StudentFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ImportScenario as S;
use Tests\Support\ImportTestKit as Kit;

/*
 * Realistic-scale synthetic import: 7,966 source rows (the size of the historical reference file),
 * generated here. No real student data. There is NO approved performance target, so nothing below
 * asserts a time limit: timing and memory are only reported (set IMPORT_SCALE_REPORT=1).
 *
 * Expected classification counts come from how the file is CONSTRUCTED, not from the importer's
 * own output.
 */

const SCALE_TOTAL_ROWS = 7966;
const SCALE_MASTER = 8000;       // students already in master data (the importer cannot create new ones)
const SCALE_CLEAN = 7855;        // students 1..7855: valid, UPDATED
const SCALE_COURSE_CHANGES = 60; // of the clean rows
const SCALE_CASE_VARIANTS = 10;  // 7856..7865: "cON" vs the dominant/stored "CON"
const SCALE_BAD_YEAR = 20;       // 7866..7885
const SCALE_NO_ID = 5;
const SCALE_UNKNOWN = 40;        // ids that are not in master data
const SCALE_IDENTICAL_REPEATS = 30; // repeats of students 1..30
const SCALE_CONFLICT_IDS = 3;    // 7886..7888, two conflicting rows each => 6 rows

beforeEach(function () {
    Storage::fake((string) config('comelec.import.disk'));
    config(['comelec.import.chunk_size' => 500]);
});

/** Bulk-inserts the master students (a seed, not the importer). Leading zeros and mixed lengths on purpose. */
function seedMasterStudents(int $count): void
{
    $priorBatch = StudentFactory::importBatchId();
    $domain = studentDomain();
    $now = now();

    foreach (array_chunk(range(1, $count), 1000) as $chunk) {
        DB::table('students')->insert(array_map(fn (int $n) => [
            'institutional_id' => scaleId($n),
            'institutional_email' => 's'.$n.'@'.$domain,
            'first_name' => 'First'.$n,
            'middle_name' => null,
            'last_name' => 'Last'.$n,
            'current_college' => 'CON',
            'current_course' => 'BSN',
            'current_year_level' => '1st Year',
            'status' => 'ACTIVE',
            'google_subject' => $n % 7 === 0 ? 'sub-'.$n : null,
            'last_import_batch_id' => $priorBatch,
            'created_at' => $now,
            'updated_at' => $now,
        ], $chunk));
    }
}

/** Mixed shapes: zero-padded numeric, dashed, and a long one - all strings, never numbers. */
function scaleId(int $n): string
{
    return match ($n % 3) {
        0 => sprintf('%07d', $n),          // leading zeros
        1 => sprintf('2019-%05d', $n),
        default => sprintf('A%d-%d', $n % 97, $n), // variable length
    };
}

/** @return list<array<string, string|int|null>> */
function scaleRows(): array
{
    $rows = [];
    for ($n = 1; $n <= SCALE_CLEAN; $n++) {
        $changed = $n > SCALE_CLEAN - SCALE_COURSE_CHANGES;
        $rows[] = ['id' => scaleId($n), 'last' => 'Last'.$n, 'first' => 'First'.$n, 'course' => $changed ? 'BSIT' : 'BSN', 'year' => (string) (($n % 4) + 1)];
    }
    for ($n = 7856; $n < 7856 + SCALE_CASE_VARIANTS; $n++) {
        $rows[] = ['id' => scaleId($n), 'last' => 'Last'.$n, 'first' => 'First'.$n, 'college' => 'cON', 'year' => '2'];
    }
    for ($n = 7866; $n < 7866 + SCALE_BAD_YEAR; $n++) {
        $rows[] = ['id' => scaleId($n), 'last' => 'Last'.$n, 'first' => 'First'.$n, 'year' => '9'];
    }
    for ($i = 0; $i < SCALE_NO_ID; $i++) {
        $rows[] = ['id' => '', 'last' => 'NoId'.$i, 'first' => 'X', 'year' => '1'];
    }
    for ($i = 1; $i <= SCALE_UNKNOWN; $i++) {
        $rows[] = ['id' => 'UNKNOWN-'.$i, 'last' => 'Unknown'.$i, 'first' => 'X', 'year' => '1'];
    }
    for ($n = 1; $n <= SCALE_IDENTICAL_REPEATS; $n++) { // identical content to the first occurrence
        $rows[] = ['id' => scaleId($n), 'last' => 'Last'.$n, 'first' => 'First'.$n, 'course' => 'BSN', 'year' => (string) (($n % 4) + 1)];
    }
    for ($n = 7886; $n < 7886 + SCALE_CONFLICT_IDS; $n++) {
        $rows[] = ['id' => scaleId($n), 'last' => 'Last'.$n, 'first' => 'First'.$n, 'year' => '1'];
        $rows[] = ['id' => scaleId($n), 'last' => 'Last'.$n, 'first' => 'First'.$n, 'year' => '3']; // same id, different content
    }

    return $rows;
}

function scaleReport(string $label, float $started, int $memBefore): void
{
    if (getenv('IMPORT_SCALE_REPORT')) {
        fwrite(STDERR, sprintf("[scale] %-34s %7.2fs  peak-mem %6.1f MB\n", $label, microtime(true) - $started, memory_get_peak_usage(true) / 1048576));
    }
}

test('a ~7,966-row import classifies exactly as constructed, applies every valid row once, and reconciles', function () {
    seedMasterStudents(SCALE_MASTER);
    $rows = scaleRows();
    expect($rows)->toHaveCount(SCALE_TOTAL_ROWS);

    S::admin(); // the harness provisions the operational admin roster; snapshot AFTER that
    $adminsBefore = S::adminFingerprint();
    $mem = memory_get_usage(true);

    $t = microtime(true);
    $batch = S::preview(Kit::csv($rows));
    scaleReport('stage + validate (7,966 rows)', $t, $mem);

    expect($batch->status)->toBe(BatchStateMachine::PREVIEWED);
    expect(S::service()->classificationCounts((int) $batch->id))->toBe([
        'DUPLICATE_IN_FILE' => SCALE_IDENTICAL_REPEATS + 2 * SCALE_CONFLICT_IDS,
        'INVALID' => SCALE_BAD_YEAR + SCALE_NO_ID,
        'NEEDS_EXCEPTION_REVIEW' => SCALE_CASE_VARIANTS + SCALE_UNKNOWN,
        'UPDATED' => SCALE_CLEAN,
    ]);
    // Validation changed no authoritative data.
    expect(S::enrollmentCount())->toBe(0);
    expect(Student::count())->toBe(SCALE_MASTER);

    S::service()->confirm($batch);
    S::service()->beginProcessing($batch);

    $t = microtime(true);
    $done = S::service()->runProcessing($batch->refresh());
    scaleReport('process 7,855 rows in 500-row chunks', $t, $mem);

    expect($done->status)->toBe(BatchStateMachine::COMPLETED);
    expect([(int) $done->records_received, (int) $done->records_created, (int) $done->records_updated, (int) $done->records_errored])
        ->toBe([SCALE_TOTAL_ROWS, 0, SCALE_CLEAN, SCALE_TOTAL_ROWS - SCALE_CLEAN]);

    // Exactly one enrollment per applied student, none for anything else; no student created.
    expect(S::enrollmentCount((int) $batch->id))->toBe(SCALE_CLEAN);
    expect(DB::table('student_enrollments')->where('import_batch_id', $batch->id)->distinct()->count('student_id'))->toBe(SCALE_CLEAN);
    expect(Student::count())->toBe(SCALE_MASTER);
    expect(DB::table('students')->where('institutional_id', 'like', 'UNKNOWN-%')->count())->toBe(0);
    expect(DB::table('students')->where('last_import_batch_id', $batch->id)->count())->toBe(SCALE_CLEAN);

    // Course-change rows became 1st Year regardless of the year the file reported; the rest took the file's year.
    $changedIds = array_map('scaleId', range(SCALE_CLEAN - SCALE_COURSE_CHANGES + 1, SCALE_CLEAN));
    expect(DB::table('students')->whereIn('institutional_id', $changedIds)->where('current_course', 'BSIT')->where('current_year_level', '1st Year')->count())->toBe(SCALE_COURSE_CHANGES);
    expect(DB::table('students')->where('institutional_id', scaleId(1))->value('current_year_level'))->toBe('2nd Year'); // (1 % 4) + 1 = 2

    // Rows that were not applied left their students untouched.
    foreach ([7856, 7866, 7886] as $n) {
        expect(DB::table('students')->where('institutional_id', scaleId($n))->value('last_import_batch_id'))->not->toBe((int) $batch->id);
    }

    // Identity, email and Google subject are never touched by the importer.
    expect(DB::table('students')->where('institutional_id', scaleId(7))->value('google_subject'))->toBe('sub-7');
    expect(DB::table('students')->where('institutional_id', scaleId(1))->value('institutional_email'))->toBe('s1@'.studentDomain());
    expect(S::adminFingerprint())->toBe($adminsBefore);
});

test('replaying the processor over the finished large batch changes nothing, and a second official batch appends history without duplicating students', function () {
    seedMasterStudents(SCALE_MASTER);
    $csv = Kit::csv(scaleRows());

    $first = S::complete($csv);
    $identityAfterFirst = DB::table('students')->orderBy('id')->get(['id', 'institutional_id', 'institutional_email', 'google_subject'])->all();
    $yearsAfterFirst = DB::table('students')->pluck('current_year_level', 'institutional_id')->all();
    $enrollmentsAfterFirst = S::enrollmentCount();

    // Replay: nothing left to apply.
    expect(app(ImportChunkProcessor::class)->processNextChunk((int) $first->id, 500))->toBe(0);
    expect(S::enrollmentCount())->toBe($enrollmentsAfterFirst);

    // A later official batch with the same content: same students, new history rows.
    $t = microtime(true);
    $second = S::complete($csv);
    scaleReport('second batch end-to-end', $t, 0);

    expect($second->status)->toBe(BatchStateMachine::COMPLETED);
    expect(Student::count())->toBe(SCALE_MASTER);
    expect(S::enrollmentCount())->toBe($enrollmentsAfterFirst + SCALE_CLEAN);
    expect(S::enrollmentCount((int) $first->id))->toBe(SCALE_CLEAN); // the first batch's history was not rewritten
    expect(DB::table('students')->orderBy('id')->get(['id', 'institutional_id', 'institutional_email', 'google_subject'])->all())->toEqual($identityAfterFirst);
    expect(DB::table('students')->where('last_import_batch_id', $second->id)->count())->toBe(SCALE_CLEAN);

    // Same placement the second time: placement is unchanged for everyone EXCEPT the students whose course
    // changed in batch 1. Their course is now unchanged, so the validated incoming year is kept (approved rule),
    // i.e. they move off the 1st Year that the course change had assigned.
    $courseChanged = array_map('scaleId', range(SCALE_CLEAN - SCALE_COURSE_CHANGES + 1, SCALE_CLEAN));
    $yearsAfterSecond = DB::table('students')->pluck('current_year_level', 'institutional_id')->all();
    foreach ($yearsAfterFirst as $institutionalId => $year) {
        if (! in_array($institutionalId, $courseChanged, true)) {
            expect($yearsAfterSecond[$institutionalId])->toBe($year);
        }
    }
    foreach ($courseChanged as $institutionalId) {
        expect($yearsAfterFirst[$institutionalId])->toBe('1st Year');
    }
    $labels = [1 => '1st Year', 2 => '2nd Year', 3 => '3rd Year', 4 => '4th Year'];
    foreach (range(SCALE_CLEAN - SCALE_COURSE_CHANGES + 1, SCALE_CLEAN) as $n) {
        expect($yearsAfterSecond[scaleId($n)])->toBe($labels[($n % 4) + 1]); // the file's year, now that the course is unchanged
    }

    // The (batch, student) uniqueness holds across both batches.
    expect(DB::table('student_enrollments')->select('import_batch_id', 'student_id')->groupBy('import_batch_id', 'student_id')->havingRaw('COUNT(*) > 1')->count())->toBe(0);
});

test('the same 7,966 rows as an .xlsx classify identically to the CSV', function () {
    seedMasterStudents(SCALE_MASTER);
    $rows = scaleRows();

    $csvCounts = S::service()->classificationCounts((int) S::preview(Kit::csv($rows))->id);

    $xlsxRows = [Kit::HEADERS];
    foreach ($rows as $i => $r) {
        $xlsxRows[] = [(string) ($i + 1), (string) $r['id'], $r['last'] ?? 'Dela Cruz', $r['first'] ?? 'Juan', $r['middle'] ?? '', 'F', $r['college'] ?? 'CON', $r['course'] ?? 'BSN', (string) ($r['year'] ?? '1')];
    }
    $path = Kit::tempPath('.xlsx');
    Kit::writeXlsx($path, $xlsxRows);

    try {
        $t = microtime(true);
        $batch = S::preview(file_get_contents($path), 'roster.xlsx');
        scaleReport('xlsx stage + validate', $t, 0);
    } finally {
        @unlink($path);
    }

    expect(S::service()->classificationCounts((int) $batch->id))->toBe($csvCounts);
});
