# DEPLOYMENT

## Environments
Development, testing/staging, and production must be separate. Use mock elections before production.

## Pre-deployment
Backup/restore point → migration review → test suite → security checks → build/dependency check → deployment plan → rollback plan.

## Health checks
Application, DB, queue, Redis, Reverb, storage, OAuth, scheduled jobs, and backup system.

## Election safety
Avoid risky migrations/config changes while voting is OPEN. If unavoidable, follow explicit incident/change-control procedure.

## Rollback
Have a tested application rollback plan and a separate data recovery plan. Never assume application rollback automatically rolls back database changes.

## Secrets
Keep DB/OAuth/encryption/backup secrets out of source control. Use protected secret configuration and document rotation/recovery.

## Production readiness
No election opens until migrations, backups, restore testing, monitoring, load tests, smoke tests, and mock election results pass the defined gates.
