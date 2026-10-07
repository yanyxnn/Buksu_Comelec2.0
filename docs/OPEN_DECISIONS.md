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

* Real change-request action types and approval thresholds (the registry ships empty; see the high-risk approval item above).
* General Reports (a Phase 03 concern) are distinct from the Phase 02 Login / Access Reports and are not implemented; their categories, statuses and workflow are undecided. Report status/handling for Access Issue reports is likewise not yet defined.
* No admin replacement/departure procedure is defined or implemented; none has been invented.

### Resolved Phase 02 authentication decision — administrator identity lifecycle

Administrator accounts are pre-authorized independently of the Data Center student import. Administrators do not publicly register or create their own administrator accounts.

The current initial roster consists of three operationally authorized administrators. This is an operational roster decision, not a permanent database or application cardinality limit.

Each administrator has a pre-authorized personal Google email before first login. `admin_users.google_subject` may be `NULL` before first successful login and is unique when populated.

On first Google login, the verified Google email is matched to the existing pre-authorized administrator record. When the match succeeds and `google_subject` is `NULL`, the stable Google `sub` is bound to that existing record. Subsequent administrator authentication uses the bound stable Google `sub`.

The Data Center import process is for student master data and must never create, discover, or provision administrator accounts.


## Scale targets
- Expected total voters.
- Peak concurrent voters.
- Peak live-result viewers.
- Target vote submissions/second.
- Target live result latency.
- Target recovery time.

When a decision is approved, update this document, the relevant domain document, `CHANGE_LOG.md`, and tests.

## Data Center import (Phase 03A)

* **How new students are provisioned.** The official Data Center file has no institutional email, and `students.institutional_email` is required and unique, so the importer cannot create a student and routes unknown IDs to `NEEDS_EXCEPTION_REVIEW`. Undecided: does the official source (or a companion file) ever carry the institutional email, or is the email supplied/derived by an approved institutional process? No derivation rule has been invented. Until decided, a student must already exist for the importer to update it, and Phase 02 login (which requires an imported, existing student) cannot be extended to newly enrolled students by the importer.
* **Review/resolution of `NEEDS_EXCEPTION_REVIEW` rows** (case-only college/course inconsistencies, unknown students): the classification and reasons are stored for preview, but who resolves them, how, and whether resolved rows are then applied is not defined.
* **Authoritative spelling of colleges/courses** (e.g. `CON` vs `cON`): currently decided per import against stored master values, then the file. Whether an approved college/course list should be the authority is undecided.
* **Academic year / semester per batch** are recorded as supplied; their allowed values and whether they gate an import are undecided.
* **Import performance targets** (no approved threshold; a 7,966-row synthetic import is exercised and its timing reported, nothing asserted).
* **Which official batch is "newer" (batch precedence).** The approved documents say the latest official upload is authoritative but do not define *latest*. `import_batches.id` is an identifier and `confirmed_at`/`completed_at` record when an administrator acted, not how recent the data is (an old file confirmed late would win), so none of them is used and none has been invented as a rule. Today the batch processed last owns a student's current placement; history stays append-only and `last_import_batch_id` names the owner. Risk: an older file processed after a newer one would overwrite the current placement (detectable from the history; nothing is lost). Candidate rules for the institution to choose from: a required official effective date or term per batch (e.g. validated `academic_year` + `semester` ordering), or refusing a batch whose term is older than one already applied.
