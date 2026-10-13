<?php

use App\Models\AdminUser;
use App\Models\Student;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

/*
 * Student Masterlist: a read-only, searchable, paginated view of the `students` table with a details modal.
 * Everything is synthetic data. The page must never expose google_subject or raw import rows, and must offer
 * no write action.
 */

beforeEach(function () {
    $this->admin = makeAdminRoster()->first();
});

function mlist(?AdminUser $admin = null)
{
    test()->actingAs($admin ?? test()->admin, 'admin');

    return Volt::test('admin.students');
}

function pupil(array $attributes = []): Student
{
    return Student::factory()->create($attributes);
}

/** The Student IDs listed in the table, in order. @return list<string> */
function listedIds($component): array
{
    return collect($component->instance()->students->items())->pluck('institutional_id')->all();
}

// ---------------------------------------------------------------------------------------------- authorization & navigation

test('only an administrator can open the masterlist', function () {
    $this->get(route('admin.students'))->assertRedirect(route('login'));

    $this->actingAs(Student::factory()->linked()->create(), 'student')->get(route('admin.students'))->assertForbidden();
    auth('student')->logout();

    $this->actingAs($this->admin, 'admin')->get(route('admin.students'))->assertOk()->assertSee('Student Masterlist');
});

test('the Livewire component itself refuses a request without an administrator', function () {
    Volt::test('admin.students')->assertForbidden();
});

test('the sidebar has a distinct Student Masterlist link next to the renamed Student Import link', function () {
    $html = $this->actingAs($this->admin, 'admin')->get(route('admin.students'))->assertOk()->getContent();

    expect($html)->toContain('href="'.route('admin.students').'"')
        ->toContain('href="'.route('admin.data-center').'"')
        ->toContain('Student Import')
        ->not->toContain('Student Master / Data Center')
        ->and(substr_count($html, 'aria-current="page"'))->toBe(2); // desktop + mobile drawer: only the masterlist is current

    expect(route('admin.students', absolute: false))->toBe('/admin/students')
        ->and(route('admin.data-center', absolute: false))->toBe('/admin/data-center');
});

test('the masterlist adds no write route', function () {
    $uris = collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => str_contains($r->uri(), 'students') && str_starts_with($r->uri(), 'admin'));

    expect($uris->map->uri()->values()->all())->toBe(['admin/students'])
        ->and($uris->flatMap->methods()->unique()->values()->all())->toBe(['GET', 'HEAD']);
});

// ---------------------------------------------------------------------------------------------- search

test('search finds students by ID and by name, case-insensitively and in any word order', function () {
    $ana = pupil(['institutional_id' => '2020-00001', 'first_name' => 'Ana', 'middle_name' => 'Maria', 'last_name' => 'Dela Cruz']);
    $ben = pupil(['institutional_id' => '2021-00002', 'first_name' => 'Ben', 'middle_name' => null, 'last_name' => 'Santos']);

    foreach (['2020-00001', '00001', '2020', 'ana', 'ANA', 'dela cruz', 'Cruz, Ana', 'Ana Cruz', 'maria'] as $term) {
        expect(listedIds(mlist()->set('search', $term)))->toBe(['2020-00001'], "search: {$term}");
    }

    expect(listedIds(mlist()->set('search', 'santos')))->toBe(['2021-00002'])
        ->and(listedIds(mlist()->set('search', 'zzz')))->toBe([])
        ->and(listedIds(mlist()->set('search', '')))->toBe(['2020-00001', '2021-00002']); // ordered by last name
});

test('search treats LIKE wildcards and SQL as plain text', function () {
    pupil(['institutional_id' => '2020-00001', 'first_name' => 'Ana', 'last_name' => 'Cruz']);

    foreach (['%', '_', '!', '\\', "'; DROP TABLE students; --", 'a%z', '2020_00001'] as $term) {
        expect(listedIds(mlist()->set('search', $term)))->toBe([], "search: {$term}");
    }
    expect(DB::table('students')->count())->toBe(1);
});

test('a very long search is cut to a safe length and does not fail', function () {
    pupil(['first_name' => 'Ana']);

    $component = mlist()->set('search', str_repeat('ana ', 200));

    expect(mb_strlen($component->get('search')))->toBeLessThanOrEqual(100);
});

