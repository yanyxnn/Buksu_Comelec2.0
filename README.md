# BUKSU COMELEC 2.0

## System Architecture and Platform Overview

BUKSU COMELEC 2.0 is a secure, reusable, configurable, and scalable election management platform for Bukidnon State University.

The system is designed to support elections of different sizes and structures using one common election engine. It is intended for long-term institutional use rather than a single election cycle.

The first production target is the November 2026 election, while the underlying architecture is designed to support future university-wide, college, department, year-level, sectoral, special, and custom elections.

---

# 1. System Purpose

The primary purpose of BUKSU COMELEC 2.0 is to provide an election platform that is:

- Secure
- Auditable
- Scalable
- Configurable
- Reliable
- Recoverable
- Reusable
- Protective of ballot secrecy
- Resistant to duplicate, partial, corrupted, or altered votes

The system is designed around the principle:

> **Prevent → Validate → Commit → Protect → Detect → Reconcile → Recover → Preserve**

---

# 2. High-Level Architecture

The platform is organized into interconnected domains:

```text
                    BUKSU COMELEC 2.0
                           │
        ┌──────────────────┼──────────────────┐
        │                  │                  │
     Identity          Election Engine      Security
        │                  │                  │
        ▼                  ▼                  ▼
    Students          Configuration       Authorization
    Enrollment        Contests            Audit
    Admins            Eligibility         Incidents
                      Candidates
                      Ballots
        │                  │                  │
        └──────────────────┼──────────────────┘
                           │
                           ▼
                     Voting Engine
                           │
             ┌─────────────┼─────────────┐
             ▼             ▼             ▼
        Participation    Ballots       Integrity
             │             │             │
             └─────────────┼─────────────┘
                           ▼
                    Results Engine
                           │
             ┌─────────────┴─────────────┐
             ▼                           ▼
      Reconciliation                Live Results
                                           │
                                           ▼
                                   Realtime Delivery
                                           │
                                     Many Clients

Supporting Systems:
- Data Center Import
- Audit and Incident Management
- Backup and Disaster Recovery
- Historical Election Archive
- Notifications
- Reporting
- System Health Monitoring
```

---

# 3. Technology Architecture

The planned technology stack is:

- **Backend:** Laravel
- **Application interface:** Blade + Livewire
- **Styling:** Tailwind CSS
- **Database:** MySQL
- **Authentication:** Google OAuth
- **Realtime:** Laravel Reverb / WebSockets
- **Realtime coordination:** Redis / pub-sub where required
- **Background processing:** Laravel Queues
- **Testing:** Laravel/Pest testing stack

The exact package versions and infrastructure configuration are environment-dependent, but the domain architecture remains independent of specific frontend presentation.

---

# 4. Core Architectural Principles

## 4.1 MySQL is the authoritative election data source

MySQL is the source of truth for:

- student identity and current official academic data
- election configuration
- eligibility snapshots
- candidate/candidacy data
- voter participation
- cast ballots
- ballot selections
- result calculations
- reconciliation
- audit records
- election incidents

Realtime channels, caches, displayed counters, queues, and reports are secondary systems.

---

## 4.2 A vote exists only after successful database commit

A vote is considered valid only after the complete voting transaction is successfully committed.

The system must not consider the following to be proof of a vote:

- button click
- browser state
- request reception
- websocket event
- queue job creation
- live counter increment

The authoritative sequence is:

```text
Validate
   ↓
Atomic Transaction
   ↓
Database Commit
   ↓
Vote Exists
```

A failed transaction must not leave a partial participation record, partial ballot, or partial ballot selection set.

---

## 4.3 Participation and ballot selections are separate

The system separates:

```text
Voter Participation
       +
Cast Ballot
       +
Ballot Selections
```

Participation records answer:

> Did this student participate in the election?

Ballot records answer:

> What ballot was cast?

Ballot selections answer:

> What was selected on that ballot?

The normal application must not provide a routine lookup that directly associates a student with candidate selections.

---

## 4.4 Cast ballots are immutable

Once a ballot is cast, it cannot be edited through normal administrative functionality.

There is no normal:

- Edit Vote
- Delete Vote
- Change Candidate Selection
- Reset Vote
- Replace Ballot

function.

When a technical problem occurs, the original record and evidence are preserved and an election incident is created.

---

# 5. Election Engine

BUKSU COMELEC 2.0 is a configurable election engine rather than a collection of election-specific code paths.

The engine supports:

