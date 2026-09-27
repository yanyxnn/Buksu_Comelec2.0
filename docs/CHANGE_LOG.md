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

## Change process
Every material architecture or election-rule change must record date, reason, proposer, affected documents/code, approval authority, migration/testing impact, and resulting decision.
