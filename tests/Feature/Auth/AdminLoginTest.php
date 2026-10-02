<?php

use App\Models\AdminUser;
use App\Models\Student;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Administrator lifecycle: pre-authorized email -> verified Google login ->
| atomic first-link of the stable `sub` -> later logins by the bound `sub`.
| Google login NEVER creates an administrator.
|--------------------------------------------------------------------------
*/

/* ------------------------------- first login ------------------------------- */

test('a pre-authorized admin with a NULL subject logs in on first verified Google login and the sub is bound', function () {
    $admin = makeAdminRoster()[0];
    expect($admin->google_subject)->toBeNull();

    googleLogin(googleIdentity('first-login-sub', $admin->authorized_email))->assertRedirect(route('admin.home'));

    $this->assertAuthenticatedAs($admin->fresh(), 'admin');
    $this->assertGuest('student');
    expect($admin->fresh()->google_subject)->toBe('first-login-sub');
    $this->get(route('admin.home'))->assertOk();
});

test('the email match on first login is case-insensitive and only the bound subject changes', function () {
    $admin = makeAdminRoster()[0];
    $before = $admin->only(['authorized_email', 'display_name', 'role']);

    googleLogin(googleIdentity('sub-case', strtoupper($admin->authorized_email)))->assertRedirect(route('admin.home'));

    $fresh = $admin->fresh();
    expect($fresh->google_subject)->toBe('sub-case')
        ->and($fresh->only(['authorized_email', 'display_name', 'role']))->toEqual($before);
});

test('first login is audited as first_link + login without email or subject in the trail', function () {
    $admin = makeAdminRoster()[0];

    googleLogin(googleIdentity('audited-sub', $admin->authorized_email));

    expect(DB::table('audit_logs')->orderBy('id')->pluck('event_type')->all())->toBe(['auth.admin.first_link', 'auth.admin.login']);
    $row = DB::table('audit_logs')->where('event_type', 'auth.admin.first_link')->first();
    expect($row->actor_type)->toBe('ADMIN')->and($row->actor_id)->toBe((string) $admin->id)->and($row->target_type)->toBe('admin_user');

    $dump = DB::table('audit_logs')->get()->toJson();
    expect($dump)->not->toContain('audited-sub')->not->toContain($admin->authorized_email);
});

test('an unverified Google email is denied and binds nothing', function () {
    $admin = makeAdminRoster()[0];

    googleLogin(googleIdentity('s-unverified', $admin->authorized_email, verified: false))->assertRedirect(route('access-issue'));

    $this->assertGuest('admin');
    expect($admin->fresh()->google_subject)->toBeNull();
    expect(lastDenialReason())->toBe('EMAIL_NOT_VERIFIED');
});

test('an unknown email is denied and Google login never creates an administrator', function () {
    makeAdminRoster();

    googleLogin(googleIdentity('stranger-sub', 'attacker@gmail.com'))
        ->assertRedirect(route('access-issue'))
        ->assertSessionHas('access_issue');

    $this->assertGuest('admin');
    $this->assertGuest('student');
    expect(AdminUser::count())->toBe(3);
    expect(AdminUser::whereNotNull('google_subject')->count())->toBe(0);
});

test('the wrong email (another person) cannot claim an admin record', function () {
    $admins = makeAdminRoster();

    googleLogin(googleIdentity('sub-x', 'not.'.$admins[0]->authorized_email))->assertRedirect(route('access-issue'));

    $this->assertGuest('admin');
    expect(AdminUser::whereNotNull('google_subject')->count())->toBe(0);
});

test('a malformed Google identity is denied with the generic login error and binds nothing', function (string $sub, string $email) {
    $admin = makeAdminRoster()[0];
    $email = $email === 'ADMIN' ? $admin->authorized_email : $email;

    googleLogin(googleIdentity($sub, $email))->assertRedirect(route('login'))->assertSessionHas('login_error', genericLoginError());

    $this->assertGuest('admin');
    expect($admin->fresh()->google_subject)->toBeNull();
})->with([
    'empty subject' => ['', 'ADMIN'],
    'blank subject' => ['   ', 'ADMIN'],
    'malformed email' => ['sub-1', 'not-an-email'],
    'empty email' => ['sub-1', ''],
]);

