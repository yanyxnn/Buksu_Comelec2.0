<?php

namespace App\Services\StudentImport;

/**
 * Deterministically classifies normalized import rows.
 *
 * Precedence: duplicate-in-file, invalid data, exception review,
 * NEW for eligible unknown students, then UPDATED for existing students.
 *
 * For eligible new students, the institutional email is generated
 * during processing from the approved student ID/email convention.
 */
final class RowClassifier
{
    public const NEW = 'NEW';

    public const UPDATED = 'UPDATED';

    public const DUPLICATE_IN_FILE = 'DUPLICATE_IN_FILE';

    public const INVALID = 'INVALID';

    public const NEEDS_EXCEPTION_REVIEW = 'NEEDS_EXCEPTION_REVIEW';

    public function __construct(
        private readonly PlacementRule $placement,
        private readonly CanonicalValueIndex $index,
    ) {}

    /**
     * @param array{
     *     values: array<string, string|null>,
     *     issues: list<array{code: string, field: string|null}>
     * } $normalized
     * @param array<string, string|null>|null $student Existing master record, or null
     * @param array{group_size: int, is_first: bool, conflict: bool} $duplicate
     * @return array{
     *     classification: string,
     *     issues: list<array{code: string, field: string|null}>,
     *     preview: array<string, mixed>|null
     * }
     */
    public function classify(
        array $normalized,
        ?array $student,
        array $duplicate
    ): array {
        // Duplicate IDs must never be applied more than once.
        if (
            $duplicate['group_size'] > 1
            && ($duplicate['conflict'] || ! $duplicate['is_first'])
        ) {
            return [
                'classification' => self::DUPLICATE_IN_FILE,
                'issues' => [
                    ImportIssue::make(
                        $duplicate['conflict']
                            ? ImportIssue::DUPLICATE_ID_CONFLICT
                            : ImportIssue::DUPLICATE_ID_REPEAT,
                        'institutional_id'
                    ),
                ],
                'preview' => null,
            ];
        }

        // Invalid source data always takes precedence over other classifications.
        if ($normalized['issues'] !== []) {
            return [
                'classification' => self::INVALID,
                'issues' => $normalized['issues'],
                'preview' => null,
            ];
        }

        $values = $normalized['values'];
        $review = [];

        // Ambiguous college/course capitalization requires manual review.
        if ($this->index->isAmbiguous('college', $values['college'])) {
            $review[] = ImportIssue::make(
                ImportIssue::COLLEGE_CASE_VARIANT,
                'college'
            );
        }

        if ($this->index->isAmbiguous('course', $values['course'])) {
            $review[] = ImportIssue::make(
                ImportIssue::COURSE_CASE_VARIANT,
                'course'
            );
        }

        // New student records require new students may have either a 7-digit legacy ID or a 10-digit current ID.
        // Their institutional email is generated during processing.
        if (
            $student === null
            && preg_match(
                '/\A(?:[0-9]{7}|[0-9]{10})\z/',
                (string) $values['institutional_id']
            ) !== 1
        ) {
            $review[] = ImportIssue::make(
                ImportIssue::NEW_STUDENT_REQUIRES_INSTITUTIONAL_EMAIL,
                'institutional_email'
            );
        }

        // Never allow a new record to bypass an outstanding review issue.
        if ($review !== []) {
            return [
                'classification' => self::NEEDS_EXCEPTION_REVIEW,
                'issues' => $review,
                'preview' => null,
            ];
        }

        // A valid, previously unknown 10-digit student ID can be created.
        if ($student === null) {
            return [
                'classification' => self::NEW,
                'issues' => [],
                'preview' => [
                    'course_changed' => false,
                    'changed_fields' => PlacementRule::TRACKED,
                    'resulting_year_level' => $values['year_level'],
                ],
            ];
        }

        // Existing students continue through the established placement rules.
        $resolved = $this->placement->resolve($student, $values);

        return [
            'classification' => self::UPDATED,
            'issues' => [],
            'preview' => [
                'course_changed' => $resolved['course_changed'],
                'changed_fields' => $resolved['changed_fields'],
                'resulting_year_level' => $resolved['values']['year_level'],
            ],
        ];
    }
}