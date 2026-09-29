<?php

namespace App\Livewire\Actions;

use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;

class Logout
{
    /**
     * Ends BOTH identity contexts, invalidates the session and rotates the CSRF token.
     */
    public function __invoke(AuditLogger $audit)
    {
        $admin = Auth::guard('admin');
        $student = Auth::guard('student');

        if ($admin->check()) {
            $audit->record('auth.logout', actorType: 'ADMIN', actorId: $admin->id());
        } elseif ($student->check()) {
            $audit->record('auth.logout', actorType: 'STUDENT', actorId: $student->id());
        }

        $admin->logout();
        $student->logout();

        Session::invalidate();
        Session::regenerateToken();

        return redirect('/');
    }
}
