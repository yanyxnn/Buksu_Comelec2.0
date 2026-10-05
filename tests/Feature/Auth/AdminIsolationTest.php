<?php

use App\Models\AdminUser;
use App\Models\Student;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

function adminTable(): string
{
    return DB::table('admin_users')->orderBy('id')->get()->toJson();
}

test('Google login never creates an administrator, whoever logs in', function () {
    makeAdminRoster();
    $student = Student::factory()->create();
    $before = adminTable();

    foreach ([
        googleIdentity('s1', 'stranger@gmail.com'),                         // unknown
        googleIdentity('s2', 'ghost@'.studentDomain()),                      // institutional, no record
        googleIdentity('s3', $student->institutional_email),                 // a real student
        googleIdentity('s4', 'x@admins.example.test', verified: false),      // unverified
    ] as $identity) {
        googleLogin($identity);
        $this->flushSession();
    }

    expect(adminTable())->toBe($before)->and(AdminUser::count())->toBe(3);
});

test('a student login never creates, binds or changes an administrator', function () {
    makeAdminRoster();
    $student = Student::factory()->create();
    $before = adminTable();

    googleLogin(googleIdentity('student-sub', $student->institutional_email))->assertRedirect(route('student.home'));

    expect(adminTable())->toBe($before);
});

test('a simulated Data Center import (insert, update, delete of students and batches) never creates, modifies or deletes an admin', function () {
    makeAdminRoster(linked: true);
    $before = adminTable();

    // what an import does: add students under a batch, update existing ones, remove some
    $created = Student::factory()->count(5)->create();
    DB::table('students')->whereIn('id', $created->pluck('id'))->update(['current_college' => 'Changed College', 'current_year_level' => '3rd Year']);
    DB::table('students')->where('id', $created[0]->id)->delete();
    $batch = DB::table('import_batches')->count();

    expect(adminTable())->toBe($before);
    expect(AdminUser::count())->toBe(3)->and($batch)->toBeGreaterThan(0);
});

test('an imported student whose email equals an authorized admin email does not become an admin and does not change one', function () {
    $admin = makeAdminRoster()[0];
    $before = adminTable();

    Student::factory()->create(['institutional_email' => $admin->authorized_email]);

    expect(adminTable())->toBe($before);
    expect(AdminUser::where('authorized_email', $admin->authorized_email)->count())->toBe(1);
});

test('student records are never converted into administrator records', function () {
    makeAdminRoster();
    $student = Student::factory()->linked('student-sub')->create();

    googleLogin(googleIdentity('student-sub', $student->institutional_email))->assertRedirect(route('student.home'));

    expect(AdminUser::count())->toBe(3);
    expect(AdminUser::where('google_subject', 'student-sub')->exists())->toBeFalse();
});

test('only the provisioner creates administrators and only the first-link/provisioner paths write admin_users', function () {
    $creators = [];
    $writers = [];

    foreach (['app', 'routes', 'resources', 'database/seeders', 'config'] as $dir) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($dir), FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (! $file->isFile() || ! preg_match('/\.php$/', $file->getFilename())) {
                continue;
            }
            // Portable relative path: normalize Windows backslashes on BOTH sides before stripping the project root.
            $path = str_replace(str_replace('\\', '/', base_path()).'/', '', str_replace('\\', '/', $file->getPathname()));
            $code = file_get_contents($file->getPathname());

            if (preg_match('/new\s+AdminUser\b|AdminUser::(create|forceCreate|firstOrCreate|updateOrCreate|insert|upsert|factory)\b|table\([\'"]admin_users[\'"]\)->(insert|upsert|delete|update)/', $code)) {
                $creators[] = $path;
            }
            if (preg_match('/AdminUser::query\(\)[^;]*->(update|delete|forceDelete)\(|AdminUser::(destroy|truncate)\b|->delete\(\)[^;]*AdminUser/s', $code)) {
                $writers[] = $path;
            }
        }
    }

    expect($creators)->toBe(['app/Services/Auth/AdminProvisioner.php']);
    expect($writers)->toBe(['app/Services/Auth/IdentityResolver.php']); // the conditional first-link only
});

test('there is no registration or admin-management surface that could create an admin', function () {
    $routes = collect(Route::getRoutes()->getRoutes())->map(fn ($r) => strtolower($r->uri()));

    expect($routes->filter(fn ($u) => preg_match('/register|signup|sign-up|invite|admins?\/(create|store|delete|promote|demote)/', $u))->all())->toBe([]);
});
