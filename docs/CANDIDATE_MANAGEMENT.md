# CANDIDATE MANAGEMENT

## Identity model
One person = one candidate identity. `candidacies` represent election-specific participation. Candidates do not have separate accounts.

## Eligibility
Candidate eligibility is configured per election/contest. Course, college, year, sector, student status, or selected-ID rules must be configurable where needed.

## Candidate lifecycle
DRAFT → FOR_REVIEW → VERIFIED → APPROVED → PUBLISHED → LOCKED. WITHDRAWN/DISQUALIFIED may be supported as configured/institutionally approved.

## Roster
Candidate roster is reviewed, approved, snapshotted, and locked before the election becomes live.

## Changes after lock
Use controlled change request + independent approval + new candidate roster version/snapshot + revalidation. Never silently mutate a live ballot candidate pool.

## Candidate profile
Official/admin-controlled photo, name, position/contest, college/sector as applicable, party, and bio. Student-facing candidate directory is informational; the candidate does not self-edit through a candidate account.

## Self-voting
Candidates remain normal students for voting and may vote according to election rules, including for themselves where allowed.

## Readiness checks
Identity verified, contest assigned, position valid, party configured as required, eligibility passed, conflicts checked, profile completeness as required, approved/published state.
