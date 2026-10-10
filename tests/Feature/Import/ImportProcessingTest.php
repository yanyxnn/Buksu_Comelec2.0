<?php

use App\Models\Student;
use App\Services\Auth\IdentityResolver;
use App\Services\StudentImport\BatchStateMachine;
use App\Services\StudentImport\ImportChunkProcessor;
use App\Services\StudentImport\ImportTransitionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ImportScenario as S;
use Tests\Support\ImportTestKit as Kit;

beforeEach(function () {
    Storage::fake((string) config('comelec.import.disk'));
});

test('an official import updates the placement, appends history and stamps last_import_batch_id', function () {
    $student = S::student('2020-00001', ['current_year_level' => '2nd Year', 'last_import_batch_id' => null]);

    $batch = S::complete(Kit::csv([['id' => '2020-00001', 'last' => 'Santos', 'first' => 'Ana', 'year' => '3']]));

    $fresh = $student->fresh();
    expect($batch->status)->toBe(BatchStateMachine::COMPLETED);
    expect($fresh->current_year_level)->toBe('3rd Year');
    expect($fresh->last_name)->toBe('Santos');
    expect((int) $fresh->last_import_batch_id)->toBe((int) $batch->id);

    $enrollment = DB::table('student_enrollments')->where('student_id', $student->id)->first();
    expect((int) $enrollment->import_batch_id)->toBe((int) $batch->id);
    expect([$enrollment->college, $enrollment->course, $enrollment->year_level, $enrollment->status])->toBe(['CON', 'BSN', '3rd Year', 'ACTIVE']);
});

test('batch counters reconcile: received = updated + created + errored, and match staged rows and enrollments', function () {
    S::student('2020-00001');
    S::student('2020-00002');

    $batch = S::complete(Kit::csv([
        ['id' => '2020-00001'], ['id' => '2020-00002'], ['id' => '2020-00002'], // repeat
        ['id' => '2020-00003'],                                                  // unknown
        ['id' => '2020-00004', 'year' => '8'],                                   // invalid
    ]));

    expect([(int) $batch->records_received, (int) $batch->records_created, (int) $batch->records_updated, (int) $batch->records_errored])->toBe([5, 0, 2, 3]);
    expect((int) $batch->records_received)->toBe((int) $batch->records_created + (int) $batch->records_updated + (int) $batch->records_errored);
    expect(S::enrollmentCount((int) $batch->id))->toBe((int) $batch->records_updated);
    expect(DB::table('import_batch_rows')->where('import_batch_id', $batch->id)->whereNotNull('processed_at')->count())->toBe(2);
    expect($batch->completed_at)->not->toBeNull();
});

test('a course change makes the year level 1st Year whatever the source year says', function () {
    $student = S::student('2020-00001', ['current_course' => 'BSN', 'current_year_level' => '4th Year']);

    S::complete(Kit::csv([['id' => '2020-00001', 'course' => 'BSIT', 'year' => '4']]));

    expect($student->fresh()->current_course)->toBe('BSIT');
    expect($student->fresh()->current_year_level)->toBe('1st Year');
    expect(DB::table('student_enrollments')->where('student_id', $student->id)->value('year_level'))->toBe('1st Year');
});

test('an unchanged course keeps the validated incoming year', function () {
    $student = S::student('2020-00001', ['current_course' => 'BSN', 'current_year_level' => '2nd Year']);

    S::complete(Kit::csv([['id' => '2020-00001', 'course' => 'BSN', 'year' => '3']]));

    expect($student->fresh()->current_year_level)->toBe('3rd Year');
});

test('the importer never changes institutional email, institutional id or an existing Google subject', function () {
    $student = S::student('2020-00001');
    $student->forceFill(['google_subject' => 'google-sub-keep-me'])->save();
    $email = $student->institutional_email;

    S::complete(Kit::csv([['id' => '2020-00001', 'course' => 'BSIT']]));

    $fresh = $student->fresh();
    expect($fresh->institutional_email)->toBe($email);
    expect($fresh->institutional_id)->toBe('2020-00001');
    expect($fresh->google_subject)->toBe('google-sub-keep-me');
});

