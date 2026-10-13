# DATA IMPORT

## Official pipeline
Excel/CSV Upload → Staging → Validate → Preview → Review Changes → Confirm Import → Chunked bulk import → Summary.

## Matching
Match by institutional/student ID. Existing IDs are updated; duplicates are never created.

## Validation
Classify new records, existing records, duplicate IDs in the file, invalid rows, changes in college/course/year/status, and records needing exception review.

## Import batches
Store file name, academic year, semester, uploader, status, counts received/created/updated/errors, timestamps, and checksum/metadata where practical.

## Authority
The latest official Data Center upload is authoritative for current academic placement. Manual academic changes may be overridden by the next official Data Center upload; preserve audit history.

## Missing students
Do not automatically label missing records as graduated. They are absent from the current official roster until resolved by the appropriate institutional process. Election-specific verified exceptions may be snapshotted separately.

## Scale
Use server-side/background chunk processing; do not issue thousands of browser requests for one import.

## Safety
Never import directly into production master tables before validation/preview/approval. Database uniqueness on institutional ID is required.

---

## Phase 03A implementation (domain/application core)

Status: implemented as backend/domain code with automated tests. The entry point is `App\Services\StudentImport\ImportBatchService`; the administrator page that drives it is described under "Phase 03B admin UI" below. This section describes what the code does today; the sections above remain the approved pipeline.

### Source contract
Accepted: `.csv` (UTF-8, BOM tolerated; any other encoding fails closed) and `.xlsx` (first worksheet only). The historical reference shape is `No., Code, Last Name, First Name, Middle Name, Sex, Colleges, Course, Year`. Headers are mapped through `config('comelec.import.header_aliases')` (case/spacing/BOM-insensitive):

| Source column | Domain field |
|---|---|
| `Code` | `institutional_id` (always a STRING: never cast, leading zeros and variable length preserved) |
| `Last Name` / `First Name` / `Middle Name` | `last_name` / `first_name` / `middle_name` |
| `Colleges` | `current_college` / enrollment `college` |
| `Course` | `current_course` / enrollment `course` |
| `Year` | `current_year_level` / enrollment `year_level` |
| `No.`, `Sex`, anything else | **ignored**: never identity, never stored on a student (the raw row is preserved in staging only) |

A file missing a required column, or mapping two columns to one field, fails as a whole with a coarse reason (`MISSING_REQUIRED_COLUMNS`, `DUPLICATE_COLUMNS`, `EMPTY_FILE`, `INVALID_ENCODING`, `FILE_UNREADABLE`, `UNSUPPORTED_FILE_TYPE`, `FILE_TOO_LARGE`, `CHECKSUM_MISMATCH`). Before anything is parsed, validation hashes the stored source file (SHA-256) and compares it with `import_batches.checksum` recorded at staging; a different or missing checksum fails the batch with `CHECKSUM_MISMATCH` and writes no staging rows (a previously previewed batch keeps its earlier rows but can no longer be confirmed). Size limits: `max_file_bytes` (compressed upload) and, for `.xlsx`, `max_uncompressed_part_bytes` (declared uncompressed size of the parts read into memory).

### Normalization and validation
Only harmless formatting is normalized: trimming and collapsing whitespace, and mapping the year token. **Case is never changed.** An identity that a spreadsheet has turned into a numeric artifact (`2.0E7`, `20001.0`) is INVALID, not repaired.

Year levels have ONE internal representation, the label stored in `students.current_year_level` and `student_enrollments.year_level` (`1st Year` ... `4th Year`), mapped from the source tokens in `config('comelec.import.year_levels')` (`1`, `1st`, `1st year`, `first year`, ...). The first configured label is the one assigned after a course change. An unknown or missing year is INVALID; nothing is guessed and nothing is derived from subjects. `status`, if the file has such a column, must be `ACTIVE` or `INACTIVE`; an absent column keeps the student's current status.

### Row classification (`import_batch_rows.classification`)
| Classification | Meaning | Applied? |
|---|---|---|
| `UPDATED` | Valid row for an **existing** student (matched by exact `institutional_id`) | Yes, on processing |
| `NEW` | Reserved. **Never produced today** (see "New students" below) | n/a |
| `DUPLICATE_IN_FILE` | The same ID appears more than once. Identical repeats: the first occurrence stays `UPDATED`, repeats are skipped. **Conflicting** content: *no* occurrence is applied (the file must be corrected). IDs differing only by case are one identity | No |
| `INVALID` | Missing/over-long/mangled ID, missing name/college/course, bad year or status | No |
| `NEEDS_EXCEPTION_REVIEW` | Case-only college/course inconsistency (`CON` vs `cON`), or an unknown student (see below) | No |

Each staged row stores the untouched `raw_row_json`, the `normalized_json` (including a preview of the resulting placement and whether the course changed), `issues_json` (machine-readable `{code, field}` reasons, carrying no student data) and `source_row_number`. Case-only ambiguity is judged against the **stored master values first**, then the file (a unique most-frequent spelling wins; a tie flags every spelling); genuinely different values are never treated as the same.

