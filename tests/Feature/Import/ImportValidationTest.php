<?php

use App\Models\Student;
use App\Services\StudentImport\BatchStateMachine;
use App\Services\StudentImport\ImportIssue;
use App\Services\StudentImport\SourceFileException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ImportScenario as S;
use Tests\Support\ImportTestKit as Kit;

beforeEach(function () {
    Storage::fake((string) config('comelec.import.disk'));
});

test('staging records the batch, checksum and file, and changes no authoritative data', function () {
    S::student('2020-00001');
    $before = [DB::table('students')->count(), S::enrollmentCount()];

    $batch = S::stage(Kit::csv([['id' => '2020-00001']]));

    expect($batch->status)->toBe(BatchStateMachine::STAGED);
    expect($batch->checksum)->toHaveLength(64);
    expect((int) $batch->records_received)->toBe(0);
    expect([DB::table('students')->count(), S::enrollmentCount()])->toBe($before);
    expect(DB::table('import_batch_rows')->count())->toBe(0);
    expect(DB::table('audit_logs')->where('event_type', 'import.batch.staged')->count())->toBe(1);
});

test('unsupported file types and oversize files are refused before anything is stored', function () {
    expect(fn () => S::stage('x', 'roster.xls'))->toThrow(SourceFileException::class);

    config(['comelec.import.max_file_bytes' => 10]);
    expect(fn () => S::stage(Kit::csv([['id' => '1']])))->toThrow(SourceFileException::class);

    expect(DB::table('import_batches')->where('source_filename', 'like', 'roster%')->count())->toBe(0);
});

test('validation classifies the reference-shaped file and leaves master data untouched', function () {
    S::student('2020-00001');                       // existing, unchanged placement
    S::student('2020-00002');                       // existing, will change course
    $before = DB::table('students')->get()->toJson();

    $batch = S::preview(Kit::csv([
        ['id' => '2020-00001', 'year' => '2'],
        ['id' => '2020-00002', 'course' => 'BSIT', 'year' => '4'],
        ['id' => '2020-09999'],                       // unknown student
        ['id' => '2020-00003', 'year' => '9'],        // invalid year (also unknown) -> invalid wins
        ['id' => ''],                                 // missing identity
    ]));

    expect($batch->status)->toBe(BatchStateMachine::PREVIEWED);
    expect((int) $batch->records_received)->toBe(5);
    expect((int) $batch->records_errored)->toBe(3);

    $rows = S::rows($batch);
    expect($rows->pluck('classification')->all())->toBe(['UPDATED', 'UPDATED', 'NEEDS_EXCEPTION_REVIEW', 'INVALID', 'INVALID']);
    expect($rows->pluck('source_row_number')->all())->toBe([2, 3, 4, 5, 6]);
    expect(S::issueCodes($rows[2]))->toBe([ImportIssue::NEW_STUDENT_REQUIRES_INSTITUTIONAL_EMAIL]);
    expect(S::issueCodes($rows[3]))->toContain(ImportIssue::YEAR_INVALID);
    expect(S::issueCodes($rows[4]))->toContain(ImportIssue::ID_MISSING);

    expect(DB::table('students')->get()->toJson())->toBe($before);
    expect(S::enrollmentCount())->toBe(0); // staging never writes enrollments
    expect(DB::table('students')->whereNotNull('google_subject')->count())->toBe(0);
});

test('the raw source row is preserved in staging, including columns the domain ignores', function () {
    S::student('2020-00001');
    $batch = S::preview(Kit::csv([['id' => '2020-00001']]));

    $raw = json_decode(S::rows($batch)[0]->raw_row_json, true);

    expect($raw['Code'])->toBe('2020-00001');
    expect($raw)->toHaveKey('Sex');
    expect($raw)->toHaveKey('No.');
    expect(json_decode(S::rows($batch)[0]->normalized_json, true)['values'])->not->toHaveKey('sex');
    expect(Schema::hasColumn('students', 'sex'))->toBeFalse();
});

