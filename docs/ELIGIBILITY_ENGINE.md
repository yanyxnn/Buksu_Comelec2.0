# ELIGIBILITY ENGINE

## Principles
Eligibility is resolved from the approved election snapshot, not from mutable live student data during voting.

## Sources
- All eligible students
- Rule-based eligibility
- Selected students
- Uploaded voter list
- Custom/configurable rule composition

## Rule dimensions
College, Program/Course, Year Level, Sector, Enrollment Status, Student Status, Institutional ID lists, and other explicitly configured dimensions.

## Election snapshot
Eligible voters are materialized into a locked election snapshot before the election opens. Later masterlist changes do not silently alter that election.

## Testing
Test Eligibility must accept a student and explain current academic data, election eligibility, eligible contests, candidate pool, and plain-language reasons.

## Exceptions
A legitimate late-COR/verified enrollment case may be added through a controlled election-specific eligibility exception. The exception must be documented and included in the election snapshot; it must not falsely rewrite the Data Center source file.

## Course-change rule
When the current official record represents a course/program change, the new course year is treated as 1st Year for COMELEC purposes. The system does not infer year from individual subjects.
