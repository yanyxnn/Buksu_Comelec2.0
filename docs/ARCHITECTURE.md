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
Two identity domains, two guards: `student` (table `students`, subject `google_subject`) and `admin` (table `admin_users`, exactly three, role `BUKSU_COMELEC_IT_ADMIN`). Google OAuth via Socialite is the only login. One login page and one callback; `IdentityResolver` maps a verified Google identity to exactly one domain or denies it. Admin routes and Livewire updates pass `EnsureAdmin`; student routes pass `EnsureStudent`. The generic `change_requests` mechanism provides proposal/approval (requester never decides own request). See `SECURITY_AND_PRIVACY.md` and `DATABASE.md`.
