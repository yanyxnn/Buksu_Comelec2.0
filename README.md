# BUKSU COMELEC 2.0

> A secure, reusable, configurable, and scalable election platform for Bukidnon State University.

BUKSU COMELEC 2.0 is being designed as a **long-term election platform**, not a one-time voting website. The system is intended to support small college/department elections as well as large university-wide elections using the same core election engine.

The first production target is the **November 2026 election**, but the architecture is intentionally election-agnostic so future elections can be configured without rewriting the application.

---

## Project Status

**Current phase:** Foundation / Architecture

The Laravel application has been initialized with Blade, Livewire, and MySQL.

The election engine, voting database model, integrity safeguards, live-results infrastructure, backup/recovery subsystem, and other production features are being implemented incrementally according to the approved architecture and documentation.

> **Important:** Do not treat a working UI or partially implemented feature as proof that the election system is production-ready. Every major subsystem must pass its required tests and integrity checks before the project advances.

---

## Core Technology

- **Backend:** Laravel
- **Frontend framework:** Blade + Livewire
- **Styling:** Tailwind CSS
- **Database:** MySQL
- **Authentication:** Google OAuth / Laravel Socialite
- **Realtime:** Laravel Reverb + WebSockets
- **Realtime coordination:** Redis / pub-sub where required
- **Queues:** Laravel Queue
- **Testing:** Laravel/PHP testing stack
- **Version control:** Git

The exact package versions and environment requirements are defined by the project's actual Composer and package configuration.

---

# Project Goals

BUKSU COMELEC 2.0 must provide:

- A reusable election engine
- Fully configurable election rules
- Secure student authentication
- Current student data based on official Data Center uploads
- Configurable eligibility rules
- Controlled candidate management
- Secret-ballot protection
- Atomic and idempotent vote submission
- Protection against duplicate/ghost votes
- Immutable cast ballots
- Reliable result calculation and reconciliation
- Anonymous live candidate leaderboards
- Overall live cast-vote count
- Scalable realtime delivery
- Comprehensive audit and incident tracking
- Automatic and downloadable backups
- Disaster recovery and restore testing
- Historical election archives
- Immutable finalized elections
- Small-to-large election scalability

---

# Architectural Principles

## 1. MySQL is the authoritative election source

MySQL is the source of truth for:

- election state
- eligibility snapshots
- participation
- cast ballots
- ballot selections
- results data
- audit/incident records

Realtime services, caches, queues, and displayed counters are secondary systems.

---

## 2. A vote only exists after a successful database commit

A click, API request, websocket event, or displayed counter does not create a vote.

The authoritative sequence is:

```text
Validate
  ↓
Atomic database transaction
  ↓
Commit successfully
  ↓
Vote exists
```

A failed transaction must not leave a partial vote.

---

## 3. Participation and ballot selections are separated

The system separates:

```text
Voter Participation
      +
Cast Ballot
      +
Ballot Selections
```

A normal application lookup must not provide a simple student-to-candidate-selection relationship.

This separation supports ballot secrecy while still allowing technical investigation of submission and participation status.

---

## 4. Cast ballots are immutable

There is no normal administrator function to:

- edit a vote
- replace a candidate selection
- delete a cast ballot
- reset a student's vote

When something goes wrong, the system creates an integrity incident and preserves the original evidence.

---

## 5. Result counters are not the source of truth

Results are derived from authoritative committed ballots:

```text
Committed Ballots
      ↓
Ballot Selections
      ↓
Result Calculation
      ↓
Aggregates
      ↓
Live Display
```

If an aggregate becomes inconsistent, it must be recalculated from authoritative ballot data.

---

## 6. Realtime is delivery only

Student-facing realtime results are limited to:

1. Overall successfully cast votes
2. Anonymous candidate leaderboards

The realtime channel must never expose:

- voter identity
- student ID
- institutional email
- ballot ID
- submission UUID
- receipt reference
- individual vote selections
- student-to-candidate relationships

If realtime fails, voting data remains safe and clients can recover the current authoritative state.

---

# Election Engine

The application is an **election engine**, not a November-specific application.

Election rules that may change between elections must be configuration-driven rather than hard-coded.

Examples include:

- contests/positions
- election scope
- voter eligibility
- candidate eligibility
- candidate pools
- number of seats
- minimum/maximum selections
- abstention
- representation groups
- schedules
- result/tally methods
- reporting dimensions

Templates/presets are starting configurations only. Each actual election becomes its own normalized and versioned configuration.

---

# Election Lifecycle

The planned lifecycle is:

```text
DRAFT
  ↓
READY
  ↓
APPROVED
  ↓
SNAPSHOTTED
  ↓
LOCKED
  ↓
SCHEDULED
  ↓
OPEN
  ↕
PAUSED
  ↓
CLOSED
  ↓
RECONCILING
  ↓
RESULTS_PENDING_VALIDATION
  ↓
VALIDATED
  ↓
OFFICIALLY_ANNOUNCED
  ↓
FINALIZED
  ↓
HISTORICAL / ARCHIVED
```

Key rules:

- Election opening and closing use backend/server time.
- Emergency pause is immediate.
- A paused election rejects new vote submissions.
- Already committed votes remain valid.
- Locked election configuration cannot be casually changed.
- Finalized elections are immutable.

---

# Student Data Rules

## Permanent identity

The student's **Institutional/Student ID** is the permanent student identity.

Institutional email is protected from ordinary manual editing.

Student status is limited to:

```text
ACTIVE
INACTIVE
```

## Current academic placement

The latest official Data Center upload determines current academic placement such as:

- college
- course/program
- year level
- current status

The application does not independently calculate academic standing from individual subjects.

## Course-change rule

When a student changes course/program, the student's year level in the new course becomes:

```text
1st Year
```

regardless of the previous course/year level.

A student may still be academically treated as irregular by the university, but **Irregular is not a student status in this system**.

## Missing students

A student missing from a later Data Center roster is not automatically assumed to be graduated.

Legitimate election-specific cases, such as late COR validation, may be handled through a controlled election eligibility exception process without falsifying the official Data Center record.

---

# Candidate Management

Candidates are students and do not have separate candidate accounts.

The system separates:

```text
Person / Student Identity
        ↓
Election-specific Candidacy
```

Candidate management supports:

- eligibility validation
- contest assignment
- party affiliation
- official profile/photo data
- approval
- candidate roster creation
- candidate roster snapshot
- roster locking
- conflict validation

Candidate rosters are reviewed and approved before locking.

Candidates use the normal student login and voting flow.

---

# Vote Integrity

Vote integrity is one of the highest-priority architectural concerns.

The system is specifically designed to prevent:

- ghost votes
- duplicate votes
- phantom live counts
- partial submissions
- incorrect result aggregates
- accidental vote alteration
- duplicate retry submissions
- race-condition vote creation

Core mechanisms include:

- database unique constraints
- atomic transactions
- idempotent submission UUIDs
- concurrency protection
- immutable ballots
- post-commit realtime events
- reconciliation
- integrity monitoring
- election incidents
- backup/recovery

An integrity anomaly is never silently corrected.

---

# Live Results

Student-facing live results are intentionally limited.

Students can see:

### Overall Cast Votes

The total number of successfully committed ballots/cast participation according to the authoritative election data.

### Anonymous Candidate Leaderboards

Students can see:

- candidate name
- contest/position
- current vote total
- current rank
- applicable seat/leaderboard information

Students cannot see who voted for whom.

The live-results architecture is designed to scale using:

```text
MySQL
  ↓
Result Aggregation
  ↓
Redis / Pub-Sub
  ↓
Reverb / WebSockets
  ↓
Many Connected Clients
```

The number of realtime viewers should be scalable by adding infrastructure capacity rather than changing election logic.

---

# Results and Reconciliation

Results are calculated from authoritative committed ballots.

The system supports:

- whole-election results
- contest/position results
- candidate totals
- college breakdowns
- course/program breakdowns
- year-level breakdowns
- sector breakdowns where applicable
- participation
- abstention
- percentages
- candidate ranking
- multi-seat contests

Candidate ranking means ranking candidates by final vote total. It is not ranked-choice voting.

## Tie handling

The system detects and flags ties.

The application does **not** decide the winner.

Tie resolution is an institutional/COMELEC decision outside the election engine. The final decision may be recorded for historical and audit purposes.

---

# Finalized Election Integrity

When an election reaches:

```text
FINALIZED
```

it becomes immutable.

Normal administrators cannot alter:

- election configuration
- eligibility snapshot
- candidate roster
- ballots
- ballot selections
- vote totals
- final results
- participation totals

The finalized election becomes part of the institutional Election History.

Historical elections are:

- searchable
- read-only
- reportable
- auditable
- archivable

A previous election may be reused as a **configuration starting point** for a future election, but votes, participation, ballots, receipts, and final results are never copied into a new election.

---

# Backup and Disaster Recovery

Backup is a first-class subsystem.

Planned capabilities include:

- automated database backups
- downloadable full backups
- encrypted backup archives
- election archive packages
- backup manifests
- SHA-256 integrity verification
- separate/offsite copies
- restore testing
- recovery procedures
- backup/download history

A backup is not considered trustworthy merely because the backup job completed. Backups must be verified and periodically restored in a controlled test environment.

---

# Audit and Incident Management

Audit logs and election incidents are separate concepts.

Audit logs record operational/security events such as:

- authentication
- admin actions
- election lifecycle changes
- imports
- approvals
- candidate changes
- exports
- backup operations
- security events

Audit logs must not contain ballot selections or unnecessary sensitive information.

Election incidents track issues such as:

- vote integrity anomalies
- reconciliation mismatches
- database incidents
- realtime failures
- import issues
- security incidents
- production recovery events

---

# Administration

There are exactly three authorized IT administrators.

They have the same base role:

```text
BUKSU_COMELEC_IT_ADMIN
```

The application does not provide normal functionality for admins to create, delete, promote, or demote other admins.

For controlled changes:

```text
Admin proposes
      ↓
All admins notified
      ↓
Different admin approves/rejects
      ↓
Change applied
      ↓
Audit recorded
```

The person who proposes a change cannot approve their own request.

More sensitive election-critical operations may require stronger dual-control procedures.

---

# Development Philosophy

This project should be implemented in controlled phases.

```text
Architecture / Documentation
        ↓
Database / Domain Foundation
        ↓
Authentication / Authorization
        ↓
Student / Data Center
        ↓
Election Engine
        ↓
Eligibility
        ↓
Candidates
        ↓
Voting
        ↓
Vote Integrity
        ↓
Results / Reconciliation
        ↓
Live Results
        ↓
Audit / Incidents
        ↓
Backup / Disaster Recovery
        ↓
Security Hardening
        ↓
Integration Tests
        ↓
Mock Election
        ↓
Load / Failure / Security Testing
        ↓
Production Readiness
```

Major phases should not be skipped simply because a feature appears to work in the browser.

Each phase should have:

- implementation
- automated tests
- integrity checks
- documentation
- review
- explicit completion criteria

---

# Project Documentation

The repository is expected to maintain these authoritative project documents:

```text
CLAUDE.md
docs/
├── REQUIREMENTS.md
├── NON_NEGOTIABLES.md
├── ARCHITECTURE.md
├── DATABASE.md
├── ELECTION_RULES.md
├── ELECTION_CONFIGURATION.md
├── ELIGIBILITY_ENGINE.md
├── VOTING_FLOW.md
├── STUDENT_MANAGEMENT.md
├── CANDIDATE_MANAGEMENT.md
├── RESULTS_AND_ANALYTICS.md
├── SECURITY_AND_PRIVACY.md
├── AUDIT_LOGGING.md
├── DATA_IMPORT.md
├── BACKUP_AND_RECOVERY.md
├── TEST_PLAN.md
├── DEPLOYMENT.md
├── OPEN_DECISIONS.md
└── CHANGE_LOG.md
```

These documents are part of the system's engineering control and should be updated as architectural decisions are finalized.

---

# Repository Rules

Before major implementation work:

1. Review the architecture documentation.
2. Identify dependencies and affected domains.
3. Propose the design.
4. Implement the smallest safe change.
5. Add tests.
6. Run the relevant test suite.
7. Record important changes and decisions.
8. Do not silently change architectural rules.

Never implement a convenience shortcut that weakens:

- ballot secrecy
- vote integrity
- election immutability
- auditability
- recoverability
- authorization
- scalability

---

# Current Development State

At the current starting point:

- Laravel is being set up.
- Blade is configured.
- Livewire is configured.
- MySQL is connected.
- The election application has not yet been implemented.

The next engineering step is to establish the repository/documentation baseline and perform a project audit before building the domain/database layer.

---

# Vision

BUKSU COMELEC 2.0 is intended to become a **long-term institutional election platform** that can safely operate elections of different sizes and different rules without becoming a collection of one-off election implementations.

The system should be:

**Reusable.  
Configurable.  
Scalable.  
Auditable.  
Recoverable.  
Secure.  
Ballot-secret preserving.  
Resistant to vote corruption.  
Designed for failure.**

> **Prevent → Validate → Commit → Protect → Detect → Reconcile → Recover → Preserve**

---

## Development Advisory

Architecture and major changes should be reviewed before implementation.

Claude is used as the implementation assistant. The project owner retains control over architecture, business rules, approval decisions, and production readiness.

Never treat generated code as automatically correct simply because it compiles or the UI appears to work.
