<?php

use App\Models\AdminUser;
use App\Models\Student;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

function lastDenialReason(): ?string
{
    $row = DB::table('audit_logs')->where('event_type', 'auth.denied')->latest('id')->first();

    return $row ? json_decode($row->metadata_json, true)['reason'] : null;
}

test('a Google account that is both an admin and a student is denied in BOTH domains', function () {
    $admin = makeAdminRoster()[0];
    $student = Student::factory()->linked($admin->google_subject)->create(); // same subject in both tables

    googleLogin(googleIdentity($admin->google_subject, $student->institutional_email))
        ->assertRedirect(route('access-issue'))
        ->assertSessionHas('access_issue');

    $this->assertGuest('admin');
    $this->assertGuest('student');
    expect(lastDenialReason())->toBe('IDENTITY_CONFLICT');

    $row = DB::table('audit_logs')->where('event_type', 'auth.denied')->first();
    expect($row->severity)->toBe('SECURITY');
});

test('an admin whose email matches a student record is denied and does not link the student', function () {
    $admin = makeAdminRoster()[0];
    $student = Student::factory()->create();

    googleLogin(googleIdentity($admin->google_subject, $student->institutional_email))
        ->assertRedirect(route('access-issue'));

    $this->assertGuest('admin');
    $this->assertGuest('student');
    expect($student->fresh()->google_subject)->toBeNull();
    expect(lastDenialReason())->toBe('IDENTITY_CONFLICT');
});

test('there is no student-to-admin path: a linked student subject is never an admin', function () {
    makeAdminRoster();
    $student = Student::factory()->linked('sub-student-only')->create();

    googleLogin(googleIdentity('sub-student-only', $student->institutional_email))
        ->assertRedirect(route('student.home'));

    $this->assertGuest('admin');
    $this->get(route('admin.home'))->assertForbidden();
});

test('a subject already linked to student A cannot be used to log in as / link student B', function () {
    $a = Student::factory()->linked('sub-of-a')->create();
    $b = Student::factory()->create();

    googleLogin(googleIdentity('sub-of-a', $b->institutional_email))
        ->assertRedirect(route('access-issue'));

    $this->assertGuest('student');
    expect($b->fresh()->google_subject)->toBeNull()
        ->and($a->fresh()->google_subject)->toBe('sub-of-a');
    expect(lastDenialReason())->toBe('IDENTITY_MISMATCH');
});

test('a student already bound to one subject cannot be re-linked to another subject', function () {
    $a = Student::factory()->linked('original-sub')->create();

    googleLogin(googleIdentity('different-sub', $a->institutional_email))->assertRedirect(route('access-issue'));

    $this->assertGuest('student');
    expect($a->fresh()->google_subject)->toBe('original-sub');
    expect(lastDenialReason())->toBe('EMAIL_BOUND_TO_OTHER_SUBJECT');
});

test('ambiguous email matches are rejected instead of guessed', function () {
    if (dbIsCaseInsensitive()) {
        $this->markTestSkipped('The case-insensitive unique index already makes case-variant duplicates impossible on this engine.');
    }

    Student::factory()->create(['institutional_email' => 'dup@'.studentDomain()]);
    Student::factory()->create(['institutional_email' => 'DUP@'.studentDomain()]);

    googleLogin(googleIdentity('sub-dup', 'dup@'.studentDomain()))->assertRedirect(route('access-issue'));

    $this->assertGuest('student');
    expect(Student::whereNotNull('google_subject')->count())->toBe(0);
    expect(lastDenialReason())->toBe('AMBIGUOUS_EMAIL');
});

test('first link is atomic: if the student is linked by someone else mid-flight it is rejected, not overwritten', function () {
    $student = Student::factory()->create();
    $raced = false;

    DB::connection()->beforeExecuting(function (string $query) use ($student, &$raced) {
        if (! $raced && isUpdateOf($query, 'students')) {
            $raced = true;
            DB::table('students')->where('id', $student->id)->update(['google_subject' => 'winner-sub']);
        }
    });

    googleLogin(googleIdentity('loser-sub', $student->institutional_email))->assertRedirect(route('access-issue'));

    // The conditional UPDATE matched 0 rows, so login is denied and this login's subject is never applied.
    // (The simulated competing write shares the test's connection, so the denial rolls it back too.)
    expect($student->fresh()->google_subject)->not->toBe('loser-sub');
    $this->assertGuest('student');
    expect(lastDenialReason())->toBe('LINK_FAILED');
});

test('the unique index rejects one subject being linked to two students, and login fails closed', function () {
    $victim = Student::factory()->linked('shared-sub')->create();
    $student = Student::factory()->create();
    $raced = false;

    // The subject gets bound elsewhere between lookup and link; the DB unique index must hold.
    DB::table('students')->where('id', $victim->id)->update(['google_subject' => null]);
    DB::connection()->beforeExecuting(function (string $query) use ($victim, &$raced) {
        if (! $raced && isUpdateOf($query, 'students')) {
            $raced = true;
            DB::table('students')->where('id', $victim->id)->update(['google_subject' => 'shared-sub']);
        }
    });

    googleLogin(googleIdentity('shared-sub', $student->institutional_email))->assertRedirect(route('access-issue'));

    $this->assertGuest('student');
    expect($student->fresh()->google_subject)->toBeNull();
    expect(lastDenialReason())->toBe('LINK_FAILED');
});

test('the database itself refuses two students with the same Google subject and two admins with the same subject', function () {
    Student::factory()->linked('dup-sub')->create();
    expect(fn () => Student::factory()->linked('dup-sub')->create())->toThrow(QueryException::class);

    AdminUser::factory()->create(['google_subject' => 'dup-admin']);
    expect(fn () => AdminUser::factory()->create(['google_subject' => 'dup-admin']))->toThrow(QueryException::class);
});
