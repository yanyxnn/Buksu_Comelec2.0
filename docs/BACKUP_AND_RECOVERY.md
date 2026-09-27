# BACKUP & DISASTER RECOVERY

## Backup types
1. Automated database backups.
2. Downloadable encrypted full-system backups.
3. Election archive packages.
4. Operational exports without raw ballot selections where appropriate.

## Full backup contents
Database plus required file assets, election configuration, eligibility snapshots, candidates, participation, ballots, results, audit/incident data, and a backup manifest.

## Integrity
Every backup gets a manifest and checksum (e.g. SHA-256). Validate file existence, checksum, archive structure, encryption, and database dump integrity.

## Storage
Maintain separate/offsite copies. Do not depend on a single server.

## Generation
Large backups run as background jobs. Backup creation/download/restore actions are audited.

## Restore
Restore to an isolated environment first, verify integrity, run application and election checks, then conduct controlled production recovery if required. Never make a normal one-click destructive production restore.

## Election milestones
Consider immutable recovery points before/after configuration lock, opening, major milestones, closing, reconciliation, validation, and finalization.

## Recovery objectives
RPO/RTO values remain an OPEN DECISION until institutional/infrastructure targets are approved.

## Disaster drills
Test database loss, server loss, queue failure, Reverb/Redis failure, corrupted backup, restore, interrupted submissions, and result reconciliation after recovery.
