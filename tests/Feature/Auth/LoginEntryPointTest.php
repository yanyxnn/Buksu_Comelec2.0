<?php

use App\Models\Student;
use Illuminate\Support\Facades\Route;

test('there is exactly one login page and it offers exactly one way in: Continue with Google', function () {
    $html = $this->get(route('login'))->assertOk()->assertSee('Continue with Google')->getContent();

    expect(substr_count($html, 'Continue with Google'))->toBe(1);
    expect(substr_count($html, route('auth.google.redirect')))->toBe(1);
    // no credentials form of any kind: no email/username/password fields
    expect($html)->not->toContain('<form')->not->toContain('<input')->not->toContain('type="password"');
});

test('there is no separate admin login: admin URLs all lead to the same Google login page', function () {
    foreach (['/admin/login', '/admin-login', '/admin/signin'] as $path) {
        $this->get($path)->assertNotFound();
    }

    // the admin area redirects guests to the one shared login page
    $this->get(route('admin.home'))->assertRedirect(route('login'));
    $this->get(route('student.home'))->assertRedirect(route('login'));
});

test('the only authentication routes are the login page, the Google redirect/callback and logout', function () {
    $authRoutes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($r) => preg_match('/login|signin|sign-in|password|register|credential|auth\//i', $r->uri()))
        ->map(fn ($r) => implode('|', array_diff($r->methods(), ['HEAD'])).' '.$r->uri())
        ->sort()->values()->all();

    expect($authRoutes)->toBe([
        'GET auth/google/callback',
        'GET auth/google/redirect',
        'GET login',
    ]);

    // No POST route accepts a login.
    expect($this->post('/login')->getStatusCode())->toBeIn([404, 405]);
});

test('Continue with Google sends the visitor to Google', function () {
    $this->get(route('auth.google.redirect'))->assertRedirect('https://accounts.google.test/o/oauth2/auth');
});

test('after Google authentication one login resolves into exactly one of admin, student or access issue', function () {
    $admin = makeAdminRoster()[0];
    $student = Student::factory()->create();

    googleLogin(adminGoogleIdentity($admin))->assertRedirect(route('admin.home'));
    googleLogin(googleIdentity('s1', $student->institutional_email))->assertRedirect(route('student.home'));
    googleLogin(googleIdentity('s2', 'ghost@'.studentDomain()))->assertRedirect(route('access-issue'));
});
