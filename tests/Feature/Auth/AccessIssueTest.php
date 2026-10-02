<?php

use App\Models\AccessIssueReport;
use App\Models\Student;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;

const ACCESS_ISSUE_TEXT = 'We could not find your student record in the current university data.';

const PROBLEM_TYPES = [
    'STUDENT_RECORD_NOT_FOUND', 'DATA_CENTER_INFORMATION_OUTDATED', 'LATE_VERIFIED_COR', 'RETURNEE_RETURNING_STUDENT',
    'RECENTLY_ENROLLED_OR_NEWLY_ADMITTED', 'TRANSFER_OR_COURSE_CHANGE', 'INCORRECT_COLLEGE_COURSE_OR_YEAR',
    'INCORRECT_STUDENT_STATUS', 'GOOGLE_OR_LOGIN_PROBLEM', 'OTHER',
];

/** A verified institutional Google account with no student record (eligible to report). */
function unknownInstitutionalLogin(string $sub = 'raw-unknown-sub', string $email = 'ghost@students.example.test'): TestResponse
{
    return googleLogin(googleIdentity($sub, $email));
}

/** The shared message block of the page (excludes the report form and framework asset injection). */
function accessIssueMessage(string $html): string
{
    preg_match('/<h1.*?<\/p>\s*<p.*?<\/p>/s', $html, $m);

    return $m[0] ?? '';
}

function accessReport(array $overrides = []): array
{
    return array_replace(['problem_type' => 'STUDENT_RECORD_NOT_FOUND', 'description' => 'I cannot sign in to vote.'], $overrides);
}

test('an unknown institutional account is shown the Access Issue page with the report form', function () {
    unknownInstitutionalLogin()->assertRedirect(route('access-issue'));

    $this->get(route('access-issue'))
        ->assertOk()
        ->assertSee('Student Access Issue')
        ->assertSee(ACCESS_ISSUE_TEXT)
        ->assertSee('Please make sure you are using your institutional Google account.')
        ->assertSee('Report Access Issue')
        ->assertSee('ghost@students.example.test'); // filled from the authenticated Google identity

    $this->assertGuest('student');
    $this->assertGuest('admin');
});

test('the form has Problem Type (required dropdown), Student ID (optional), Description (required) and a non-editable email', function () {
    unknownInstitutionalLogin();

    $html = $this->get(route('access-issue'))->getContent();

    expect($html)->toContain('<select name="problem_type" required')
        ->and($html)->toContain('name="student_id"')
        ->and($html)->toContain('<textarea name="description"')
        ->and($html)->toContain('ghost@students.example.test');
    // the email is displayed, never an input; denial_reason is not a field at all
    expect($html)->not->toContain('name="google_email"')->not->toContain('name="email"')->not->toContain('denial_reason');
    expect(substr_count($html, '<option'))->toBe(count(PROBLEM_TYPES) + 1); // + placeholder
});

test('the problem types are the agreed initial list, config-backed with human-readable labels', function () {
    $types = config('comelec.access_issue_problem_types');

    expect(array_keys($types))->toBe(PROBLEM_TYPES);
    foreach ($types as $key => $label) {
        expect($label)->toBeString()->not->toBe($key)->and($label)->toMatch('/[a-z]/'); // readable, not a raw CONSTANT
    }

    unknownInstitutionalLogin();
    $page = $this->get(route('access-issue'));
    foreach ($types as $label) {
        $page->assertSee($label);
    }
});

test('the message block is identical for admin conflicts, unknown, non-institutional and unverified accounts (nothing is revealed)', function () {
    $admin = makeAdminRoster(linked: true)[0];
    $conflict = Student::factory()->linked($admin->google_subject)->create();

    $messages = [];
    foreach ([
        googleIdentity($admin->google_subject, $conflict->institutional_email),
        googleIdentity('s-unknown', 'ghost@'.studentDomain()),
        googleIdentity('s-outsider', 'outsider@gmail.com'),
        googleIdentity('s-unverified', 'late@'.studentDomain(), verified: false),
    ] as $identity) {
        googleLogin($identity)->assertRedirect(route('access-issue'));
        $messages[] = accessIssueMessage($this->get(route('access-issue'))->getContent());
        $this->flushSession();
    }

    expect($messages[0])->not->toBe('')->toBe($messages[1])->toBe($messages[2])->toBe($messages[3]);
    expect(strtolower($messages[0]))->not->toContain('administrator')->not->toContain('conflict');
});