test('status is preserved when the source has no status and applied when it has one', function () {
    $inactive = S::student('2020-00001', ['status' => 'INACTIVE']);
    $active = S::student('2020-00002', ['status' => 'ACTIVE']);

    S::complete(Kit::csv([['id' => '2020-00001'], ['id' => '2020-00002']]));
    expect($inactive->fresh()->status)->toBe('INACTIVE');
    expect(DB::table('student_enrollments')->where('student_id', $inactive->id)->value('status'))->toBe('INACTIVE');

    $csv = "Code,Last Name,First Name,Colleges,Course,Year,Status\n2020-00002,Cruz,Ana,CON,BSN,2,inactive\n";
    S::complete($csv);
    expect($active->fresh()->status)->toBe('INACTIVE');
});

test('re-importing the same id in a later official batch appends history and never duplicates the student', function () {
    $student = S::student('2020-00001', ['current_year_level' => '1st Year']);

    $first = S::complete(Kit::csv([['id' => '2020-00001', 'year' => '2']]));
    $second = S::complete(Kit::csv([['id' => '2020-00001', 'year' => '3']]));

    expect(Student::query()->where('institutional_id', '2020-00001')->count())->toBe(1);
    expect(S::enrollmentCount((int) $first->id))->toBe(1);
    expect(S::enrollmentCount((int) $second->id))->toBe(1);
    expect(DB::table('student_enrollments')->where('student_id', $student->id)->orderBy('id')->pluck('year_level')->all())->toBe(['2nd Year', '3rd Year']);
    expect((int) $student->fresh()->last_import_batch_id)->toBe((int) $second->id);
    expect($student->fresh()->current_year_level)->toBe('3rd Year');
});

test('a student missing from a later roster keeps all data and is not graduated or deactivated', function () {
    $present = S::student('2020-00001');
    $absent = S::student('2020-00002');
    $first = S::complete(Kit::csv([['id' => '2020-00001'], ['id' => '2020-00002']]));
    $snapshot = $absent->fresh()->toArray();

    S::complete(Kit::csv([['id' => '2020-00001', 'year' => '4']]));

    expect($absent->fresh()->toArray())->toBe($snapshot);
    expect($absent->fresh()->status)->toBe('ACTIVE');
    expect((int) $absent->fresh()->last_import_batch_id)->toBe((int) $first->id);
    expect(DB::table('student_enrollments')->where('student_id', $absent->id)->count())->toBe(1);
});

test('numeric batch ids are never proof of chronology: the batch processed last owns the placement, whatever its id', function () {
    $student = S::student('2020-00001');

    $lowId = S::preview(Kit::csv([['id' => '2020-00001', 'year' => '2']]));
    $highId = S::preview(Kit::csv([['id' => '2020-00001', 'year' => '4']]));
    expect((int) $highId->id)->toBeGreaterThan((int) $lowId->id);

    // Higher id first, lower id second. If the id were read as "newer", the lower id would be ignored.
    foreach ([$highId, $lowId] as $batch) {
        S::service()->confirm($batch);
        S::service()->beginProcessing($batch);
        S::service()->runProcessing($batch);
    }

    expect($student->fresh()->current_year_level)->toBe('2nd Year');
    expect((int) $student->fresh()->last_import_batch_id)->toBe((int) $lowId->id);
    // Append-only history: both batches keep their own row with their own reported placement.
    expect(DB::table('student_enrollments')->where('import_batch_id', $highId->id)->value('year_level'))->toBe('4th Year');
    expect(DB::table('student_enrollments')->where('import_batch_id', $lowId->id)->value('year_level'))->toBe('2nd Year');
    expect(DB::table('student_enrollments')->where('student_id', $student->id)->count())->toBe(2);
});

test('the outcome follows processing order for both id orders, and a replay changes nothing', function () {
    foreach ([['low', 'high'], ['high', 'low']] as $n => [$firstProcessed, $lastProcessed]) {
        $student = S::student('2020-0010'.$n);
        $batches = [
            'low' => S::preview(Kit::csv([['id' => '2020-0010'.$n, 'year' => '1']])),
            'high' => S::preview(Kit::csv([['id' => '2020-0010'.$n, 'year' => '3']])),
        ];
        expect((int) $batches['high']->id)->toBeGreaterThan((int) $batches['low']->id);

        foreach ([$firstProcessed, $lastProcessed] as $which) {
            S::service()->confirm($batches[$which]);
            S::service()->beginProcessing($batches[$which]);
            S::service()->runProcessing($batches[$which]);
        }

        $expectedYear = $lastProcessed === 'high' ? '3rd Year' : '1st Year';
        expect($student->fresh()->current_year_level)->toBe($expectedYear);
        expect((int) $student->fresh()->last_import_batch_id)->toBe((int) $batches[$lastProcessed]->id);

        // Replaying the batch that is NOT current must not take the placement back.
        $before = DB::table('student_enrollments')->count();
        app(ImportChunkProcessor::class)->processNextChunk((int) $batches[$firstProcessed]->id, 500);
        expect(DB::table('student_enrollments')->count())->toBe($before);
        expect($student->fresh()->current_year_level)->toBe($expectedYear);
    }
});

