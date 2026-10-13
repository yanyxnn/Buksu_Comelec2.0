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

Phase 03A/03B (implemented): the official import updates existing students and can create eligible new students during confirmed batch processing. It appends a `student_enrollments` history row per applied batch and stamps `students.last_import_batch_id`. Existing students' `institutional_id`, `institutional_email` and `google_subject` are never overwritten by the importer. New students with supported 7-digit legacy or 10-digit current numeric IDs receive an institutional email generated as `<institutional_id>@student.buksu.edu.ph`. This email convention remains provisional pending confirmation by the university and responsible Google Workspace authority; it must be confirmed before staging or production use. Unsupported IDs and rows needing exception review are not created. The source `Sex` and `No.` columns are ignored. See `DATA_IMPORT.md` and `OPEN_DECISIONS.md`.

## Missing roster records
Missing from a later roster does not automatically mean graduated. Current election eligibility is based on the approved election snapshot and controlled exceptions where legitimate.

## Manual changes
All fields except Student ID and Institutional Email may be corrected through administration, subject to appropriate approval. Eligibility-impacting changes should require independent approval.

## Approval
All three admins receive notification of pending change requests. The proposer cannot approve their own request. At least one independent admin approval is required for normal controlled changes; higher-risk operations may require two independent approvals.

## Student reports
Students may report wrong information, eligibility issues, wrong candidates shown, technical problems, or other concerns. Reports are separate from general feedback and operational audit logs and can be threaded with admin replies.
