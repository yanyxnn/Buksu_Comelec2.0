<?php

namespace App\Services\StudentImport;

use Generator;

/**
 * Streams a CSV file as raw string cells. Never casts: "007" stays "007".
 * The file must be UTF-8 (a BOM is tolerated); anything else fails closed rather than
 * being guessed at, because a mis-decoded name or ID would silently corrupt master data.
 */
final class CsvRowReader implements RowReader
{
    public function rows(string $path): Generator
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new SourceFileException(SourceFileException::FILE_UNREADABLE);
        }

        try {
            $rowNumber = 0;
            $first = true;

            while (($cells = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                $rowNumber++;

                if ($cells === [null]) { // blank line
                    continue;
                }

                $out = [];
                foreach ($cells as $cell) {
                    $cell = (string) $cell;
                    if ($first) {
                        $cell = preg_replace('/^\xEF\xBB\xBF/', '', $cell) ?? $cell;
                    }
                    if (! mb_check_encoding($cell, 'UTF-8')) {
                        throw new SourceFileException(SourceFileException::INVALID_ENCODING);
                    }
                    $out[] = $cell;
                }
                $first = false;

                if (self::isBlank($out)) {
                    continue;
                }

                yield $rowNumber => $out;
            }
        } finally {
            fclose($handle);
        }
    }

    /** @param list<string|null> $cells */
    public static function isBlank(array $cells): bool
    {
        foreach ($cells as $cell) {
            if (trim((string) $cell) !== '') {
                return false;
            }
        }

        return true;
    }
}
