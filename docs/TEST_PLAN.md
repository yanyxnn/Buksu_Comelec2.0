# TEST PLAN

## Testing layers
Unit → Feature → Integration → Database integrity → Concurrency/idempotency → Security → Realtime/load → Disaster recovery → Full mock election → Production readiness.

## Critical vote tests
- Double click/duplicate request.
- Same submission UUID replay.
- Simultaneous submissions by same student.
- Timeout after commit.
- Timeout before commit.
- DB rollback during vote transaction.
- Pause during submission.
- Close during submission.
- Invalid contest/candidate/selection.
- Invalid abstention combination.
- Eligibility mismatch.
- Partial ballot prevention.

## Reconciliation tests
Participation = ballots, ballots contain valid items, aggregates equal independent calculation, live aggregate never becomes authoritative, finalized result reproducible.

## Realtime tests
Hundreds/thousands of viewers, vote bursts, duplicate broadcast, missed event, disconnect/reconnect, Reverb node failure, Redis/queue degradation.

## Security tests
Authorization, privilege escalation, CSRF/session behavior, XSS/injection, file uploads, rate limits, OAuth flows, admin approval/self-approval prevention, backup access.

## Import tests
Duplicate IDs, malformed rows, large files, college/course/year changes, missing students, re-imports, import failures/rollback, manual changes overridden by later official upload.

## Disaster tests
Database restore, backup corruption detection, production recovery procedure, application outage, queue failure, realtime failure, deployment rollback.

## Load tests
Use progressively increasing concurrent users, simultaneous submissions, realtime viewers, reporting workload, imports, and backup operations. Establish measurable limits before production.