test('every admin denial goes to the same Access Issue page and reveals nothing', function () {
    $admins = makeAdminRoster();
    $admins[1]->forceFill(['google_subject' => 'other-bound-sub'])->save();

    $reasons = [];
    foreach ([
        googleIdentity('s1', 'nobody@gmail.com'),                          // unknown
        googleIdentity('s2', $admins[0]->authorized_email, verified: false), // unverified
        googleIdentity('s3', $admins[1]->authorized_email),                // bound to another subject
    ] as $identity) {
        googleLogin($identity)->assertRedirect(route('access-issue'))->assertSessionMissing('login_error');
        $reasons[] = lastDenialReason();
        $this->assertGuest('admin');
        $this->flushSession();
    }

    expect($reasons)->toBe(['DOMAIN_NOT_ALLOWED', 'EMAIL_NOT_VERIFIED', 'ADMIN_EMAIL_BOUND_TO_OTHER_SUBJECT']);
});

/* --------------------- first-link is conditional and safe --------------------- */

test('first-link race: a competing binding wins once, the other identity is denied and never overwrites it', function () {
    $admin = makeAdminRoster()[0];
    $raced = false;

    DB::connection()->beforeExecuting(function (string $query) use ($admin, &$raced) {
        if (! $raced && isUpdateOf($query, 'admin_users')) {
            $raced = true;
            // another identity wins the binding between our lookup and our conditional UPDATE
            DB::table('admin_users')->where('id', $admin->id)->update(['google_subject' => 'winner-sub']);
        }
    });

    googleLogin(googleIdentity('loser-sub', $admin->authorized_email))->assertRedirect(route('access-issue'));

    $this->assertGuest('admin');
    expect(lastDenialReason())->toBe('LINK_FAILED');
    expect($admin->fresh()->google_subject)->not->toBe('loser-sub');
});

test('the first-link UPDATE is conditional on google_subject IS NULL', function () {
    $admin = makeAdminRoster()[0];
    $statement = null;

    DB::connection()->beforeExecuting(function (string $query) use (&$statement) {
        if ($statement === null && isUpdateOf($query, 'admin_users')) {
            $statement = strtolower($query);
        }
    });

    googleLogin(googleIdentity('cond-sub', $admin->authorized_email));

    expect($statement)->toContain('google_subject')->and($statement)->toMatch('/google_subject[`"]? is null/');
});

test('a twin request from the SAME Google account that bound the subject a moment earlier is admitted', function () {
    $admin = makeAdminRoster()[0];
    $twinBound = false;

    // The twin commits its binding after our subject lookup but before our email lookup.
    DB::connection()->beforeExecuting(function (string $query) use ($admin, &$twinBound) {
        if (! $twinBound && preg_match('/^select .*lower\(authorized_email\)/is', $query)) {
            $twinBound = true;
            DB::table('admin_users')->where('id', $admin->id)->update(['google_subject' => 'same-sub']);
        }
    });

    googleLogin(googleIdentity('same-sub', $admin->authorized_email))->assertRedirect(route('admin.home'));

    $this->assertAuthenticatedAs($admin->fresh(), 'admin');
    expect($admin->fresh()->google_subject)->toBe('same-sub');
});

test('a Google subject already used by a student can never be bound to an admin', function () {
    $admin = makeAdminRoster()[0];
    $student = Student::factory()->linked('student-owned-sub')->create();

    googleLogin(googleIdentity('student-owned-sub', $admin->authorized_email))->assertRedirect(route('access-issue'));

    $this->assertGuest('admin');
    $this->assertGuest('student');
    expect($admin->fresh()->google_subject)->toBeNull()->and($student->fresh()->google_subject)->toBe('student-owned-sub');
    expect(lastDenialReason())->toBe('IDENTITY_CONFLICT');
});

test('a subject already bound to another admin authenticates THAT admin and never rebinds anyone', function () {
    [$a, $b] = makeAdminRoster();
    $a->forceFill(['google_subject' => 'a-sub'])->save();

    googleLogin(googleIdentity('a-sub', $b->authorized_email))->assertRedirect(route('admin.home'));
    Auth::forgetGuards();

    $this->assertAuthenticatedAs($a->fresh(), 'admin'); // the bound subject is authoritative
    expect($b->fresh()->google_subject)->toBeNull();    // b was NOT linked by email
});

