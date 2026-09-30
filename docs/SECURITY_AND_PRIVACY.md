# SECURITY & PRIVACY

## Ballot secrecy
Normal application data must not provide a simple student → candidate-selection relationship. Participation and ballot selections are separate. Receipts prove participation only.

## Access control

The system has a current operational roster of three authorized COMELEC IT administrators, all using the fixed role `BUKSU_COMELEC_IT_ADMIN`. This roster is established by system/operational authorization, not by student Data Center imports.

Administrator accounts are pre-authorized before first login. There is no public admin registration, admin self-registration, password registration, or admin account creation flow exposed to users.

The current roster size of three is an operational control, not a permanent database cardinality limit.

Candidates are normal students.


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

* Google OAuth (Socialite) is the only credential mechanism. There is no password registration, password reset, public admin registration, or email/password login path. Google credentials live in `.env` only.
* Student and admin are separate guards/tables and separate provisioning domains.
* Student identity records are established through the official Data Center student-master-data process. Administrator records are pre-authorized independently and are never created, discovered, or provisioned from Data Center student imports.
* Each administrator has a pre-authorized personal Google email recorded before first login. The admin's `google_subject` may be `NULL` until first successful Google authentication and is unique when populated.
* On first administrator login, Google must report a verified email. The verified email is matched to the existing pre-authorized administrator record. When the match succeeds and `google_subject` is `NULL`, the stable Google `sub` is atomically bound to that existing admin record.
* An administrator is never created by logging in with an arbitrary Google account. Unauthorized or unmatched Google accounts remain unauthenticated.
* After first-linking, administrator authentication uses the bound stable Google `sub`. An existing binding is not replaced merely because another Google identity presents the same email.
* The current three-admin roster is operational and is not a permanent schema-level or application-level maximum.
* A Google account resolving to both domains is denied in both and audited as SECURITY.
* One Google login entry point is used for all users. After Google authentication every non-admitted account is sent to the same Access Issue page; internal denial reasons are audit-only and never reveal admin/student/unknown.
* Only a Google-verified email on the configured institutional domain may submit a student access issue report. Personal/unverified/non-institutional accounts receive the same denial page without a report form.
* Students authenticate only when their row came from the official Data Center import (`last_import_batch_id`); Google login never creates a student. INACTIVE students may authenticate; election eligibility is determined by the locked election snapshot.
* Audit records never contain raw Google `sub`, tokens, emails, passwords, or ballot data. Denied attempts use only the approved keyed subject fingerprint mechanism.
* Session is invalidated and regenerated on login; logout is POST-only, clears both guards, and rotates the CSRF token.
* Admin authorization is checked on every request and Livewire update through the appropriate persistent middleware.
