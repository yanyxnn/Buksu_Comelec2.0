<?php

use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\EnsureStudent;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'admin' => EnsureAdmin::class,
            'student' => EnsureStudent::class,
        ]);

        // Unauthenticated visitors always go to the single Google login page.
        $middleware->redirectGuestsTo(fn () => route('login'));

        // Already-authenticated visitors go to the home of THEIR identity domain.
        $middleware->redirectUsersTo(fn (Request $request) => Auth::guard('admin')->check()
            ? route('admin.home')
            : route('student.home'));
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
