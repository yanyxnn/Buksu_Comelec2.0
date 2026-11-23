# Phase 01C — Verification register

**Status:** documentation only. Phase 01C is **IN PROGRESS / NOT complete**.
**Base:** commit `c4f6c86` (Phase 01C D3). **Recorded:** 2026-11-23.

This register records, for every verification artifact that the project documentation mentions or claims for Phase 01C, where it is, and what evidence exists. It adds no new verification and makes no pass claim of its own.

## 1. Status vocabulary

| Status | Meaning |
|---|---|
| **Recovered** | The artifact exists in the committed repository, or is identified outside it and named as such. Says nothing about whether it passed. |
| **Verified** | A result was captured by the project owner on a named environment (host, port, MariaDB version, database, counts, exit code) and recorded in the repository documentation. |
| **Unverified** | Claimed in documentation, but no artifact and no captured evidence supports the claim. |
| **Not found in searched locations** | Searched for and not found. Further recovery remains possible. This is not the same as "does not exist". |
| **Outstanding** | Required by the documentation; not yet done or run. |

An artifact can be Recovered without being Verified. Nothing moves to Verified without a captured result (environment, counts, exit code, who ran it, date).

## 2. Search record (as reported by the project owner, 2026-11-23)

Searched:
- Current branch `phase01c-d3-uuid-migration`; local branch `phase1b-integrity-fix`; no dedicated remote Phase 01C branch found.
- `git log --all --oneline --name-status -- database/verification` (history shows: original Phase 01B integrity harness, later Phase 01B integrity fixes, Phase 03A import concurrency and queue-recovery scripts, Phase 01C D3 refusal and UUIDv4 schema scripts).
- Stashes: none.
- Untracked and ignored files in `database/verification`: none.
- Project and Downloads folders: D3 patch versions and reports, D3 design reviews, Phase 01C remaining-work assessments, `docs/SECURITY_AND_PRIVACY.md`, `docs/VOTING_FLOW.md`, `phase03a_import_concurrency.php`.

**Not exhaustively searched:** ZIP archives; sibling worktrees. Any item below classed "Not found in searched locations" may still be recovered from those.

## 3. Register

### 3.1 Recovered, and verified

| ID | Artifact | Location | Status | Captured evidence |
|---|---|---|---|---|
| A1 | Phase 01B integrity harness with D3 checks: `phase1b_integrity_tests.sh` | `database/verification/` | Recovered; **Verified** | 89 passed / 0 failed, exit 0; MariaDB 10.4.32, port 3307, host GOBLIN, database `buksu_p1c_throwaway_test` (project owner, 2026-11-23); see `docs/PHASE01C_PATCH_STATUS.md` |
| A2 | D3 refusal and recovery tests: `phase01c_d3_refusal_tests.sh` | `database/verification/` | Recovered; **Verified** | 47 passed / 0 failed, exit 0; same environment |
| A3 | D3 schema test: `phase01c_d3_uuidv4_schema_tests.sh` | `database/verification/` | Recovered; **Verified** | 8 passed / 0 failed, exit 0; same environment |
| A4 | Pest suite on SQLite | `tests/` | Recovered; **Verified** | 494 passed, 1 skipped, 10,113 assertions, exit 0 (project owner, 2026-11-23) |

### 3.2 Recovered, not part of Phase 01C voting verification

| ID | Artifact | Location | Status | Note |
|---|---|---|---|---|
| A5 | `phase03a_import_concurrency.php`, `phase03a_queue_recovery.php` | `database/verification/` | Recovered | Student-import scripts. **Not voting tests and not counted toward Phase 01C concurrency.** |
| A6 | Phase 01B original integrity harness and fix history | git history; local branch `phase1b-integrity-fix` | Recovered (history) | Content is carried by A1. |
| A7 | D3 patch versions, D3 design reviews and reports, Phase 01C remaining-work assessments | Outside the repository (Project / Downloads) | Recovered outside the repository | Not part of commit `c4f6c86`; informational only. |

### 3.3 Claimed in documentation, not supported by an artifact

All of these were claimed in `docs/DATABASE.md` (Implementation status, Phase 01C) before this reconciliation. They were recorded there as "verified so far". **No artifact and no captured evidence supports them.** The historical figures are listed only so they can be identified; they are **unsupported historical claims and are not results of this project's verified record**.

| ID | Claimed artifact or result | Historical figure (unsupported) | Status |
|---|---|---|---|
| U1 | "Phase 01C baseline" suite | "23 passed / 0 failed" | **Not found in searched locations; further recovery remains possible.** Unverified. (The figure 23 also appears as the Phase 01B ENUM-column count; whether the two were conflated is unconfirmed.) |
| U2 | "Extended Phase 01C" suite | "56 passed / 0 failed" | Not found in searched locations; further recovery remains possible. Unverified. |
| U3 | Reconciliation oracles | "11 passed / 0 failed" | Not found in searched locations; further recovery remains possible. Unverified. |
| U4 | "Phase 01B regression re-confirmed during the Phase 01C sequence" | none | The script exists and is verified for the D3 tree (A1). The claimed earlier run has no record: Unverified. |
| U5 | "Full sequence executed twice, identical results" | none | Not found in searched locations; further recovery remains possible. Unverified. |
| U6 | Voting concurrency harness: duplicate-submission races, forced deadlock, blocking close/vote, stale-read canary | none | Not found in searched locations; further recovery remains possible. Unverified. |
| U7 | Database-role privilege verification (real MariaDB privilege enforcement) | none | Not found in searched locations; further recovery remains possible. Unverified. No role or GRANT definitions exist in the repository. |
| U8 | Reconciliation with clean fixtures and seeded corruption | none | Not found in searched locations; further recovery remains possible. Unverified. |
| U9 | Run on MariaDB 10.11.14 "in the verification environment" | none | Not found in searched locations; further recovery remains possible. Unverified. |
| U10 | Record of "approved Phase 01C design and transaction/security decisions" | n/a | No standalone record found. Related content exists in `docs/DATABASE.md` (Section 7), `docs/VOTING_FLOW.md`, `docs/SECURITY_AND_PRIVACY.md`; its completeness is unverified. See `docs/PHASE01C_SCOPE_AND_DECISIONS.md`. |

### 3.4 Outstanding

| ID | Item | Status |
|---|---|---|
| O1 | Expanded Phase 01C verification suite run on MariaDB 10.4.32 | **Outstanding.** Binding for any newly developed expanded suite (decision D-5). Results from other MariaDB versions do not replace it. |
| O2 | Ballot writer and runtime UUIDv4 generation | Not started; later application implementation work (see scope document). Not a verification artifact. |
| O3 | Phase 01C sign-off gates (security, regression, concurrency, reconciliation, MariaDB-version, sign-off) | **Outstanding** |

## 4. Recovery log

If a claimed artifact is recovered, add a row here, import the artifact unchanged in a separate approved change, run it on MariaDB 10.4.32, and only then change its status above with the captured result.

| Date | Artifact ID | Recovered from | By | Result captured? |
|---|---|---|---|---|
| (none) | | | | |

## 5. Update rules

1. A status changes to Verified only with: environment (host, port, MariaDB version, database), counts, exit code, who ran it, date.
2. Results from MariaDB 10.11.14 (or any version other than 10.4.32) are exploratory evidence and never replace the 10.4.32 gate.
3. A claim without an artifact is recorded as Unverified, not deleted, so the gap stays visible.
4. This register does not declare Phase 01C complete.
