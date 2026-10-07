# ARCHITECTURE

## 1. Architectural style
Layered/domain-oriented Laravel application with explicit domain services, policies, state machines, jobs/events, repositories or query services where useful, and strong database constraints.

## 2. Main domains
- Identity & Authentication
- Student Master Data
- Election Configuration
- Eligibility
- Candidate & Candidacy
- Voting & Ballot Integrity
- Results & Reconciliation
- Audit & Incidents
- Notifications
- Backup & Disaster Recovery
- Election History
- Operational Health

## 3. Authoritative data
MySQL is authoritative. Redis is for cache/pub-sub/coordination. Reverb is delivery. Queues are processing infrastructure. Live aggregates are derived.

## 4. Vote path
Authentication → election state → eligibility snapshot → ballot structure → submission UUID → validation → atomic transaction → participation + ballot + ballot items + outbox/technical events → commit → post-commit result/broadcast processing.

## 5. Realtime path
Committed vote → result aggregation → Redis/pub-sub → horizontally scalable Reverb nodes → connected clients. Clients use authoritative result refresh after reconnect/missed events.

## 6. Failure principle
Critical voting operations fail closed. Secondary services may lag/fail without invalidating a committed vote.

## 7. Historical principle
FINALIZED elections are read-only historical records. Prior elections may seed configuration for a new draft but never copy votes/participation/results.

## 8. Security principle
Least privilege, controlled admin operations, secret separation, audit logging, ballot secrecy, encrypted backups, restore testing, and explicit incident handling.

## 9. Authentication & authorization (Phase 02)

Two identity domains, two guards: `student` (table `students`, subject `google_subject`) and `admin` (table `admin_users`, subject `google_subject`, role `BUKSU_COMELEC_IT_ADMIN`). Google OAuth via Socialite is the only login mechanism.

Student and administrator identities have separate provisioning sources. Student identity records are established and maintained through the official Data Center student-master-data process. Administrator records are pre-authorized system records and are not created, discovered, or provisioned from Data Center student imports.

Administrators do not publicly register. There is no public admin registration flow, password registration flow, or admin self-registration path.

The initial/current administrator roster consists of three operationally authorized administrators. This is an operational roster decision, not a permanent database or application cardinality limit. The system must not treat `3` as a permanent schema-level maximum.

Each pre-authorized administrator has an authorized personal Google email recorded in the administrator account before first login. The authorized email is independent of the Google `sub` binding.

`admin_users.google_subject` may be `NULL` before the administrator completes a successful first Google login. The field is unique when populated.

On first Google login, the system requires a verified Google email and matches that verified Google email against the pre-authorized administrator record. On a successful email match, the administrator's stable Google `sub` is bound to that existing administrator record. The Google login does not create a new administrator account and does not grant administrator access solely because the email was presented by Google.

After the first successful binding, subsequent administrator authentication resolves by the bound stable Google `sub`. The system does not replace an existing admin binding merely because another Google identity presents the same email.

Student first-linking remains separate: a verified institutional Google email is matched to an existing Data Center-loaded student record whose `google_subject` is `NULL`, after which the stable Google `sub` is bound to that student record.

`IdentityResolver` maps a verified Google identity to exactly one identity domain or denies it. A Google identity that would resolve to both domains is denied. Admin routes and Livewire updates pass `EnsureAdmin`; student routes pass `EnsureStudent`.

The generic `change_requests` mechanism provides proposal/approval (requester never decides own request).

See `SECURITY_AND_PRIVACY.md` and `DATABASE.md` for the detailed authentication, authorization, identity-binding, and data-model rules.

## Student master data import (Phase 03A)
The Data Center import is a domain/application service, not controller logic: `App\Services\StudentImport\ImportBatchService` owns the lifecycle (`BatchStateMachine` is the only definition of legal status transitions), `ImportValidator` stages and classifies rows into `import_batch_rows`, and `ImportChunkProcessor` applies confirmed rows to `students`/`student_enrollments` in per-chunk database transactions. Large files are processed in the background by the queued `ProcessImportBatch` job (restartable, idempotent, one at a time per batch via a batch-row lock). Institution-shaped values (header aliases, year-level labels, chunk size, limits) live in `config/comelec.php`, not in code. The importer writes only student master data and staging/batch records: it never touches `admin_users`, ballots, participation or eligibility. Details and guarantees: `DATA_IMPORT.md`.
