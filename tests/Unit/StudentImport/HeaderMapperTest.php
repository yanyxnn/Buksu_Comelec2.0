<?php

use App\Services\StudentImport\SourceFileException;
use Tests\Support\ImportTestKit;

it('maps the historical Data Center headers and ignores No. and Sex', function () {
    $map = ImportTestKit::mapper()->map(ImportTestKit::HEADERS);

    expect($map)->toBe([
        1 => 'institutional_id', 2 => 'last_name', 3 => 'first_name', 4 => 'middle_name',
        6 => 'college', 7 => 'course', 8 => 'year_level',
    ]);
    expect(array_values($map))->not->toContain('sex');
});

it('tolerates case, spacing and a UTF-8 BOM in headers', function () {
    $map = ImportTestKit::mapper()->map(["\xEF\xBB\xBFCODE", '  last   NAME ', 'First Name', 'Middle Name', 'COLLEGES', 'course', 'YEAR']);

    expect(array_values($map))->toBe(['institutional_id', 'last_name', 'first_name', 'middle_name', 'college', 'course', 'year_level']);
});

it('rejects a file that lacks a required column', function () {
    expect(fn () => ImportTestKit::mapper()->map(['Code', 'Last Name', 'First Name', 'Colleges', 'Course']))
        ->toThrow(SourceFileException::class);

    try {
        ImportTestKit::mapper()->map(['Code', 'Last Name', 'First Name', 'Colleges', 'Course']);
    } catch (SourceFileException $e) {
        expect($e->reason)->toBe(SourceFileException::MISSING_REQUIRED_COLUMNS);
    }
});

it('rejects two columns mapping to the same field', function () {
    try {
        ImportTestKit::mapper()->map(['Code', 'Code', 'Last Name', 'First Name', 'Colleges', 'Course', 'Year']);
        $reason = null;
    } catch (SourceFileException $e) {
        $reason = $e->reason;
    }

    expect($reason)->toBe(SourceFileException::DUPLICATE_COLUMNS);
});

it('extracts raw cells without casting the identity to a number', function () {
    $mapper = ImportTestKit::mapper();
    $map = $mapper->map(ImportTestKit::HEADERS);
    $row = $mapper->extract(['1', '0012345', 'Cruz', 'Ana', '', 'F', 'CON', 'BSN', '3'], $map);

    expect($row['institutional_id'])->toBe('0012345');
    expect($row['institutional_id'])->toBeString();
    expect(array_key_exists('sex', $row))->toBeFalse();
});