test('without a Google-authenticated denial there is no Access Issue page and nothing can be reported', function () {
    $this->get(route('access-issue'))->assertRedirect(route('login'));
    $this->post(route('access-issue.store'), accessReport())->assertRedirect(route('login'));

    expect(AccessIssueReport::count())->toBe(0);
});

test('a verified institutional student can submit a report', function () {
    unknownInstitutionalLogin('raw-unknown-sub', 'Ghost@students.example.test');

    $this->post(route('access-issue.store'), accessReport([
        'problem_type' => 'LATE_VERIFIED_COR',
        'student_id' => ' 23-00123 ',
        'description' => 'I enrolled this semester but cannot sign in.',
    ]))->assertRedirect(route('home'))->assertSessionHas('status');

    $r = AccessIssueReport::sole();
    expect($r->google_email)->toBe('ghost@students.example.test')
        ->and($r->problem_type)->toBe('LATE_VERIFIED_COR')
        ->and($r->reported_student_id)->toBe('23-00123')
        ->and($r->description)->toBe('I enrolled this semester but cannot sign in.')
        ->and($r->denial_reason)->toBe('NO_STUDENT_RECORD')
        ->and($r->subject_fingerprint)->toHaveLength(16)
        ->and($r->created_at)->not->toBeNull();

    $audit = DB::table('audit_logs')->where('event_type', 'access_issue.reported')->first();
    expect($audit->target_id)->toBe((string) $r->id);
});

test('every problem type is accepted and stored correctly', function (string $type) {
    unknownInstitutionalLogin();

    $this->post(route('access-issue.store'), accessReport(['problem_type' => $type]))->assertSessionHasNoErrors()->assertRedirect(route('home'));

    expect(AccessIssueReport::sole()->problem_type)->toBe($type);
})->with(PROBLEM_TYPES);

test('an invalid problem type is rejected and nothing is recorded', function (mixed $value) {
    unknownInstitutionalLogin();

    $this->post(route('access-issue.store'), accessReport(['problem_type' => $value]))->assertSessionHasErrors('problem_type');

    expect(AccessIssueReport::count())->toBe(0);
    $this->get(route('access-issue'))->assertOk(); // context survives so it can be corrected
})->with([
    'unknown key' => ['NOT_A_TYPE'],
    'wrong case' => ['other'],
    'a label instead of a key' => ['Other'],
    'blank' => [''],
    'array' => [['OTHER']],
    'sql-ish' => ["OTHER' OR '1'='1"],
]);

test('problem type is required', function () {
    unknownInstitutionalLogin();

    $this->post(route('access-issue.store'), ['description' => 'no type chosen'])->assertSessionHasErrors('problem_type');

    expect(AccessIssueReport::count())->toBe(0);
});

test('the allowed problem types are config-backed: no schema change is needed to add or retire one', function () {
    config(['comelec.access_issue_problem_types' => ['OTHER' => 'Other', 'BRAND_NEW_TYPE' => 'A brand new type']]);
    unknownInstitutionalLogin();

    $this->post(route('access-issue.store'), accessReport(['problem_type' => 'BRAND_NEW_TYPE']))->assertSessionHasNoErrors();
    expect(AccessIssueReport::sole()->problem_type)->toBe('BRAND_NEW_TYPE');

    $this->flushSession();
    unknownInstitutionalLogin();
    $this->post(route('access-issue.store'), accessReport(['problem_type' => 'LATE_VERIFIED_COR']))->assertSessionHasErrors('problem_type'); // retired
    expect(AccessIssueReport::count())->toBe(1);

    // a plain string column, deliberately not a database ENUM
    expect(Schema::hasColumn('access_issue_reports', 'problem_type'))->toBeTrue();
    expect(Schema::getColumnType('access_issue_reports', 'problem_type'))->toBeIn(['varchar', 'string']);
});

test('an empty allowed list rejects every submission (fail closed)', function () {
    config(['comelec.access_issue_problem_types' => []]);
    unknownInstitutionalLogin();

    $this->post(route('access-issue.store'), accessReport(['problem_type' => 'OTHER']))->assertSessionHasErrors('problem_type');

    expect(AccessIssueReport::count())->toBe(0);
});

