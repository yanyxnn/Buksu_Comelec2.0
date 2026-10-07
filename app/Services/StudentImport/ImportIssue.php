<?php

namespace App\Services\StudentImport;

/**
 * Machine-readable reasons stored in import_batch_rows.issues_json so the
 * preview/review step can explain WHY a row was classified the way it was.
 * Codes carry no student data.
 */
final class ImportIssue
{
    // INVALID (the row cannot be used as it is)
    public const ID_MISSING = 'ID_MISSING';

    public const ID_TOO_LONG = 'ID_TOO_LONG';

    public const ID_CONTROL_CHARACTERS = 'ID_CONTROL_CHARACTERS';

    /** e.g. "2.0E7" or "20001.0": a spreadsheet turned the identity into a float/number format. */
    public const ID_NUMERIC_FORMAT_ARTIFACT = 'ID_NUMERIC_FORMAT_ARTIFACT';

    public const LAST_NAME_MISSING = 'LAST_NAME_MISSING';

    public const FIRST_NAME_MISSING = 'FIRST_NAME_MISSING';

    public const NAME_TOO_LONG = 'NAME_TOO_LONG';

    public const COLLEGE_MISSING = 'COLLEGE_MISSING';

    public const COLLEGE_TOO_LONG = 'COLLEGE_TOO_LONG';

    public const COURSE_MISSING = 'COURSE_MISSING';

    public const COURSE_TOO_LONG = 'COURSE_TOO_LONG';

    public const YEAR_MISSING = 'YEAR_MISSING';

    public const YEAR_INVALID = 'YEAR_INVALID';

    public const STATUS_INVALID = 'STATUS_INVALID';

    // DUPLICATE_IN_FILE
    /** Same ID again with identical content: the first occurrence is used, this one is skipped. */
    public const DUPLICATE_ID_REPEAT = 'DUPLICATE_ID_REPEAT';

    /** Same ID with DIFFERENT content: no occurrence is applied (the file must be corrected). */
    public const DUPLICATE_ID_CONFLICT = 'DUPLICATE_ID_CONFLICT';

    // NEEDS_EXCEPTION_REVIEW
    public const COLLEGE_CASE_VARIANT = 'COLLEGE_CASE_VARIANT';

    public const COURSE_CASE_VARIANT = 'COURSE_CASE_VARIANT';

    /** No existing student and the source has no institutional email: never fabricated. */
    public const NEW_STUDENT_REQUIRES_INSTITUTIONAL_EMAIL = 'NEW_STUDENT_REQUIRES_INSTITUTIONAL_EMAIL';

    /**
     * @return array{code: string, field: string|null}
     */
    public static function make(string $code, ?string $field = null): array
    {
        return ['code' => $code, 'field' => $field];
    }
}
