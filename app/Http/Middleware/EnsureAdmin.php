<?php

namespace App\Http\Middleware;

use App\Models\AdminUser;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\AdminRoster;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate for every admin route AND (registered as Livewire persistent
 * middleware) every Livewire update request from an admin component.
 *
 * Fails closed: student-only sessions, mixed-identity sessions, a wrong role
 * and an authorized roster that fails the current integrity check are all rejected.
 */
class EnsureAdmin
{
    public function __construct(private readonly AdminRoster $roster, private readonly AuditLogger $audit) {}

    public function handle(Request $request, Closure $next): Response
    {
        $admin = Auth::guard('admin');
        $student = Auth::guard('student');

        if (! $admin->check()) {
            // A student (or anyone else) is not an admin: 403, not a login redirect.
            abort_if($student->check(), 403);

            throw new AuthenticationException('Unauthenticated.', ['admin']);
        }

        if ($student->check()) {
            // Never allow one session to hold both identity domains.
            $this->audit->record('auth.context_violation', AuditLogger::SECURITY, 'ADMIN', $admin->id(), description: 'Session held both student and admin identities; both cleared.');
            $this->clearIdentities();
            abort(403);
        }

        $model = $admin->user();

        if (! $model instanceof AdminUser || $model->role !== AdminUser::ROLE || ! $this->roster->isIntact()) {
            $this->audit->record('auth.admin_access_denied', AuditLogger::SECURITY, 'ADMIN', $admin->id(), description: 'Admin access denied: role or roster invalid.');
            $this->clearIdentities();
            abort(403);
        }

        // Make Gate / policies / auth() resolve the ADMIN identity for this request.
        Auth::shouldUse('admin');

        return $next($request);
    }

    private function clearIdentities(): void
    {
        Auth::guard('admin')->logout();
        Auth::guard('student')->logout();
        Session::invalidate();
    }
}