### New students and institutional email (fail closed)
`students.institutional_email` is NOT NULL and UNIQUE, and the official source carries **no** institutional email. The importer therefore never creates a student: an ID with no existing student is classified `NEEDS_EXCEPTION_REVIEW` with issue `NEW_STUDENT_REQUIRES_INSTITUTIONAL_EMAIL`. An email is **never** synthesized from the ID, never guessed from a domain or naming convention, and an existing student's email is never overwritten. How new students are provisioned (and what the review step does with these rows) is an open decision (see `OPEN_DECISIONS.md`).

### Batch lifecycle
`STAGED -> VALIDATING -> PREVIEWED -> CONFIRMED -> PROCESSING -> COMPLETED | FAILED`, enforced only by `BatchStateMachine` through `ImportBatchService` (status and counters are not mass-assignable; each change is a conditional `UPDATE ... WHERE status = <expected>`). `PREVIEWED` may be re-validated (staging is rebuilt, never duplicated). `FAILED` before confirmation can only go back to validation; `FAILED` after confirmation can only resume processing. `COMPLETED` is terminal.

### Processing, transactions, idempotency
* **Background:** `startProcessing()` moves the batch to `PROCESSING` and queues `ProcessImportBatch` (carries only the batch id; unique per batch). No browser request per student.
* **Chunk = one transaction** (`comelec.import.chunk_size`, default 500, max 1000): for each row, the append-only `student_enrollments` row, the `students` update (name, college, course, year, status, `last_import_batch_id`) and the row's `processed_at` marker, plus the batch counters. A failure rolls back that whole chunk; chunks already committed stay committed. **There is no cross-chunk atomicity and none is claimed**: a half-processed batch is visible as `PROCESSING` (or `FAILED`) with unprocessed rows, never as `COMPLETED`.
* **Restartable / idempotent:** only rows with `processed_at IS NULL` are selected, so a retry resumes at the first unprocessed row. A row whose `(batch, student)` enrollment already exists is marked processed without being applied again. `UNIQUE(import_batch_id, student_id)` is the final guard.
* **Concurrency:** each chunk transaction first locks the batch row (`SELECT ... FOR UPDATE`), so two workers on one batch take turns. This was added after real multi-process runs on MariaDB produced InnoDB deadlocks (error 1213) without it; a residual deadlock is retried at most 3 times, which is safe because a chunk is idempotent.
* **Reconciliation before success:** `complete()` checks `rows applicable = rows processed = enrollments for the batch`. A mismatch FAILS the batch with `INTEGRITY_MISMATCH`; nothing is silently corrected.
* **Batch precedence is undecided (open decision).** `import_batches.id` is only an identifier and is never compared or treated as chronology. A batch that an administrator has confirmed and started is applied as the current official placement of its students; the outcome therefore follows the order batches are *processed*, not their ids. This is a deliberate non-decision, not a rule: the importer cannot tell whether a batch processed late is older data. Safety does not depend on any ordering: `student_enrollments` is append-only (one row per batch and student, never edited), `students.last_import_batch_id` always names the batch that produced the current placement, and replaying a batch is a no-op. See `OPEN_DECISIONS.md`.
* **Counters:** `records_received = records_created + records_updated + records_errored`; `errored` counts every staged row that was not applied (invalid, duplicate, review).
* **Staged source file:** `stage()` validates the file (type, readability, size) before any write, then writes it to the configured import disk inside the database transaction. A rollback cannot undo a file write, so on any failure after the key was chosen the exact key is deleted; a cleanup problem never replaces the original exception, is reported once through the application log (`import.staged_file_cleanup_failed`: disk and storage key only, no filename, contents or student data), and staging still fails. An orphan that could not be removed is referenced by no batch row, and a later batch always overwrites its own key with its own file (the stored checksum matches the stored source).
* **Failure hook:** when the job exhausts its retries the batch is marked `FAILED` (`PROCESSING_ERROR`); re-dispatching resumes from the first unprocessed row. `ValidateImportBatch::failed()` does the same for validation: only a batch still `VALIDATING` becomes `FAILED` (`VALIDATION_ERROR`, audited); any other status is left alone, and a `FAILED` unconfirmed batch is re-validated as before.
* **Zombie-worker guard:** each chunk transaction checks the locked batch row and applies nothing unless its status is `PROCESSING`. A worker whose batch was failed (or completed) while it ran stops at its next chunk instead of continuing to apply rows.
* **Queue settings:** both import jobs have a 900 s timeout, so the database queue `retry_after` (`DB_QUEUE_RETRY_AFTER`, default 960 in `config/queue.php` and `.env.example`) must stay greater than that, and `queue:work --timeout` must stay below it. A smaller `retry_after` hands a still-running job to a second worker and eventually fails it while it is still working. `ProcessImportBatch::$uniqueFor` (3600 s) is unchanged. There is still no automatic recovery of a batch whose worker died with its job lost; see `CHANGE_LOG.md`.

