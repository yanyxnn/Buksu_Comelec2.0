# STUDENT MANAGEMENT

## Permanent identity
Institutional/Student ID is the permanent identity and must be unique. Institutional email is system-controlled/locked from ordinary editing.

## Status
Only `ACTIVE` and `INACTIVE` are used as student status values. Do not create an `IRREGULAR` status.

## Current academic placement
Latest official Data Center upload is authoritative for current College, Course/Program, Year Level, and other official current attributes.

## Course change
A change to a new course/program makes the student's COMELEC year level 1st Year in the new course, regardless of previous course/year. The app does not calculate academic year from individual subjects.

## Import behavior
Upload → staging → validation → preview → approval → chunked import → summary. Match students by institutional ID; never create duplicates for an existing ID.

Phase 03A (implemented): the official import updates the placement and name of **existing** students and appends a `student_enrollments` history row per batch; it stamps `students.last_import_batch_id` (which Phase 02 student login requires). It never changes `institutional_id`, `institutional_email` or `google_subject`. The source `Sex` and `No.` columns are not part of the student domain and are ignored. Because the official source has no institutional email, the importer **never creates a student**: an unknown ID is routed to exception review rather than given a fabricated email. See `DATA_IMPORT.md`.

## Missing roster records
Missing from a later roster does not automatically mean graduated. Current election eligibility is based on the approved election snapshot and controlled exceptions where legitimate.

## Manual changes
All fields except Student ID and Institutional Email may be corrected through administration, subject to appropriate approval. Eligibility-impacting changes should require independent approval.

## Approval
All three admins receive notification of pending change requests. The proposer cannot approve their own request. At least one independent admin approval is required for normal controlled changes; higher-risk operations may require two independent approvals.

## Student reports
Students may report wrong information, eligibility issues, wrong candidates shown, technical problems, or other concerns. Reports are separate from general feedback and operational audit logs and can be threaded with admin replies.
