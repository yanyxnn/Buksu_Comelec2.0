# BUKSU COMELEC 2.0 — DATABASE ARCHITECTURE

Status: **APPROVED — Phase 1A domain model baseline**  
Implementation status: **Not yet implemented; Phase 1B migration work is next**

This document is the approved database architecture baseline derived from the completed Phase 1A domain model review. It defines the intended MySQL domain structure, relationships, integrity boundaries, immutability rules, ballot-secrecy boundary, and migration order.

Laravel migrations become the executable schema source of truth during Phase 1B. If an implementation detail differs from this document, the discrepancy must be reviewed rather than silently changing the architecture.

---

## 1. Core database principles

1. **MySQL is authoritative.** Election facts required to reconstruct participation, ballots, and results must be committed to MySQL.
2. **InnoDB is required.** Transactions and foreign-key integrity are foundational to vote submission.
3. **Election rules are data-driven.** Contest types, seats, selection limits, eligibility rules, scopes, and reporting dimensions must not be hard-coded into table structure or election-specific controllers.
4. **Participation and ballot content are separated.** No normal database path connects a ballot to a student.
5. **Cast ballots are immutable evidence.** Normal administration cannot edit, delete, replace, or reset a cast ballot.
6. **Snapshots are frozen before voting.** Configuration, eligibility, candidate roster, and ballot structure are locked before `OPEN`.
7. **Finalized elections are immutable.** Post-finalization issues are handled through incident/disposition records, not by mutating finalized election data.
8. **Critical integrity uses the simplest strong mechanism:** database constraints first, transactional application enforcement second, reconciliation third, and triggers only if a future demonstrated requirement justifies them.

---

## 2. Domain A — voter identity and participation

### `students`

Permanent student identity and current official master-data projection.

Key fields:
- `institutional_id` — unique permanent student identity.
- `institutional_email` — write-protected through ordinary editing.
- `first_name`, `middle_name`, `last_name` / official name fields.
- `current_college`, `current_course`, `current_year_level`.
- `status` — `ACTIVE` or `INACTIVE` only; no `IRREGULAR` status.
- `google_subject` — nullable until first institutional Google login; unique when present.
- `last_import_batch_id` — FK to `import_batches`.

### `student_enrollments`

Append-only history of the official academic placement reported by each Data Center import.

Key fields:
- `student_id` FK.
- `import_batch_id` FK.
- `college`, `course`, `year_level`, `status`.
- `effective_from`.

Constraint:
- `UNIQUE(import_batch_id, student_id)`.

Each row is the placement reported for a student by one official Data Center import. An import may contain at most one placement record per student. Duplicate student IDs within the same uploaded file are handled through `import_batch_rows` (classified `DUPLICATE_IN_FILE`) and do not become multiple enrollment rows. The same student may have enrollment rows across different import batches; those rows preserve historical placement. The uniqueness constraint also protects chunked-import retry/idempotency, so a retried chunk cannot insert the same placement twice.

The course-change rule is applied when the new course placement is recorded: a student entering a new course becomes `1st Year` in that new course. Year level is not derived from individual subjects.

### `admin_users`

Authorized COMELEC IT administrators.

Key fields:
- `google_subject` — unique personal Google identity.
- `display_name`.
- `role = BUKSU_COMELEC_IT_ADMIN`.

Exactly three authorized admins remain an operational/provisioning control rather than a table-cardinality constraint.

### `import_batches`

Audit/history for official Data Center imports.

Key fields:
- `source_filename`, `academic_year`, `semester`.
- `uploaded_by` FK `admin_users`.
- `status`: `STAGED`, `VALIDATING`, `PREVIEWED`, `CONFIRMED`, `PROCESSING`, `COMPLETED`, `FAILED`.
- received/created/updated/error counts.
- source-file `checksum`.
- `started_at`, `confirmed_at`, `completed_at`.

### `import_batch_rows`

