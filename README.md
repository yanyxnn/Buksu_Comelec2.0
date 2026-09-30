# BUKSU COMELEC 2.0

BUKSU COMELEC 2.0 is a secure, configurable institutional election platform designed for Bukidnon State University.

The system is designed to support different types of elections using the same reusable election engine instead of creating separate application logic for every election.

It is built around:

- election integrity
- ballot secrecy
- configurable election rules
- secure student authentication
- database-enforced integrity
- reliable vote submission
- reproducible results
- reconciliation and auditability
- backup and disaster recovery
- long-term historical election preservation

---

## Core capabilities

### Secure Google Authentication

The platform uses a single Google sign-in entry point.

Students authenticate through their institutional Google account and are linked to existing student records originating from the official Data Center student-master-data process.

Authorized COMELEC IT administrators are separate from student master data. Administrator records are pre-authorized as part of system/operational setup; administrators do not publicly register and are not created by Data Center imports.

Each administrator has a pre-authorized personal Google email before first login. On the first successful Google login, the verified Google email is matched to the existing administrator record and the stable Google `sub` is bound to that record. Before first login, the admin `google_subject` may be `NULL`.

Student and administrator identity domains are separated and independently authorized.

The system does not create student or administrator records merely because a user signs in with Google.

The system does not create student records from Google login.

---

## Student Master Data

Official student academic information comes from the university Data Center.

The system supports:

- institutional/student ID matching
- institutional email protection
- college
- course/program
- year level
- ACTIVE / INACTIVE status
- import history
- duplicate detection
- validation and preview
- chunked bulk import
- controlled exception handling

Student master data remains separate from election-specific eligibility decisions.

---

## Configurable Elections

BUKSU COMELEC 2.0 is a reusable election engine.

Administrators can configure:

- election name and type
- contests/positions
- seats
- selection limits
- voting methods
- voter eligibility
- candidate eligibility
- representation groups
- parties
- abstention rules
- schedules
- result and reporting configuration

Templates provide starting configurations rather than hard-coded election behavior.

---

## Election Snapshots

Before voting begins, important election data is frozen into approved snapshots.

The system supports:

- election configuration snapshots
- eligibility snapshots
- candidate roster snapshots
- ballot structure snapshots

Once the election is locked, later changes to current student data or editable configuration do not silently change the running election.

---

## Candidate Management

Candidates are represented by normal student identities.

Election-specific candidacies contain the election-specific information needed for the contest.

Candidate management supports:

```text
DRAFT
→ FOR REVIEW
→ VERIFIED
→ APPROVED
→ PUBLISHED
→ LOCKED