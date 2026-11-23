# Phase 01C — Domain A / Domain B privilege matrix

> **PROPOSED / UNVERIFIED.** This matrix is not approved, not implemented and not verified.
> The functional principals FP1–FP6 are **descriptive placeholders**. They are **not** real database accounts, and nothing here is an approved grant.
> No SQL `GRANT`, role or account is created by this document. Real accounts and privileges are deferred (decision **D-4**).

**Base:** commit `c4f6c86`. **Recorded:** 2026-11-23. Related: `docs/PHASE01C_SCOPE_AND_DECISIONS.md`, `docs/PHASE01C_VERIFICATION_REGISTER.md`.

## 1. Source principles

From the project documentation:
- Cast ballots are immutable evidence; normal administration cannot edit, delete, replace or reset a cast ballot (`DATABASE.md`, core principles).
- Ordinary admin features must not have unrestricted direct modification access to cast ballot data (`SECURITY_AND_PRIVACY.md`, least privilege).
- Domain A (voter identity: `students`, `voter_participations`, `submission_attempts`) and Domain B (anonymous ballot lifecycle: `ballots`, `ballot_contest_responses`, `ballot_candidate_selections`, `ballot_reporting_contexts`, `ballot_events`, `ballot_dispositions`) have no persistent join key (`DATABASE.md`, Section 7).
- No triggers are used; integrity rests on constraints, transactional enforcement and reconciliation, so database privileges are one of the controls protecting immutability.

## 2. Limits of this control

Database privileges restrict ordinary application and administrative paths. They do **not** constrain a database administrator or a highly privileged session, which can bypass them (including by disabling `check_constraint_checks`). Those cases need procedural and audit controls and are outside this matrix.

## 3. Functional principals (placeholders)

| Placeholder | Description |
|---|---|
| FP1 | Vote-submission principal: the future atomic submission transaction only |
| FP2 | General application / administrator features |
| FP3 | Results / reporting reader |
| FP4 | Reconciliation verifier (read-only) |
| FP5 | Migration / DDL |
| FP6 | Backup |

## 4. Draft matrix

I = INSERT, S = SELECT, U = UPDATE, D = DELETE, DDL = schema changes, — = none. All entries are **PROPOSED**.

| Object | FP1 | FP2 | FP3 | FP4 (see section 5) | FP5 | FP6 (see section 5) |
|---|---|---|---|---|---|---|
| Domain A: `students` | S | S, I, U (import/auth behaviour as implemented today; no D) | — | S (counts only) | DDL | S |
| Domain A: `voter_participations` | I (and S for idempotency checks) | — | — | S (counts only) | DDL | S |
| Domain A: `submission_attempts` | I, U (status) | — | — | S (counts only) | DDL | S |
| Domain B: `ballots` | I | — | S | S (counts only) | DDL | S |
| Domain B: `ballot_contest_responses` | I | — | S | S (counts only) | DDL | S |
| Domain B: `ballot_candidate_selections` | I | — | S | S (counts only) | DDL | S |
| Domain B: `ballot_reporting_contexts` | I | — | S | S (counts only) | DDL | S |
| Domain B: `ballot_events` | I (technical events, if designed) | I via a controlled exceptional procedure only (open) | — | S | DDL | S |
| Domain B: `ballot_dispositions` | — | I via a controlled exceptional procedure only (open) | — | S | DDL | S |
| Election configuration, snapshots, eligibility | S | by lifecycle state (administrator writes only before LOCKED) | S | S | DDL | S |
| `outbox_events` | I | S | — | S | DDL | S |
| Results tables (`result_*`) | — | S | I (calculation) | S | DDL | S |
| `audit_logs` | I | I | — | S | DDL | S |

## 5. Rules and open items

1. **No principal holds UPDATE or DELETE on any of the six Domain B tables.** Corrections are made through append-only `ballot_events` and `ballot_dispositions`.
2. **FP2 (ordinary administrator features) has no privilege on cast-ballot rows.**
3. **FP3 has Domain B read access and no Domain A access.**
4. **FP1 is the only principal with write access to both domains.** This is where the transient correspondence exists inside the atomic vote transaction. It must never persist a link: no shared identifier, hash or reused correlation identifier. No voter identity enters any Domain B row.
5. **FP4 — HIGH-RISK, UNRESOLVED.** A reconciliation verifier that can read both domains could join Domain A and Domain B rows by ordering or timestamps and so weaken ballot secrecy. **Dual-domain row-level access is NOT approved.** The "S (counts only)" entries in the FP4 column state a reconciliation *need* (compare counts), not an approved grant. Mitigation options remain under consideration and none is chosen: separate per-domain verifiers; restricted aggregate procedures or views; other options to be proposed.
6. **FP6 — HIGH-RISK, UNRESOLVED.** The backup placeholder is shown with read access to both Domain A and Domain B. **Cross-domain backup access is NOT approved as an operational privilege.** Read-only access does not remove the risk: backups of both domains could permit correlation and re-identification through identifiers, ordering, timestamps and metadata. The "S" entries in the FP6 column state that backups are needed (see `BACKUP_AND_RECOVERY.md`), not an approved grant. Possible mitigations, **none selected and none approved**: separate backups per domain; separate encryption keys; restricted restoration access; separation of duties. These remain under consideration.
7. Entries marked "open" depend on the later application design (S-1/S-2), on D-1, D-2 or D-4.
8. **Verification:** a privilege test, when developed, must be run on MariaDB 10.4.32 (D-5). None exists in the repository (register item U7). This matrix therefore remains PROPOSED / UNVERIFIED.

## 6. Not claimed

No account, role or grant exists or is approved; no privilege test has been run; Phase 01C is not complete.