Thin staging/classification layer for validation preview and review.

Key fields:
- `import_batch_id` FK.
- `institutional_id` from the source row.
- `classification`: `NEW`, `UPDATED`, `DUPLICATE_IN_FILE`, `INVALID`, `NEEDS_EXCEPTION_REVIEW`.
- `raw_row_json`.
- `resolved_student_id` nullable FK.

---

## 3. Domain B — election configuration and snapshots

### `elections`

Election event and lifecycle root.

Key fields:
- `name`.
- `type` as a descriptive/configuration label, not a code branch.
- `state` — configured lifecycle from the approved election state machine.
- `current_config_version_id`.
- `locked_config_version_id`.
- `timezone` — default `Asia/Manila` unless explicitly configured.
- `opens_at`, `closes_at`.
- `created_by`, `approved_by` FKs to `admin_users`.

### `election_config_versions`

Versioned normalized configuration plus frozen serialized snapshot.

Key fields:
- `election_id` FK.
- `version_number`.
- `status` — `DRAFT`, `APPROVED`, `SNAPSHOTTED`, `SUPERSEDED`.
- `config_json` — frozen artifact generated from normalized configuration when snapshotted.
- `proposed_by`, `approved_by` FKs to `admin_users`.

**Source of truth rule:** while editable, normalized configuration tables are authoritative. At snapshot time, `config_json` is generated as a read-only reproducibility artifact. It is not a second editable source of truth.

### `representation_groups`

Reusable grouping of contests around configured dimensions such as college/year. The exact table-vs-columns implementation choice is deferred to Phase 1B migration design, provided it preserves configuration-driven behavior.

### `contests`

Election contest/position definition.

Key fields:
- `election_id` FK.
- `election_config_version_id` FK.
- `name`, `position_label`.
- `scope`.
- `representation_group_id` nullable FK.

### `contest_rules`

Contest-specific voting rules.

Key fields:
- `contest_id` FK.
- `seat_count`.
- `selection_limit`.
- `allow_abstain`.
- `voting_method` (`SINGLE_CHOICE` / `MULTI_CHOICE` or the approved normalized representation).

Important behavior:
- A multi-seat contest may allow a voter to select fewer than the configured maximum.
- Fewer than the maximum selections is still `VOTE`.
- Explicit abstention is a separate response.

### `contest_eligibility_rules`

Rule definition for which eligible voters may participate in the contest.

Key fields:
- `contest_id` FK.
- `rule_definition_json`.

### `candidate_roster_snapshots`

Versioned frozen candidate roster.

Key fields:
- `election_id` FK.
- `election_config_version_id` FK.
- `version_number`.
- `status`: `DRAFT` / `LOCKED`.
- `locked_at`.

### `ballot_structure_snapshots`

Frozen definition of what voters actually receive.

Key fields:
- `election_id` FK.
- `election_config_version_id` FK.
- `candidate_roster_snapshot_id` FK.
- `eligibility_snapshot_id` FK.
- `version_number`.
- `status`: `DRAFT` / `LOCKED`.
- `locked_at`.
- `structure_json` — frozen description of contests/rules/candidates presented.

---

## 4. Domain C — candidates and candidacies

### `candidates`

Thin permanent candidate identity anchor.

Key field:
- `student_id` FK, unique.

No election-specific profile fields belong here.

### `candidacies`

Election-specific candidacy.

Key fields:
- `candidate_id` FK.
- `election_id` FK.
- `contest_id` FK.
- `party_id` nullable FK.
- candidate display/profile fields (`display_name`, `photo_path`, `bio`).
- `status` lifecycle including `DRAFT`, `FOR_REVIEW`, `VERIFIED`, `APPROVED`, `PUBLISHED`, `LOCKED`, with `WITHDRAWN` / `DISQUALIFIED` as applicable.
- `roster_snapshot_id` FK nullable until locked.

### `parties`

Election-scoped party/group configuration by current approved design.

