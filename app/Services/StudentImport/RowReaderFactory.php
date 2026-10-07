<?php

namespace App\Services\StudentImport;

final class RowReaderFactory
{
    /** @throws SourceFileException */
    public static function forExtension(string $extension, int $maxXlsxPartBytes = XlsxRowReader::DEFAULT_MAX_PART_BYTES): RowReader
    {
        return match (strtolower($extension)) {
            'csv' => new CsvRowReader,
            'xlsx' => new XlsxRowReader($maxXlsxPartBytes),
            default => throw new SourceFileException(SourceFileException::UNSUPPORTED_FILE_TYPE),
        };
    }
}
