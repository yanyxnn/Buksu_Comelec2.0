# CHANGE LOG

## 2026-09-26 — Architecture Baseline Planning
- Established reusable configurable election-engine objective.
- Locked MySQL as authoritative election data source.
- Locked atomic/idempotent vote submission and immutable cast ballots.
- Locked separation of voter participation from ballot selections.
- Locked receipt as proof of participation only.
- Locked student-facing live results to overall cast votes + anonymous candidate leaderboards.
- Locked horizontally scalable Reverb/Redis realtime architecture.
- Locked election-wide integrity lock and immutable historical elections.
- Locked searchable election history and configuration-only reuse from previous elections.
- Locked Data Center as current-student-data authority.
- Locked Student status to ACTIVE/INACTIVE only.
- Locked course-change rule: new course is 1st Year for COMELEC purposes.
- Locked controlled late-COR/election-specific eligibility exception concept.
- Locked candidate roster approval, snapshot, and lock.
- Locked tie handling: detect/flag; COMELEC President/authorized heads decide outside the engine.
- Locked automatic/downloadable encrypted backups and restore testing.
- Identified remaining institutional/open decisions; see `OPEN_DECISIONS.md`.

## 2026-09-28 — Phase 1A Domain Model & Database Architecture Approved

- Completed Phase 1A domain model and database architecture review.
- Approved the separation of voter identity/participation from anonymous ballot content.
- Approved anonymous but traceable ballot architecture using opaque ballot identifiers, ballot events, and separate ballot dispositions.
- Approved explicit per-contest `VOTE` / `ABSTAIN` responses; `SKIP` is not a domain state.
- Approved multi-seat voting where selecting fewer than the configured maximum remains a valid `VOTE`.
- Approved complete election abstention as participation with a committed ballot containing explicit `ABSTAIN` responses and zero candidate selections.
- Confirmed non-participation as the absence of both participation and ballot records.
- Approved frozen, non-identifying ballot reporting context for college/course/year/sector result breakdowns.
- Approved configurable result aggregation and immutable finalized result snapshots.
- Approved ballot immutability; exceptional ballot handling uses additive disposition/incident records rather than modifying the original cast ballot.
- Approved MySQL-first integrity enforcement with schema constraints, transactional validation, and reconciliation; no triggers proposed for Phase 1A.
- Approved ULID strategy for privacy-sensitive/externally referenced identifiers.
- Approved candidate identity as a separate thin `candidates` entity.
- Approved election-scoped parties unless institutional policy later establishes a persistent recognized-party registry.
- Marked Phase 1A complete and authorized progression to Phase 1B database/migration implementation.
- Remaining institutional/reporting decisions remain tracked in `OPEN_DECISIONS.md`.

## Change process
Every material architecture or election-rule change must record date, reason, proposer, affected documents/code, approval authority, migration/testing impact, and resulting decision.