A future institutional decision that parties must be persistent/accredited across elections would require revisiting this model.

---

## 5. Domain D — eligibility snapshots

### `election_eligibility_snapshots`

Frozen eligibility computation for an election.

Key fields:
- `election_id` FK.
- `election_config_version_id` FK.
- `status`: `DRAFT` / `LOCKED`.
- `rule_definition_json`.

### `election_eligible_voters`

Materialized locked voter set used by the election, not a live query against current student master data.

Key fields:
- `eligibility_snapshot_id` FK.
- `student_id` FK.
- frozen `college`, `course`, `year_level`, `status`, `sector` where applicable.
- `source`.
- `is_exception` and `exception_reference`.

After the snapshot is locked, later Data Center imports do not silently change active-election eligibility.

---

## 6. Domain E — voting and ballot secrecy

### `submission_attempts`

Idempotency/request tracking.

Key fields:
- `submission_uuid` — unique, opaque, client-generated.
- `election_id` FK.
- `student_id` FK.
- `status`: `RECEIVED`, `VALIDATING`, `COMMITTED`, `FAILED`, `REJECTED`.
- `failure_reason` coarse-grained only.
- `committed_at`.

### `voter_participations`

Records that a student successfully participated.

Key fields:
- `election_id` FK.
- `student_id` FK.
- unique `(election_id, student_id)`.
- `submission_uuid` (same business submission reference used by the participation record).
- `participated_at`.
- opaque `receipt_code`.
- `is_full_election_abstention`.

This table is Domain A and may reference the student.

### `ballots`

Anonymous cast ballot.

Required properties:
- `election_id` FK.
- `ballot_structure_snapshot_id` FK.
- opaque ULID/ballot identifier.
- `cast_at`.
- row-level `status = CAST` for normal operation.

The table MUST NOT contain:
- `student_id`.
- `institutional_id`.
- `voter_participation_id`.
- `submission_uuid`.
- `receipt_code`.
- Google identity/subject.
- Any equivalent voter identifier.

### `ballot_contest_responses`

Exactly one explicit response per contest on a committed ballot.

Fields:
- `ballot_id` FK.
- `contest_id` FK.
- `response_type` = `VOTE` or `ABSTAIN`.
- unique `(ballot_id, contest_id)`.

There is no domain `SKIP` state.

### `ballot_candidate_selections`

Candidate selections under a `VOTE` contest response.

Fields:
- `ballot_contest_response_id` FK.
- denormalized `contest_id` used for composite candidate-membership FK.
- `candidacy_id` FK.
- unique `(ballot_contest_response_id, candidacy_id)`.
- composite FK `(candidacy_id, contest_id)` to a unique `(id, contest_id)` on `candidacies`.

`ABSTAIN` means zero selection rows. The database FK does not itself enforce zero children for `ABSTAIN`; the atomic voting transaction enforces the rule and reconciliation detects anomalies.

### `ballot_reporting_contexts`

One immutable non-identifying reporting context per ballot.

Fields:
- `ballot_id` FK, unique.
- `election_id` FK.
- frozen college/course/year/sector values where applicable.

It is copied by value from the locked eligibility snapshot at cast time.

It MUST NOT contain a student identifier or a key back to an eligibility-voter row.

### `ballot_events`

Ballot lifecycle/traceability event history.

Fields:
- `ballot_id` FK.
- event type.
- related incident/result-run IDs where applicable.
- `actor_id` may reference `admin_users` for investigation actions.
- notes and timestamp.

It must remain inside the ballot correlation domain and must not contain voter identity fields.

### `ballot_dispositions`

Append-only exceptional handling for a ballot.

Possible dispositions include:
- `EXCLUDED_FROM_CALCULATION`.
- `VOIDED`.
- `REINSTATED`.

Original ballot, response, and selection rows are never edited or deleted. Result calculation uses the applicable disposition when deciding whether a ballot contributes to a calculation.