test('denial_reason stays system-generated: the student cannot supply or change it (nor the email or fingerprint)', function () {
    unknownInstitutionalLogin('sub-x', 'ghost@students.example.test');

    $this->post(route('access-issue.store'), accessReport([
        'problem_type' => 'LATE_VERIFIED_COR',
        'denial_reason' => 'HACKED_BY_STUDENT',
        'google_email' => 'someone-else@students.example.test',
        'email' => 'someone-else@students.example.test',
        'subject_fingerprint' => 'aaaaaaaaaaaaaaaa',
    ]))->assertRedirect(route('home'));

    $r = AccessIssueReport::sole();
    expect($r->denial_reason)->toBe('NO_STUDENT_RECORD')            // what the system decided
        ->and($r->problem_type)->toBe('LATE_VERIFIED_COR')          // what the student said
        ->and($r->google_email)->toBe('ghost@students.example.test')
        ->and($r->subject_fingerprint)->not->toBe('aaaaaaaaaaaaaaaa');
});

test('denial_reason and problem_type are independent: the same problem type under different system reasons', function () {
    $unimported = Student::factory()->unimported()->create();

    googleLogin(googleIdentity('s-a', 'ghost@'.studentDomain()));
    $this->post(route('access-issue.store'), accessReport(['problem_type' => 'LATE_VERIFIED_COR']));
    $this->flushSession();

    googleLogin(googleIdentity('s-b', $unimported->institutional_email));
    $this->post(route('access-issue.store'), accessReport(['problem_type' => 'LATE_VERIFIED_COR']));

    expect(AccessIssueReport::orderBy('id')->pluck('denial_reason')->all())->toBe(['NO_STUDENT_RECORD', 'NOT_DATA_CENTER_LOADED'])
        ->and(AccessIssueReport::pluck('problem_type')->unique()->all())->toBe(['LATE_VERIFIED_COR']);
});

test('a non-institutional Google account sees the same page but cannot submit a report', function () {
    googleLogin(googleIdentity('s-personal', 'someone@gmail.com'))->assertRedirect(route('access-issue'));

    $html = $this->get(route('access-issue'))->assertOk()->assertSee(ACCESS_ISSUE_TEXT)->getContent();
    expect($html)->not->toContain('<form')->not->toContain('Report Access Issue')->not->toContain('someone@gmail.com');

    $this->post(route('access-issue.store'), accessReport())->assertRedirect(route('access-issue')); // silently not accepted
    expect(AccessIssueReport::count())->toBe(0);
    expect(DB::table('audit_logs')->where('event_type', 'access_issue.reported')->count())->toBe(0);
});

test('a domain look-alike cannot submit a report either', function () {
    googleLogin(googleIdentity('s-fake', 'x@evil-'.studentDomain()))->assertRedirect(route('access-issue'));

    $this->get(route('access-issue'))->assertDontSee('Report Access Issue');
    $this->post(route('access-issue.store'), accessReport());

    expect(AccessIssueReport::count())->toBe(0);
});

test('an unverified Google account cannot submit a report, even on the institutional domain, and its email is never stored or shown', function () {
    googleLogin(googleIdentity('s-unverified', 'spoof@students.example.test', verified: false))->assertRedirect(route('access-issue'));

    $this->get(route('access-issue'))->assertOk()->assertDontSee('Report Access Issue')->assertDontSee('spoof@students.example.test');
    $this->post(route('access-issue.store'), accessReport());

    expect(AccessIssueReport::count())->toBe(0);
});

test('reporting creates no student and changes no student', function () {
    $existing = Student::factory()->create();
    $before = DB::table('students')->get()->toJson();

    unknownInstitutionalLogin();
    $this->post(route('access-issue.store'), accessReport(['student_id' => $existing->institutional_id, 'description' => 'That is my student ID.']));

    expect(Student::count())->toBe(1)->and(DB::table('students')->get()->toJson())->toBe($before);
    expect(Student::where('institutional_email', 'ghost@students.example.test')->exists())->toBeFalse();
});

test('reporting never grants access: even quoting a real student ID leaves the person a guest and unlinked', function () {
    $existing = Student::factory()->create();

    unknownInstitutionalLogin('ghost-sub');
    $this->post(route('access-issue.store'), accessReport(['student_id' => $existing->institutional_id, 'description' => 'I am that student.']));

    $this->assertGuest('student');
    $this->assertGuest('admin');
    $this->get(route('student.home'))->assertRedirect(route('login'));
    expect($existing->fresh()->google_subject)->toBeNull();

    unknownInstitutionalLogin('ghost-sub')->assertRedirect(route('access-issue')); // still refused
    $this->assertGuest('student');
});