test('the search text is not put in the URL', function () {
    pupil(['first_name' => 'Ana']);

    $component = mlist()->set('search', 'ana');

    expect(json_encode($component->effects ?? []))->not->toContain('ana');
    expect(file_get_contents(base_path('resources/views/livewire/admin/students.blade.php')))->not->toMatch('/#\[Url[^\]]*\]\s*public string \$search/');
});

// ---------------------------------------------------------------------------------------------- filters

test('college, course, year level and status filters narrow the list and combine', function () {
    pupil(['institutional_id' => 'A-1', 'last_name' => 'Aaa', 'current_college' => 'CAS', 'current_course' => 'BSBio', 'current_year_level' => '1st Year', 'status' => 'ACTIVE']);
    pupil(['institutional_id' => 'A-2', 'last_name' => 'Bbb', 'current_college' => 'CAS', 'current_course' => 'BSMath', 'current_year_level' => '2nd Year', 'status' => 'INACTIVE']);
    pupil(['institutional_id' => 'B-1', 'last_name' => 'Ccc', 'current_college' => 'CoE', 'current_course' => 'BSIT', 'current_year_level' => '2nd Year', 'status' => 'ACTIVE']);

    expect(listedIds(mlist()->set('college', 'CAS')))->toBe(['A-1', 'A-2'])
        ->and(listedIds(mlist()->set('course', 'BSIT')))->toBe(['B-1'])
        ->and(listedIds(mlist()->set('year', '2nd Year')))->toBe(['A-2', 'B-1'])
        ->and(listedIds(mlist()->set('status', 'INACTIVE')))->toBe(['A-2'])
        ->and(listedIds(mlist()->set('college', 'CAS')->set('year', '2nd Year')->set('status', 'ACTIVE')))->toBe([])
        ->and(listedIds(mlist()->set('college', 'CAS')->set('search', 'a-2')->set('status', 'INACTIVE')))->toBe(['A-2']);
});

test('the filter choices come from the data, and courses follow the chosen college', function () {
    pupil(['current_college' => 'CAS', 'current_course' => 'BSBio', 'current_year_level' => '2nd Year']);
    pupil(['current_college' => 'CoE', 'current_course' => 'BSIT', 'current_year_level' => '1st Year']);

    $all = mlist();
    expect($all->instance()->colleges)->toBe(['CAS', 'CoE'])
        ->and($all->instance()->courses)->toBe(['BSBio', 'BSIT'])
        ->and($all->instance()->years)->toBe(['1st Year', '2nd Year']); // configured order, not alphabetical accident

    expect(mlist()->set('college', 'CoE')->instance()->courses)->toBe(['BSIT']);
});

test('a filter value that is not offered is dropped, and a course from another college is cleared', function () {
    pupil(['current_college' => 'CAS', 'current_course' => 'BSBio']);
    pupil(['current_college' => 'CoE', 'current_course' => 'BSIT']);

    mlist()->set('college', 'Nowhere')->assertSet('college', '')
        ->set('status', 'DELETED')->assertSet('status', '')
        ->set('year', '9th Year')->assertSet('year', '');

    mlist()->set('course', 'BSIT')->set('college', 'CAS')->assertSet('course', '');
});

test('filters can be deep-linked, and clearing resets search and filters', function () {
    pupil(['institutional_id' => 'A-1', 'current_college' => 'CAS', 'status' => 'ACTIVE']);
    pupil(['institutional_id' => 'B-1', 'current_college' => 'CoE', 'status' => 'INACTIVE']);

    $html = $this->actingAs($this->admin, 'admin')->get(route('admin.students', ['college' => 'CoE', 'status' => 'INACTIVE']))->assertOk()->getContent();
    expect($html)->toContain('B-1')->not->toContain('A-1');

    $this->get(route('admin.students', ['college' => 'Nowhere']))->assertOk()->assertSee('A-1', false)->assertSee('B-1', false);

    mlist()->set('search', 'zzz')->set('college', 'CAS')->call('clearFilters')
        ->assertSet('search', '')->assertSet('college', '')->assertSet('status', '');
});

test('an empty table says so, and a search with no match offers to clear it', function () {
    expect(mlist()->html())->toContain('No students yet');

    pupil();
    expect(mlist()->set('search', 'zzz')->html())->toContain('No students match')->toContain('Clear search and filters');
});

// ---------------------------------------------------------------------------------------------- pagination

