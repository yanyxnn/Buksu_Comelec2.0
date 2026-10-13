<?php

use App\Jobs\ProcessImportBatch;
use App\Jobs\ValidateImportBatch;
use App\Models\AdminUser;
use App\Models\ImportBatch;
use App\Models\Student;
use App\Services\StudentImport\BatchStateMachine as State;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use Tests\Support\ImportScenario as S;
use Tests\Support\ImportTestKit as Kit;

/*
 * Phase 03B: the Student Import (Data Center import) UI. Everything is synthetic data.
 * The backend (stage/validate/confirm/process) is covered by the Phase 03A tests; here the UI is checked
 * for what it calls, what it shows, what it hides and what it refuses.
 */

beforeEach(function () {
    Storage::fake((string) config('comelec.import.disk'));
    $this->admin = S::admin();
});

function ui(?AdminUser $admin = null)
{
    test()->actingAs($admin ?? test()->admin, 'admin');

    return Volt::test('admin.data-center');
}

function upload(string $name = 'roster.csv', ?string $content = null): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, $content ?? Kit::csv([['id' => '2020-00001']]));
}

/** A batch row written the way the service does (tests may seed status directly; the app never does). */
function batchAt(string $status, bool $confirmed = false, array $counters = []): ImportBatch
{
    $id = DB::table('import_batches')->insertGetId([
        'source_filename' => 'seed.csv', 'academic_year' => '2026-2027', 'semester' => '1st',
        'uploaded_by' => test()->admin->id, 'status' => $status, 'checksum' => str_repeat('a', 64),
        'confirmed_at' => $confirmed ? now() : null, 'created_at' => now(), 'updated_at' => now(),
    ] + $counters);

    return ImportBatch::query()->findOrFail($id);
}

