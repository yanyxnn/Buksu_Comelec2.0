<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\AuthDecision;
use App\Services\Auth\DenialReason;
use App\Services\Auth\GoogleIdentity;
use App\Services\Auth\GoogleIdentityProvider;
use App\Services\Auth\IdentityResolver;
use Illuminate\Http\RedirectResponse as LaravelRedirect;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Throwable;

class GoogleAuthController extends Controller
{
    public function __construct(private readonly IdentityResolver $resolver) {}

    public function redirect(GoogleIdentityProvider $google): RedirectResponse
    {
        return $google->redirect();
    }

    /**
     * Fails closed: ANY problem (bad state, provider error, denied identity,
     * audit failure, session failure) ends in the same generic rejection.
     */
    public function callback(
        Request $request,
        GoogleIdentityProvider $google,
        AuditLogger $audit,
    ): LaravelRedirect {
        $correlationId = (string) Str::uuid();
        $identity = null;

        try {
            $identity = $google->identity();
            $decision = $this->resolver->resolve($identity);

            if ($decision->isDenied()) {
                return $this->deny($audit, $decision->reason, $identity, $correlationId);
            }

            return $this->establishSession($request, $audit, $decision, $correlationId);
        } catch (Throwable $e) {
            try {
                return $this->deny($audit, DenialReason::ProviderError, $identity, $correlationId, ['exception' => class_basename($e)]);
            } catch (Throwable) {
                return $this->genericRejection();
            }
        }
    }

    private function establishSession(Request $request, AuditLogger $audit, AuthDecision $decision, string $correlationId): LaravelRedirect
    {
        $isAdmin = $decision->admin !== null;
        $model = $isAdmin ? $decision->admin : $decision->student;
        $actorType = $isAdmin ? 'ADMIN' : 'STUDENT';

        // Audit first: if the trail cannot be written, nobody gets in.
        if ($decision->firstLink) {
            $audit->record('auth.student.first_link', AuditLogger::INFO, 'STUDENT', $model->getKey(), 'student', $model->getKey(), 'Student Google identity linked on first login.', correlationId: $correlationId);
        }

        $audit->record($isAdmin ? 'auth.admin.login' : 'auth.student.login', AuditLogger::INFO, $actorType, $model->getKey(), description: 'Successful Google login.', correlationId: $correlationId);

        // One identity domain per session: clear both, flush, then log in.
        Auth::guard('student')->logout();
        Auth::guard('admin')->logout();
        Session::invalidate();

        Auth::guard($isAdmin ? 'admin' : 'student')->login($model);

        // Session fixation defence (guard->login also migrates; this is explicit and asserted by tests).
        $request->session()->regenerate();
        $request->session()->regenerateToken();

        return redirect()->route($isAdmin ? 'admin.home' : 'student.home');
    }

    /**
     * Records the denial, then sends the person to the SAME place for every reason
     * that follows a successful Google authentication (the Access Issue page), so the
     * response never reveals admin / student / unknown. Failures with no trustworthy
     * identity (provider error, malformed identity) go back to the login page.
     *
     * @param  array<string, mixed>  $extra
     */
    private function deny(AuditLogger $audit, DenialReason $reason, ?GoogleIdentity $identity, string $correlationId, array $extra = []): LaravelRedirect
    {
        $metadata = ['reason' => $reason->value] + $extra;

        if ($identity !== null && $identity->sub !== '') {
            $metadata['subject_fingerprint'] = $audit->fingerprint($identity->sub);
        }

        $audit->record('auth.denied', $reason->severity(), description: 'Google login denied.', metadata: $metadata, correlationId: $correlationId);

        if ($identity === null || in_array($reason, [DenialReason::ProviderError, DenialReason::MalformedIdentity], true)) {
            return $this->genericRejection();
        }

        // One-time context for the access-issue page. Everyone sees the same page, but only a
        // Google-verified email on the configured institutional domain may SUBMIT a report;
        // any other account (personal Gmail, unverified, ...) cannot open student access tickets.
        // Only that eligible email is kept; the raw subject is never stored (a keyed fingerprint is).
        $canReport = $identity->emailVerified && $this->resolver->isInstitutionalEmail($identity->email);

        session()->put('access_issue', [
            'can_report' => $canReport,
            'email' => $canReport ? mb_strtolower(trim($identity->email)) : null,
            'fingerprint' => $metadata['subject_fingerprint'],
            'reason' => $reason->value,
        ]);

        return redirect()->route('access-issue');
    }

    /** Failures with no usable Google identity (state/network/provider error). */
    private function genericRejection(): LaravelRedirect
    {
        return redirect()->route('login')->with('login_error', __('We could not sign you in with that Google account.'));
    }
}
