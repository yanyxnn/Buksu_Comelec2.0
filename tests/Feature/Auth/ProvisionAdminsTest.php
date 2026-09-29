<?php

use App\Models\AdminUser;
use App\Models\Student;
use Illuminate\Support\Facades\DB;

function three(array $override = []): array
{
    return array_replace([
        ['google_subject' => '111111111111111111111', 'display_name' => 'Admin One'],
        ['google_subject' => '222222222222222222222', 'display_name' => 'Admin Two'],
        ['google_subject' => '333333333333333333333', 'display_name' => 'Admin Three'],
    ], $override);
}

test('provisions exactly three admins with the fixed role and audits it', function () {
    config(['comelec.admin_identities' => three()]);

    $this->artisan('comelec:provision-admins')->assertSuccessful();

    expect(AdminUser::count())->toBe(3)
        ->and(AdminUser::pluck('role')->unique()->all())->toBe(['BUKSU_COMELEC_IT_ADMIN']);
    expect(DB::table('audit_logs')->where('event_type', 'admin.provisioned')->count())->toBe(3);
    expect(DB::table('audit_logs')->get()->toJson())->not->toContain('111111111111111111111');
});

test('is idempotent: a second run changes nothing', function () {
    config(['comelec.admin_identities' => three()]);
    $this->artisan('comelec:provision-admins')->assertSuccessful();
    $ids = AdminUser::orderBy('id')->pluck('id')->all();

    $this->artisan('comelec:provision-admins')->assertSuccessful();

    expect(AdminUser::orderBy('id')->pluck('id')->all())->toBe($ids);
    expect(DB::table('audit_logs')->where('event_type', 'admin.provisioned')->count())->toBe(3);
});

test('refuses anything other than exactly three configured identities', function (mixed $config) {
    config(['comelec.admin_identities' => $config]);

    $this->artisan('comelec:provision-admins')->assertFailed();

    expect(AdminUser::count())->toBe(0);
})->with([
    'none' => [[]],
    'two' => [fn () => array_slice(three(), 0, 2)],
    'four' => [fn () => array_merge(three(), [['google_subject' => '4444', 'display_name' => 'Four']])],
    'not a list' => ['nonsense'],
]);

test('refuses duplicate subjects, blank subjects and blank names', function (array $config) {
    config(['comelec.admin_identities' => $config]);

    $this->artisan('comelec:provision-admins')->assertFailed();

    expect(AdminUser::count())->toBe(0);
})->with([
    'duplicate subject' => [fn () => three([2 => ['google_subject' => '111111111111111111111', 'display_name' => 'Dup']])],
    'blank subject' => [fn () => three([1 => ['google_subject' => '  ', 'display_name' => 'Blank']])],
    'blank name' => [fn () => three([0 => ['google_subject' => '999', 'display_name' => '']])],
    'missing keys' => [fn () => three([1 => ['name' => 'x']])],
]);

test('refuses a subject that is already linked to a student and writes nothing', function () {
    Student::factory()->unimported()->linked('222222222222222222222')->create();
    config(['comelec.admin_identities' => three()]);

    $this->artisan('comelec:provision-admins')->assertFailed();

    expect(AdminUser::count())->toBe(0);
});

test('never deletes or replaces an existing admin that is not in the configured set', function () {
    $stranger = AdminUser::factory()->create(['google_subject' => 'existing-stranger', 'display_name' => 'Stranger']);
    config(['comelec.admin_identities' => three()]);

    $this->artisan('comelec:provision-admins')->assertFailed();

    expect(AdminUser::count())->toBe(1)
        ->and($stranger->fresh()->google_subject)->toBe('existing-stranger');
});

test('completes a partially provisioned roster additively and never silently renames', function () {
    $existing = AdminUser::factory()->create(['google_subject' => '111111111111111111111', 'display_name' => 'Old Name']);
    config(['comelec.admin_identities' => three()]);

    $this->artisan('comelec:provision-admins')->assertSuccessful();

    expect(AdminUser::count())->toBe(3)
        ->and($existing->fresh()->display_name)->toBe('Old Name'); // reported, not overwritten
    expect(DB::table('audit_logs')->where('event_type', 'admin.provisioned')->count())->toBe(2);
});
