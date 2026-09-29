<?php

namespace App\Http\Middleware;

use App\Models\Student;
use App\Services\Audit\AuditLogger;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate for student routes. Deliberately does NOT look at students.status:
 * authentication identity and election eligibility are separate concerns.
 */
class EnsureStudent
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(Request $request, Closure $next): Response
    {
        $student = Auth::guard('student');
        $admin = Auth::guard('admin');

        if (! $student->check()) {
            abort_if($admin->check(), 403);

            throw new AuthenticationException('Unauthenticated.', ['student']);
        }

        if ($admin->check()) {
            $this->audit->record('auth.context_violation', AuditLogger::SECURITY, 'STUDENT', $student->id(), description: 'Session held both student and admin identities; both cleared.');
            $student->logout();
            $admin->logout();
            Session::invalidate();
            abort(403);
        }

        if (! $student->user() instanceof Student) {
            $student->logout();
            abort(403);
        }

        Auth::shouldUse('student');

        return $next($request);
    }
}
