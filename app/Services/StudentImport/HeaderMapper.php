<?php

namespace App\Services\StudentImport;

/**
 * Maps the source file's column headers to domain fields via a configured alias table.
 * Unmapped columns ("No.", "Sex", anything else) are ignored for domain purposes; they
 * are never identity data and never become student fields.
 */
final class HeaderMapper
{
    public const REQUIRED = ['institutional_id', 'last_name', 'first_name', 'college', 'course', 'year_level'];

    /** @var array<string, string> normalized alias => field */
    private array $lookup = [];

    /**
     * @param  array<string, list<string>>  $aliases  field => accepted header spellings
     */
    public function __construct(array $aliases)
    {
        foreach ($aliases as $field => $names) {
            foreach ($names as $name) {
                $this->lookup[self::normalizeHeader($name)] = $field;
            }
        }
    }

    public static function normalizeHeader(?string $header): string
    {
        $header = (string) $header;
        $header = preg_replace('/^\xEF\xBB\xBF/', '', $header) ?? $header; // UTF-8 BOM
        $header = preg_replace('/[\s\x{00A0}]+/u', ' ', $header) ?? $header;

        return mb_strtolower(trim($header), 'UTF-8');
    }

    /**
     * @param  list<string|null>  $headers
     * @return array<int, string> column index => field
     *
     * @throws SourceFileException
     */
    public function map(array $headers): array
    {
        $map = [];
        $seen = [];

        foreach ($headers as $index => $header) {
            $field = $this->lookup[self::normalizeHeader($header)] ?? null;

            if ($field === null) {
                continue;
            }

            if (isset($seen[$field])) {
                throw new SourceFileException(SourceFileException::DUPLICATE_COLUMNS);
            }

            $seen[$field] = true;
            $map[$index] = $field;
        }

        if (array_diff(self::REQUIRED, array_values($map)) !== []) {
            throw new SourceFileException(SourceFileException::MISSING_REQUIRED_COLUMNS);
        }

        return $map;
    }

    /**
     * @param  list<string|null>  $cells
     * @param  array<int, string>  $map
     * @return array<string, string|null> field => raw cell (never cast)
     */
    public function extract(array $cells, array $map): array
    {
        $row = array_fill_keys(array_values($map), null);

        foreach ($map as $index => $field) {
            $row[$field] = $cells[$index] ?? null;
        }

        return $row;
    }
}
