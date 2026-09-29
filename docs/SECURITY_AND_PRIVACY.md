# SECURITY & PRIVACY

## Ballot secrecy
Normal application data must not provide a simple student → candidate-selection relationship. Participation and ballot selections are separate. Receipts prove participation only.

## Access control
Exactly three fixed IT admins, same role, no admin-management UI. Candidates are normal students.

## Sensitive operations
Election-impacting changes, production restore, exceptional recovery, and other high-risk actions use independent approval/dual control as configured.

## Least privilege
Separate service responsibilities and database privileges where practical. Ordinary admin features must not have unrestricted direct modification access to cast ballot data.

## Authentication
One login page with Google OAuth via Socialite. Admin personal Google identities are separate from student institutional Google identities.

## Input/security controls
Validate IDs, uploads, election configuration, ballot structures, selection limits, authorization, CSRF/session protections, rate limits, XSS/injection protections, safe file handling, and secure error handling.

## Logging restrictions
Never log passwords, OAuth tokens, secrets, full ballot selections with voter identity, or unnecessary sensitive PII. Access to sensitive logs should itself be auditable.

## Realtime privacy
Student-facing channels contain aggregate result state only. No student identity or individual ballot data.

## Backups
Encrypt sensitive backups, restrict access, record backup actions, verify checksums, and test restores.

## Threat model
Include duplicate requests, malicious payloads, privilege escalation, compromised admin, DB failure, realtime failure, queue failure, backup compromise/corruption, import errors, deployment errors, and ballot-data exposure scenarios.

## Phase 02 authentication controls
- Google OAuth (Socialite) is the only credential; there is no password, registration, reset or email-verification path. Google credentials live in `.env` only.
- Student and admin are separate guards/tables. A Google account resolving to both domains is denied in both and audited as SECURITY.
- One Google login entry point. After Google authentication every non-admitted account is sent to the same Access Issue page; internal denial reasons are audit-only and never reveal admin/student/unknown. Only a Google-verified email on the configured institutional domain may submit an access issue report (personal or unverified accounts get the same page with no form, and a forged POST is ignored). Reports store the verified email, a student-chosen `problem_type` validated against a config allow-list, an optional typed Student ID, a description, the system-generated `denial_reason` (never taken from input) and a keyed subject fingerprint; they never create/modify students or grant access, and the endpoint is rate limited and requires the one-time session context left by a real Google denial.
- Students authenticate only if their row came from the Data Center import (`last_import_batch_id`); Google login never creates a student. INACTIVE students may authenticate (eligibility is the election snapshot's job).
- Audit records never contain raw Google `sub`, tokens, emails, passwords or ballot data; denied attempts store a keyed 16-hex fingerprint of the `sub` only. IP/user-agent are not recorded (institutional approval unresolved).
- Session is invalidated and regenerated on login; logout is POST-only, clears both guards and rotates the CSRF token.
- Admin access requires exactly three admins with the fixed role, checked on every request; Livewire update requests are re-checked through persistent middleware.
- Production must set `SESSION_SECURE_COOKIE=true`. `sessions.user_id` is informational only and is not an authorization source.
