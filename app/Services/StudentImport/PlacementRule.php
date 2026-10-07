<?php

namespace App\Services\StudentImport;

/**
 * Applies an incoming official placement to an EXISTING student's current record.
 *
 * Approved rule (CLAUDE.md / STUDENT_MANAGEMENT.md): when the student's course/program
 * changes, the COMELEC year level in the new course is the first year, regardless of the
 * previous course/year and regardless of the year the source reports. Year level is never
 * derived from subjects. With an unchanged course, the validated incoming year is kept.
 * An absent status column keeps the student's current status (nothing is invented).
 */
final class PlacementRule
{
    public const TRACKED = ['first_name', 'middle_name', 'last_name', 'college', 'course', 'year_level', 'status'];

    public function __construct(private readonly string $firstYearLabel) {}

    /**
     * @param  array<string, string|null>  $student  current: first_name, middle_name, last_name, current_college, current_course, current_year_level, status
     * @param  array<string, string|null>  $incoming  RowNormalizer values
     * @return array{values: array<string, string|null>, course_changed: bool, changed_fields: list<string>}
     */
    public function resolve(array $student, array $incoming): array
    {
        $courseChanged = ($student['current_course'] ?? null) !== $incoming['course'];

        $values = [
            'first_name' => $incoming['first_name'],
            'middle_name' => $incoming['middle_name'],
            'last_name' => $incoming['last_name'],
            'college' => $incoming['college'],
            'course' => $incoming['course'],
            'year_level' => $courseChanged ? $this->firstYearLabel : $incoming['year_level'],
            'status' => $incoming['status'] ?? ($student['status'] ?? null),
        ];

        $current = [
            'first_name' => $student['first_name'] ?? null,
            'middle_name' => $student['middle_name'] ?? null,
            'last_name' => $student['last_name'] ?? null,
            'college' => $student['current_college'] ?? null,
            'course' => $student['current_course'] ?? null,
            'year_level' => $student['current_year_level'] ?? null,
            'status' => $student['status'] ?? null,
        ];

        $changed = [];
        foreach (self::TRACKED as $field) {
            if (($current[$field] ?? null) !== ($values[$field] ?? null)) {
                $changed[] = $field;
            }
        }

        return ['values' => $values, 'course_changed' => $courseChanged, 'changed_fields' => $changed];
    }
}
