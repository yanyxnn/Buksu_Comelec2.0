<?php

use App\Models\Student;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

test('login regenerates the session id (session fixation defence)', function () {
    $student = Student::factory()->create();
    $attackerKnownId = Str::random(40);

    // The request arrives carrying a session id chosen before login.
    $this->withCookie(config('session.cookie'), $attackerKnownId);
    expect(session()->getId())->not->toBe($attackerKnownId);

    googleLogin(googleIdentity('sub-fix', $student->institutional_email))->assertRedirect(route('student.home'));

    expect(session()->getId())->not->toBe($attackerKnownId);
    $this->assertAuthenticatedAs($student, 'student');
});

test('login flushes pre-existing session data and rotates the CSRF token', function () {
    $student = Student::factory()->create();
    $this->withSession(['planted' => 'value', '_token' => 'old-token']);

    googleLogin(googleIdentity('sub-flush', $student->institutional_email));

    expect(session('planted'))->toBeNull()
        ->and(session()->token())->not->toBe('old-token');
});

test('logout clears the identity, invalidates the session and is POST only', function () {
    $student = Student::factory()->linked('sub-out')->create();
    $this->actingAs($student, 'student')->withSession(['keep' => 'x']);

    $this->get('/logout')->assertStatus(405);
    $this->assertAuthenticated('student');

    $this->post(route('logout'))->assertRedirect('/');

    $this->assertGuest('student');
    $this->assertGuest('admin');
    expect(session('keep'))->toBeNull();
});

test('admin logout ends the admin session and is audited', function () {
    $admin = makeAdminRoster()[0];
    $this->actingAs($admin, 'admin');

    $this->post(route('logout'))->assertRedirect('/');

    $this->assertGuest('admin');
    $row = DB::table('audit_logs')->where('event_type', 'auth.logout')->first();
    expect($row->actor_type)->toBe('ADMIN')->and($row->actor_id)->toBe((string) $admin->id);
});

test('after logout the protected areas require login again', function () {
    $admin = makeAdminRoster()[0];
    googleLogin(adminGoogleIdentity($admin));
    $this->get(route('admin.home'))->assertOk();

    $this->post(route('logout'));
    Auth::forgetGuards();

    $this->get(route('admin.home'))->assertRedirect(route('login'));
});

test('a successful Google login as a student REPLACES an existing admin session', function () {
    $admin = makeAdminRoster()[0];
    $student = Student::factory()->create();

    googleLogin(adminGoogleIdentity($admin))->assertRedirect(route('admin.home'));
    $adminSessionId = session()->getId();

    googleLogin(googleIdentity('sub-s', $student->institutional_email))->assertRedirect(route('student.home'));
    Auth::forgetGuards(); // force both guards to be re-read from the session itself

    $this->assertAuthenticatedAs($student, 'student');
    $this->assertGuest('admin');
    expect(session()->getId())->not->toBe($adminSessionId);
});

test('a successful Google login as an admin REPLACES an existing student session', function () {
    $admin = makeAdminRoster()[0];
    $student = Student::factory()->create();

    googleLogin(googleIdentity('sub-s', $student->institutional_email))->assertRedirect(route('student.home'));
    googleLogin(adminGoogleIdentity($admin))->assertRedirect(route('admin.home'));
    Auth::forgetGuards();

    $this->assertAuthenticatedAs($admin, 'admin');
    $this->assertGuest('student');
});

test('a DENIED second login does not disturb the identity already in the session', function () {
    $admin = makeAdminRoster()[0];

    googleLogin(adminGoogleIdentity($admin));
    googleLogin(googleIdentity('stranger', 'nobody@'.studentDomain()))->assertRedirect(route('access-issue'));
    Auth::forgetGuards();

    $this->assertAuthenticatedAs($admin, 'admin');
});

test('logging in as a student after an admin logout leaves no admin identity behind', function () {
    $admin = makeAdminRoster()[0];
    $student = Student::factory()->create();

    googleLogin(adminGoogleIdentity($admin));
    $this->post(route('logout'));
    Auth::forgetGuards();

    googleLogin(googleIdentity('sub-s', $student->institutional_email))->assertRedirect(route('student.home'));

    $this->assertAuthenticatedAs($student, 'student');
    $this->assertGuest('admin');
});

test('a session that somehow holds BOTH identities is rejected and fully cleared', function () {
    $admin = makeAdminRoster()[0];
    $student = Student::factory()->linked()->create();

    $this->actingAs($admin, 'admin')->actingAs($student, 'student');

    $this->get(route('admin.home'))->assertForbidden();

    $this->assertGuest('admin');
    $this->assertGuest('student');
    expect(DB::table('audit_logs')->where('event_type', 'auth.context_violation')->where('severity', 'SECURITY')->exists())->toBeTrue();
});

test('the same mixed session is rejected from the student side too', function () {
    $admin = makeAdminRoster()[0];
    $student = Student::factory()->linked()->create();

    $this->actingAs($student, 'student')->actingAs($admin, 'admin');

    $this->get(route('student.home'))->assertForbidden();

    $this->assertGuest('admin');
    $this->assertGuest('student');
});
