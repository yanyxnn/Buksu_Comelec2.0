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
- Admins: fixed role `BUKSU_COMELEC_IT_ADMIN`; pre-authorized records (see the 2026-09-30 administrator identity entries below, which supersede the original subject-first design). The current operational roster is re-checked at runtime on every admin login and request (fail closed). No admin-management routes or UI exist.
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

## 2026-09-30 — Phase 02 Administrator Identity Lifecycle: Implementation Revision

* **Reason:** implement the approved administrator model (pre-authorized records; Google login never creates an administrator). Supersedes the original subject-first implementation. **Approval authority:** project owner (audit and plan approved with four decisions, recorded below).
* **Lifecycle implemented:** pre-authorized administrator (`authorized_email`, `google_subject` NULL) -> verified Google login -> atomic first-link of the stable `sub` (conditional on `google_subject IS NULL`, backed by the UNIQUE index) -> subsequent authentication by the bound `sub`. An alternate `sub` presenting a bound administrator's email is denied and the binding is never replaced. Cross-domain (student/admin) conflicts remain fail-closed and audited. Audit events `auth.admin.first_link` / `auth.admin.login` carry no email or subject.
* **Provisioning:** `comelec:provision-admins` now takes `COMELEC_ADMIN_IDENTITIES` as `[{"authorized_email","display_name"}, ...]` (a configured `google_subject` is rejected). Emails are trimmed, lowercased and validated. It is idempotent and additive: it never rebinds, replaces, renames or deletes an existing admin, and it never reads or writes student or Data Center import data.
* **Roster size:** `AdminRoster::REQUIRED_COUNT` (hard-coded 3) is replaced by `config('comelec.admin_roster_size')` (default 3, no environment override). Three is an operational roster control only: it is not a schema constraint and not a maximum; the database allows any number of admin rows.
* **Migration impact (two additive migrations; the historical `create_admin_users_table` is untouched):** (1) adds nullable `authorized_email` with a unique index and makes `google_subject` nullable (existing unique index kept); (2) makes `authorized_email` NOT NULL. **Manual legacy-data prerequisite:** any `admin_users` row created before this correction has a subject but no email. Migration (2) will not invent or infer an email (for example from the subject), will not delete or replace the row and will not touch its subject: it fails safely, changes nothing, and requires the REAL authorized email to be assigned manually (for example `UPDATE admin_users SET authorized_email = '<real email>' WHERE id = <id>`), after which `php artisan migrate` is re-run. Rollback of (1) is refused while any admin has a NULL `google_subject` (subjects are never fabricated); rollback of (2) is always safe.
* **Phase 01B verification script** (`database/verification/phase1b_integrity_tests.sh`): the `admin_users` fixture now supplies `authorized_email` (fixture compatibility only; the invariant tested is unchanged). The script now distinguishes the Phase 01B baseline (must remain 23/23 ENUM columns with their CHECKs, meta-A/B/C) from approved Phase 02 extensions (`change_requests.status` ENUM and the CHECKs `chk_admin_users_role`, `chk_change_requests_status`, `chk_change_requests_decision_state`, `chk_change_requests_no_self_decision`; meta-A2/B2/C2). The baseline was NOT redefined to 24, no constraint was removed or weakened, an unapproved ENUM or CHECK still fails, and a dropped Phase 02 CHECK now fails too. `phase1b_enum_check_final.patch` is a historical artifact and is unchanged.
* **Tests:** admin tests revised to the final model (provisioning, first login, repeat login, first-link race, identity conflicts, isolation from Data Center imports, schema/cardinality, configured roster size, migration guards). See `TEST_PLAN.md`.
* **Documentation alignment:** `docs/REQUIREMENTS.md` now describes the current operational roster of three authorized COMELEC IT administrators with identical role permissions (previously "Exactly three provisioned IT admins"); no other requirement was changed.
* **Not claimed:** the Phase 02 gate is not marked passed. Phase 01C is untouched. No administrator revocation/departure procedure is defined or implemented: because the bound subject is authoritative, changing `authorized_email` does not revoke an already-bound administrator.

## 2026-10-06 — Phase 03A Student Master / Data Center Import Core (implemented; pending review)

