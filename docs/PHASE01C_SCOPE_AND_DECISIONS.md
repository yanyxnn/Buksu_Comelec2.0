# Phase 01C — Scope and decision register

**Status:** documentation only. Phase 01C is **IN PROGRESS / NOT complete**.
**Base:** commit `c4f6c86` (Phase 01C D3). **Recorded:** 2026-11-23.
Related: `docs/PHASE01C_VERIFICATION_REGISTER.md`, `docs/PHASE01C_PRIVILEGE_MATRIX.md`, `docs/PHASE01C_PATCH_STATUS.md` (D3 only).

## Part A — Phase 01C scope

Source: `docs/DATABASE.md` (Implementation status; Section 15), `docs/TEST_PLAN.md`.

**In scope.** Verification of the Phase 01B schema on MariaDB for database integrity, idempotency, concurrency, immutability, secrecy-boundary behaviour (Domain A / Domain B as defined in `DATABASE.md` Section 7) and reconciliation; the D3 Domain B identifier correction; the MariaDB 10.4.32 gate; sign-off. Gates required by the documentation: security, regression, concurrency, reconciliation, MariaDB-version and sign-off.

**Later application implementation work (not part of this documentation milestone).** The ballot writer and runtime UUIDv4 generation. Whether they are prerequisites for final Phase 01C sign-off is **open** (S-2).

**Never touched by Phase 01C documentation work.** Phase 04; the untracked local migration `2026_11_12_170707_add_contest_jurisdiction_fields_to_contests_table.php`; the private untracked `phpunit.mariadb.xml`.

**Invariants that apply to all Phase 01C work.** Domain A (voter identity: `students`, `voter_participations`, `submission_attempts`) and Domain B (anonymous ballot lifecycle: `ballots`, `ballot_contest_responses`, `ballot_candidate_selections`, `ballot_reporting_contexts`, `ballot_events`, `ballot_dispositions`) stay separate. There is no persistent join key between them. No voter identity enters an anonymous ballot record.

## Part B — Objective ledger

| Objective | Status |
|---|---|
| D3: Domain B identifiers `CHAR(36)` with lowercase-UUIDv4 `CHECK` on four primary keys; forward-only, empty-table-only, fail-closed | **Completed; verified on MariaDB 10.4.32** (47/0, 89/0, 8/0) |
| D3 verification-harness hardening (allowlist, `db:wipe` guard, fixture cleanup, optional gate-server requirement) | **Completed; verified** |
| D3 documentation with limitations | **Completed** |
| Phase 01B regression gate remains runnable | **Completed** (A1 in the register) |
| Integrity verification beyond D3 and Phase 01B | Outstanding |
| Idempotency verification (`submission_uuid`; `(election_id, student_id)`) | Outstanding |
| Concurrency verification | Outstanding |
| Immutability / database-role privilege verification | Outstanding (privilege matrix is PROPOSED only) |
| Reconciliation oracles and seeded-corruption tests | Outstanding |
| Secrecy-boundary review of ordering, timestamp and small-cell correlation channels | Outstanding; depends on deferred decisions D-1 to D-3 |
| Expanded suite on MariaDB 10.4.32 | Outstanding (D-5) |
| Documentation reconciled with repository reality | Addressed by this milestone |
| Sign-off | Outstanding |

## Part C — Decision register

Two series are kept apart. **D3-D1 to D3-D8** are the decisions for the D3 Domain B identifier migration. **D-1 to D-5** are the newer Phase 01C owner decisions. **S-1, S-2** record scope statements.

### C.1 D3 decisions (D3-D1 to D3-D8)

| ID | Decision | State |
|---|---|---|
| D3-D1 | Forward-only migration; the six historical migrations are preserved. | Decided |
| D3-D2 | Empty-table-only, fail-closed: the migration changes nothing unless all six Domain B tables are empty. | Decided |
| D3-D3 | Database-level lowercase UUIDv4 `CHECK` constraints on the four primary keys, provided MariaDB 10.4.32 verification confirms enforcement. | Decided; enforcement confirmed on 10.4.32 |
| D3-D4 | Destructive verification only against the exact allowlisted throwaway database `buksu_p1c_throwaway_test`, with explicit cleanup confirmation, identity verification and server details printed. | Decided; implemented |
| D3-D5 | Claims only from captured evidence; no 10.4.32 claim before the owner runs the tests. | Decided |
| D3-D6 | Migration timestamp: `2026_11_13_000100`. It sorts after the pending Phase 04 migration dated `2026_11_12_170707`. **Qualification:** this check was based on file names only; the contents of the Phase 04 migration were not reviewed. | Decided |
| D3-D7 | One complete, self-contained patch. | Decided |
| D3-D8 | No repository-tracked checksum manifest was found. External or locally stored manifests remain **unverified** (their absence is not established). | Recorded |

### C.2 Phase 01C owner decisions (D-1 to D-5)

| ID | Decision | State |
|---|---|---|
| D-1 | Ordinal (auto-increment) identifiers on `ballot_candidate_selections` and `ballot_reporting_contexts`. | **Deferred** |
| D-2 | Timestamp granularity of Domain B rows relative to Domain A `participated_at`. | **Deferred** |
| D-3 | Minimum cell size for detailed results (already an open item in `docs/OPEN_DECISIONS.md`). | **Deferred** |
| D-4 | Production database accounts and privileges. Until decided, the privilege matrix is descriptive and PROPOSED only. | **Deferred** |
| D-5 | Any newly developed expanded verification suite must be tested on MariaDB 10.4.32. | **Required** |

### C.3 Scope statements

| ID | Statement | State |
|---|---|---|
| S-1 | The ballot writer and runtime UUIDv4 generation are later application implementation work and are excluded from this documentation-only milestone. | Stated by the owner |
| S-2 | Whether S-1 is a prerequisite for final Phase 01C sign-off. | **Open** |

### C.4 Other recorded points

- Earlier documentation describes the Phase 01C design as approved. Its standalone approval and transaction/security decision record has not been recovered, so the approval scope remains unverified (register item U10).
- The privilege matrix reconciliation-verifier risk (FP4) is classified **high-risk and unresolved**; dual-domain row-level access is **not approved**.
- The privilege matrix backup risk (FP6) is classified **high-risk and unresolved**; cross-domain backup access is **not approved** as an operational privilege. No mitigation is selected or approved.
- The Domain A / Domain B naming in `DATABASE.md` was corrected: "Domain A" and "Domain B" now refer only to the secrecy boundary of Section 7.

## Part D — Not claimed

- Phase 01C is not complete.
- No result in this document was produced by the author of this change; recorded results come from the project owner and are cited to their documents.
- No expanded-suite result exists on any MariaDB version in the repository.
- Nothing here approves a database account, grant, role or privilege.
