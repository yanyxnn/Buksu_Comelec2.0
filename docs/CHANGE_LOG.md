# CHANGE LOG

## 2026-09-26 — Architecture Baseline Planning
- Established reusable configurable election-engine objective.
- Locked MySQL as authoritative election data source.
- Locked atomic/idempotent vote submission and immutable cast ballots.
- Locked separation of voter participation from ballot selections.
- Locked receipt as proof of participation only.
- Locked student-facing live results to overall cast votes + anonymous candidate leaderboards.
- Locked horizontally scalable Reverb/Redis realtime architecture.
- Locked election-wide integrity lock and immutable historical elections.
- Locked searchable election history and configuration-only reuse from previous elections.
- Locked Data Center as current-student-data authority.
- Locked Student status to ACTIVE/INACTIVE only.
- Locked course-change rule: new course is 1st Year for COMELEC purposes.
- Locked controlled late-COR/election-specific eligibility exception concept.
- Locked candidate roster approval, snapshot, and lock.
- Locked tie handling: detect/flag; COMELEC President/authorized heads decide outside the engine.
- Locked automatic/downloadable encrypted backups and restore testing.
- Identified remaining institutional/open decisions; see `OPEN_DECISIONS.md`.

## 2026-09-28 — Phase 1A Domain Model & Database Architecture Approved

- Completed Phase 1A domain model and database architecture review.
- Approved the separation of voter identity/participation from anonymous ballot content.
- Approved anonymous but traceable ballot architecture using opaque ballot identifiers, ballot events, and separate ballot dispositions.
- Approved explicit per-contest `VOTE` / `ABSTAIN` responses; `SKIP` is not a domain state.
- Approved multi-seat voting where selecting fewer than the configured maximum remains a valid `VOTE`.
- Approved complete election abstention as participation with a committed ballot containing explicit `ABSTAIN` responses and zero candidate selections.
- Confirmed non-participation as the absence of both participation and ballot records.
- Approved frozen, non-identifying ballot reporting context for college/course/year/sector result breakdowns.
- Approved configurable result aggregation and immutable finalized result snapshots.
- Approved ballot immutability; exceptional ballot handling uses additive disposition/incident records rather than modifying the original cast ballot.
- Approved MySQL-first integrity enforcement with schema constraints, transactional validation, and reconciliation; no triggers proposed for Phase 1A.
- Approved ULID strategy for privacy-sensitive/externally referenced identifiers.
- Approved candidate identity as a separate thin `candidates` entity.
- Approved election-scoped parties unless institutional policy later establishes a persistent recognized-party registry.
- Marked Phase 1A complete and authorized progression to Phase 1B database/migration implementation.
- Remaining institutional/reporting decisions remain tracked in `OPEN_DECISIONS.md`.

## 2026-09-29 — Phase 02 Authentication & Admin Authorization (implemented; pending review)

