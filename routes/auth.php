<?php

use App\Http\Controllers\Auth\AccessIssueController;
use App\Http\Controllers\Auth\GoogleAuthController;
use App\Livewire\Actions\Logout;
use Illuminate\Support\Facades\Route;

/*
 * ONE user-facing login entry point: "Continue with Google". There is no password,
 * no separate admin login, no registration, password-reset or email-verification.
 * The redirect/callback are not guest-only on purpose: a successful Google login
 * REPLACES whatever identity the session held (student <-> admin), it never stacks.
 */
Route::view('login', 'auth.login')->middleware('guest:student,admin')->name('login');

Route::middleware('throttle:10,1')->group(function () {
    Route::get('auth/google/redirect', [GoogleAuthController::class, 'redirect'])->name('auth.google.redirect');
    Route::get('auth/google/callback', [GoogleAuthController::class, 'callback'])->name('auth.google.callback');
});

// Access assistance for a Google-authenticated person who is not let in (no access is granted here).
Route::get('access-issue', [AccessIssueController::class, 'show'])->name('access-issue');
Route::post('access-issue', [AccessIssueController::class, 'store'])->middleware('throttle:5,1')->name('access-issue.store');

Route::post('logout', Logout::class)->name('logout');
