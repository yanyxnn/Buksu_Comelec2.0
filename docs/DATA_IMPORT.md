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
