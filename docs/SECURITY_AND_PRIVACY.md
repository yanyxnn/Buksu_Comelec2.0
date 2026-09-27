# SECURITY & PRIVACY

## Ballot secrecy
Normal application data must not provide a simple student → candidate-selection relationship. Participation and ballot selections are separate. Receipts prove participation only.

## Access control
Exactly three fixed IT admins, same role, no admin-management UI. Candidates are normal students.

## Sensitive operations
Election-impacting changes, production restore, exceptional recovery, and other high-risk actions use independent approval/dual control as configured.

## Least privilege
Separate service responsibilities and database privileges where practical. Ordinary admin features must not have unrestricted direct modification access to cast ballot data.

## Authentication
One login page with Google OAuth via Socialite. Admin personal Google identities are separate from student institutional Google identities.

## Input/security controls
Validate IDs, uploads, election configuration, ballot structures, selection limits, authorization, CSRF/session protections, rate limits, XSS/injection protections, safe file handling, and secure error handling.

## Logging restrictions
Never log passwords, OAuth tokens, secrets, full ballot selections with voter identity, or unnecessary sensitive PII. Access to sensitive logs should itself be auditable.

## Realtime privacy
Student-facing channels contain aggregate result state only. No student identity or individual ballot data.

## Backups
Encrypt sensitive backups, restrict access, record backup actions, verify checksums, and test restores.

## Threat model
Include duplicate requests, malicious payloads, privilege escalation, compromised admin, DB failure, realtime failure, queue failure, backup compromise/corruption, import errors, deployment errors, and ballot-data exposure scenarios.
