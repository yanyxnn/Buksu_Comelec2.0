<?php

use App\Services\StudentImport\CsvRowReader;
use App\Services\StudentImport\RowReaderFactory;
use App\Services\StudentImport\SourceFileException;
use App\Services\StudentImport\XlsxRowReader;
use Tests\Support\ImportTestKit;

function writeTemp(string $contents, string $suffix): string
{
    $path = ImportTestKit::tempPath($suffix);
    file_put_contents($path, $contents);

    return $path;
}

it('reads CSV cells as strings with the original row numbers, skipping blank lines and a BOM', function () {
    $path = writeTemp("\xEF\xBB\xBFCode,Last Name\n\n007,Cruz\n,\n0012,Reyes\n", '.csv');
    $rows = iterator_to_array((new CsvRowReader)->rows($path));
    unlink($path);

    expect(array_keys($rows))->toBe([1, 3, 5]);
    expect($rows[1][0])->toBe('Code');
    expect($rows[3])->toBe(['007', 'Cruz']);
    expect($rows[5][0])->toBe('0012');
});

it('handles quoted CSV fields containing commas and newlines', function () {
    $path = writeTemp("Code,Last Name\n1,\"Cruz, Jr.\"\n2,\"Two\nLines\"\n", '.csv');
    $rows = iterator_to_array((new CsvRowReader)->rows($path));
    unlink($path);

    expect($rows[2][1])->toBe('Cruz, Jr.');
    expect($rows[3][1])->toBe("Two\nLines");
});

it('fails closed on non UTF-8 content instead of guessing an encoding', function () {
    $path = writeTemp("Code,Last Name\n1,Mu\xF1oz\n", '.csv');

    try {
        iterator_to_array((new CsvRowReader)->rows($path));
        $reason = null;
    } catch (SourceFileException $e) {
        $reason = $e->reason;
    } finally {
        unlink($path);
    }

    expect($reason)->toBe(SourceFileException::INVALID_ENCODING);
});

it('reads the first worksheet of an xlsx as raw strings, keeping text IDs and column gaps', function () {
    $path = ImportTestKit::tempPath('.xlsx');
    ImportTestKit::writeXlsx($path, [
        ['No.', 'Code', 'Last Name'],
        [1, '00123', 'Cruz'],
        [],
        [2, ['inline' => '0456'], null, 'gap'],
    ]);
    $rows = iterator_to_array((new XlsxRowReader)->rows($path));
    unlink($path);

    expect(array_keys($rows))->toBe([1, 2, 4]);
    expect($rows[1])->toBe(['No.', 'Code', 'Last Name']);
    expect($rows[2])->toBe(['1', '00123', 'Cruz']);
    expect($rows[4])->toBe(['2', '0456', null, 'gap']);
    expect(json_encode($rows))->not->toContain('SHOULD NOT BE READ');
});

it('returns numeric xlsx cells as their stored text and does not reformat them', function () {
    $path = ImportTestKit::tempPath('.xlsx');
    ImportTestKit::writeXlsx($path, [['Code'], [20001]]);
    $rows = iterator_to_array((new XlsxRowReader)->rows($path));
    unlink($path);

    expect($rows[2][0])->toBe('20001');
});

it('rejects a corrupt xlsx and an unsupported extension', function () {
    $path = writeTemp('not a zip', '.xlsx');
    try {
        iterator_to_array((new XlsxRowReader)->rows($path));
        $reason = null;
    } catch (SourceFileException $e) {
        $reason = $e->reason;
    } finally {
        unlink($path);
    }

    expect($reason)->toBe(SourceFileException::FILE_UNREADABLE);
    expect(fn () => RowReaderFactory::forExtension('xls'))->toThrow(SourceFileException::class);
    expect(RowReaderFactory::forExtension('CSV'))->toBeInstanceOf(CsvRowReader::class);
});

it('converts column references to zero-based indexes', function () {
    expect(XlsxRowReader::columnIndex('A1'))->toBe(0);
    expect(XlsxRowReader::columnIndex('Z9'))->toBe(25);
    expect(XlsxRowReader::columnIndex('AA1'))->toBe(26);
    expect(XlsxRowReader::columnIndex('AB12'))->toBe(27);
});

it('refuses an xlsx whose worksheet declares more uncompressed data than the cap (zip-bomb guard)', function () {
    $path = ImportTestKit::tempPath('.xlsx');
    // Highly compressible: a few hundred KB of text deflates to a tiny archive but declares its full size.
    $rows = [['Code', 'Last Name']];
    for ($i = 0; $i < 4000; $i++) {
        $rows[] = [(string) $i, str_repeat('x', 200)];
    }
    ImportTestKit::writeXlsx($path, $rows);

    try {
        expect(fn () => iterator_to_array((new XlsxRowReader(maxPartBytes: 100_000))->rows($path)))
            ->toThrow(SourceFileException::class);

        // The same file is read normally under a sane cap, so the guard is the only difference.
        expect(iterator_to_array((new XlsxRowReader)->rows($path)))->toHaveCount(4001);
    } finally {
        unlink($path);
    }
});
