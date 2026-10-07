<?php

use Tests\Support\ImportTestKit;

function resolveFor(array $student, array $rawOverrides): array
{
    $values = ImportTestKit::normalizer()->normalize(ImportTestKit::raw($rawOverrides))['values'];

    return ImportTestKit::rule()->resolve($student, $values);
}

it('sets the year to 1st Year when the course changes, whatever the source year says', function (string $sourceYear) {
    $result = resolveFor(ImportTestKit::student(['current_course' => 'BSN', 'current_year_level' => '4th Year']), ['course' => 'BSIT', 'year_level' => $sourceYear]);

    expect($result['course_changed'])->toBeTrue();
    expect($result['values']['course'])->toBe('BSIT');
    expect($result['values']['year_level'])->toBe('1st Year');
    expect($result['changed_fields'])->toContain('course', 'year_level');
})->with(['1', '2', '3', '4']);

it('keeps the validated incoming year when the course is unchanged', function () {
    $result = resolveFor(ImportTestKit::student(['current_course' => 'BSN', 'current_year_level' => '2nd Year']), ['course' => 'BSN', 'year_level' => '3']);

    expect($result['course_changed'])->toBeFalse();
    expect($result['values']['year_level'])->toBe('3rd Year');
    expect($result['changed_fields'])->toBe(['year_level']);
});

it('reports no change for an identical placement', function () {
    $result = resolveFor(ImportTestKit::student(), []);

    expect($result['changed_fields'])->toBe([]);
});

it('keeps the current status when the source has no status and applies one when it has', function () {
    $kept = resolveFor(ImportTestKit::student(['status' => 'INACTIVE']), ['status' => null]);
    $applied = resolveFor(ImportTestKit::student(['status' => 'INACTIVE']), ['status' => 'ACTIVE']);

    expect($kept['values']['status'])->toBe('INACTIVE');
    expect($applied['values']['status'])->toBe('ACTIVE');
    expect($applied['changed_fields'])->toContain('status');
});

it('treats a college-only change without a course change as a plain update', function () {
    $result = resolveFor(ImportTestKit::student(), ['college' => 'CAS']);

    expect($result['course_changed'])->toBeFalse();
    expect($result['values']['year_level'])->toBe('2nd Year');
    expect($result['changed_fields'])->toBe(['college']);
});