The exact disposition-to-result-run semantics are an implementation-phase decision and must preserve historical reproducibility.

### `outbox_events`

Transactional outbox for post-commit side effects such as aggregate realtime updates and notifications.

The outbox contains aggregate-level payloads only and never selection-level voter-linked content.

---

## 7. Ballot secrecy and traceability boundary

The database intentionally contains two non-overlapping correlation domains.

### Domain A — voter identity

`students`, `voter_participations`, and `submission_attempts` may reference student identity.

### Domain B — anonymous ballot lifecycle

`ballots`, `ballot_contest_responses`, `ballot_candidate_selections`, `ballot_reporting_contexts`, `ballot_events`, and `ballot_dispositions` must not contain voter identity.

There must be no persistent join key between these domains — not a foreign key, not a shared UUID, not a hash, and not a reused correlation identifier.

The correspondence exists only transiently while the atomic vote transaction is being processed.

This provides **anonymous-but-traceable ballots**:

> COMELEC can investigate what happened to ballot X without the normal database providing a lookup from ballot X to the student who cast it.

Server/application logging must follow the same boundary and must not introduce a shared identifier that recreates the link.

---

## 8. Abstention and participation semantics

### Non-participation

A student never opens/submits the election:

- no `voter_participations` row.
- no `ballots` row.

### Complete election abstention

A student deliberately abstains from the entire election:

- one `voter_participations` row.
- one `ballots` row.
- one `ABSTAIN` response for every applicable contest.
- zero candidate-selection rows.

### Contest-level abstention

For each contest independently:

- `VOTE` → one or more candidate selections, subject to configured limits.
- `ABSTAIN` → zero candidate selections.

Selecting fewer candidates than a multi-seat maximum is still `VOTE`, not abstention.

---

## 9. Results architecture

### `result_calculation_runs`

Records a calculation execution:
- `election_id` FK.
- algorithm version.
- ballot count at run.
- status.
- started/completed timestamps.
- result digest/checksum.

### `result_aggregates`

Configurable aggregate model.

Fields:
- `result_calculation_run_id` FK.
- `contest_id` nullable.
- `candidacy_id` nullable.
- `metric_type` — e.g. `CANDIDATE_VOTE_COUNT`, `CONTEST_ABSTAIN_COUNT`, `CONTEST_PARTICIPATION_COUNT`, `OVERALL_CAST_COUNT`, `OVERALL_PARTICIPATION_COUNT`.
- `dimension_type` — `OVERALL`, `COLLEGE`, `COURSE`, `YEAR_LEVEL`, `SECTOR`.
- `dimension_value` nullable for `OVERALL`.
- `count`.
- `denominator_type` and `denominator_value` where applicable.

Unique slice:
`(result_calculation_run_id, contest_id, candidacy_id, metric_type, dimension_type, dimension_value)`.

### `result_snapshots`

Frozen official result record.

Fields:
- `election_id` FK.
- `result_calculation_run_id` FK.
- `status`: `PENDING_VALIDATION`, `VALIDATED`, `OFFICIALLY_ANNOUNCED`, `FINALIZED`.
- `snapshot_json` containing the complete detailed result set and stored denominator values.

At finalization, this becomes the durable official record and is not edited.

### Result sources

Overall results:
`ballots → ballot_contest_responses → ballot_candidate_selections`.

Detailed results:
`ballots → ballot_reporting_contexts → responses/selections`.

Participation:
`election_eligible_voters ↔ voter_participations`.

No result calculation reconnects a ballot to a student.

### Percentages

Every percentage must carry an explicit denominator.

Supported denominator concepts include:
- election-eligible voters.
- contest-eligible voters.
- contest participants (`VOTE + ABSTAIN`).
- contest voters who chose `VOTE`.

The default reporting choice for contest abstention and candidate vote percentages remains an institutional/reporting-policy decision.

For multi-seat contests, candidate totals and candidate percentages may collectively exceed 100% because one voter may select multiple candidates.