function stageRow(int $batchId, int $n, string $classification, ?array $values = null, ?array $issues = null, bool $processed = false): void
{
    DB::table('import_batch_rows')->insert([
        'import_batch_id' => $batchId, 'source_row_number' => $n, 'institutional_id' => '2020-'.str_pad((string) $n, 5, '0', STR_PAD_LEFT),
        'classification' => $classification, 'raw_row_json' => json_encode(['secret' => 'RAW-ONLY-MARKER']),
        'normalized_json' => json_encode(['values' => $values ?? ['last_name' => 'Santos', 'first_name' => 'Ana', 'middle_name' => null, 'college' => 'CON', 'course' => 'BSN', 'year_level' => '2nd Year'], 'preview' => null]),
        'issues_json' => $issues === null ? null : json_encode($issues),
        'processed_at' => $processed ? now() : null, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

function status(ImportBatch $batch): string
{
    return (string) DB::table('import_batches')->where('id', $batch->id)->value('status');
}

// ---------------------------------------------------------------------------------------------- access

test('the page is admin-only and registered inside the admin middleware group', function () {
    $this->get(route('admin.data-center'))->assertRedirect(route('login'));

    $this->actingAs(Student::factory()->linked()->create(), 'student')->get(route('admin.data-center'))->assertForbidden();

    expect(Route::getRoutes()->getByName('admin.data-center')->gatherMiddleware())->toContain('admin');
});

test('an admin sees the page and the sidebar entry is a real, active link', function () {
    $html = $this->actingAs($this->admin, 'admin')->get(route('admin.data-center'))->assertOk()->getContent();

    expect($html)->toContain('Student Import')->not->toContain('Student Master / Data Center')->toContain('href="'.route('admin.data-center').'"');
    expect(preg_match('/href="'.preg_quote(route('admin.data-center'), '/').'"[^>]*aria-current="page"/s', $html))->toBe(1);
});

test('a student cannot drive the component through the Livewire endpoint', function () {
    $html = $this->actingAs($this->admin, 'admin')->get(route('admin.data-center'))->assertOk()->getContent();
    preg_match('/wire:snapshot="([^"]+)"/', $html, $m);
    $snapshot = html_entity_decode($m[1], ENT_QUOTES);

    Auth::forgetGuards();
    $this->actingAs(Student::factory()->linked()->create(), 'student');

    $this->postJson(route('default-livewire.update'), [
        'components' => [['snapshot' => $snapshot, 'updates' => (object) [], 'calls' => []]],
    ], ['X-Livewire' => 'true'])->assertForbidden();
});

test('component actions refuse a non-admin even when middleware is bypassed', function () {
    $batch = batchAt(State::PREVIEWED);
    $this->actingAs(Student::factory()->linked()->create(), 'student');

    Volt::test('admin.data-center')->call('confirm', $batch->id)->assertForbidden();

    expect(status($batch))->toBe(State::PREVIEWED);
});

// ---------------------------------------------------------------------------------------------- staging

test('a valid CSV is staged with the authenticated admin as uploader and validation is queued', function () {
    Queue::fake();

    ui()->set('file', upload('Roster 2026.csv'))->set('academicYear', '  2026-2027 ')->set('semester', '1st')
        ->call('stageFile')->assertHasNoErrors();

    $batch = ImportBatch::query()->firstOrFail();
    expect($batch->status)->toBe(State::STAGED)
        ->and($batch->uploaded_by)->toBe($this->admin->id)
        ->and($batch->source_filename)->toBe('Roster 2026.csv')
        ->and($batch->academic_year)->toBe('2026-2027')
        ->and($batch->semester)->toBe('1st')
        ->and($batch->checksum)->not->toBeNull();

    Storage::disk((string) config('comelec.import.disk'))->assertExists('student-imports/'.$batch->id.'.csv');
    Queue::assertPushed(ValidateImportBatch::class, fn ($job) => $job->batchId === $batch->id && $job->adminId === $this->admin->id);
    Queue::assertNotPushed(ProcessImportBatch::class);
});

test('the uploader can only come from the admin guard: there is no client-controlled uploader field', function () {
    $component = ui();

    foreach (['uploadedBy', 'uploaded_by', 'adminId', 'admin_id'] as $property) {
        expect(fn () => $component->set($property, 999))->toThrow(Exception::class);
    }
});

test('a second admin uploading is recorded as that admin', function () {
    Queue::fake();
    $other = makeAdminRoster()->last();

    ui($other)->set('file', upload())->set('academicYear', 'AY')->set('semester', 'S')->call('stageFile');

    expect(ImportBatch::query()->firstOrFail()->uploaded_by)->toBe($other->id);
});

test('unsupported, oversized and incomplete uploads are rejected before anything is staged', function () {
    Queue::fake();

    ui()->set('file', upload('roster.txt'))->set('academicYear', 'AY')->set('semester', 'S')->call('stageFile')->assertHasErrors('file');
    ui()->set('file', upload('roster.xls'))->set('academicYear', 'AY')->set('semester', 'S')->call('stageFile')->assertHasErrors('file');

    config(['comelec.import.max_file_bytes' => 1024]);
    ui()->set('file', upload('big.csv', str_repeat('x', 4096)))->set('academicYear', 'AY')->set('semester', 'S')->call('stageFile')->assertHasErrors('file');
    config(['comelec.import.max_file_bytes' => 20 * 1024 * 1024]);

    ui()->set('file', upload())->set('academicYear', '   ')->set('semester', '')->call('stageFile')->assertHasErrors(['academicYear', 'semester']);
    ui()->set('academicYear', 'AY')->set('semester', 'S')->call('stageFile')->assertHasErrors('file');

    expect(ImportBatch::count())->toBe(0);
    Queue::assertNothingPushed();
});

test('academic year and semester are free text: no allowed-value list is imposed', function () {
    Queue::fake();

    ui()->set('file', upload())->set('academicYear', 'whatever it is')->set('semester', 'Summer?')->call('stageFile')->assertHasNoErrors();

    expect(ImportBatch::query()->value('academic_year'))->toBe('whatever it is');
});

test('a queue that cannot accept the job leaves a STAGED batch with a clear, safe message', function () {
    Queue::shouldReceive('push')->andThrow(new RuntimeException('connection refused: secret-host'));
    Queue::shouldReceive('connection')->andReturnSelf();

    $html = ui()->set('file', upload())->set('academicYear', 'AY')->set('semester', 'S')->call('stageFile')->html();

    expect(ImportBatch::count())->toBe(1)->and(ImportBatch::query()->value('status'))->toBe(State::STAGED);
    expect($html)->toContain('validation could not be queued')->not->toContain('secret-host');
});

// ---------------------------------------------------------------------------------------------- listing and preview

test('existing batches are listed with status, term, uploader and recorded counters', function () {
    $done = batchAt(State::COMPLETED, true, ['records_received' => 12, 'records_updated' => 9, 'records_errored' => 3]);

    $html = ui()->html();

    expect($html)->toContain('#'.$done->id.' · seed.csv')->toContain('2026-2027 · 1st')->toContain('Completed')
        ->toContain($this->admin->display_name)->toContain('>12<')->toContain('>9<')->toContain('>3<');
});

test('the empty state is shown when there are no imports', function () {
    expect(ui()->html())->toContain('No imports yet');
});

test('the batch list is paginated server-side', function () {
    foreach (range(1, 12) as $i) {
        batchAt(State::COMPLETED, true);
    }

    $component = ui();
    expect(substr_count($component->html(), 'wire:key="batch-'))->toBe(10)->and($component->html())->toContain('Page 1 of 2');

    $component->call('nextPage', 'batches');
    expect(substr_count($component->html(), 'wire:key="batch-'))->toBe(2);
});

test('the preview shows normalized fields, classification totals and safe issue codes, and never the raw source row', function () {
    S::student('2020-00001');
    $csv = Kit::csv([
        ['id' => '2020-00001', 'last' => 'Santos', 'first' => 'Ana'],            // UPDATED
        ['id' => '2020-00009', 'last' => 'Nobody', 'first' => 'Known'],          // unknown student
        ['id' => '2020-00003', 'year' => '9'],                                   // INVALID year
        ['id' => '2020-00004', 'last' => 'A'], ['id' => '2020-00004', 'last' => 'B'], // conflicting duplicates
    ]);
    $csv = str_replace(',F,', ',RAW-ONLY-SEX-MARKER,', $csv); // the ignored "Sex" column survives only in raw_row_json
    $batch = S::preview($csv);

    expect(DB::table('import_batch_rows')->where('import_batch_id', $batch->id)->value('raw_row_json'))->toContain('RAW-ONLY-SEX-MARKER');

    $component = ui()->call('selectBatch', $batch->id);
    $html = $component->html();

    expect($html)
        ->toContain('Santos, Ana')                                       // normalized name
        ->toContain('NEW_STUDENT_REQUIRES_INSTITUTIONAL_EMAIL')
        ->toContain('YEAR_INVALID')
        ->toContain('DUPLICATE_ID_CONFLICT')
        ->toContain('Needs exception review')->toContain('Duplicate in file')->toContain('Invalid')->toContain('Update existing student')
        ->not->toContain('RAW-ONLY')->not->toContain('raw_row_json');

    // classification totals match the backend's counts exactly
    foreach (S::service()->classificationCounts($batch->id) as $n) {
        expect($html)->toContain('>'.$n.'<');
    }
    expect(S::service()->classificationCounts($batch->id))->toBe(['DUPLICATE_IN_FILE' => 2, 'INVALID' => 1, 'NEEDS_EXCEPTION_REVIEW' => 1, 'UPDATED' => 1]);
});

test('preview rows are paginated and filtered server-side', function () {
    $batch = batchAt(State::PREVIEWED);
    foreach (range(1, 60) as $n) {
        stageRow($batch->id, $n, $n % 10 === 0 ? 'INVALID' : 'NEEDS_EXCEPTION_REVIEW');
    }

    $component = ui()->call('selectBatch', $batch->id);
    expect(substr_count($component->html(), 'wire:key="row-'))->toBe(25)->and($component->html())->toContain('Rows 1–25 of 60');

    $component->call('nextPage', 'preview');
    expect($component->html())->toContain('Rows 26–50 of 60');

    $component->set('filter', 'INVALID');
    expect(substr_count($component->html(), 'wire:key="row-'))->toBe(6)->and($component->html())->toContain('Rows 1–6 of 6');

    $component->set('filter', 'not-a-classification');
    expect($component->get('filter'))->toBe('');
});

test('the selected batch can be deep-linked and an unknown id is handled', function () {
    $batch = batchAt(State::COMPLETED, true);
    $this->actingAs($this->admin, 'admin');

    $this->get(route('admin.data-center', ['batch' => $batch->id]))->assertOk()->assertSee('Import #'.$batch->id.' ·', false);
    $this->get(route('admin.data-center', ['batch' => 987654]))->assertOk()->assertSee('That import does not exist');
    expect(ui()->call('selectBatch', 987654)->get('batchId'))->toBeNull();
});

// ---------------------------------------------------------------------------------------------- confirmation

test('confirming a previewed batch confirms it and queues processing through the service', function () {
    Queue::fake();
    S::student('2020-00001');
    $batch = S::preview(Kit::csv([['id' => '2020-00001']]));

    ui()->call('selectBatch', $batch->id)->call('confirm', $batch->id)->assertHasNoErrors();

    $fresh = $batch->fresh();
    expect($fresh->status)->toBe(State::PROCESSING)->and($fresh->confirmed_at)->not->toBeNull()->and($fresh->started_at)->not->toBeNull();
    Queue::assertPushed(ProcessImportBatch::class, 1);
    Queue::assertPushed(ProcessImportBatch::class, fn ($job) => $job->batchId === $batch->id);
    expect(DB::table('audit_logs')->where('event_type', 'import.batch.confirmed')->where('actor_id', (string) $this->admin->id)->exists())->toBeTrue();
});

test('rows that will not be applied require an explicit acknowledgement', function () {
    Queue::fake();
    S::student('2020-00001');
    $batch = S::preview(Kit::csv([['id' => '2020-00001'], ['id' => '2020-00077']])); // 1 update + 1 unknown

    $component = ui()->call('selectBatch', $batch->id);
    expect($component->html())
        ->toContain('1 rows will not be applied')
        ->toContain('Valid new students with unique 7-digit legacy or 10-digit current IDs will be created')
        ->toContain('@student.buksu.edu.ph');

    $component->call('confirm', $batch->id)->assertHasErrors('acknowledged');
    expect(status($batch))->toBe(State::PREVIEWED);
    Queue::assertNothingPushed();

    $component->set('acknowledged', true)->call('confirm', $batch->id)->assertHasNoErrors();
    expect(status($batch))->toBe(State::PROCESSING);
    Queue::assertPushed(ProcessImportBatch::class, 1);
});

test('a batch with nothing applicable cannot be confirmed', function () {
    Queue::fake();
    $batch = S::preview(Kit::csv([['id' => '2020-00077'], ['id' => '2020-00078']])); // unknown students only

    ui()->call('selectBatch', $batch->id)->set('acknowledged', true)->call('confirm', $batch->id);

    expect(status($batch))->toBe(State::PREVIEWED);
    Queue::assertNothingPushed();
});

test('a second confirmation of the same batch is denied and queues nothing more', function () {
    Queue::fake();
    S::student('2020-00001');
    $batch = S::preview(Kit::csv([['id' => '2020-00001']]));
    $component = ui()->call('selectBatch', $batch->id);

    $component->call('confirm', $batch->id)->call('confirm', $batch->id);

    expect($component->get('noticeTone'))->toBe('warning');
    Queue::assertPushed(ProcessImportBatch::class, 1);
});

test('confirm, start and revalidate are refused in every state the state machine does not allow', function (string $status, bool $confirmed, array $allowed) {
    Queue::fake();
    $batch = batchAt($status, $confirmed);
    stageRow($batch->id, 1, 'UPDATED');

    $component = ui()->call('selectBatch', $batch->id);

    foreach (['confirm' => 'confirm', 'process' => 'startProcessing', 'revalidate' => 'revalidate'] as $action => $method) {
        if (in_array($action, $allowed, true)) {
            continue;
        }
        $component->call($method, $batch->id);
        expect(status($batch))->toBe($status, "$method must not move a $status batch");
    }

    Queue::assertNotPushed(ProcessImportBatch::class, fn () => ! in_array('process', $allowed, true) && ! in_array('confirm', $allowed, true));
    Queue::assertNotPushed(ValidateImportBatch::class, fn () => ! in_array('revalidate', $allowed, true));
})->with([
    'staged' => [State::STAGED, false, ['revalidate']],
    'validating' => [State::VALIDATING, false, []],
    'confirmed' => [State::CONFIRMED, true, ['process']],
    'processing' => [State::PROCESSING, true, []],
    'completed' => [State::COMPLETED, true, []],
    'failed before confirmation' => [State::FAILED, false, ['revalidate']],
    'failed after confirmation' => [State::FAILED, true, ['process']],
]);

test('allowed recovery actions work: revalidate, start and resume', function () {
    Queue::fake();

    foreach ([[State::STAGED, false], [State::PREVIEWED, false], [State::FAILED, false]] as [$status, $confirmed]) {
        $batch = batchAt($status, $confirmed);
        ui()->call('revalidate', $batch->id);
        Queue::assertPushed(ValidateImportBatch::class, fn ($job) => $job->batchId === $batch->id);
    }

    foreach ([[State::CONFIRMED, true], [State::FAILED, true]] as [$status, $confirmed]) {
        $batch = batchAt($status, $confirmed);
        ui()->call('startProcessing', $batch->id);
        expect(status($batch))->toBe(State::PROCESSING);
        Queue::assertPushed(ProcessImportBatch::class, fn ($job) => $job->batchId === $batch->id);
    }
});

test('revalidation is never offered for a confirmed batch, and only valid recovery buttons are rendered', function () {
    $failedAfter = batchAt(State::FAILED, true);
    $failedBefore = batchAt(State::FAILED, false);
    $done = batchAt(State::COMPLETED, true);

    $after = ui()->call('selectBatch', $failedAfter->id)->html();
    expect($after)->toContain('Resume processing')->not->toContain('Re-run validation');

    $before = ui()->call('selectBatch', $failedBefore->id)->html();
    expect($before)->toContain('Re-run validation')->not->toContain('Resume processing');

    $finished = ui()->call('selectBatch', $done->id)->html();
    expect($finished)->not->toContain('Re-run validation')->not->toContain('Resume processing')->not->toContain('Confirm and start processing');
});

// ---------------------------------------------------------------------------------------------- states from persisted data

test('processing progress is read from persisted rows', function () {
    $batch = batchAt(State::PROCESSING, true, ['records_received' => 4]);
    stageRow($batch->id, 1, 'UPDATED', processed: true);
    stageRow($batch->id, 2, 'UPDATED');
    stageRow($batch->id, 3, 'INVALID');

    $html = ui()->call('selectBatch', $batch->id)->html();

    expect($html)->toContain('1 of 2 applicable rows applied')->toContain('role="progressbar"')->toContain('aria-valuenow="1"');
});

test('a completed import shows its recorded summary and marks only processed rows as applied', function () {
    S::student('2020-00001');
    $batch = S::complete(Kit::csv([['id' => '2020-00001'], ['id' => '2020-00055']]));

    $html = ui()->call('selectBatch', $batch->id)->html();

    expect($html)->toContain('Import completed')->toContain('1 existing students were updated. 1 of 2 rows were not applied.')
        ->toContain('Applied')->toContain('Not applied');
    expect((int) $batch->records_created)->toBe(0);
});

test('unknown students and exception-review rows are never shown as applied and no student is created', function () {
    $before = Student::count();
    $batch = S::complete(Kit::csv([['id' => '2020-00001']])); // no such student: needs exception review

    $component = ui()->call('selectBatch', $batch->id);
    $html = $component->html();

    expect($html)->toContain('Needs exception review')->toContain('NEW_STUDENT_REQUIRES_INSTITUTIONAL_EMAIL')
        ->not->toContain('>Applied<')->not->toContain('Will apply');
    expect(Student::count())->toBe($before)->and(S::enrollmentCount())->toBe(0);
});

test('a failed batch shows a coarse reason from the audit event and no technical detail', function () {
    $batch = S::stage("Foo,Bar\n1,2\n");
    S::service()->validate($batch); // file-level failure: missing required columns

    $html = ui()->call('selectBatch', $batch->id)->html();

    expect($html)->toContain('Validation failed')->toContain('missing one or more required columns')
        ->toContain('Re-run validation')
        ->not->toContain('Stack trace')->not->toContain('Exception')->not->toContain('Foo,Bar');
});

test('an unknown failure code falls back to a generic message', function () {
    $batch = batchAt(State::FAILED, true);
    DB::table('audit_logs')->insert([
        'event_type' => 'import.batch.failed', 'severity' => 'WARNING', 'target_type' => 'import_batch', 'target_id' => (string) $batch->id,
        'metadata_json' => json_encode(['failure_code' => 'SOMETHING_NEW']), 'created_at' => now(),
    ]);

    expect(ui()->call('selectBatch', $batch->id)->html())->toContain('Technical details are not shown here');
});

test('polling runs only while an import is active', function () {
    expect(ui()->html())->not->toContain('wire:poll');

    $idle = batchAt(State::COMPLETED, true);
    expect(ui()->call('selectBatch', $idle->id)->html())->not->toContain('wire:poll');

    $running = batchAt(State::PROCESSING, true);
    expect(ui()->html())->toContain('wire:poll');
    expect(ui()->call('selectBatch', $running->id)->html())->toContain('wire:poll');
});

// ---------------------------------------------------------------------------------------------- scope and regressions

test('using the UI does not touch administrator records or add routes beyond the one page', function () {
    Queue::fake();
    $fingerprint = S::adminFingerprint();
    $routes = collect(Route::getRoutes()->getRoutes())->map(fn ($r) => $r->uri())->sort()->values()->all();

    ui()->set('file', upload())->set('academicYear', 'AY')->set('semester', 'S')->call('stageFile');
    $batch = ImportBatch::query()->firstOrFail();
    ui()->call('selectBatch', $batch->id)->call('revalidate', $batch->id);

    expect(S::adminFingerprint())->toBe($fingerprint);
    expect($routes)->toContain('admin/data-center')
        ->and(collect($routes)->filter(fn ($u) => str_contains($u, 'import'))->all())->toBe([]);
});

test('the component never reads raw staged rows or writes batch status itself', function () {
    $source = file_get_contents(resource_path('views/livewire/admin/data-center.blade.php'));

    expect($source)->not->toContain("'raw_row_json'")->not->toContain('->update(')->not->toContain('->save(')
        ->not->toContain('forceFill')->not->toContain("DB::table('import_batches')");
});

// ---------------------------------------------------------------------------------------------- polling lifecycle (v2)

test('a synchronous queue never leaves the page waiting for a job that already ran', function () {
    // phpunit.xml uses QUEUE_CONNECTION=sync: the validation job runs inside dispatch().
    $component = ui()->set('file', upload())->set('academicYear', 'AY')->set('semester', 'S')->call('stageFile');

    expect(ImportBatch::query()->firstOrFail()->status)->toBe(State::PREVIEWED);
    $component->assertSet('awaitingSince', null);
    expect($component->html())->not->toContain('wire:poll');
});

test('re-validating on a synchronous queue does not start waiting either', function () {
    $batch = S::preview(Kit::csv([['id' => '2020-00001']]));

    $component = ui()->call('selectBatch', $batch->id)->call('revalidate', $batch->id);

    expect(status($batch))->toBe(State::PREVIEWED);
    $component->assertSet('awaitingSince', null)->assertSet('noticeTone', 'success');
    expect($component->html())->not->toContain('wire:poll');
});

test('with a background worker the page waits only until the batch has moved, even inside one second', function () {
    Queue::fake();
    $this->freezeTime(); // every timestamp is identical: only a real change may end the wait
    $batch = S::preview(Kit::csv([['id' => '2020-00001']]));

    $component = ui()->call('selectBatch', $batch->id)->call('revalidate', $batch->id);
    $component->assertNotSet('awaitingSince', null);
    expect($component->html())->toContain('wire:poll');

    $component->call('tick')->assertNotSet('awaitingSince', null); // nothing has happened yet: keep waiting

    S::service()->validate($batch->fresh()); // the worker runs the job: PREVIEWED -> VALIDATING -> PREVIEWED

    $component->call('tick')->assertSet('awaitingSince', null);
    expect($component->html())->not->toContain('wire:poll');
});

test('a worker that finishes before the next render ends the wait on the next poll', function () {
    Queue::fake();
    $batch = S::stage(Kit::csv([['id' => '2020-00001']]));

    $component = ui()->call('selectBatch', $batch->id)->call('revalidate', $batch->id)->assertNotSet('awaitingSince', null);
    S::service()->validate($batch->fresh());

    $component->call('tick')->assertSet('awaitingSince', null);
    expect(status($batch))->toBe(State::PREVIEWED);
});

test('waiting for a worker that never picks the job up is bounded', function () {
    Queue::fake();
    $batch = S::preview(Kit::csv([['id' => '2020-00001']]));
    $component = ui()->call('selectBatch', $batch->id)->call('revalidate', $batch->id);
    expect($component->html())->toContain('wire:poll');

    $this->travel(5)->minutes();

    expect($component->call('tick')->html())->not->toContain('wire:poll');
    $component->assertSet('awaitingSince', null);
});

test('the poll element is not tied to the viewport, so it keeps polling when the details are scrolled to', function () {
    $running = batchAt(State::PROCESSING, true);

    $html = ui()->call('selectBatch', $running->id)->html();

    expect($html)->toContain('wire:poll.5s="tick"')->not->toContain('.visible');
});

test('an idle STAGED or CONFIRMED batch does not poll forever', function () {
    foreach ([[State::STAGED, false], [State::CONFIRMED, true]] as [$state, $confirmed]) {
        $batch = batchAt($state, $confirmed);
        expect(ui()->call('selectBatch', $batch->id)->html())->not->toContain('wire:poll');
    }
});

// ---------------------------------------------------------------------------------------------- one validation job per batch (v2)

test('repeated re-validation actions queue a single validation job for the batch', function () {
    Queue::fake();
    $batch = S::preview(Kit::csv([['id' => '2020-00001']]));

    $component = ui()->call('selectBatch', $batch->id);
    $component->call('revalidate', $batch->id)->call('revalidate', $batch->id)->call('revalidate', $batch->id);

    Queue::assertPushed(ValidateImportBatch::class, 1);
});

test('two administrator sessions cannot queue a second validation job for the same batch', function () {
    Queue::fake();
    $other = makeAdminRoster()->last();
    $batch = S::preview(Kit::csv([['id' => '2020-00001']]));
    $second = S::preview(Kit::csv([['id' => '2020-00002']]));

    ui()->call('revalidate', $batch->id);
    ui($other)->call('revalidate', $batch->id);
    Queue::assertPushed(ValidateImportBatch::class, 1);

    ui($other)->call('revalidate', $second->id); // a different batch is not blocked
    Queue::assertPushed(ValidateImportBatch::class, 2);
});

test('the validation lock is released when the job finishes, so a later re-validation still runs', function () {
    $batch = S::preview(Kit::csv([['id' => '2020-00001']])); // 1 validated event

    ui()->call('revalidate', $batch->id);
    ui()->call('revalidate', $batch->id); // synchronous queue: each job has finished and freed the lock

    expect(DB::table('audit_logs')->where('event_type', 'import.batch.validated')->where('target_id', (string) $batch->id)->count())->toBe(3);
});

test('ValidateImportBatch is unique per batch id, whoever queues it', function () {
    $job = new ValidateImportBatch(5, 1);

    expect($job)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->uniqueId())->toBe((new ValidateImportBatch(5, 2))->uniqueId())
        ->and($job->uniqueId())->not->toBe((new ValidateImportBatch(6, 1))->uniqueId())
        ->and($job->uniqueFor)->toBeGreaterThan($job->timeout);
});

// ---------------------------------------------------------------------------------------------- wording (v2)

test('the term help text says surrounding spaces are trimmed and does not claim verbatim storage', function () {
    $html = ui()->html();

    expect($html)->toContain('Leading and trailing spaces are trimmed')->not->toContain('exactly as typed');

    Queue::fake();
    ui()->set('file', upload())->set('academicYear', '  2026   2027  ')->set('semester', "\t1st  Sem ")->call('stageFile');
    $batch = ImportBatch::query()->firstOrFail();
    expect([$batch->academic_year, $batch->semester])->toBe(['2026   2027', '1st  Sem']); // inner spacing untouched
});

// ---------------------------------------------------------------------------------------------- details modal

test('the import details live in an accessible modal that is closed until a batch is opened', function () {
    $batch = S::preview(Kit::csv([['id' => '2020-00001']]));

    $closed = ui()->assertSet('detailOpen', false)->html();
    expect($closed)->toContain('data-modal="import-details"')->not->toContain('id="batch-title"');

    $open = ui()->call('selectBatch', $batch->id)->assertSet('detailOpen', true)->html();
    expect($open)->toContain('id="batch-title"')
        ->toContain('aria-label="Close modal"')   // the close button
        ->not->toContain('disable-escape')        // Escape closes the native dialog
        ->not->toContain('disable-click-outside');
});

test('the modal shows batch information, counters, classification summary and paginated rows', function () {
    $batch = batchAt(State::PREVIEWED, false, ['records_received' => 30]);
    foreach (range(1, 30) as $n) {
        stageRow($batch->id, $n, $n <= 28 ? 'UPDATED' : 'INVALID');
    }

    $component = ui()->call('selectBatch', $batch->id);
    $html = $component->html();
    expect($html)->toContain('Import #'.$batch->id)->toContain('Rows received')->toContain('Confirm import')->toContain('Page 1 of 2');

    $component->call('nextPage', 'preview');
    expect($component->html())->toContain('Page 2 of 2');
});

test('dismissing the modal (close button or Escape) clears the selection and the deep link', function () {
    $batch = S::preview(Kit::csv([['id' => '2020-00001']]));

    $component = ui()->call('selectBatch', $batch->id)->assertSet('batchId', $batch->id);
    $component->set('detailOpen', false)->assertSet('batchId', null)->assertSet('detailOpen', false);
    expect($component->html())->not->toContain('id="batch-title"');

    ui()->call('selectBatch', $batch->id)->call('closeBatch')->assertSet('batchId', null)->assertSet('detailOpen', false);
});

test('a deep link opens the modal and an unknown id does not', function () {
    $batch = S::preview(Kit::csv([['id' => '2020-00001']]));

    $this->actingAs($this->admin, 'admin')->get(route('admin.data-center', ['batch' => $batch->id]))->assertOk()->assertSee('&quot;detailOpen&quot;:true', false);
    $this->actingAs($this->admin, 'admin')->get(route('admin.data-center', ['batch' => 987654]))->assertOk()->assertSee('&quot;detailOpen&quot;:false', false)->assertSee('That import does not exist');
});

test('polling and status-aware actions keep working while the modal is open', function () {
    $running = batchAt(State::PROCESSING, true);
    $failed = batchAt(State::FAILED, true);

    $html = ui()->call('selectBatch', $running->id)->assertSet('detailOpen', true)->html();
    expect($html)->toContain('wire:poll.5s="tick"');

    $component = ui()->call('selectBatch', $failed->id);
    expect($component->html())->toContain('Resume processing');
});

test('the modal never exposes raw source data', function () {
    $batch = batchAt(State::PREVIEWED, false, ['records_received' => 1]);
    DB::table('import_batch_rows')->insert([
        'import_batch_id' => $batch->id, 'source_row_number' => 1, 'institutional_id' => '2020-00001',
        'raw_row_json' => json_encode(['secret_column' => 'RAW-SOURCE-VALUE']), 'normalized_json' => json_encode(['values' => ['institutional_id' => '2020-00001'], 'preview' => null]),
        'classification' => 'UPDATED', 'issues_json' => json_encode([]), 'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(ui()->call('selectBatch', $batch->id)->html())->not->toContain('RAW-SOURCE-VALUE')->not->toContain('secret_column');
});
