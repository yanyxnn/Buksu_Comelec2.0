# Phase 01C — D3 patch status

**Scope:** Domain B identifier schema only (CHAR(26) ULID → CHAR(36) lowercase-UUIDv4-shaped for four primary keys and five dependent columns). **Phase 01C is NOT complete.** This document records the D3 Domain B identifier schema work only and does not declare Phase 01C complete.

**Note:** this file covers D3 only. The status of every other Phase 01C verification artifact is in `docs/PHASE01C_VERIFICATION_REGISTER.md`; scope and decisions are in `docs/PHASE01C_SCOPE_AND_DECISIONS.md`; the PROPOSED, UNVERIFIED privilege matrix is in `docs/PHASE01C_PRIVILEGE_MATRIX.md`. The D3 results below are unchanged.

## What the patch contains
- Migration `database/migrations/2026_11_13_000100_normalize_domain_b_ballot_ids_to_uuidv4.php` (forward-only, empty-table-only, fail-closed, lock-first).
- `database/verification/phase01c_d3_uuidv4_schema_tests.sh` (read-only schema checks).
- `database/verification/phase01c_d3_refusal_tests.sh` (refusal, rollback-protection, lock, resume tests on a disposable database).
- `database/verification/phase1b_integrity_tests.sh` (hardened safety gate; UUIDv4 fixtures; all original checks kept; D3 checks added).
- Documentation updates (`DATABASE.md`, `TEST_PLAN.md`, `CHANGE_LOG.md`, this file).

## Decisions applied
D3-D1 forward-only (historical migrations untouched); D3-D2 fail-closed on any record in the six tables; D3-D3 database-level lowercase-UUIDv4 CHECKs (enforcement **confirmed on MariaDB 10.4.32**, see below); D3-D4 exact database allowlist and destructive-cleanup confirmation; D3-D5 documentation claims only from captured evidence; D3-D7 one self-contained patch.

## Evidence status
The D3 v2 patch was applied locally and the required MariaDB 10.4.32 gate was run. The figures below are the captured results supplied by the project owner.

### Required gate: MariaDB 10.4.32, port 3307, hostname GOBLIN, database `buksu_p1c_throwaway_test`
| Test | Result | Exit code |
|---|---|---|
| D3 refusal and recovery test (`phase01c_d3_refusal_tests.sh`) | 47 passed, 0 failed | 0 |
| Phase 01B integrity harness (`phase1b_integrity_tests.sh`, including the D3 checks) | 89 passed, 0 failed | 0 |
| Standalone D3 UUIDv4 schema test (`phase01c_d3_uuidv4_schema_tests.sh`) | 8 passed, 0 failed | 0 |

Confirmed by those runs on MariaDB 10.4.32:
- The four Domain B primary keys and the five dependent columns are `CHAR(36) NOT NULL`.
- Domain A identifiers (`voter_participations.id`, `submission_attempts.id`) remain `CHAR(26)` ULIDs.
- All 12 foreign keys and the required indexes passed verification.
- The four lowercase-UUIDv4 `CHECK` constraints were verified, and invalid UUID formats were rejected. D3-D3 (CHECK enforcement) is therefore confirmed on 10.4.32.
- All six Domain B tables finished with zero fixture rows.
- The refusal script confirmed that all 26 Phase 01B fixture tables were empty at the end.

### Separate entry: full SQLite regression suite (Pest)
| Test | Result | Exit code |
|---|---|---|
| Full Pest suite on SQLite | 494 passed, 1 skipped; 10,113 assertions; 98.41 seconds | 0 |

The SQLite entry is a regression result for the application test suite. The D3 migration is a documented no-op on SQLite, so it does not test the MariaDB schema change.

## Known limitations
- UUID shape validation is not UUID generation: nothing generates UUIDv4 values, and there is no ballot writer yet.
- The CHECK is a database-level guard. A session with `check_constraint_checks=0` can bypass it.
- The advisory lock coordinates migration runs only and does not block ordinary writers; run the migration before any ballot writer exists, or in maintenance mode.
- MariaDB DDL commits implicitly: not atomic; an interrupted run on empty tables is resumable.
- Populated legacy ballot data is deliberately refused: the migration (and its rollback) stops if any of the six Domain B tables holds a row; there is no conversion path.
- The foreign-key creation order is not exposed by the catalogue; it is preserved by construction and checked behaviourally by Phase 01B test A2.
- The Laravel `migrations` table stores no content checksum. No repository-tracked migration checksum manifest exists in the baseline; the existence of an external local manifest is unknown.

## Not done (future work)
Application ballot writer; runtime UUIDv4 generation; populated-data conversion (intentionally refused); Phase 01C completion. **Phase 01C is not complete.**
