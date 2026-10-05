<?php

use App\Models\AdminUser;
use App\Models\Student;
use Illuminate\Support\Facades\DB;

function threeAdmins(array $override = []): array
{
    return array_replace([
        ['authorized_email' => 'one@admins.example.test', 'display_name' => 'Admin One'],
        ['authorized_email' => 'two@admins.example.test', 'display_name' => 'Admin Two'],
        ['authorized_email' => 'three@admins.example.test', 'display_name' => 'Admin Three'],
    ], $override);
}

test('provisions the configured authorized emails as pre-authorized admins with a NULL Google subject', function () {
    config(['comelec.admin_identities' => threeAdmins()]);

    $this->artisan('comelec:provision-admins')->assertSuccessful();

    expect(AdminUser::count())->toBe(3)
        ->and(AdminUser::pluck('role')->unique()->all())->toBe(['BUKSU_COMELEC_IT_ADMIN'])
        ->and(AdminUser::whereNotNull('google_subject')->count())->toBe(0)
        ->and(AdminUser::orderBy('id')->pluck('authorized_email')->all())->toBe(['one@admins.example.test', 'two@admins.example.test', 'three@admins.example.test']);
    expect(DB::table('audit_logs')->where('event_type', 'admin.provisioned')->count())->toBe(3);
    expect(DB::table('audit_logs')->get()->toJson())->not->toContain('admins.example.test');
});

test('emails are trimmed and lowercased before they are stored', function () {
    config(['comelec.admin_identities' => threeAdmins([0 => ['authorized_email' => '  One@Admins.Example.TEST ', 'display_name' => '  Admin One ']])]);

    $this->artisan('comelec:provision-admins')->assertSuccessful();

    expect(AdminUser::orderBy('id')->first()->only(['authorized_email', 'display_name']))
        ->toBe(['authorized_email' => 'one@admins.example.test', 'display_name' => 'Admin One']);
});

test('is idempotent: a second run changes nothing', function () {
    config(['comelec.admin_identities' => threeAdmins()]);
    $this->artisan('comelec:provision-admins')->assertSuccessful();
    $snapshot = AdminUser::orderBy('id')->get()->toJson();

    $this->artisan('comelec:provision-admins')->assertSuccessful();

    expect(AdminUser::orderBy('id')->get()->toJson())->toBe($snapshot);
    expect(DB::table('audit_logs')->where('event_type', 'admin.provisioned')->count())->toBe(3);
});

test('refuses a configuration that does not match the configured operational roster size', function (mixed $config) {
    config(['comelec.admin_identities' => $config]);

    $this->artisan('comelec:provision-admins')->assertFailed();

    expect(AdminUser::count())->toBe(0);
})->with([
    'none' => [[]],
    'two' => [fn () => array_slice(threeAdmins(), 0, 2)],
    'four' => [fn () => array_merge(threeAdmins(), [['authorized_email' => 'four@admins.example.test', 'display_name' => 'Four']])],
    'not a list' => ['nonsense'],
]);

test('refuses duplicate (including case-variant), malformed and incomplete entries', function (array $config) {
    config(['comelec.admin_identities' => $config]);

    $this->artisan('comelec:provision-admins')->assertFailed();

    expect(AdminUser::count())->toBe(0);
})->with([
    'duplicate email' => [fn () => threeAdmins([2 => ['authorized_email' => 'one@admins.example.test', 'display_name' => 'Dup']])],
    'case-variant duplicate' => [fn () => threeAdmins([2 => ['authorized_email' => 'ONE@Admins.Example.Test', 'display_name' => 'Dup']])],
    'malformed email' => [fn () => threeAdmins([1 => ['authorized_email' => 'not-an-email', 'display_name' => 'Bad']])],
    'email without domain' => [fn () => threeAdmins([1 => ['authorized_email' => 'someone@', 'display_name' => 'Bad']])],
    'blank email' => [fn () => threeAdmins([1 => ['authorized_email' => '  ', 'display_name' => 'Blank']])],
    'blank name' => [fn () => threeAdmins([0 => ['authorized_email' => 'ok@admins.example.test', 'display_name' => '']])],
    'missing email key' => [fn () => threeAdmins([1 => ['display_name' => 'x']])],
    'non-string email' => [fn () => threeAdmins([1 => ['authorized_email' => ['a@b.test'], 'display_name' => 'x']])],
]);

test('a configured Google subject is rejected: subjects are bound at first login, never provisioned', function () {
    config(['comelec.admin_identities' => threeAdmins([1 => ['authorized_email' => 'two@admins.example.test', 'display_name' => 'Two', 'google_subject' => '222']])]);

    $this->artisan('comelec:provision-admins')->assertFailed();

    expect(AdminUser::count())->toBe(0);
});

test('an existing Google binding is preserved: provisioning never replaces or clears a subject', function () {
    AdminUser::factory()->linked('already-bound-sub')->create(['authorized_email' => 'one@admins.example.test', 'display_name' => 'Admin One']);
    config(['comelec.admin_identities' => threeAdmins()]);

    $this->artisan('comelec:provision-admins')->assertSuccessful();

    expect(AdminUser::count())->toBe(3);
    expect(AdminUser::where('authorized_email', 'one@admins.example.test')->value('google_subject'))->toBe('already-bound-sub');
    expect(AdminUser::whereNotNull('google_subject')->count())->toBe(1);
});

test('never deletes or replaces an existing admin that is not in the configured set', function () {
    $stranger = AdminUser::factory()->linked('stranger-sub')->create(['authorized_email' => 'stranger@admins.example.test']);
    config(['comelec.admin_identities' => threeAdmins()]);

    $this->artisan('comelec:provision-admins')->assertFailed();

    expect(AdminUser::count())->toBe(1)
        ->and($stranger->fresh()->google_subject)->toBe('stranger-sub')
        ->and($stranger->fresh()->authorized_email)->toBe('stranger@admins.example.test');
});

test('completes a partially provisioned roster additively and never silently renames', function () {
    $existing = AdminUser::factory()->create(['authorized_email' => 'one@admins.example.test', 'display_name' => 'Old Name']);
    config(['comelec.admin_identities' => threeAdmins()]);

    $this->artisan('comelec:provision-admins')->assertSuccessful();

    expect(AdminUser::count())->toBe(3)->and($existing->fresh()->display_name)->toBe('Old Name');
    expect(DB::table('audit_logs')->where('event_type', 'admin.provisioned')->count())->toBe(2);
});

test('the roster size is configuration: a configured size of four provisions four', function () {
    config(['comelec.admin_roster_size' => 4, 'comelec.admin_identities' => array_merge(threeAdmins(), [['authorized_email' => 'four@admins.example.test', 'display_name' => 'Four']])]);

    $this->artisan('comelec:provision-admins')->assertSuccessful();

    expect(AdminUser::count())->toBe(4);
});

test('provisioning is independent of students and the Data Center import: it neither reads nor changes them', function () {
    $student = Student::factory()->unimported()->create(['institutional_email' => 'one@admins.example.test']); // even a colliding email
    $before = DB::table('students')->get()->toJson().DB::table('import_batches')->get()->toJson();
    config(['comelec.admin_identities' => threeAdmins()]);

    $this->artisan('comelec:provision-admins')->assertSuccessful();

    expect(DB::table('students')->get()->toJson().DB::table('import_batches')->get()->toJson())->toBe($before);
    expect($student->fresh()->google_subject)->toBeNull();
});