* **Reason:** implement the approved import pipeline (upload -> staging -> validation -> preview -> confirmation -> chunked background processing -> students + append-only enrollment history -> summary) as a secure domain core. **Approval authority:** project owner (Phase 03A brief). The historical Python/bulk-SQL uploader was used only to understand source shape and scale; it is not the design.
* **Implemented:** `ImportBatchService`, `BatchStateMachine`, `ImportValidator`, `ImportChunkProcessor`, `RowClassifier`, `PlacementRule`, `RowNormalizer`, `HeaderMapper`, `CanonicalValueIndex`, CSV/XLSX readers, `ImportBatch` model, queued `ProcessImportBatch` / `ValidateImportBatch` jobs. Behaviour and guarantees are in `DATA_IMPORT.md`.
* **Data contract:** `Code` is the permanent identity (string, never cast); `No.` and `Sex` are ignored (Sex is not added to the student domain). Year levels use one internal representation (`1st Year`...`4th Year`) mapped from configurable source tokens; unknown years are INVALID.
* **Institutional email (fail closed):** the official source has no institutional email and `students.institutional_email` is NOT NULL, so the importer never creates a student and never fabricates, guesses or overwrites an email. Unknown IDs are `NEEDS_EXCEPTION_REVIEW` (`NEW_STUDENT_REQUIRES_INSTITUTIONAL_EMAIL`). No new email rule was invented; provisioning of new students is an open decision.
* **Migration impact (one additive migration):** `import_batch_rows` gains nullable `source_row_number`, `normalized_json`, `issues_json`, `processed_at`, a UNIQUE `(import_batch_id, source_row_number)` and a processing index. No existing column, constraint or ENUM/CHECK changed (Phase 01B baseline unaffected; the Phase 01B SQL integrity script still passes against the migrated schema). Reversible.
* **Config:** new `comelec.import` block (disk, directory, extensions, size limits, chunk size, year labels, header aliases).
* **Concurrency finding:** real multi-process runs on MariaDB showed InnoDB deadlocks (1213) between concurrent chunk transactions on one batch. Fixed by locking the batch row first in each chunk transaction (+ bounded retry); data integrity held even before the fix. Regression-checked by `database/verification/phase03a_import_concurrency.php`.
* **Hardening:** `.xlsx` declared-uncompressed-size cap (zip-bomb guard); deterministic (sorted) classification summary across database engines.
* **Testing impact:** Pest suites under `tests/Unit/StudentImport` and `tests/Feature/Import`; see `TEST_PLAN.md`.
* **Not claimed:** no upload UI/route, no review/resolution workflow for `NEEDS_EXCEPTION_REVIEW` rows, no new-student provisioning, and no performance target (none approved). Verified on MariaDB 10.11.14 and SQLite; the **MariaDB 10.4.32 run is still required**. Phase 01C is untouched and remains in progress; no Phase gate is marked passed.

## 2026-10-07 — Phase 03A Targeted Hardening Revision (pending review)

* **Batch precedence no longer inferred from the auto-increment id.** The processor compared `students.last_import_batch_id` with the batch id to skip overwriting the placement "when a newer batch already owns it". A database identifier is not official chronology, and nothing approved defines one, so the comparison is removed rather than replaced by an invented rule (not `>=`, not `created_at`, not `confirmed_at`). The applied batch becomes the current placement; enrollment history stays append-only; `last_import_batch_id` names the producing batch; replay stays a no-op. **Behavioural consequence:** an older file processed after a newer one now overwrites the current placement (history preserved). Recorded in `OPEN_DECISIONS.md` with candidate rules; no policy was introduced. Regression tests prove ids are never treated as chronology.
* **Staged source file cleanup.** `stage()` now tracks the exact storage key it writes and deletes it if the surrounding database work fails (a rollback cannot remove a file). The original exception is always the one raised; a cleanup failure is logged once (disk and key only) and never makes staging look successful. Successful staging is unchanged.
* **Not changed:** schema/migrations, Phase 01C status, election/candidate/voting rules. **Not claimed:** MariaDB 10.4.32 verification (still required locally).

## 2026-10-10 — Phase 03A Hardening: verify the staged file checksum before parsing (pending review)

* **Change:** `ImportValidator` now hashes the local copy of the stored source file (SHA-256) and compares it with `import_batches.checksum` before parsing. A mismatch, or a batch with no recorded checksum, fails closed with the coarse code `CHECKSUM_MISMATCH` (`SourceFileException`), before the staging transaction starts: no rows are staged or deleted and no student or enrollment data is touched. The batch ends `FAILED` through the existing `ImportBatchService::validate()` path and the existing `import.batch.failed` audit event.
* **Not changed:** schema/migrations, import rules, Phase 01C, the concurrency verification script.
* **Tests:** four cases appended to `tests/Feature/Import/ImportValidationTest.php` (matching checksum, modified file, tampering with a previewed batch, missing checksum).
* **Not claimed:** this detects accidental or malicious change of the stored file after staging, not a compromised stage-time upload (the checksum is computed from the file received). Recovery of batches stuck in `VALIDATING`/`PROCESSING` is separate and unchanged.
