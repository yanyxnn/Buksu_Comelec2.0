<?php

use App\Models\AdminUser;
use App\Models\Student;
use Illuminate\Support\Facades\Schema;

test('the stock User model and password-auth tables are gone', function () {
    expect(file_exists(app_path('Models/User.php')))->toBeFalse();
    expect(file_exists(database_path('factories/UserFactory.php')))->toBeFalse();
    expect(Schema::hasTable('users'))->toBeFalse()
        ->and(Schema::hasTable('password_reset_tokens'))->toBeFalse()
        ->and(Schema::hasTable('sessions'))->toBeTrue();
});

test('only the student and admin guards exist, and no provider uses the stock User', function () {
    expect(array_keys(config('auth.guards')))->toBe(['student', 'admin']);
    expect(config('auth.defaults.guard'))->toBe('student');
    expect(config('auth.providers.students.model'))->toBe(Student::class)
        ->and(config('auth.providers.admins.model'))->toBe(AdminUser::class);
    expect(array_keys(config('auth.providers')))->toBe(['students', 'admins'])
        ->and(config('auth.passwords'))->toBe([]);
});

test('the scaffolded password/registration/settings paths no longer exist', function (string $method, string $path) {
    $status = $this->call($method, $path)->getStatusCode();

    expect($status)->toBeIn([404, 405]);
})->with([
    ['GET', '/register'], ['POST', '/register'], ['GET', '/forgot-password'], ['GET', '/reset-password/abc'],
    ['GET', '/verify-email'], ['GET', '/confirm-password'], ['GET', '/dashboard'], ['GET', '/settings/profile'],
    ['GET', '/settings/password'], ['POST', '/login'],
]);

test('no application code depends on App\\Models\\User', function () {
    $offenders = [];
    $self = realpath(__FILE__);

    foreach (['app', 'database', 'routes', 'resources', 'config', 'tests', 'bootstrap'] as $dir) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($dir), FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (! $file->isFile() || realpath($file->getPathname()) === $self || ! preg_match('/\.(php|blade\.php)$/', $file->getFilename())) {
                continue;
            }
            if (preg_match('/App\\\\Models\\\\User\b|\bUser::(factory|query|create|find)/', file_get_contents($file->getPathname()))) {
                $offenders[] = str_replace(base_path().'/', '', $file->getPathname());
            }
        }
    }

    expect($offenders)->toBe([]);
});

test('the login page offers Google only: no password field', function () {
    $this->get(route('login'))->assertOk()->assertSee('Continue with Google')
        ->assertDontSee('type="password"', false);
});