### Placement rules applied
Course/program changed: the new official placement is recorded and the COMELEC year level is **1st Year** whatever year the source reports. Course unchanged: the validated incoming year is kept. Old `student_enrollments` rows are never modified. A student missing from a later roster is left exactly as is (not graduated, not deactivated). Imports never touch `institutional_id`, `institutional_email` or `google_subject`, never create or alter `admin_users`, and make no eligibility decision.

### Privacy
Audit events (`import.batch.*`) carry the batch id, status and counters only (plus a coarse failure code): no student data, filenames, emails or Google identifiers. Raw staging rows are never exposed through a route. Committed tests use synthetic data only.

---

## Phase 03B admin UI (Student Import)

The sidebar label, page title and heading read "Student Import" (renamed from "Student Master / Data Center"; the route and URL `/admin/data-center` are unchanged). The imported students themselves are browsed on the read-only Student Masterlist (`/admin/students`).

Route `admin.data-center` (`GET /admin/data-center`, Volt page `resources/views/livewire/admin/data-center.blade.php`), inside the existing `admin` middleware group and linked from the admin sidebar. The page only calls `ImportBatchService` and the existing queued jobs; it never writes a batch status or counter, never parses a file, and takes the uploader id from the admin guard (no client-controlled uploader field exists).

* **Upload:** file (extensions and maximum size from `config('comelec.import')`), plus required free-text academic year and semester (leading and trailing spaces are trimmed, inner text is kept; no allowed-value list, no term validation, never used to decide which data is newer). `stage()` registers the batch, then `ValidateImportBatch` is queued; nothing is validated or applied inside the Livewire request.
* **Batches:** newest first (display order only, not precedence), paginated, with status, term, uploader, and the counters recorded on the batch. A batch is opened with `?batch=<id>`.
* **Preview:** classification totals (from `ImportBatchService::classificationCounts()`), and a server-side paginated (25/page) row table filterable by classification. Rows show the **normalized** values (`normalized_json`), the resulting placement/changed fields, and safe issue codes with plain-language text (`issues_json`). `raw_row_json` is never selected.
* **Review warning and confirmation:** rows that will not be applied (`INVALID`, `DUPLICATE_IN_FILE`, `NEEDS_EXCEPTION_REVIEW`) are summarised, and the administrator must tick an acknowledgement before confirming. The page states that it does not resolve those rows and does not create students (an unknown student would need an institutional email that the file does not contain). A batch with nothing applicable cannot be confirmed from the page (a presentation guard, not a domain rule). Confirm calls `confirm()` then `startProcessing()`.
* **Actions offered** are computed from `BatchStateMachine::canTransition()` and re-checked on every action: validate/re-validate for `STAGED`, `PREVIEWED`, and `FAILED` before confirmation; confirm for `PREVIEWED`; start for `CONFIRMED`; resume for `FAILED` after confirmation. A confirmed batch is never offered re-validation.
* **Progress and results:** `PROCESSING` shows applied/applicable rows from `processed_at`; `COMPLETED` shows the recorded counters. A row is shown as applied only if it was applicable and has `processed_at`.
* **Failure:** the coarse failure code is read from the existing `import.batch.failed` audit event and mapped to fixed text. Exception messages, stack traces and source contents are never shown.
* **Polling and waiting (v2):** after queueing validation the page records the batch state (status, `updated_at`, validated/failed audit-event count) *before* dispatch. If the state already differs afterwards (synchronous queue or very fast worker) nothing is awaited; otherwise `tick()` clears the wait as soon as the state differs, or after 120 s. The poll element is rendered only while something is running or awaited and is not `.visible`-gated, so it keeps polling when scrolled to the batch details. Idle `STAGED`/`CONFIRMED` batches do not poll.
* **One validation job per batch (v2):** `ValidateImportBatch` implements `ShouldBeUnique` (`uniqueId` = `validate-import-batch-{id}`, `uniqueFor` 1000 s > the 900 s timeout). Repeated clicks or concurrent administrator sessions cannot queue a second job while one is pending/running; the lock is released when the job ends. No other job behaviour changed.
* **Polling:** the page uses `wire:poll.5s="tick"` (without the `.visible` modifier) only while the selected batch is `STAGED`/`VALIDATING`/`CONFIRMED`/`PROCESSING`, a job was just queued for it, or a listed batch is `VALIDATING`/`PROCESSING`; idle pages do not poll.
* **Not provided:** exception-row resolution, new-student provisioning, discarding a batch, recovery of a `PROCESSING` batch whose job was lost (see `CHANGE_LOG.md`, Phase 03A queue hardening), and any change to migrations, the service layer, jobs, or authentication.
