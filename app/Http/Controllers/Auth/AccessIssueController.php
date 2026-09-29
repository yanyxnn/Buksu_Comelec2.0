<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AccessIssueReport;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Shown after Google authentication succeeded but the account is not let in.
 * The page is identical for every such denial, so it never reveals whether the
 * account is an admin, a student or unknown. Submitting a report never creates or
 * changes a student and never grants access. It requires the one-time context
 * left in the session by the Google callback (so it cannot be used anonymously).
 */
class AccessIssueController extends Controller
{
    public function show(Request $request): View|RedirectResponse
    {
        $context = $request->session()->get('access_issue');

        if (! is_array($context)) {
            return redirect()->route('login');
        }

        return view('auth.access-issue', [
            'canReport' => (bool) ($context['can_report'] ?? false),
            'email' => $context['email'] ?? null,
            'problemTypes' => (array) config('comelec.access_issue_problem_types'),
        ]);
    }

    public function store(Request $request, AuditLogger $audit): RedirectResponse
    {
        $context = $request->session()->get('access_issue');

        if (! is_array($context)) {
            return redirect()->route('login');
        }

        // Everyone sees the same page, but only a verified institutional account may submit.
        // Anyone else gets no report and no explanation.
        if (! ($context['can_report'] ?? false)) {
            return redirect()->route('access-issue');
        }

        // `denial_reason` and the email come ONLY from the server-side context, never from input.
        $data = $request->validate([
            'problem_type' => ['required', 'string', Rule::in(array_keys((array) config('comelec.access_issue_problem_types')))],
            'student_id' => ['nullable', 'string', 'max:50'],
            'description' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        DB::transaction(function () use ($context, $data, $audit) {
            $report = AccessIssueReport::create([
                'google_email' => $context['email'],
                'problem_type' => $data['problem_type'],
                'subject_fingerprint' => $context['fingerprint'],
                'reported_student_id' => isset($data['student_id']) ? trim($data['student_id']) : null,
                'description' => trim($data['description']),
                'denial_reason' => $context['reason'],
            ]);

            $audit->record(
                eventType: 'access_issue.reported',
                targetType: 'access_issue_report',
                targetId: $report->getKey(),
                description: 'Access issue reported.',
                metadata: ['reason' => $context['reason'], 'problem_type' => $data['problem_type']],
            );
        });

        $request->session()->forget('access_issue'); // one report per denial

        return redirect()->route('home')->with('status', __('Your access issue was submitted. An administrator will look into it.'));
    }
}
