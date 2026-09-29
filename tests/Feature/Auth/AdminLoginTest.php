<?php

use App\Models\AdminUser;
use App\Models\Student;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

test('a provisioned admin logs in with Google and lands in the admin area', function () {
    $admins = makeAdminRoster();
    $admin = $admins[0];

    googleLogin(googleIdentity($admin->google_subject, 'personal.admin@gmail.com'))
        ->assertRedirect(route('admin.home'));

    $this->assertAuthenticatedAs($admin, 'admin');
    $this->assertGuest('student');

    $this->get(route('admin.home'))->assertOk();
});

test('admin login is by subject: the email plays no part', function () {
    $admin = makeAdminRoster()[1];

    googleLogin(googleIdentity($admin->google_subject, 'anything@anywhere.example'))
        ->assertRedirect(route('admin.home'));
    $this->assertAuthenticatedAs($admin, 'admin');
});

test('an unauthorized Google account cannot become an admin', function () {
    makeAdminRoster();

    googleLogin(googleIdentity('not-an-admin-sub', 'attacker@gmail.com'))
        ->assertRedirect(route('access-issue'))
        ->assertSessionHas('access_issue');

    $this->assertGuest('admin');
    $this->assertGuest('student');
    expect(AdminUser::count())->toBe(3);
});

test('an admin whose Google email is unverified is rejected', function () {
    $admin = makeAdminRoster()[0];

    googleLogin(googleIdentity($admin->google_subject, 'a@gmail.com', verified: false))
        ->assertRedirect(route('access-issue'));

    $this->assertGuest('admin');
});

test('admin login fails closed unless the roster is exactly three admins', function (int $count) {
    $admins = AdminUser::factory()->count($count)->create();

    googleLogin(googleIdentity($admins[0]->google_subject, 'a@gmail.com'))
        ->assertRedirect(route('access-issue'))
        ->assertSessionHas('access_issue');

    $this->assertGuest('admin');

    $row = DB::table('audit_logs')->where('event_type', 'auth.denied')->first();
    expect(json_decode($row->metadata_json, true)['reason'])->toBe('ADMIN_ROSTER_INVALID');
})->with([1, 2, 4]);

test('admin login fails closed if an admin row carries a role other than the fixed role', function () {
    $admins = makeAdminRoster();
    $tamper = fn () => DB::table('admin_users')->where('id', $admins[2]->id)->update(['role' => 'SUPER_ADMIN']);

    if (dbEnforcesChecks()) {
        // MySQL/MariaDB: the CHECK constraint refuses the tampering outright.
        expect($tamper)->toThrow(QueryException::class);

        return;
    }

    // SQLite has no such CHECK, so the application-level guard must catch it.
    $tamper();

    googleLogin(googleIdentity($admins[0]->google_subject, 'a@gmail.com'))->assertRedirect(route('access-issue'));

    $this->assertGuest('admin');
});

test('all three admins share the one fixed role and there is no other role', function () {
    $admins = makeAdminRoster();

    expect($admins->pluck('role')->unique()->all())->toBe(['BUKSU_COMELEC_IT_ADMIN']);
    expect(AdminUser::ROLE)->toBe('BUKSU_COMELEC_IT_ADMIN');
});

test('admin and student subject/role cannot be mass assigned', function () {
    $admin = AdminUser::factory()->create();
    $admin->fill(['display_name' => 'New Name', 'role' => 'SUPER_ADMIN', 'google_subject' => 'hijack']);

    expect($admin->role)->toBe('BUKSU_COMELEC_IT_ADMIN')
        ->and($admin->google_subject)->not->toBe('hijack');

    $student = Student::factory()->create();
    $student->fill(['institutional_email' => 'changed@x.test', 'google_subject' => 'hijack']);

    expect($student->institutional_email)->not->toBe('changed@x.test')
        ->and($student->google_subject)->toBeNull();
});

test('admin login and denial are audited with the actual admin identified', function () {
    $admin = makeAdminRoster()[0];

    googleLogin(googleIdentity($admin->google_subject, 'a@gmail.com'));

    $row = DB::table('audit_logs')->where('event_type', 'auth.admin.login')->first();
    expect($row->actor_type)->toBe('ADMIN')->and($row->actor_id)->toBe((string) $admin->id);
    expect(json_encode(DB::table('audit_logs')->get()))->not->toContain($admin->google_subject);
});
