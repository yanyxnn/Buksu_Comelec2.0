<?php

use App\Services\StudentImport\ImportIssue;
use Tests\Support\ImportTestKit;

function codes(array $result): array
{
    return array_column($result['issues'], 'code');
}

it('keeps the institutional id as an exact string (leading zeros, any length)', function (string $id) {
    $result = ImportTestKit::normalizer()->normalize(ImportTestKit::raw(['institutional_id' => $id]));

    expect($result['values']['institutional_id'])->toBe($id);
    expect($result['issues'])->toBe([]);
})->with(['00123', '0', '2020-00001', 'A-0007', '123456789012345678901234567890']);

it('trims surrounding whitespace but never changes case', function () {
    $result = ImportTestKit::normalizer()->normalize(ImportTestKit::raw([
        'institutional_id' => '  2020-1 ', 'college' => " cON\u{00A0}", 'course' => ' B S  N ', 'last_name' => '  de   la Cruz ',
    ]));

    expect($result['values']['institutional_id'])->toBe('2020-1');
    expect($result['values']['college'])->toBe('cON');
    expect($result['values']['course'])->toBe('B S N');
    expect($result['values']['last_name'])->toBe('de la Cruz');
});

it('flags a missing identity', function (?string $id) {
    $result = ImportTestKit::normalizer()->normalize(ImportTestKit::raw(['institutional_id' => $id]));

    expect(codes($result))->toContain(ImportIssue::ID_MISSING);
})->with([null, '', '   ']);

it('flags identities a spreadsheet has mangled into numeric formats', function (string $id) {
    $result = ImportTestKit::normalizer()->normalize(ImportTestKit::raw(['institutional_id' => $id]));

    expect(codes($result))->toContain(ImportIssue::ID_NUMERIC_FORMAT_ARTIFACT);
})->with(['2.0E7', '2.5e+10', '20001.0']);

it('maps approved year values deterministically to one internal label', function (string $source, string $label) {
    $result = ImportTestKit::normalizer()->normalize(ImportTestKit::raw(['year_level' => $source]));

    expect($result['values']['year_level'])->toBe($label);
    expect($result['issues'])->toBe([]);
})->with([
    ['1', '1st Year'], ['2', '2nd Year'], ['3', '3rd Year'], ['4', '4th Year'],
    ['3.0', '3rd Year'], [' 4 ', '4th Year'], ['2nd Year', '2nd Year'], ['2ND YEAR', '2nd Year'], ['first year', '1st Year'],
]);

it('rejects unknown or missing year values instead of guessing', function (string $source, string $code) {
    $result = ImportTestKit::normalizer()->normalize(ImportTestKit::raw(['year_level' => $source]));

    expect(codes($result))->toContain($code);
    expect($result['values']['year_level'])->toBeNull();
})->with([
    ['0', ImportIssue::YEAR_INVALID], ['5', ImportIssue::YEAR_INVALID], ['1.5', ImportIssue::YEAR_INVALID],
    ['-1', ImportIssue::YEAR_INVALID], ['fifth', ImportIssue::YEAR_INVALID], ['', ImportIssue::YEAR_MISSING],
]);

it('requires names, college and course', function () {
    $result = ImportTestKit::normalizer()->normalize(ImportTestKit::raw([
        'last_name' => ' ', 'first_name' => null, 'college' => '', 'course' => '',
    ]));

    expect(codes($result))->toContain(ImportIssue::LAST_NAME_MISSING, ImportIssue::FIRST_NAME_MISSING, ImportIssue::COLLEGE_MISSING, ImportIssue::COURSE_MISSING);
});

it('treats an empty middle name as null', function () {
    expect(ImportTestKit::normalizer()->normalize(ImportTestKit::raw(['middle_name' => '  ']))['values']['middle_name'])->toBeNull();
});

it('accepts only ACTIVE and INACTIVE as status when a status column exists', function () {
    $n = ImportTestKit::normalizer();

    expect($n->normalize(ImportTestKit::raw(['status' => 'inactive']))['values']['status'])->toBe('INACTIVE');
    expect($n->normalize(ImportTestKit::raw(['status' => null]))['values']['status'])->toBeNull();
    expect(codes($n->normalize(ImportTestKit::raw(['status' => 'IRREGULAR']))))->toContain(ImportIssue::STATUS_INVALID);
    expect(codes($n->normalize(ImportTestKit::raw(['status' => 'GRADUATED']))))->toContain(ImportIssue::STATUS_INVALID);
});

it('never carries sex or the source row number into the normalized values', function () {
    $values = ImportTestKit::normalizer()->normalize(ImportTestKit::raw())['values'];

    expect(array_keys($values))->not->toContain('sex');
    expect(array_keys($values))->not->toContain('no');
});

it('flags over-long values that the master columns could not hold', function () {
    $result = ImportTestKit::normalizer()->normalize(ImportTestKit::raw(['college' => str_repeat('A', 101), 'course' => str_repeat('B', 101)]));

    expect(codes($result))->toContain(ImportIssue::COLLEGE_TOO_LONG, ImportIssue::COURSE_TOO_LONG);
});
