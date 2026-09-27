# REQUIREMENTS — BUKSU COMELEC 2.0

## Product goal
A reusable institutional election platform that can support college, department, year-level, sectoral, special, and university-wide elections without separate code paths.

## Functional requirements
- Google sign-in through one login flow.
- Exactly three provisioned IT admins with identical role permissions.
- Student master data and current academic placement managed through official Data Center uploads.
- Election creation from templates or custom configuration; templates are starting configurations only.
- Configurable contests, voter eligibility, candidate eligibility, seats, selection limits, abstention, schedules, result rules, and reporting.
- Candidate roster approval and locking.
- Election configuration/eligibility/ballot snapshots and locking before OPEN.
- Student voting with final irreversible submission and contest-level/election-level abstention as configured.
- Proof-of-participation receipt that does not reveal candidate selections.
- Live student results limited to overall cast votes and anonymous candidate leaderboards.
- Admin live analytics and reconciliation.
- Automatic integrity/anomaly detection and election incidents.
- Downloadable encrypted backups and election archive packages.
- Searchable immutable election history.
- Results export to approved formats such as PDF/XLSX/CSV.

## Non-functional requirements
- Strong ballot secrecy architecture.
- Atomic/idempotent vote submission.
- DB-enforced integrity constraints.
- Horizontal realtime scaling.
- Graceful degradation of secondary services.
- Reproducible result calculation.
- Disaster recovery and restore testing.
- Auditability without logging voter choices.
- Load-testable for small and large election scenarios.

## Open institutional requirements
Exact November 2026 contest rules, sectoral eligibility, seat counts, tie procedure, retention periods, peak scale targets, and some dual-control thresholds remain in `OPEN_DECISIONS.md` until officially confirmed.
