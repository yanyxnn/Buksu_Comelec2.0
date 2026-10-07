<?php

use App\Services\StudentImport\CanonicalValueIndex;
use App\Services\StudentImport\ImportIssue;
use App\Services\StudentImport\RowClassifier;
use Tests\Support\ImportTestKit;

function classifyRaw(array $raw, ?array $student, array $dup = [], ?CanonicalValueIndex $index = null): array
{
    $normalized = ImportTestKit::normalizer()->normalize(ImportTestKit::raw($raw));

    return ImportTestKit::classifier($index)->classify(
        $normalized,
        $student,
        array_replace(['group_size' => 1, 'is_first' => true, 'conflict' => false], $dup),
    );
}

it('classifies a valid row for an existing student as UPDATED', function () {
    $result = classifyRaw([], ImportTestKit::student());

    expect($result['classification'])->toBe(RowClassifier::UPDATED);
    expect($result['issues'])->toBe([]);
});

it('records the changed placement in the preview, including a course change to 1st Year', function () {
    $result = classifyRaw(['course' => 'BSIT', 'year_level' => '4'], ImportTestKit::student());

    expect($result['classification'])->toBe(RowClassifier::UPDATED);
    expect($result['preview']['course_changed'])->toBeTrue();
    expect($result['preview']['resulting_year_level'])->toBe('1st Year');
});

it('never classifies an unknown student as NEW and never invents an email: it needs review', function () {
    $result = classifyRaw([], null);

    expect($result['classification'])->toBe(RowClassifier::NEEDS_EXCEPTION_REVIEW);
    expect(array_column($result['issues'], 'code'))->toBe([ImportIssue::NEW_STUDENT_REQUIRES_INSTITUTIONAL_EMAIL]);
    expect($result['classification'])->not->toBe(RowClassifier::NEW);
});

it('classifies bad data as INVALID', function (array $raw, string $code) {
    $result = classifyRaw($raw, ImportTestKit::student());

    expect($result['classification'])->toBe(RowClassifier::INVALID);
    expect(array_column($result['issues'], 'code'))->toContain($code);
})->with([
    'missing id' => [['institutional_id' => ''], ImportIssue::ID_MISSING],
    'bad year' => [['year_level' => '9'], ImportIssue::YEAR_INVALID],
    'no first name' => [['first_name' => ''], ImportIssue::FIRST_NAME_MISSING],
    'bad status' => [['status' => 'IRREGULAR'], ImportIssue::STATUS_INVALID],
]);

it('sends a case-only college inconsistency to review, not to a silent merge', function () {
    $index = new CanonicalValueIndex;
    foreach (array_merge(array_fill(0, 20, 'CON'), ['cON']) as $v) {
        $index->addFileValue('college', $v);
    }

    $minor = classifyRaw(['college' => 'cON'], ImportTestKit::student(), [], $index);
    $major = classifyRaw(['college' => 'CON'], ImportTestKit::student(), [], $index);

    expect($minor['classification'])->toBe(RowClassifier::NEEDS_EXCEPTION_REVIEW);
    expect(array_column($minor['issues'], 'code'))->toContain(ImportIssue::COLLEGE_CASE_VARIANT);
    expect($major['classification'])->toBe(RowClassifier::UPDATED);
});

it('keeps the first of identical duplicates and skips the repeats', function () {
    $first = classifyRaw([], ImportTestKit::student(), ['group_size' => 3, 'is_first' => true]);
    $repeat = classifyRaw([], ImportTestKit::student(), ['group_size' => 3, 'is_first' => false]);

    expect($first['classification'])->toBe(RowClassifier::UPDATED);
    expect($repeat['classification'])->toBe(RowClassifier::DUPLICATE_IN_FILE);
    expect(array_column($repeat['issues'], 'code'))->toBe([ImportIssue::DUPLICATE_ID_REPEAT]);
});

it('applies NO occurrence of a conflicting duplicate', function () {
    $first = classifyRaw([], ImportTestKit::student(), ['group_size' => 2, 'is_first' => true, 'conflict' => true]);
    $second = classifyRaw([], ImportTestKit::student(), ['group_size' => 2, 'is_first' => false, 'conflict' => true]);

    expect($first['classification'])->toBe(RowClassifier::DUPLICATE_IN_FILE);
    expect($second['classification'])->toBe(RowClassifier::DUPLICATE_IN_FILE);
    expect(array_column($first['issues'], 'code'))->toBe([ImportIssue::DUPLICATE_ID_CONFLICT]);
});

it('does not use the Sex column or the No. column for anything', function () {
    $result = classifyRaw([], ImportTestKit::student());

    expect(json_encode($result))->not->toContain('sex');
});
