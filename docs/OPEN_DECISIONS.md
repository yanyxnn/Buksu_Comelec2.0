# OPEN DECISIONS

These items must not be hard-coded until officially confirmed.

## November 2026
- Exact final list of contests/positions.
- Exact SSC seat count and selection limits.
- Exact 1st-Year Representative voter-to-contest mapping, if any special exception applies.
- Sector definitions and voter eligibility.
- Final ballot/abstention rules per contest where not yet confirmed.

## Institutional procedures
- Exact tie-resolution documentation/approval format after the COMELEC President/heads decide.
- Exact retention period for raw ballots, participation, audit logs, incidents, backups, and historical archives.
- Exact two-admin/two-approval thresholds for exceptional high-risk operations.
- Exact election recovery/resume procedure after catastrophic outage.
- RPO/RTO targets.
- Minimum-cell-size suppression threshold for detailed reporting, especially small sector/college/year combinations.
- Standard denominator policy for contest abstention percentages and candidate vote percentages:
  eligible contest voters vs. participating/voting contest voters.
- Whether student parties are persistent institutionally-recognized entities across elections or remain election-scoped.
- Student Google Workspace login matching: **decided for Phase 02** as automatic first-link by exact institutional email (verified email + configured domain + existing student + null `google_subject`), then by Google `sub`. The real institutional domain and Workspace configuration still need institutional confirmation before staging/production.

## Authentication & approval (Phase 02)
- How operators obtain each of the three admins' Google `sub` before provisioning (`admin_users.google_subject` is NOT NULL, so a row cannot exist without it). Operational decision, unresolved.
- Real change-request action types and approval thresholds (the registry ships empty; see the high-risk approval item above).
- General Reports (a Phase 03 concern) are distinct from the Phase 02 Login / Access Reports and are not implemented; their categories, statuses and workflow are undecided. Report status/handling for Access Issue reports is likewise not yet defined.
- No admin replacement/departure procedure is defined or implemented; none has been invented.

## Scale targets
- Expected total voters.
- Peak concurrent voters.
- Peak live-result viewers.
- Target vote submissions/second.
- Target live result latency.
- Target recovery time.

When a decision is approved, update this document, the relevant domain document, `CHANGE_LOG.md`, and tests.