test('the importer source never compares batch ids to decide precedence', function () {
    $source = file_get_contents(base_path('app/Services/StudentImport/ImportChunkProcessor.php'));
    $code = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $source); // comments may discuss the rule

    expect($code)->not->toMatch('/last_import_batch_id\s*(>|<|>=|<=)/')
        ->and($code)->not->toMatch('/\$batch->id\s*(>|<|>=|<=)/')
        ->and($code)->not->toMatch('/(>|<|>=|<=)\s*\(int\)\s*\$batch->id/');
});

test('duplicate source rows never produce duplicate enrollments, and a conflicting duplicate applies nothing', function () {
    $same = S::student('2020-00001');
    $conflict = S::student('2020-00002', ['current_year_level' => '1st Year']);

    $batch = S::complete(Kit::csv([
        ['id' => '2020-00001'], ['id' => '2020-00001'],
        ['id' => '2020-00002', 'year' => '2'], ['id' => '2020-00002', 'year' => '3'],
    ]));

    expect(DB::table('student_enrollments')->where('student_id', $same->id)->count())->toBe(1);
    expect(DB::table('student_enrollments')->where('student_id', $conflict->id)->count())->toBe(0);
    expect($conflict->fresh()->current_year_level)->toBe('1st Year');
    expect((int) $batch->records_updated)->toBe(1);
});

test('replaying an already-applied chunk changes nothing, even after a course change', function () {
    $student = S::student('2020-00001', ['current_course' => 'BSN', 'current_year_level' => '4th Year']);
    $batch = S::complete(Kit::csv([['id' => '2020-00001', 'course' => 'BSIT', 'year' => '4']]));
    $after = $student->fresh()->toArray();

    // Simulate "the processed marker was lost but the data committed": the row looks unprocessed again.
    DB::table('import_batch_rows')->where('import_batch_id', $batch->id)->update(['processed_at' => null]);
    // ...mid-processing: chunks are only applied while the batch is PROCESSING (a COMPLETED batch applies nothing).
    DB::table('import_batches')->where('id', $batch->id)->update(['status' => BatchStateMachine::PROCESSING, 'completed_at' => null]);
    $handled = app(ImportChunkProcessor::class)->processNextChunk((int) $batch->id, 500);

    expect($handled)->toBe(1);
    expect($student->fresh()->toArray())->toBe($after);             // still 1st Year in BSIT, not the source's 4th
    expect(S::enrollmentCount((int) $batch->id))->toBe(1);          // no duplicate history row
    expect((int) DB::table('import_batches')->where('id', $batch->id)->value('records_updated'))->toBe(1);
});

test('a finished batch cannot be processed again', function () {
    S::student('2020-00001');
    $batch = S::complete(Kit::csv([['id' => '2020-00001']]));

    expect(fn () => S::service()->beginProcessing($batch))->toThrow(ImportTransitionException::class);
    expect(fn () => app(ImportChunkProcessor::class)->processAll($batch))->toThrow(ImportTransitionException::class);
    expect(S::enrollmentCount((int) $batch->id))->toBe(1);
});

test('status can only move along the approved transitions', function () {
    S::student('2020-00001');
    $svc = S::service();
    $batch = S::stage(Kit::csv([['id' => '2020-00001']]));

    expect(fn () => $svc->confirm($batch))->toThrow(ImportTransitionException::class);           // STAGED -> CONFIRMED
    expect(fn () => $svc->beginProcessing($batch))->toThrow(ImportTransitionException::class);   // STAGED -> PROCESSING
    expect(fn () => $svc->complete($batch))->toThrow(ImportTransitionException::class);          // STAGED -> COMPLETED

    $svc->validate($batch);
    expect(fn () => $svc->beginProcessing($batch))->toThrow(ImportTransitionException::class);   // PREVIEWED -> PROCESSING

    $svc->confirm($batch);
    expect(fn () => $svc->confirm($batch))->toThrow(ImportTransitionException::class);           // CONFIRMED -> CONFIRMED
    expect(fn () => $svc->validate($batch))->toThrow(ImportTransitionException::class);          // CONFIRMED -> VALIDATING
    expect($batch->refresh()->status)->toBe(BatchStateMachine::CONFIRMED);
});