test('the table is paginated server-side, 25 rows per page, in a stable order', function () {
    foreach (range(1, 60) as $n) {
        pupil(['institutional_id' => sprintf('2020-%05d', $n), 'last_name' => sprintf('Surname%02d', $n)]);
    }

    $component = mlist();
    $first = listedIds($component);
    expect($first)->toHaveCount(25)->and($first[0])->toBe('2020-00001')
        ->and($component->html())->toContain('Page 1 of 3')->toContain('1–25 of 60');

    $component->call('nextPage');
    $second = listedIds($component);
    expect($second)->toHaveCount(25)->and(array_intersect($first, $second))->toBe([])
        ->and($second[0])->toBe('2020-00026')->and($component->html())->toContain('Page 2 of 3');

    $component->call('nextPage');
    expect(listedIds($component))->toHaveCount(10)->and($component->html())->toContain('Page 3 of 3');

    expect(mlist()->html())->not->toContain('2020-00026'); // rows beyond the first page are not rendered
});

test('changing the search or a filter returns to the first page', function () {
    foreach (range(1, 30) as $n) {
        pupil(['institutional_id' => sprintf('2020-%05d', $n), 'last_name' => sprintf('Surname%02d', $n), 'status' => $n > 5 ? 'ACTIVE' : 'INACTIVE']);
    }

    $component = mlist()->call('nextPage');
    expect($component->html())->toContain('Page 2 of 2');

    $component->set('status', 'ACTIVE');
    expect($component->html())->toContain('1–25 of 25')->not->toContain('Page 2 of');
    expect(listedIds($component))->toHaveCount(25);
});

// ---------------------------------------------------------------------------------------------- details modal

test('the details modal is closed until a student is opened', function () {
    pupil(['institutional_id' => '2020-00001']);

    $closed = mlist()->assertSet('detailOpen', false)->html();
    expect($closed)->toContain('data-modal="student-details"')->not->toContain('id="student-title"');
});

