<?php

use App\Models\AdminUser;
use App\Services\Auth\AdminRoster;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function insertAdminRow(array $row): void
{
    DB::table('admin_users')->insert($row + [
        'authorized_email' => 'row'.uniqid().'@admins.example.test',
        'google_subject' => null,
        'display_name' => 'Row',
        'role' => 'BUKSU_COMELEC_IT_ADMIN',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/* ---------------------------------- schema ---------------------------------- */

test('the valid pre-first-login state is representable: authorized_email known, google_subject NULL', function () {
    $admin = AdminUser::factory()->create(['authorized_email' => 'known@admins.example.test']);

    expect($admin->fresh()->authorized_email)->toBe('known@admins.example.test')
        ->and($admin->fresh()->google_subject)->toBeNull();
});

test('authorized_email is required', function () {
    expect(fn () => DB::table('admin_users')->insert([
        'google_subject' => 'x', 'display_name' => 'No Email', 'role' => 'BUKSU_COMELEC_IT_ADMIN', 'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

test('authorized_email is unique', function () {
    insertAdminRow(['authorized_email' => 'dup@admins.example.test']);

    expect(fn () => insertAdminRow(['authorized_email' => 'dup@admins.example.test']))->toThrow(QueryException::class);
});

test('google_subject is nullable, many rows may be NULL, and it is unique once populated', function () {
    insertAdminRow([]);
    insertAdminRow([]);
    insertAdminRow([]);
    expect(AdminUser::whereNull('google_subject')->count())->toBe(3);

    insertAdminRow(['google_subject' => 'bound-1']);
    expect(fn () => insertAdminRow(['google_subject' => 'bound-1']))->toThrow(QueryException::class);
});

test('the schema does not restrict how many admin rows exist: 3 is not a database cardinality limit', function () {
    AdminUser::factory()->count(10)->create();

    expect(AdminUser::count())->toBe(10);
    // and nothing in the table definition caps rows (the only MySQL/MariaDB CHECK is on `role`)
    if (dbEnforcesChecks()) {
        $checks = DB::select("select constraint_name from information_schema.check_constraints where constraint_schema = database() and table_name = 'admin_users'");
        expect(collect($checks)->pluck('constraint_name')->map(fn ($n) => strtolower($n))->all())->toBe(['chk_admin_users_role']);
    }
});

test('the admin_users columns are exactly the approved identity fields', function () {
    expect(Schema::hasColumns('admin_users', ['authorized_email', 'google_subject', 'display_name', 'role']))->toBeTrue();
});

/* ------------------------ roster = configured operational size ------------------------ */

test('there is no hard-coded maximum or required count in the application', function () {
    expect(defined(AdminRoster::class.'::REQUIRED_COUNT'))->toBeFalse();
    expect(defined(AdminRoster::class.'::MAX_ADMINS'))->toBeFalse();
    expect(config('comelec.admin_roster_size'))->toBe(3); // the current operational value, in config
});

test('roster integrity follows the configured operational roster size', function (int $configured, int $rows, bool $intact) {
    config(['comelec.admin_roster_size' => $configured]);
    AdminUser::factory()->count($rows)->create();

    expect(app(AdminRoster::class)->isIntact())->toBe($intact);
})->with([
    'default size, three rows' => [3, 3, true],
    'default size, two rows' => [3, 2, false],
    'default size, four rows' => [3, 4, false],
    'size raised to four, four rows' => [4, 4, true],
    'size raised to four, three rows' => [4, 3, false],
    'size lowered to two, two rows' => [2, 2, true],
    'misconfigured zero' => [0, 0, false],
    'misconfigured negative' => [-1, 0, false],
]);

test('with a larger configured roster, a fourth admin can authenticate and decide approvals', function () {
    config(['comelec.admin_roster_size' => 4]);
    $admins = AdminUser::factory()->count(4)->create();

    googleLogin(googleIdentity('fourth-sub', $admins[3]->authorized_email))->assertRedirect(route('admin.home'));

    $this->assertAuthenticatedAs($admins[3]->fresh(), 'admin');
});

test('a misconfigured roster size fails admin access closed', function () {
    $admins = makeAdminRoster();
    config(['comelec.admin_roster_size' => 0]);

    googleLogin(googleIdentity('s', $admins[0]->authorized_email))->assertRedirect(route('access-issue'));

    $this->assertGuest('admin');
    expect(lastDenialReason())->toBe('ADMIN_ROSTER_INVALID');
});

/* --------------------------- migration safety guards --------------------------- */

test('rolling back the additive migration is refused while a pre-authorized admin has no bound subject', function () {
    AdminUser::factory()->create(); // google_subject NULL
    $migration = require database_path('migrations/2026_09_30_000100_add_authorized_email_to_admin_users_table.php');

    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'Cannot roll back');

    expect(Schema::hasColumn('admin_users', 'authorized_email'))->toBeTrue(); // nothing was changed
    expect(AdminUser::count())->toBe(1);
});

test('the tightening migration fails safely on a legacy row without an authorized email: nothing is invented, deleted or changed', function () {
    if (dbEnforcesChecks()) {
        $this->markTestSkipped('Needs DDL inside the test transaction (implicit commit on MySQL/MariaDB); verified manually on MariaDB.');
    }

    $tighten = require database_path('migrations/2026_09_30_000200_require_authorized_email_on_admin_users_table.php');
    $tighten->down(); // authorized_email nullable again, as it is between the two migrations
    DB::table('admin_users')->insert(['authorized_email' => null, 'google_subject' => 'legacy-sub', 'display_name' => 'Legacy', 'role' => 'BUKSU_COMELEC_IT_ADMIN', 'created_at' => now(), 'updated_at' => now()]);
    $before = DB::table('admin_users')->get()->toJson();

    expect(fn () => $tighten->up())->toThrow(RuntimeException::class, 'no authorized_email');

    expect(DB::table('admin_users')->get()->toJson())->toBe($before); // row, subject and email untouched
    expect(DB::table('admin_users')->whereNull('authorized_email')->count())->toBe(1); // nothing inferred from the subject

    // once the REAL email is assigned manually, the migration proceeds and the column is required
    DB::table('admin_users')->where('google_subject', 'legacy-sub')->update(['authorized_email' => 'real.person@admins.example.test']);
    $tighten->up();
    expect(DB::table('admin_users')->where('google_subject', 'legacy-sub')->value('google_subject'))->toBe('legacy-sub');
    expect(fn () => insertAdminRow(['authorized_email' => null]))->toThrow(QueryException::class);
});