- multiple elections
- reusable templates/presets
- custom election configuration
- configurable contests
- configurable scopes
- configurable eligibility
- configurable candidate pools
- configurable seat counts
- configurable selection limits
- abstention
- representation groups
- schedules
- result/tally rules
- reporting configuration

Rules that may reasonably differ between elections must be represented as election configuration rather than hard-coded logic.

---

# 6. Election Lifecycle

The planned election lifecycle is:

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

Important lifecycle rules:

- Backend/server time is authoritative.
- The browser countdown is display-only.
- Emergency pause is immediate.
- A paused election rejects new vote submissions.
- Previously committed votes remain valid.
- Ballot-critical configuration is locked before voting.
- Finalized elections are immutable historical records.

---

# 7. Election Configuration and Snapshots

Before an election becomes active, the system produces and locks approved snapshots, including:

- election configuration snapshot
- eligibility snapshot
- candidate roster snapshot
- ballot structure snapshot

The running election uses these approved snapshots.

Changes to current student records or future election configurations must not silently alter an election that is already locked or running.

---

# 8. Student Identity and Academic Data

## 8.1 Permanent identity

The Institutional/Student ID is the permanent student identity.

Institutional email is protected from ordinary manual editing.

Student status is limited to:

```text
ACTIVE
INACTIVE
```

No separate `IRREGULAR` student status is stored in the system.

## 8.2 Current academic placement

The latest official Data Center data determines current academic placement, including:

- college
- course/program
- year level
- current status

The election platform does not independently calculate academic standing from individual subjects.

## 8.3 Course-change rule

When a student changes course/program:

```text
New course = current course
Year level in new course = 1st Year
```

This applies regardless of the previous course or year level.

## 8.4 Missing student records

A student missing from a later Data Center roster is not automatically assumed to be graduated.

Legitimate election-specific cases may be handled through a controlled eligibility exception process while preserving the official Data Center source record.

---

# 9. Data Center Import Architecture

The normal import process is:

```text
Upload
  ↓
Staging
  ↓
Validation
  ↓
Preview
  ↓
Review
  ↓
Import Confirmation
  ↓
Chunked Processing
  ↓
Import Summary
```

The import system should:

- prevent duplicate institutional identities
- identify new students
- identify changed students
- detect college/course/year changes
- identify invalid records
- record import batches
- preserve import history

The latest official Data Center upload becomes the current official academic record.

---

# 10. Eligibility Engine

Eligibility is configuration-driven.

The architecture supports eligibility sources such as:

- all eligible students
- rule-based eligibility
- selected students
- uploaded voter lists
- custom rules
- college
- program/course
- year level
- sector
- enrollment status
- student status
- specific institutional IDs

Election eligibility is resolved into a locked election-specific snapshot.

Once the election is running, changes to current student data do not silently change the locked eligibility snapshot.

The system also supports controlled election-specific eligibility exceptions for legitimate cases.

---

# 11. Candidate Architecture

Candidates are students and do not have separate candidate accounts.

The model is:

```text
Student Identity
      +
Election-Specific Candidacy
```

The candidate subsystem supports:

- candidate eligibility
- contest assignment
- party affiliation
- candidate profile information
- candidate photo
- candidate conflicts
- candidate approval
- candidate roster
- candidate roster snapshot
- candidate roster locking

Candidate information used by a live election must be approved and locked before voting.

Candidates participate in voting through normal student authentication.

---

# 12. Voting Architecture

The voting system is designed around transactional integrity.

A final submission must perform:

```text
Validate Election State
        ↓
Validate Eligibility
        ↓
Validate Ballot Structure
        ↓
Validate Submission Idempotency
        ↓
Create Participation
        ↓
Create Cast Ballot
        ↓
Create Ballot Items
        ↓
Create Participation Receipt
        ↓
COMMIT
```

All authoritative vote records must succeed or fail together.

## Idempotency

Each final submission uses an idempotent submission identifier so that:

- double-clicks
- repeated requests
- network retries
- mobile connection problems
- browser retries

do not create duplicate votes.

If the result of a submission is uncertain because of a network failure, the system must determine the submission status before allowing a retry.

---

# 13. Abstention

The system supports two distinct concepts:

## Election-level abstention

A student may deliberately abstain from the entire election.

## Contest-level abstention

A student may abstain from an individual contest.

For contest-level abstention:

- Abstain is mutually exclusive with candidate selections.
- An abstention does not affect later contests.
- Abstention is counted separately from candidate selections.