/* ------------------------------- repeat login ------------------------------- */

test('an admin with a bound subject logs in by that subject, whatever email Google now reports', function () {
    $admin = makeAdminRoster(linked: true)[0];

    googleLogin(googleIdentity($admin->google_subject, 'renamed.account@gmail.com'))->assertRedirect(route('admin.home'));

    $this->assertAuthenticatedAs($admin, 'admin');
    expect($admin->fresh()->google_subject)->toBe($admin->google_subject);
});

test('an alternate subject presenting the same authorized email is denied and the original binding is unchanged', function () {
    $admin = makeAdminRoster(linked: true)[0];
    $original = $admin->google_subject;

    googleLogin(googleIdentity('impostor-sub', $admin->authorized_email))->assertRedirect(route('access-issue'));

    $this->assertGuest('admin');
    expect($admin->fresh()->google_subject)->toBe($original);
    expect(lastDenialReason())->toBe('ADMIN_EMAIL_BOUND_TO_OTHER_SUBJECT');
    expect(DB::table('audit_logs')->where('event_type', 'auth.denied')->value('severity'))->toBe('SECURITY');
});

test('there is no automatic re-linking: after a denied impostor the original subject still works and nothing changed', function () {
    $admin = makeAdminRoster(linked: true)[0];
    $original = $admin->google_subject;

    googleLogin(googleIdentity('impostor-sub', $admin->authorized_email));
    googleLogin(googleIdentity('impostor-sub', $admin->authorized_email)); // trying again changes nothing
    expect($admin->fresh()->google_subject)->toBe($original);

    googleLogin(googleIdentity($original, $admin->authorized_email))->assertRedirect(route('admin.home'));
    $this->assertAuthenticatedAs($admin, 'admin');
});

/* ----------------------- roster integrity (operational) ----------------------- */

test('admin login fails closed unless the roster matches the configured operational size, and first login binds nothing', function (int $count) {
    $admins = AdminUser::factory()->count($count)->create();

    googleLogin(googleIdentity('roster-sub', $admins[0]->authorized_email))->assertRedirect(route('access-issue'));

    $this->assertGuest('admin');
    expect(lastDenialReason())->toBe('ADMIN_ROSTER_INVALID');
    expect(AdminUser::whereNotNull('google_subject')->count())->toBe(0);
})->with([1, 2, 4]);

test('a bound admin is also denied while the roster is not intact', function () {
    $admins = AdminUser::factory()->count(2)->linked()->create();

    googleLogin(googleIdentity($admins[0]->google_subject, $admins[0]->authorized_email))->assertRedirect(route('access-issue'));

    $this->assertGuest('admin');
    expect(lastDenialReason())->toBe('ADMIN_ROSTER_INVALID');
});

test('admin login fails closed if an admin row carries a role other than the fixed role', function () {
    $admins = makeAdminRoster();
    $tamper = fn () => DB::table('admin_users')->where('id', $admins[2]->id)->update(['role' => 'SUPER_ADMIN']);

    if (dbEnforcesChecks()) {
        expect($tamper)->toThrow(QueryException::class); // MySQL/MariaDB CHECK refuses it

        return;
    }

    $tamper();
    googleLogin(googleIdentity('s', $admins[0]->authorized_email))->assertRedirect(route('access-issue'));

    $this->assertGuest('admin');
});

test('all admins share the one fixed role and there is no other role', function () {
    $admins = makeAdminRoster();

    expect($admins->pluck('role')->unique()->all())->toBe(['BUKSU_COMELEC_IT_ADMIN']);
    expect(AdminUser::ROLE)->toBe('BUKSU_COMELEC_IT_ADMIN');
});

test('authorized_email, google_subject and role cannot be mass assigned', function () {
    $admin = AdminUser::factory()->linked('bound')->create();
    $email = $admin->authorized_email;

    $admin->fill(['display_name' => 'New Name', 'role' => 'SUPER_ADMIN', 'google_subject' => 'hijack', 'authorized_email' => 'hijack@x.test']);

    expect($admin->display_name)->toBe('New Name')
        ->and($admin->role)->toBe('BUKSU_COMELEC_IT_ADMIN')
        ->and($admin->google_subject)->toBe('bound')
        ->and($admin->authorized_email)->toBe($email);
});