- **Proposer:** implementation session, per approved Phase 02 plan. **Approval authority:** project owner (plan approved with the corrections recorded below).
- **Reason:** AUTH-031 Google OAuth foundation, AUTH-032 exactly-three admins, AUTH-033 student/admin identity separation, AUTH-034 proposal/approval workflow.
- ONE user-facing login entry point: "Continue with Google". No separate admin login, no passwords. Google OAuth via Laravel Socialite behind a `GoogleIdentityProvider` seam (fake in tests; no real Google calls in the automated suite). `composer.json` now requires `laravel/socialite` `^5.26`. **`composer.lock` is intentionally NOT part of this change and must be regenerated on a networked machine:** `composer update laravel/socialite --with-dependencies`, then `composer validate` and re-run the test suite.
- Two independent session guards, `student` and `admin`, backed by `students` and `admin_users`. Stock password/register/reset/verify/settings flows and `App\Models\User` removed. Forward migration drops `users` and `password_reset_tokens`; `sessions` and the historical `0001_01_01_000000` migration are untouched. No table had a foreign key to `users`.
- Student login: first-link by exact (case-insensitive) institutional email after `email_verified`, configured-domain check, an existing student row that came from the Data Center import (`students.last_import_batch_id IS NOT NULL`) and `google_subject IS NULL`; afterwards by Google `sub`. Login never creates a student and never writes Google data into student fields. An existing INACTIVE student may authenticate: `status` is not an authentication credential and no live `status` check decides eligibility (the locked election eligibility snapshot does). Dependency: the Phase 01C import must stamp `last_import_batch_id`, otherwise every student login fails closed.
- Admins: fixed role `BUKSU_COMELEC_IT_ADMIN`, provisioned only by `php artisan comelec:provision-admins` from `COMELEC_ADMIN_IDENTITIES`. Exactly-three roster and role are re-enforced at runtime on every admin login and request (fail closed). No admin-management routes or UI exist.
- A Google identity that resolves to both domains is denied in both (`IDENTITY_CONFLICT`, SECURITY audit).
- **Access Issue flow:** when Google authentication succeeds but the account is not let in (unknown or non-institutional account, unverified email, not Data Center-loaded, conflicts), the person is sent to one identical Access Issue page ("Student Access Issue") that never reveals admin/student/unknown. Only a Google-verified email on the configured institutional domain may SUBMIT a report; any other denied account sees the same page without a form and cannot create a student access ticket. The form has a required Problem Type (config-backed list, `comelec.access_issue_problem_types`, not a DB enum), an optional Student ID and a required Description; the verified email is filled from the Google identity and is not editable. `problem_type` (what the student says they need help with) is kept separate from the system-generated `denial_reason` (why authentication denied the login); the student can supply only the former. Reports go into `access_issue_reports` with the date/time. A report never creates or modifies a student, never grants access, and stores no token, raw Google `sub` or unverified email. Read-only admin list at `/admin/access-issues` (submitted at, problem type, student ID, institutional email, system denial reason, description; no status workflow yet). Failures with no usable Google identity (provider/state error, malformed identity) return to the login page with one generic message. Login / Access Reports (this Phase 02 mechanism: about being unable to authenticate) are distinct from the future General Reports (Phase 03: reports from authenticated users about other matters), which are NOT implemented here.
- A successful Google login REPLACES any existing session identity (student <-> admin); a denied attempt leaves the current session untouched.
- `EnsureAdmin` / `EnsureStudent` middleware, also registered as Livewire persistent middleware (Livewire 4 allow-list mechanism, verified against the installed package).
- Generic `change_requests` mechanism: requester cannot decide own request (policy, service pre-check, atomic UPDATE predicate, and DB CHECK on MySQL/MariaDB); atomic PENDING to APPROVED/REJECTED; all three admins notified in-app in the same transaction; creation/decision audited in the same transaction. No real action types and no approval thresholds were invented.
- Framework-merged default `web` guard, `users` provider and password broker are explicitly removed at runtime (Laravel 12 re-merges them beneath `config/auth.php`).
- **Migration impact:** `change_requests`, `notifications`, `access_issue_reports` added; CHECK on `admin_users.role` (MySQL/MariaDB); `users`/`password_reset_tokens` dropped (reversible).
- **Testing impact:** stock auth/settings/dashboard tests deleted with the features they tested; Phase 02 suites added. See `TEST_PLAN.md`.
- **Not claimed:** the Phase 02 gate is not marked passed. A real Google OAuth smoke test on staging remains to be done (documented, not automated). Phase 01C is untouched and remains in progress.

## Change process
Every material architecture or election-rule change must record date, reason, proposer, affected documents/code, approval authority, migration/testing impact, and resulting decision.

## 2026-09-30 — Phase 02 Administrator Identity Lifecycle Correction

* **Reason:** Documentation correction to align the approved Phase 02 administrator model with the final project decision.
* Administrator accounts are pre-authorized system records and are independent of the official Data Center student import.
* Administrators do not publicly register and cannot create their own administrator accounts through Google login.
* The current initial roster of three administrators is an operational roster, not a permanent database or application cardinality limit.
* Each administrator has a pre-authorized personal Google email before first login.
* `admin_users.google_subject` is nullable before first successful login and unique when populated.
* On first Google login, the verified Google email is matched to the pre-authorized administrator record and the stable Google `sub` is then bound to that existing record.
* Subsequent administrator authentication uses the bound stable Google `sub`; an existing binding is not replaced by an alternate `sub`.
* Data Center imports remain authoritative for student master data only and must never create or provision administrator accounts.
* This entry documents the approved design correction only. Code, migration, and test implementation changes are intentionally deferred to the implementation phase.