test('opening a student shows identity, email, placement, status and Data Center information, read-only', function () {
    $batchId = DB::table('import_batches')->insertGetId([
        'source_filename' => 'roster-fall.csv', 'academic_year' => '2026-2027', 'semester' => '1st Semester',
        'uploaded_by' => $this->admin->id, 'status' => 'COMPLETED', 'checksum' => str_repeat('a', 64),
        'completed_at' => '2026-10-01 09:30:00', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $student = pupil([
        'institutional_id' => '2020-00001', 'institutional_email' => 'ana.cruz@students.example.test',
        'first_name' => 'Ana', 'middle_name' => 'Maria', 'last_name' => 'Cruz',
        'current_college' => 'College of Arts', 'current_course' => 'BS Biology', 'current_year_level' => '3rd Year',
        'status' => 'INACTIVE', 'last_import_batch_id' => $batchId,
    ]);
    DB::table('student_enrollments')->insert([
        'student_id' => $student->id, 'import_batch_id' => $batchId, 'college' => 'College of Arts', 'course' => 'BS Biology',
        'year_level' => '3rd Year', 'status' => 'INACTIVE', 'effective_from' => '2026-10-01 09:30:00', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $html = mlist()->call('selectStudent', $student->id)->assertSet('detailOpen', true)->assertSet('selectedId', $student->id)->html();

    expect($html)->toContain('id="student-title"')
        ->toContain('Cruz, Ana Maria')->toContain('2020-00001')->toContain('ana.cruz@students.example.test')
        ->toContain('College of Arts')->toContain('BS Biology')->toContain('3rd Year')->toContain('Inactive')
        ->toContain('#'.$batchId)->toContain('roster-fall.csv')->toContain('2026-2027')->toContain('1st Semester')
        ->toContain('Oct 1, 2026 9:30 AM')->toContain('Placements recorded by imports')
        ->toContain('aria-label="Close modal"')->toContain('Read-only')
        ->not->toContain('disable-escape');
});

test('a student that no import loaded says so instead of showing import details', function () {
    $student = pupil(['last_import_batch_id' => null]);

    expect(mlist()->call('selectStudent', $student->id)->html())->toContain('not loaded by a Data Center import')->not->toContain('Placements recorded by imports');
});

test('closing the modal (close button or Escape) clears the selection and the deep link', function () {
    $student = pupil();

    mlist()->call('selectStudent', $student->id)->assertSet('selectedId', $student->id)
        ->set('detailOpen', false)->assertSet('selectedId', null)->assertSet('detailOpen', false);
});

test('a deep link opens the modal, and an unknown id opens nothing', function () {
    $student = pupil(['institutional_id' => '2020-00001']);

    $this->actingAs($this->admin, 'admin')->get(route('admin.students', ['student' => $student->id]))
        ->assertOk()->assertSee('&quot;detailOpen&quot;:true', false)->assertSee('id="student-title"', false);

    $this->get(route('admin.students', ['student' => 987654]))
        ->assertOk()->assertSee('&quot;detailOpen&quot;:false', false)->assertSee('That student does not exist')->assertDontSee('id="student-title"', false);

    mlist()->call('selectStudent', 987654)->assertSet('detailOpen', false)->assertSet('selectedId', null);
});

test('opening a student keeps the search, filters and page', function () {
    foreach (range(1, 30) as $n) {
        pupil(['institutional_id' => sprintf('2020-%05d', $n), 'last_name' => sprintf('Surname%02d', $n), 'current_college' => 'CAS']);
    }
    $target = Student::query()->where('institutional_id', '2020-00003')->firstOrFail();

    $component = mlist()->set('college', 'CAS')->set('search', 'Surname')->call('selectStudent', $target->id);

    $component->assertSet('college', 'CAS')->assertSet('search', 'Surname')->assertSet('detailOpen', true);
    expect(listedIds($component))->toHaveCount(25);
});

// ---------------------------------------------------------------------------------------------- privacy

test('the page and the modal never expose google_subject, raw import rows or another student', function () {
    $other = pupil(['institutional_id' => '2021-99999', 'institutional_email' => 'other.person@students.example.test', 'first_name' => 'Other']);
    $student = Student::factory()->linked('SECRET-GOOGLE-SUB-123')->create(['institutional_id' => '2020-00001', 'institutional_email' => 'ana.cruz@students.example.test']);
    $batchId = (int) $student->last_import_batch_id;
    DB::table('import_batch_rows')->insert([
        'import_batch_id' => $batchId, 'source_row_number' => 1, 'institutional_id' => '2020-00001',
        'raw_row_json' => json_encode(['secret_column' => 'RAW-SOURCE-VALUE']), 'normalized_json' => json_encode(['values' => []]),
        'classification' => 'UPDATED', 'issues_json' => json_encode([]), 'created_at' => now(), 'updated_at' => now(),
    ]);

    $list = mlist();
    $listHtml = $list->html();
    $open = mlist()->call('selectStudent', $student->id);
    $openHtml = $open->html();

    foreach ([$listHtml, $openHtml, json_encode($open->snapshot)] as $output) {
        expect($output)->not->toContain('SECRET-GOOGLE-SUB-123')->not->toContain('google_subject')
            ->not->toContain('RAW-SOURCE-VALUE')->not->toContain('secret_column')->not->toContain('raw_row_json');
    }

    // Emails appear only in the modal of the opened student; never in the list and never in the state snapshot.
    expect($listHtml)->not->toContain('ana.cruz@students.example.test')->not->toContain('other.person@students.example.test')
        ->and($openHtml)->toContain('ana.cruz@students.example.test')->not->toContain('other.person@students.example.test')
        ->and(json_encode($open->snapshot))->not->toContain('ana.cruz@students.example.test');
    expect($other->exists)->toBeTrue();
});

test('the component is read-only: no write call, no model, no google_subject column', function () {
    $source = file_get_contents(base_path('resources/views/livewire/admin/students.blade.php'));

    expect($source)->not->toMatch('/->(update|insert|insertGetId|delete|upsert|save|create|forceDelete|truncate)\(/')
        ->not->toMatch('/Student::|new Student|ImportBatch::/')
        ->not->toMatch('/[\'"](students\.)?google_subject[\'"]/')
        ->not->toMatch('/raw_row_json|import_batch_rows/')
        ->not->toContain('select(\'*\')')
        ->not->toMatch('/public (\?)?(object|array|Student|Model)\b/');
});

test('listing and opening students writes nothing', function () {
    $student = pupil();
    $before = [DB::table('students')->get()->toJson(), DB::table('import_batches')->count(), DB::table('audit_logs')->count()];

    mlist()->set('search', 'a')->call('selectStudent', $student->id)->set('detailOpen', false);

    expect([DB::table('students')->get()->toJson(), DB::table('import_batches')->count(), DB::table('audit_logs')->count()])->toBe($before);
});
