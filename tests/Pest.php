<?php

use App\Models\AdminUser;
use App\Services\Auth\GoogleIdentity;
use App\Services\Auth\GoogleIdentityProvider;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakeGoogleIdentityProvider;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(Tests\TestCase::class)
    ->use(Illuminate\Foundation\Testing\RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/*
|--------------------------------------------------------------------------
| Phase 02 helpers (authentication / approval)
|--------------------------------------------------------------------------
*/

/**
 * The exactly-three admin roster. Tops up to three, reusing an admin that already exists
 * (e.g. the uploader of the shared Data Center import batch). @return Collection<int, AdminUser>
 */
function makeAdminRoster(): Collection
{
    AdminUser::factory()->count(max(0, 3 - AdminUser::count()))->create();

    return AdminUser::query()->orderBy('id')->get()->values();
}

function googleIdentity(string $sub, string $email, bool $verified = true): GoogleIdentity
{
    return new GoogleIdentity($sub, $email, $verified);
}

function fakeGoogle(?GoogleIdentity $identity = null, ?Throwable $failure = null): void
{
    app()->instance(GoogleIdentityProvider::class, new FakeGoogleIdentityProvider($identity, $failure));
}

/** Drives the real callback route with a fake Google identity. */
function googleLogin(GoogleIdentity $identity): TestResponse
{
    fakeGoogle($identity);

    return test()->get(route('auth.google.callback'));
}

function studentDomain(): string
{
    return (string) config('comelec.student_email_domain');
}

/** The generic user-facing rejection, identical for every failure. */
function genericLoginError(): string
{
    return __('We could not sign you in with that Google account.');
}

/** True on engines where the migrations add CHECK constraints (MySQL/MariaDB). */
function dbEnforcesChecks(): bool
{
    return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
}

/** True when the database's default collation makes unique text indexes case-insensitive. */
function dbIsCaseInsensitive(): bool
{
    return dbEnforcesChecks();
}

/**
 * Failure injection that is NOT DDL (DDL implicitly commits on MySQL and would
 * break the surrounding test transaction): throws when an INSERT into $table runs.
 */
function failOnInsertInto(string $table): void
{
    DB::connection()->beforeExecuting(function (string $query) use ($table) {
        if (preg_match('/^insert\s+into\s+[`"]?'.preg_quote($table, '/').'[`"]?[\s(]/i', $query)) {
            throw new RuntimeException("Injected failure writing {$table}");
        }
    });
}

/** Matches an UPDATE of $table regardless of identifier quoting style. */
function isUpdateOf(string $query, string $table): bool
{
    return (bool) preg_match('/^update\s+[`"]?'.preg_quote($table, '/').'[`"]?\s/i', $query);
}