test('institutional ids are matched as exact strings: leading zeros and length matter', function () {
    $zero = S::student('00123');
    S::student('123');

    $batch = S::preview(Kit::csv([['id' => '00123'], ['id' => '0123']]));
    $rows = S::rows($batch);

    expect($rows[0]->classification)->toBe('UPDATED');
    expect((int) $rows[0]->resolved_student_id)->toBe($zero->id);
    expect($rows[0]->institutional_id)->toBe('00123');
    expect($rows[1]->classification)->toBe('NEEDS_EXCEPTION_REVIEW'); // "0123" is a different identity
    expect($rows[1]->resolved_student_id)->toBeNull();
});

test('a row without any identity is INVALID and the source No. never identifies a student', function () {
    S::student('1');
    $batch = S::preview(Kit::csv([['id' => '']])); // the No. column is 1 for this row

    expect(S::rows($batch)[0]->classification)->toBe('INVALID');
    expect(S::rows($batch)[0]->resolved_student_id)->toBeNull();
});

test('identical duplicate ids keep the first row and skip the repeats', function () {
    S::student('2020-00001');
    $batch = S::preview(Kit::csv([['id' => '2020-00001'], ['id' => '2020-00001'], ['id' => '2020-00001']]));

    expect(S::rows($batch)->pluck('classification')->all())->toBe(['UPDATED', 'DUPLICATE_IN_FILE', 'DUPLICATE_IN_FILE']);
    expect(S::issueCodes(S::rows($batch)[1]))->toBe([ImportIssue::DUPLICATE_ID_REPEAT]);
});

test('conflicting duplicate ids apply NO occurrence', function () {
    S::student('2020-00001');
    $batch = S::preview(Kit::csv([['id' => '2020-00001', 'year' => '2'], ['id' => '2020-00001', 'year' => '3']]));

    expect(S::rows($batch)->pluck('classification')->all())->toBe(['DUPLICATE_IN_FILE', 'DUPLICATE_IN_FILE']);
    expect(S::issueCodes(S::rows($batch)[0]))->toBe([ImportIssue::DUPLICATE_ID_CONFLICT]);
    expect((int) $batch->records_errored)->toBe(2);
});

test('duplicate ids that differ only by case are one identity', function () {
    S::student('AB-001');
    $batch = S::preview(Kit::csv([['id' => 'AB-001'], ['id' => 'ab-001']]));

    expect(S::rows($batch)->pluck('classification')->all())->toContain('DUPLICATE_IN_FILE');
});

test('a case-only college inconsistency goes to review instead of being merged silently', function () {
    foreach (range(1, 6) as $i) {
        S::student("2020-0000$i");
    }

    $batch = S::preview(Kit::csv(array_merge(
        array_map(fn ($i) => ['id' => "2020-0000$i", 'college' => 'CON'], range(1, 5)),
        [['id' => '2020-00006', 'college' => 'cON']],
    )));

    $rows = S::rows($batch);
    expect($rows->take(5)->pluck('classification')->unique()->all())->toBe(['UPDATED']);
    expect($rows[5]->classification)->toBe('NEEDS_EXCEPTION_REVIEW');
    expect(S::issueCodes($rows[5]))->toBe([ImportIssue::COLLEGE_CASE_VARIANT]);
    expect(json_decode($rows[5]->normalized_json, true)['values']['college'])->toBe('cON'); // source value untouched
});

test('a case difference against the stored master value is also reviewed', function () {
    S::student('2020-00001', ['current_college' => 'CON']);
    $batch = S::preview(Kit::csv([['id' => '2020-00001', 'college' => 'con']]));

    expect(S::rows($batch)[0]->classification)->toBe('NEEDS_EXCEPTION_REVIEW');
});

