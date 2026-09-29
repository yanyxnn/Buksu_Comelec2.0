<?php

/*
 * BUKSU COMELEC 2.0 — application-level identity and approval configuration.
 *
 * Nothing in this file is a secret except where noted, and nothing institution-
 * specific is hard-coded: values come from the environment.
 */
return [

    /*
     * Institutional Google Workspace domain that student accounts must belong
     * to (e.g. "students.example.edu"). Compared case-insensitively against the
     * domain part of the verified Google email. If empty/unset, EVERY student
     * login fails closed.
     */
    'student_email_domain' => env('COMELEC_STUDENT_EMAIL_DOMAIN'),

    /*
     * The exactly-three IT admin Google identities, provisioned OUTSIDE the
     * application UI via `php artisan comelec:provision-admins`.
     *
     * JSON array of exactly three objects:
     *   [{"google_subject":"…","display_name":"…"}, … ]
     * `google_subject` is Google's stable `sub` claim, not an email address.
     * Personal identifiers: keep this in the deployment environment only.
     */
    'admin_identities' => json_decode((string) env('COMELEC_ADMIN_IDENTITIES', '[]'), true) ?: [],

    /*
     * Login / Access Report "problem type" choices: what the STUDENT says they need help with.
     * KEY => human-readable label. Config-backed (not a DB enum) so the list can evolve without
     * a schema migration; only the KEY is stored. An empty list rejects every submission.
     * Separate from the system-generated `denial_reason` (why authentication was denied).
     */
    'access_issue_problem_types' => [
        'STUDENT_RECORD_NOT_FOUND' => 'My student record was not found',
        'DATA_CENTER_INFORMATION_OUTDATED' => 'My university (Data Center) information is outdated',
        'LATE_VERIFIED_COR' => 'My COR was verified late',
        'RETURNEE_RETURNING_STUDENT' => 'I am a returnee / returning student',
        'RECENTLY_ENROLLED_OR_NEWLY_ADMITTED' => 'I recently enrolled or was newly admitted',
        'TRANSFER_OR_COURSE_CHANGE' => 'I transferred or changed my course',
        'INCORRECT_COLLEGE_COURSE_OR_YEAR' => 'My college, course, or year level is incorrect',
        'INCORRECT_STUDENT_STATUS' => 'My student status is incorrect',
        'GOOGLE_OR_LOGIN_PROBLEM' => 'I have a Google account or login problem',
        'OTHER' => 'Other',
    ],

    /*
     * Registered change-request action types. Intentionally EMPTY: Phase 02
     * ships the generic approval mechanism only. Later phases register their
     * real action types here; no institutional action types are invented.
     */
    'change_request_action_types' => [],

];
