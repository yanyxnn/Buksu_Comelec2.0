# RESULTS AND ANALYTICS

## Authoritative chain
Committed ballots → ballot selections → result calculation → aggregates → displays/reports.

## Student-facing realtime results
Only:
1. Overall successfully cast ballots.
2. Anonymous candidate leaderboards with current vote totals/rank.
No voter identity, ballot IDs, receipt references, or student-to-candidate mapping are transmitted.

## Admin analytics
May include participation, abstention, contest/candidate totals, and configured breakdowns by college, course/program, year, sector, etc., while preserving ballot secrecy.

## Percentages
Contest result percentages must state their denominator. Candidate percentages may be based on eligible voters and optionally participating voters. Multi-choice candidate percentages may sum above 100% because selections are independent.

## Live delivery
MySQL is authoritative. Result aggregation produces current derived state. Redis/Reverb distributes realtime updates. Clients resynchronize with an authoritative fetch after reconnect or missed events.

## Result calculation runs
Each calculation records algorithm/version, election, ballot count, status, timestamps, and result digest so calculations are reproducible and comparable.

## Reconciliation
Compare participation vs ballots, ballots vs ballot items, ballot selections vs result totals, live aggregates vs independent recalculation, and expected eligibility vs participation.

## Finalization
Only reconciled/validated results become official and FINALIZED. Final snapshots are immutable.

## Exports
Support official PDF/Excel/CSV outputs and other approved reports through background jobs so report generation does not block voting.
