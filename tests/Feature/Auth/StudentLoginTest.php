<?php

use App\Models\Student;
use Illuminate\Support\Facades\DB;

test('a student logs in with Google and is first-linked by exact institutional email', function () {
    $student = Student::factory()->create();
    $before = $student->only(['institutional_id', 'institutional_email', 'first_name', 'middle_name', 'last_name', 'current_college', 'current_course', 'current_year_level', 'status']);

    googleLogin(googleIdentity('sub-student-1', $student->institutional_email))
        ->assertRedirect(route('student.home'));

    $this->assertAuthenticatedAs($student->fresh(), 'student');
    $this->assertGuest('admin');

    $fresh = $student->fresh();
    expect($fresh->google_subject)->toBe('sub-student-1');
    // Nothing from Google overwrites the student's official data.
    expect($fresh->only(array_keys($before)))->toEqual($before);
});

test('first login never creates a student record', function () {
    $student = Student::factory()->create();

    googleLogin(googleIdentity('sub-x', $student->institutional_email));

    expect(Student::count())->toBe(1);
});

test('repeat login is by the stable Google subject, not by email', function () {
    $student = Student::factory()->linked('sub-stable')->create();

    // Same subject, Google-side email changed but still on the institutional domain.
    googleLogin(googleIdentity('sub-stable', 'renamed@'.studentDomain()))
        ->assertRedirect(route('student.home'));

    $this->assertAuthenticatedAs($student, 'student');
    expect($student->fresh()->google_subject)->toBe('sub-stable');
});

test('email matching on first link is case-insensitive', function () {
    $student = Student::factory()->create(['institutional_email' => 'MixedCase.Student@'.studentDomain()]);

    googleLogin(googleIdentity('sub-case', 'mixedcase.student@'.studentDomain()))
        ->assertRedirect(route('student.home'));

    expect($student->fresh()->google_subject)->toBe('sub-case');
});

test('an INACTIVE student can authenticate: status is not a credential', function () {
    $student = Student::factory()->inactive()->create();

    googleLogin(googleIdentity('sub-inactive', $student->institutional_email))
        ->assertRedirect(route('student.home'));

    $this->assertAuthenticatedAs($student, 'student');
});

test('the student landing page renders for a logged-in student', function () {
    $student = Student::factory()->linked()->create();

    $this->actingAs($student, 'student')->get(route('student.home'))->assertOk();
});

test('every post-Google denial goes to the same Access Issue page and leaves the visitor a guest', function (string $case) {
    $student = Student::factory()->create();
    $linked = Student::factory()->linked('sub-already-bound')->create();
    $unimported = Student::factory()->unimported()->create();

    $identity = match ($case) {
        'unverified email' => googleIdentity('s1', $student->institutional_email, verified: false),
        'non-institutional domain' => googleIdentity('s2', 'someone@gmail.com'),
        'domain look-alike' => googleIdentity('s3', 'x@evil-'.studentDomain()),
        'unknown institutional account' => googleIdentity('s4', 'nobody@'.studentDomain()),
        'email bound to another subject' => googleIdentity('s5', $linked->institutional_email),
        'student row not loaded by the Data Center import' => googleIdentity('s7', $unimported->institutional_email),
    };

    googleLogin($identity)
        ->assertRedirect(route('access-issue'))
        ->assertSessionHas('access_issue')
        ->assertSessionMissing('login_error');

    $this->assertGuest('student');
    $this->assertGuest('admin');
    expect($student->fresh()->google_subject)->toBeNull()
        ->and($unimported->fresh()->google_subject)->toBeNull();
})->with([
    'unverified email', 'non-institutional domain', 'domain look-alike', 'unknown institutional account',
    'email bound to another subject', 'student row not loaded by the Data Center import',
]);

test('failures with no trustworthy Google identity go back to the login page with one generic message', function (string $sub, string $email) {
    googleLogin(googleIdentity($sub, $email))
        ->assertRedirect(route('login'))
        ->assertSessionHas('login_error', genericLoginError())
        ->assertSessionMissing('access_issue');

    $this->assertGuest('student');
})->with([
    'malformed email' => ['s6', 'not-an-email'],
    'empty subject' => ['', 'x@students.example.test'],
]);

test('a student who is not in Data Center-loaded records cannot log in even if the email matches', function () {
    $student = Student::factory()->unimported()->create();

    googleLogin(googleIdentity('sub-manual', $student->institutional_email))->assertRedirect(route('access-issue'));

    $this->assertGuest('student');
    expect($student->fresh()->google_subject)->toBeNull();
    $row = DB::table('audit_logs')->where('event_type', 'auth.denied')->first();
    expect(json_decode($row->metadata_json, true)['reason'])->toBe('NOT_DATA_CENTER_LOADED');
});

test('a linked student whose record is no longer Data Center-loaded is denied', function () {
    $student = Student::factory()->linked('sub-was-fine')->create();
    $student->forceFill(['last_import_batch_id' => null])->save();

    googleLogin(googleIdentity('sub-was-fine', $student->institutional_email))->assertRedirect(route('access-issue'));

    $this->assertGuest('student');
});

test('the institutional domain is configuration, and an unset domain fails closed', function () {
    $student = Student::factory()->create();

    config(['comelec.student_email_domain' => null]);
    googleLogin(googleIdentity('sub-a', $student->institutional_email))->assertRedirect(route('access-issue'));
    $this->assertGuest('student');

    config(['comelec.student_email_domain' => '']);
    googleLogin(googleIdentity('sub-a', $student->institutional_email))->assertRedirect(route('access-issue'));
    $this->assertGuest('student');

    config(['comelec.student_email_domain' => 'other.example.test']);
    googleLogin(googleIdentity('sub-a', $student->institutional_email))->assertRedirect(route('access-issue'));
    $this->assertGuest('student');
    expect($student->fresh()->google_subject)->toBeNull();
});

test('a Google provider failure (bad state, network, etc.) fails closed', function () {
    fakeGoogle(failure: new RuntimeException('invalid state'));

    $this->get(route('auth.google.callback'))
        ->assertRedirect(route('login'))
        ->assertSessionHas('login_error', genericLoginError());

    $this->assertGuest('student');
    $this->assertGuest('admin');
});

test('audit records login and first link without raw Google subject or email', function () {
    $student = Student::factory()->create();

    googleLogin(googleIdentity('raw-sub-should-not-be-logged', $student->institutional_email));

    $events = DB::table('audit_logs')->pluck('event_type')->all();
    expect($events)->toContain('auth.student.first_link', 'auth.student.login');

    $dump = DB::table('audit_logs')->get()->toJson();
    expect($dump)->not->toContain('raw-sub-should-not-be-logged')
        ->and($dump)->not->toContain($student->institutional_email);

    $login = DB::table('audit_logs')->where('event_type', 'auth.student.login')->first();
    expect($login->actor_type)->toBe('STUDENT')->and($login->actor_id)->toBe((string) $student->id);
});

test('a denied login is audited with a subject fingerprint, never the raw subject or the email', function () {
    googleLogin(googleIdentity('raw-unknown-sub', 'nobody@'.studentDomain()));

    $row = DB::table('audit_logs')->where('event_type', 'auth.denied')->first();
    expect($row)->not->toBeNull();

    $metadata = json_decode($row->metadata_json, true);
    expect($metadata['reason'])->toBe('NO_STUDENT_RECORD')
        ->and($metadata['subject_fingerprint'])->toHaveLength(16);
    expect(json_encode($row))->not->toContain('raw-unknown-sub')->not->toContain('nobody@');
});
