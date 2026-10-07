<?php

namespace App\Services\StudentImport;

/**
 * Deterministic classification of one normalized row into the existing import_batch_rows
 * classification set. Precedence: duplicate-in-file, invalid, needs review, then
 * UPDATED (existing student). The NEW classification is deliberately NOT produced: a student
 * who does not exist yet cannot be created from import data because the master record requires
 * an institutional email and the official source does not carry one (never fabricated).
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
     * @param  array{values: array<string, string|null>, issues: list<array{code: string, field: string|null}>}  $normalized
     * @param  array<string, string|null>|null  $student  existing master record (or null)
     * @param  array{group_size: int, is_first: bool, conflict: bool}  $duplicate
     * @return array{classification: string, issues: list<array{code: string, field: string|null}>, preview: array<string, mixed>|null}
     */
    public function classify(array $normalized, ?array $student, array $duplicate): array
    {
        if ($duplicate['group_size'] > 1 && ($duplicate['conflict'] || ! $duplicate['is_first'])) {
            return [
                'classification' => self::DUPLICATE_IN_FILE,
                'issues' => [ImportIssue::make(
                    $duplicate['conflict'] ? ImportIssue::DUPLICATE_ID_CONFLICT : ImportIssue::DUPLICATE_ID_REPEAT,
                    'institutional_id'
                )],
                'preview' => null,
            ];
        }

        if ($normalized['issues'] !== []) {
            return ['classification' => self::INVALID, 'issues' => $normalized['issues'], 'preview' => null];
        }

        $values = $normalized['values'];
        $review = [];

        if ($this->index->isAmbiguous('college', $values['college'])) {
            $review[] = ImportIssue::make(ImportIssue::COLLEGE_CASE_VARIANT, 'college');
        }
        if ($this->index->isAmbiguous('course', $values['course'])) {
            $review[] = ImportIssue::make(ImportIssue::COURSE_CASE_VARIANT, 'course');
        }
        if ($student === null) {
            $review[] = ImportIssue::make(ImportIssue::NEW_STUDENT_REQUIRES_INSTITUTIONAL_EMAIL, 'institutional_email');
        }

        if ($review !== []) {
            return ['classification' => self::NEEDS_EXCEPTION_REVIEW, 'issues' => $review, 'preview' => null];
        }

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