test('year levels map deterministically and unknown values are INVALID', function () {
    foreach (['1', '2', '3', '4'] as $n) {
        S::student("2020-0000$n");
    }

    $batch = S::preview(Kit::csv([
        ['id' => '2020-00001', 'year' => '1'], ['id' => '2020-00002', 'year' => '2nd Year'],
        ['id' => '2020-00003', 'year' => '3.0'], ['id' => '2020-00004', 'year' => '5'],
    ]));

    $values = S::rows($batch)->map(fn ($r) => json_decode($r->normalized_json, true)['values']['year_level'])->all();
    expect($values)->toBe(['1st Year', '2nd Year', '3rd Year', null]);
    expect(S::rows($batch)[3]->classification)->toBe('INVALID');
});

test('an unknown student is never created or given a fabricated email, and is never NEW', function () {
    $batch = S::complete(Kit::csv([['id' => '2020-77777']]));

    $row = S::rows($batch)[0];
    expect($row->classification)->toBe('NEEDS_EXCEPTION_REVIEW');
    expect(Student::query()->where('institutional_id', '2020-77777')->exists())->toBeFalse();
    expect(DB::table('students')->count())->toBe(0);
    expect(DB::table('import_batch_rows')->where('classification', 'NEW')->count())->toBe(0);
    expect($batch->status)->toBe(BatchStateMachine::COMPLETED);
    expect((int) $batch->records_updated)->toBe(0);
    expect((int) $batch->records_errored)->toBe(1);
});

test('re-validating a previewed batch rebuilds the staging rows without duplicates', function () {
    S::student('2020-00001');
    $batch = S::preview(Kit::csv([['id' => '2020-00001'], ['id' => '2020-00002']]));

    S::service()->validate($batch);

    expect(S::rows($batch))->toHaveCount(2);
    expect($batch->refresh()->status)->toBe(BatchStateMachine::PREVIEWED);
    expect((int) $batch->records_received)->toBe(2);
});

test('an xlsx upload is staged and classified exactly like the same csv', function () {
    S::student('00123');
    $path = Kit::tempPath('.xlsx');
    Kit::writeXlsx($path, [Kit::HEADERS, [1, '00123', 'Cruz', 'Ana', null, 'F', 'CON', 'BSN', 2]]);
    $batch = S::preview(file_get_contents($path), 'roster.xlsx');
    unlink($path);

    expect($batch->status)->toBe(BatchStateMachine::PREVIEWED);
    expect(S::rows($batch)[0]->classification)->toBe('UPDATED');
    expect(S::rows($batch)[0]->institutional_id)->toBe('00123');
});

test('file-level problems fail the batch with a coarse reason and stage nothing', function (string $contents, string $reason) {
    $batch = S::preview($contents);

    expect($batch->status)->toBe(BatchStateMachine::FAILED);
    expect(DB::table('import_batch_rows')->where('import_batch_id', $batch->id)->count())->toBe(0);
    $event = DB::table('audit_logs')->where('event_type', 'import.batch.failed')->latest('id')->first();
    expect(json_decode($event->metadata_json, true)['failure_code'])->toBe($reason);
})->with([
    'missing required column' => ["Code,Last Name,First Name\n1,Cruz,Ana\n", SourceFileException::MISSING_REQUIRED_COLUMNS],
    'duplicate column' => ["Code,Code,Last Name,First Name,Colleges,Course,Year\n", SourceFileException::DUPLICATE_COLUMNS],
    'header only' => ["Code,Last Name,First Name,Colleges,Course,Year\n", SourceFileException::EMPTY_FILE],
    'not utf-8' => ["Code,Last Name,First Name,Colleges,Course,Year\n1,Mu\xF1oz,Ana,CON,BSN,1\n", SourceFileException::INVALID_ENCODING],
]);

test('a file that failed before confirmation can be re-validated after the file problem is gone', function () {
    $batch = S::preview("Code,Last Name\n");
    expect($batch->status)->toBe(BatchStateMachine::FAILED);

    // FAILED before confirmation may legally re-enter validation (it fails again: same bytes)
    $again = S::service()->validate($batch);
    expect($again->status)->toBe(BatchStateMachine::FAILED);
});