---

## 10. Audit, incident, and operational tables

### `audit_logs`

General operational activity history.

Must not contain candidate selections, passwords, tokens, session IDs, or unnecessary sensitive data.

### `election_incidents`

Investigation and incident record.

### `incident_events`

Append-only events within an incident.

Ballot-specific lifecycle belongs to `ballot_events`/`ballot_dispositions`, not to a general audit record that might accidentally introduce voter/ballot correlation.

---

## 11. Snapshot dependency order

1. Election configuration version.
2. Eligibility snapshot and candidate roster snapshot.
3. Ballot structure snapshot.
4. Election enters the locked/open lifecycle.

The ballot structure snapshot references the final configuration, candidate roster, and eligibility snapshot.

---

## 12. Migration dependency order

1. `admin_users`, `students`
2. `student_enrollments`, `elections`, `import_batches`
3. `import_batch_rows`, `election_config_versions`
4. `representation_groups`, `parties`, `candidates`
5. `contests`
6. `contest_rules`, `contest_eligibility_rules`
7. `election_eligibility_snapshots`
8. `election_eligible_voters`, `candidate_roster_snapshots`
9. `candidacies`
10. `ballot_structure_snapshots`
11. `submission_attempts`, `voter_participations`
12. `ballots`
13. `ballot_contest_responses`
14. `ballot_candidate_selections`
15. `ballot_reporting_contexts`, `outbox_events`
16. `result_calculation_runs`
17. `result_aggregates`, `result_snapshots`
18. `election_incidents`, `audit_logs`
19. `incident_events`, `ballot_events`, `ballot_dispositions`
20. Backup/health metadata — deferred until RPO/RTO and tooling decisions are finalized.

There must never be a migration wave that creates a foreign key from voter participation to ballot.

---

## 13. MySQL integrity strategy

### Database-enforced
- unique `(election_id, student_id)` on `voter_participations`.
- unique `submission_uuid`.
- unique `(ballot_id, contest_id)` on contest responses.
- unique candidate selection per response/candidate.
- candidate-membership composite FK.
- necessary foreign keys for parent/child integrity.

### Transactionally enforced
- election/contest state.
- selection count limits.
- `VOTE` requires at least one selection where required.
- `ABSTAIN` requires zero selections.
- exact completeness of contest responses.
- snapshot compatibility checks.

### Detective reconciliation
- unexpected ballot/participation count mismatches.
- impossible `ABSTAIN` + selection combinations.
- invalid selection counts.
- missing reporting context.
- malformed/incomplete ballot structures.
- inconsistent result aggregates.

No MySQL triggers are required by the current Phase 1A design.

---

## 14. Identifier strategy

Use ULIDs for privacy-relevant or externally referenced identifiers such as:
- ballots.
- ballot contest responses.
- ballot events.
- ballot dispositions.
- submission attempts.
- voter participations.

Use auto-increment identifiers where opacity has no security value and internal query/index performance is more important, such as configuration/reference rows and high-volume child selection rows where they are never externally exposed.

---

## 15. Implementation boundaries

This document does not itself authorize:
- models.
- controllers.
- services.
- policies.
- UI.
- realtime implementation.
- package installation.
- institutional policy invention.

Phase 1B converts this approved architecture into Laravel migrations. Phase 1C verifies MySQL integrity, idempotency, concurrency, immutability, and secrecy-boundary tests.

---

## 16. Remaining decisions intentionally not fixed by this schema

- exact contest list and seat counts for each election.
- sector definitions and sector voter eligibility.
- reporting percentage default denominator policy.
- small-group reporting suppression threshold.
- persistent institutional party registry, if ever required.
- exact Google Workspace account-linking policy.
- RPO/RTO and backup tooling/retention.
- capacity targets and load thresholds.
- exact change-request workflow implementation.
- exact ballot-disposition scoping if future incidents require per-result-run treatment.