test('the report is single-use and the access-issue context is cleared after submitting', function () {
    unknownInstitutionalLogin();
    $this->post(route('access-issue.store'), accessReport());

    $this->post(route('access-issue.store'), accessReport())->assertRedirect(route('login'));
    $this->get(route('access-issue'))->assertRedirect(route('login'));

    expect(AccessIssueReport::count())->toBe(1);
});

test('description is required and fields are length-limited; a failed submit records nothing', function (array $input) {
    unknownInstitutionalLogin();

    $this->post(route('access-issue.store'), $input)->assertSessionHasErrors();

    expect(AccessIssueReport::count())->toBe(0);
    $this->get(route('access-issue'))->assertOk();
})->with([
    'missing description' => [['problem_type' => 'OTHER']],
    'too short' => [['problem_type' => 'OTHER', 'description' => 'no']],
    'too long' => [['problem_type' => 'OTHER', 'description' => str_repeat('x', 1001)]],
    'student id too long' => [['problem_type' => 'OTHER', 'student_id' => str_repeat('9', 51), 'description' => 'valid description']],
]);

test('the report endpoint is rate limited', function () {
    foreach (range(1, 5) as $i) {
        $this->post(route('access-issue.store'), accessReport())->assertRedirect(route('login'));
    }

    $this->post(route('access-issue.store'), accessReport())->assertStatus(429);
});

test('no OAuth token, secret or raw Google subject is stored or logged anywhere', function () {
    expect(array_keys(get_object_vars(googleIdentity('a', 'b@c.d'))))->toBe(['sub', 'email', 'emailVerified']);
    foreach (Schema::getColumnListing('access_issue_reports') as $column) {
        expect($column)->not->toMatch('/token|secret|password|credential|google_subject|^sub$/i');
    }

    unknownInstitutionalLogin('RAW-SUB-DO-NOT-PERSIST', 'ghost@students.example.test');
    $this->post(route('access-issue.store'), accessReport());

    $everything = DB::table('access_issue_reports')->get()->toJson().DB::table('audit_logs')->get()->toJson();
    expect($everything)->not->toContain('RAW-SUB-DO-NOT-PERSIST');
    expect(AccessIssueReport::sole()->subject_fingerprint)->toBe(app(AuditLogger::class)->fingerprint('RAW-SUB-DO-NOT-PERSIST'));
});

test('the read-only admin view shows problem type, student id, email, system denial reason, description and submitted-at', function () {
    unknownInstitutionalLogin('s', 'ghost@students.example.test');
    $this->post(route('access-issue.store'), accessReport(['problem_type' => 'LATE_VERIFIED_COR', 'student_id' => '99-99999', 'description' => 'Please check my record.']));
    Auth::forgetGuards();
    $this->flushSession();

    $this->actingAs(makeAdminRoster()[0], 'admin')->get(route('admin.access-issues'))
        ->assertOk()
        ->assertSeeInOrder(['Submitted at', 'Problem type', 'Student ID', 'Institutional Google email', 'System denial reason', 'Description'])
        ->assertSee('My COR was verified late')       // human-readable label, not the raw key
        ->assertSee('99-99999')
        ->assertSee('ghost@students.example.test')
        ->assertSee('NO_STUDENT_RECORD')
        ->assertSee('Please check my record.')
        ->assertSee(AccessIssueReport::sole()->created_at->format('Y-m-d'));

    Auth::forgetGuards();
    $this->actingAs(Student::factory()->linked()->create(), 'student')->get(route('admin.access-issues'))->assertForbidden();
});

test('the admin view still renders a report whose problem type was later retired from the config', function () {
    unknownInstitutionalLogin();
    $this->post(route('access-issue.store'), accessReport(['problem_type' => 'LATE_VERIFIED_COR']));
    Auth::forgetGuards();
    config(['comelec.access_issue_problem_types' => ['OTHER' => 'Other']]);

    $this->actingAs(makeAdminRoster()[0], 'admin')->get(route('admin.access-issues'))->assertOk()->assertSee('LATE_VERIFIED_COR');
});

test('report text is escaped when an admin views it', function () {
    unknownInstitutionalLogin();
    $this->post(route('access-issue.store'), accessReport(['description' => '<script>alert(1)</script> hello']));
    Auth::forgetGuards();

    $this->actingAs(makeAdminRoster()[0], 'admin')->get(route('admin.access-issues'))
        ->assertDontSee('<script>alert(1)</script>', false)
        ->assertSee('&lt;script&gt;', false);
});
