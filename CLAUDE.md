# CLAUDE.md — BUKSU COMELEC 2.0

## Mission
Build BUKSU COMELEC 2.0 as a reusable, configurable, secure election platform for small-to-large elections. November 2026 is a production configuration of the engine, not a one-off application.

## Working rules
1. Read `docs/REQUIREMENTS.md`, `docs/NON_NEGOTIABLES.md`, `docs/ARCHITECTURE.md`, and the relevant domain document before implementing a feature.
2. Do not invent election rules. If an institutional rule is not confirmed, mark it as an open decision and keep the engine configurable.
3. Do not hard-code November-specific behavior into reusable services.
4. Do not change locked architecture decisions without recording a Change/Architecture Decision in `docs/CHANGE_LOG.md`.
5. Prefer domain services, policies, actions, value objects, state machines, database constraints, and tests over controller-heavy business logic.
6. Database integrity rules are required in addition to application validation.
7. Cast ballots are immutable under normal administration. There is no normal “edit vote” feature.
8. MySQL is the authoritative source of election data. Redis/Reverb/queues are not authoritative.
9. Live student results expose only overall successfully cast votes and anonymous candidate leaderboards.
10. Never log candidate selections together with voter identity. Receipts prove participation only.
11. Vote submission must be atomic and idempotent and must safely handle timeout/retry/race conditions.
12. Realtime vote/result events are dispatched only after successful database commit.
13. Finalized elections are immutable historical records.
14. Any integrity mismatch becomes an incident/anomaly; never silently correct it.
15. Backups must be encrypted/verified and restore-tested. Downloadable backups are additional recovery options, not the only backups.
16. Do not build detailed frontend visuals before backend/domain contracts are sound.
17. Keep tests beside every important domain behavior. Prefer failure-mode tests, not only happy paths.
18. Before large changes, explain files affected, data-model impact, security impact, and rollback/migration considerations.

## Current stack
- Laravel
- Blade + Livewire + Tailwind
- MySQL
- Laravel Socialite / Google OAuth
- Redis for cache/pub-sub/queue coordination as applicable
- Laravel Reverb/Echo for realtime delivery

## Core roles

Administrator accounts are pre-authorized system records and are provisioned independently of Data Center student imports. There is no public administrator registration or self-registration flow.

Each authorized administrator has a pre-authorized personal Google email before first login. `admin_users.google_subject` may be `NULL` until first successful Google login; the verified Google email is matched to the pre-authorized record and the stable Google `sub` is then bound. Subsequent administrator authentication uses the bound stable `sub`.

The current initial roster consists of three operationally authorized IT administrators, all with role `BUKSU_COMELEC_IT_ADMIN`. The current count of three is an operational roster decision, not a permanent database or application cardinality limit. Do not introduce a permanent `MAX_ADMINS = 3` schema rule.

Data Center imports apply to student master data only and never create, discover, or provision administrator records.

Candidates are normal students; they do not have candidate accounts.

## Student identity rules
- Institutional/Student ID is the permanent student identity.
- Institutional email is locked from ordinary editing.
- Student status is only `ACTIVE` or `INACTIVE`.
- Current official Data Center upload is authoritative for current academic placement.
- Course change means the student's year level in the new course is 1st Year, regardless of previous course/year. The application does not calculate year from individual subjects.
- Missing from a later roster does not automatically mean graduated; use current roster/status rules and controlled election-specific exceptions where legitimately verified.

## Voting integrity
A vote is real only when the complete authoritative DB transaction commits successfully. Participation and ballot selections are separated. `submission_uuid`/idempotency and DB constraints protect against duplicate submissions. Ballots are immutable after casting.

## Election lifecycle
`DRAFT → READY → APPROVED → SNAPSHOTTED → LOCKED → SCHEDULED → OPEN ↔ PAUSED → CLOSED → RECONCILING → RESULTS_PENDING_VALIDATION → VALIDATED → OFFICIALLY_ANNOUNCED → FINALIZED`

## Finalization
Once FINALIZED, election configuration, eligibility snapshot, candidate roster, ballots, results, and final reports are read-only through normal application operations. Historical elections remain searchable and downloadable. Any post-finalization issue is documented as an incident/correction record; the original record is not silently modified.

## Before declaring a feature complete
- Run the relevant automated tests.
- Run integrity/reconciliation checks if voting/results are affected.
- Verify audit events do not contain prohibited sensitive data.
- Verify no ballot secrecy relationship was introduced.
- Update documentation and change log where necessary.
- Report exactly what changed, what was tested, and what remains open.
