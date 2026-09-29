<?php

use App\Models\AdminUser;
use App\Models\Student;

/*
 * Phase 02 — two separate authentication domains.
 *
 * `student` and `admin` are independent session guards backed by independent
 * tables. The scaffolded `web` guard / `users` provider / password-reset broker
 * have been removed on purpose: there is no password authentication path.
 * Google OAuth (Socialite) is the only way to establish either identity.
 *
 * The default guard is `student` (least privilege). Admin routes explicitly
 * switch the request to the `admin` guard via the EnsureAdmin middleware, and
 * application code must never rely on the default guard for admin decisions.
 */
return [

    'defaults' => [
        'guard' => 'student',
    ],

    'guards' => [
        'student' => [
            'driver' => 'session',
            'provider' => 'students',
        ],

        'admin' => [
            'driver' => 'session',
            'provider' => 'admins',
        ],
    ],

    'providers' => [
        'students' => [
            'driver' => 'eloquent',
            'model' => Student::class,
        ],

        'admins' => [
            'driver' => 'eloquent',
            'model' => AdminUser::class,
        ],
    ],

];