Participation and abstention reporting must clearly distinguish:

- eligible voters
- participating voters
- non-participants
- complete abstentions
- contest abstentions
- candidate selections

---

# 14. Vote Integrity Architecture

Vote integrity is a core system concern.

The system must prevent or detect:

- ghost votes
- duplicate votes
- phantom live counts
- partial submissions
- incorrect aggregates
- race-condition submissions
- inconsistent participation/ballot states
- invalid ballot contents

Core protections include:

- database unique constraints
- foreign keys
- atomic transactions
- idempotency
- concurrency protection
- immutable ballots
- post-commit events
- reconciliation
- integrity monitoring
- election incident management

---

# 15. Integrity Reconciliation

The system continuously verifies relationships such as:

```text
Eligibility
   ↔
Participation
   ↔
Cast Ballots
   ↔
Ballot Items
   ↔
Result Calculations
   ↔
Live Aggregates
```

Examples of integrity anomalies include:

- participation without a ballot
- ballot without valid participation context
- incomplete ballot
- invalid candidate/contest combination
- aggregate mismatch
- result calculation mismatch

An anomaly is recorded and investigated.

It is not silently corrected.

---

# 16. Results Engine

Results are derived from authoritative committed ballots.

The result chain is:

```text
Committed Ballots
      ↓
Ballot Selections
      ↓
Result Calculation
      ↓
Result Aggregates
      ↓
Result Snapshot
```

The system supports:

- overall election results
- contest/position results
- candidate totals
- candidate ranking
- multi-seat results
- college breakdowns
- course/program breakdowns
- year-level breakdowns
- applicable sector breakdowns
- participation
- abstention
- percentages

Candidate ranking is based on final vote totals. It is not ranked-choice voting.

---

# 17. Tie Handling

The system detects and flags ties.

The election platform does not automatically choose a winner.

Tie resolution belongs to the COMELEC President and authorized heads outside the election engine.

The resulting institutional decision can be recorded for historical and audit purposes.

---

# 18. Live Results Architecture

Student-facing live results are intentionally limited to:

### Overall cast votes

The current total number of successfully committed/counted ballots according to authoritative election data.

### Anonymous candidate leaderboards

Students can see:

- candidate name
- contest/position
- current vote total
- current rank
- applicable seat/leaderboard information

The student-facing realtime system must not expose:

- voter identity
- Student ID
- institutional email
- ballot ID
- submission UUID
- receipt/reference number
- individual selections
- student-to-candidate relationships
- administrative diagnostics

Realtime is a delivery layer only.

If realtime delivery fails, voting data remains safe and the client can resynchronize from authoritative backend data.

---

# 19. Realtime Scalability

The realtime architecture is designed to support many simultaneous viewers.

Conceptually:

```text
MySQL
  ↓
Result Aggregation
  ↓
Redis / Pub-Sub
  ↓
Reverb / WebSocket Servers
  ↓
Connected Students and Administrators
```

Realtime servers can scale horizontally.

The election database remains authoritative regardless of the number of connected viewers.

A realtime outage must not become a vote-integrity outage.

---

# 20. Audit Logging

Audit logging records important operational and security events, including:

- authentication events
- administrative actions
- election lifecycle changes
- approvals
- student-data changes
- imports
- candidate changes
- result calculations
- exports
- backup operations
- security events
- sensitive administrative access

Audit logs must not contain:

- passwords
- authentication tokens
- candidate selections
- unnecessary sensitive personal data

Audit logging must support investigation without becoming a mechanism for reconstructing individual ballot choices.

---

# 21. Election Incident Management

Election incidents are separate from routine audit logs.

Incidents may include:

- vote integrity anomalies
- participation/ballot mismatch
- result mismatch
- invalid ballot conditions
- database failure
- queue failure
- realtime failure
- backup/restore incidents
- security incidents
- configuration issues

Each incident preserves:

- what happened
- when it happened
- how it was detected
- investigation history
- resolution
- responsible administrators
- supporting records

---

# 22. Administrative Security

The system has exactly three authorized IT administrators.

All three use the same base role:

```text
BUKSU_COMELEC_IT_ADMIN
```

The platform does not provide normal functionality for admins to:

- create another admin
- delete another admin
- change admin roles
- manage the authorized administrator identities

For controlled changes:

```text
Admin proposes
      ↓
All admins notified
      ↓
Independent admin approves/rejects
      ↓
Change applied
      ↓
Audit recorded
```

