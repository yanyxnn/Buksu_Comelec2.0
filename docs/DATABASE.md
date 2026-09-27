# DATABASE DOMAIN MODEL

## Identity
- `students`: permanent identity and current master fields.
- `student_enrollments`: term/current placement records as needed for audit/context.
- `admin_users`: the three provisioned IT admin identities and role.

## Election configuration
- `elections`
- `election_config_versions`
- `contests`
- `contest_rules`
- `representation_groups`

## Candidates
- `candidates`: person identity.
- `candidacies`: election-specific candidate participation.
- `parties`: election/party data as configured.

## Eligibility
- `election_eligibility_snapshots`
- `election_eligible_voters`
- `contest_eligibility_rules`
- election-specific eligibility exception/change records as required.

## Voting
- `submission_attempts`
- `voter_participations`
- `ballots`
- `ballot_items`
- `outbox_events`

## Results
- `result_calculation_runs`
- `result_aggregates`
- `result_snapshots`

## Operations
- `audit_logs`
- `election_incidents`
- `incident_events`
- health/backup metadata tables as appropriate.

## Critical constraints
- Unique `(election_id, student_id)` for successful participation.
- Unique submission UUID within the relevant election/context.
- Valid foreign keys between ballot, contest, candidate, and election.
- Prevent duplicate contest items per ballot where the configured rule requires one contest decision.
- Enforce valid abstain/candidate selection structures through application + DB constraints where possible.
- No normal FK from ballot to student in the application-facing ballot model unless a separately protected technical design is explicitly approved.

## Migration principle
Schema changes affecting elections/votes require review, tests, backup/recovery consideration, and a recorded change entry.