test('nothing outside a confirmed batch is ever applied', function () {
    $student = S::student('2020-00001', ['current_year_level' => '1st Year']);
    $batch = S::preview(Kit::csv([['id' => '2020-00001', 'year' => '4']]));

    expect($student->fresh()->current_year_level)->toBe('1st Year');
    expect(S::enrollmentCount())->toBe(0);
    expect(fn () => app(ImportChunkProcessor::class)->processAll($batch))->toThrow(ImportTransitionException::class);
});

test('the batch status and counters cannot be mass-assigned', function () {
    $batch = S::stage(Kit::csv([['id' => '1']]));
    $batch->fill(['status' => 'COMPLETED', 'records_updated' => 99])->save();

    expect($batch->fresh()->status)->toBe(BatchStateMachine::STAGED);
    expect((int) $batch->fresh()->records_updated)->toBe(0);
});

test('an import never creates, changes or removes an administrator record', function () {
    makeAdminRoster(linked: true);
    $before = S::adminFingerprint();
    $adminEmail = (string) DB::table('admin_users')->value('authorized_email');

    S::student('2020-00001');
    // the source even lists a person with an administrator's address as a would-be student
    S::complete(Kit::csv([['id' => '2020-00001'], ['id' => $adminEmail, 'first' => 'Admin']]));

    expect(S::adminFingerprint())->toBe($before);
    expect(DB::table('admin_users')->count())->toBe((int) config('comelec.admin_roster_size'));
});

test('Phase 02 compatibility: an imported student can first-link, and an imported INACTIVE student is not locked out by status', function () {
    $unimported = S::student('2020-00001', ['last_import_batch_id' => null]);
    $inactive = S::student('2020-00002', ['status' => 'INACTIVE', 'last_import_batch_id' => null]);
    $resolver = app(IdentityResolver::class);

    // before the import neither may sign in (not Data Center-loaded)
    expect($resolver->resolve(googleIdentity('sub-a', $unimported->institutional_email))->isDenied())->toBeTrue();

    S::complete(Kit::csv([['id' => '2020-00001'], ['id' => '2020-00002']]));

    $decision = $resolver->resolve(googleIdentity('sub-a', $unimported->institutional_email));
    expect($decision->isDenied())->toBeFalse();
    expect($decision->student->institutional_id)->toBe('2020-00001');
    expect($decision->firstLink)->toBeTrue();

    expect($resolver->resolve(googleIdentity('sub-b', $inactive->institutional_email))->isDenied())->toBeFalse();
    expect($inactive->fresh()->status)->toBe('INACTIVE'); // status is a record attribute, not a credential
});

test('audit events carry counters only: no student data, filenames, emails or identifiers', function () {
    $student = S::student('2020-00001');
    S::complete(Kit::csv([['id' => '2020-00001', 'last' => 'Uniquesurname']]), 'secret-roster-name.csv');

    $events = DB::table('audit_logs')->where('event_type', 'like', 'import.batch.%')->get();
    expect($events->pluck('event_type')->all())->toContain('import.batch.staged', 'import.batch.validated', 'import.batch.confirmed', 'import.batch.processing_started', 'import.batch.completed');

    $blob = $events->pluck('metadata_json')->implode(' ').' '.$events->pluck('description')->implode(' ').' '.$events->pluck('target_id')->implode(' ');
    foreach (['2020-00001', 'Uniquesurname', 'secret-roster-name', $student->institutional_email, 'google'] as $needle) {
        expect(stripos($blob, $needle))->toBeFalse("audit must not contain {$needle}");
    }
});

test('no public route exposes import staging data', function () {
    $uris = collect(app('router')->getRoutes()->getRoutes())->map(fn ($r) => $r->uri())->implode(' ');

    expect(stripos($uris, 'import'))->toBeFalse();
});