The proposer cannot approve their own request.

Highly sensitive election operations may require stronger dual-control authorization.

---

# 23. Backup and Disaster Recovery

Backup and recovery are first-class platform functions.

The architecture supports:

- automatic database backups
- downloadable full backups
- encrypted backup archives
- election archive packages
- uploaded-file/asset backups
- backup manifests
- cryptographic integrity checks
- separate/offsite backup copies
- restore verification
- restore testing
- disaster recovery procedures

A backup is not considered reliable merely because a backup job completed successfully.

Backups must be verified and periodically restored in a controlled environment.

---

# 24. Finalized Election Integrity Lock

When an election reaches:

```text
FINALIZED
```

it becomes immutable.

Normal administration cannot modify:

- election configuration
- eligibility snapshot
- candidate roster
- participation
- cast ballots
- ballot selections
- vote totals
- final results

If a genuine post-election issue must be recorded, it is handled through a separate incident/correction record without silently rewriting the finalized election.

---

# 25. Election History

The platform maintains a searchable history of previous elections.

Historical elections can retain:

- election metadata
- configuration snapshot
- eligibility snapshot
- candidate roster
- final results
- participation
- reconciliation history
- incident history
- audit history
- archive information

Historical elections are read-only.

A previous election may be used as a starting configuration for a future election.

The new election must never inherit:

- votes
- ballots
- participation
- receipts
- final results

---

# 26. Reporting and Official Results

The results subsystem can generate reports covering:

- overall election
- contest/position
- candidate
- college
- course/program
- year
- sector where applicable
- participation
- abstention
- validation
- reconciliation
- incidents

The system is designed to produce reproducible result snapshots.

The external publication of officially approved results remains an institutional process; the platform maintains and exports the official election record.

---

# 27. System Reliability

The architecture distinguishes critical and non-critical services.

Secondary service failures should not corrupt authoritative election data.

Examples:

- Realtime failure should not lose votes.
- Queue failure should not undo committed votes.
- Notification failure should not stop voting.
- Report generation failure should not corrupt election data.
- Backup-generation failure should not alter votes.
- Database failure should cause safe refusal of new commits rather than accepting unverifiable votes.

---

# 28. Scalability

The same election engine must support:

- small elections
- college elections
- department elections
- year-level elections
- sectoral elections
- university-wide elections

Scaling is achieved through:

- infrastructure capacity
- database optimization
- background processing
- caching/aggregation
- horizontally scaled realtime services
- queue workers
- appropriate indexing
- load testing

Election logic must not fork into separate "small election" and "large election" implementations.

---

# 29. Historical and Institutional Continuity

The platform is designed to become a long-term institutional election record.

Each completed election forms a preserved unit of:

```text
Configuration
+
Eligibility
+
Candidate Roster
+
Participation
+
Ballot Data
+
Results
+
Reconciliation
+
Incidents
+
Audit History
+
Archive Metadata
```

This allows future elections to be built using the same engine while preserving previous elections as immutable institutional history.

---

# 30. Overall System Flow

The complete platform follows this general lifecycle:

```text
Configure Election
        ↓
Define Eligibility
        ↓
Prepare Candidates
        ↓
Review and Approve
        ↓
Create Snapshots
        ↓
Lock Election
        ↓
Schedule / Open
        ↓
Accept Secure Votes
        ↓
Record Participation
        ↓
Commit Immutable Ballots
        ↓
Calculate Results
        ↓
Publish Anonymous Live Aggregates
        ↓
Reconcile
        ↓
Validate Results
        ↓
Official Announcement
        ↓
Finalize
        ↓
Archive as Historical Election
```

When an integrity or operational problem occurs:

```text
Detect
  ↓
Protect / Pause if necessary
  ↓
Preserve Evidence
  ↓
Create Incident
  ↓
Investigate
  ↓
Reconcile
  ↓
Recover
  ↓
Document Resolution
```

---

# 31. System Design Objective

BUKSU COMELEC 2.0 is designed to become a long-term institutional election platform with a strong emphasis on:

**Election integrity**  
**Ballot secrecy**  
**Reliability**  
**Auditability**  
**Scalability**  
**Recoverability**  
**Configurability**  
**Historical preservation**

The system should remain useful whether an election involves a small group of voters or a university-wide population.

The objective is not simply to make voting work.

The objective is to make the entire election lifecycle **controlled, verifiable, recoverable, reproducible, and suitable for long-term institutional use.**
