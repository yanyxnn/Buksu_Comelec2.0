# ELECTION CONFIGURATION

## Creation workflow
Create Election → Basic Information → Template/Custom → Scope → Contests → Voter Eligibility → Candidate Eligibility → Candidates/Parties → Ballot Rules → Abstention → Schedule → Results/Reporting → Preview → Eligibility Test → Validation → Approval → Configuration Snapshot → Lock → Schedule/Open.

## Templates
University-Wide, College/Department, Year Representative, Sectoral, Custom, and future “Create from Previous Election”. A template is a starting configuration only.

## State machine
DRAFT → READY → APPROVED → SNAPSHOTTED → LOCKED → SCHEDULED → OPEN ↔ PAUSED → CLOSED → RECONCILING → RESULTS_PENDING_VALIDATION → VALIDATED → OFFICIALLY_ANNOUNCED → FINALIZED.

## Locking
Before OPEN, ballot-critical configuration is editable through controlled workflow. Once locked, normal edits are disabled. Exceptional changes require a change request, reason, independent approval, new version/snapshot, and revalidation as applicable.

## Election clock
Backend/server time is authoritative. Frontend countdown is display-only. Timezone: Asia/Manila unless institutionally changed and documented.

## Historical record
Every finalized election keeps its final configuration snapshot and immutable results as historical data.
