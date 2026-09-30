# TEST PLAN

## Testing layers
Unit → Feature → Integration → Database integrity → Concurrency/idempotency → Security → Realtime/load → Disaster recovery → Full mock election → Production readiness.

## Critical vote tests
- Double click/duplicate request.
- Same submission UUID replay.
- Simultaneous submissions by same student.
- Timeout after commit.
- Timeout before commit.
- DB rollback during vote transaction.
- Pause during submission.
- Close during submission.
- Invalid contest/candidate/selection.
- Invalid abstention combination.
- Eligibility mismatch.
- Partial ballot prevention.

## Reconciliation tests
Participation = ballots, ballots contain valid items, aggregates equal independent calculation, live aggregate never becomes authoritative, finalized result reproducible.

## Realtime tests
Hundreds/thousands of viewers, vote bursts, duplicate broadcast, missed event, disconnect/reconnect, Reverb node failure, Redis/queue degradation.

## Security tests
Authorization, privilege escalation, CSRF/session behavior, XSS/injection, file uploads, rate limits, OAuth flows, admin approval/self-approval prevention, backup access.

## Import tests
Duplicate IDs, malformed rows, large files, college/course/year changes, missing students, re-imports, import failures/rollback, manual changes overridden by later official upload.

## Disaster tests
Database restore, backup corruption detection, production recovery procedure, application outage, queue failure, realtime failure, deployment rollback.

## Load tests
Use progressively increasing concurrent users, simultaneous submissions, realtime viewers, reporting workload, imports, and backup operations. Establish measurable limits before production.

## Phase 02 tests (authentication, admin authorization, approvals)
Automated with a fake Google provider; no real Google calls. Suites: `tests/Feature/Auth/*`, `tests/Feature/Approval/*`, `tests/Feature/StockAuthRemovalTest.php`.
- One Google login entry point (no credentials form, no separate admin login, exact authentication route inventory).
- Student login, first-link, repeat login by `sub`, INACTIVE student can authenticate, no student created, no Google data written, non-Data Center-loaded rows denied.
- Access Issue flow: same message for every denial; only verified institutional accounts can submit (non-institutional, look-alike and unverified accounts cannot); every problem type accepted/stored, invalid or missing type rejected, list is config-backed and fails closed when empty; `denial_reason` stays system-generated and independent of `problem_type`; no student created/changed, no access granted, single-use, validation, rate limit, no OAuth values stored/logged; admin read-only view (all required columns, readable labels, escaping).
- Admin login, unauthorized/unverified identities rejected, exactly-three roster and fixed-role enforcement, provisioning command rules.
- Student/admin identity separation, duplicate/conflicting identity rejection, atomic first-link and unique-index behaviour.
- Session fixation, logout, cross-guard isolation, mixed-session rejection.
- Admin/student route protection, no admin-management routes, Livewire persistent-middleware boundary (real HTTP to the update endpoint; `Livewire::test()` bypasses it).
- Approvals: self-approve/reject refused, approval/rejection by another admin, atomic race, all-admin in-app notification in the same transaction, audit evidence, no sensitive data.
- Engine notes: the MySQL/MariaDB CHECK test skips on SQLite; the case-variant-duplicate-email test skips on case-insensitive engines. **Still to do:** a real Google OAuth smoke test on staging (documented, not part of the automated suite). Running on MySQL 8 is not a Phase 02 gate.

## Testing layers

Unit → Feature → Integration → Database integrity → Concurrency/idempotency → Security → Realtime/load → Disaster recovery → Full mock election → Production readiness.

The test strategy distinguishes between:

- design approval
- implementation
- verification
- production-readiness gates

Passing application tests does not by itself establish database integrity, ballot secrecy, concurrency safety, or production readiness.

---

# 1. Core testing principles

The system must prove that authoritative election data remains correct under:

- normal voting
- duplicate requests
- retries
- transaction failure
- database failure
- concurrent submissions
- election pause/close races
- unauthorized database access
- invalid ballot content
- reconciliation anomalies
- result recalculation

Critical integrity must be demonstrated against the authoritative MySQL/MariaDB database engine rather than relying only on SQLite application tests.

---

# 2. Phase 01B regression

The canonical Phase 01B integrity suite remains the baseline schema regression test.

Expected result:

```text
76 passed / 0 failed