<?php

namespace App\Services\StudentImport;

/**
 * Deterministic, safe normalization of ONE mapped source row.
 *
 * Only harmless formatting is touched: surrounding/internal whitespace and the year-level
 * token. Identity (institutional ID) is kept as the trimmed STRING - never cast, never
 * zero-stripped. Case is never changed (case-only differences are a REVIEW concern, see
 * CanonicalValueIndex). Nothing is guessed: anything that cannot be normalized safely is
 * reported as an issue and leaves the row INVALID.
 */
final class RowNormalizer
{
    private const MAX_ID = 255;

    private const MAX_NAME = 255;

    private const MAX_PLACEMENT = 100;

    /** @var array<string, string> normalized year token => canonical label */
    private array $yearLookup = [];

    /**
     * @param  array<string, list<string>>  $yearLevels  canonical label => accepted source tokens
     */
    public function __construct(array $yearLevels)
    {
        foreach ($yearLevels as $label => $tokens) {
            foreach ($tokens as $token) {
                $this->yearLookup[self::yearToken($token)] = $label;
            }
            $this->yearLookup[self::yearToken($label)] = $label;
        }
    }

    public static function squish(?string $value): string
    {
        $value = (string) $value;
        $value = preg_replace('/[\s\x{00A0}]+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    private static function yearToken(string $value): string
    {
        $token = mb_strtolower(self::squish($value), 'UTF-8');

        // Spreadsheets hand numeric cells over as "1" or "1.0".
        if (preg_match('/^(\d+)\.0+$/', $token, $m) === 1) {
            return $m[1];
        }

        return $token;
    }

    /**
     * @param  array<string, string|null>  $raw  field => raw cell
     * @return array{values: array<string, string|null>, issues: list<array{code: string, field: string|null}>}
     */
    public function normalize(array $raw): array
    {
        $issues = [];

        // --- identity: trimmed string, preserved exactly otherwise ------------------------
        $id = trim((string) ($raw['institutional_id'] ?? ''));
        if ($id === '') {
            $issues[] = ImportIssue::make(ImportIssue::ID_MISSING, 'institutional_id');
        } else {
            if (mb_strlen($id, 'UTF-8') > self::MAX_ID) {
                $issues[] = ImportIssue::make(ImportIssue::ID_TOO_LONG, 'institutional_id');
            }
            if (preg_match('/[\x00-\x1F\x7F]/', $id) === 1) {
                $issues[] = ImportIssue::make(ImportIssue::ID_CONTROL_CHARACTERS, 'institutional_id');
            }
            if (preg_match('/^\d+(\.\d+)?[eE][+-]?\d+$/', $id) === 1 || preg_match('/^\d+\.0+$/', $id) === 1) {
                $issues[] = ImportIssue::make(ImportIssue::ID_NUMERIC_FORMAT_ARTIFACT, 'institutional_id');
            }
        }

        // --- names -------------------------------------------------------------------------
        $last = self::squish($raw['last_name'] ?? null);
        $first = self::squish($raw['first_name'] ?? null);
        $middle = self::squish($raw['middle_name'] ?? null);

        if ($last === '') {
            $issues[] = ImportIssue::make(ImportIssue::LAST_NAME_MISSING, 'last_name');
        }
        if ($first === '') {
            $issues[] = ImportIssue::make(ImportIssue::FIRST_NAME_MISSING, 'first_name');
        }
        foreach (['last_name' => $last, 'first_name' => $first, 'middle_name' => $middle] as $field => $value) {
            if (mb_strlen($value, 'UTF-8') > self::MAX_NAME) {
                $issues[] = ImportIssue::make(ImportIssue::NAME_TOO_LONG, $field);
            }
        }

        // --- placement ---------------------------------------------------------------------
        $college = self::squish($raw['college'] ?? null);
        $course = self::squish($raw['course'] ?? null);

        if ($college === '') {
            $issues[] = ImportIssue::make(ImportIssue::COLLEGE_MISSING, 'college');
        } elseif (mb_strlen($college, 'UTF-8') > self::MAX_PLACEMENT) {
            $issues[] = ImportIssue::make(ImportIssue::COLLEGE_TOO_LONG, 'college');
        }

        if ($course === '') {
            $issues[] = ImportIssue::make(ImportIssue::COURSE_MISSING, 'course');
        } elseif (mb_strlen($course, 'UTF-8') > self::MAX_PLACEMENT) {
            $issues[] = ImportIssue::make(ImportIssue::COURSE_TOO_LONG, 'course');
        }

        $yearRaw = self::squish($raw['year_level'] ?? null);
        $year = null;
        if ($yearRaw === '') {
            $issues[] = ImportIssue::make(ImportIssue::YEAR_MISSING, 'year_level');
        } else {
            $year = $this->yearLookup[self::yearToken($yearRaw)] ?? null;
            if ($year === null) {
                $issues[] = ImportIssue::make(ImportIssue::YEAR_INVALID, 'year_level');
            }
        }

        // --- status (optional column; ACTIVE/INACTIVE only) ---------------------------------
        $statusRaw = self::squish($raw['status'] ?? null);
        $status = null;
        if ($statusRaw !== '') {
            $candidate = strtoupper($statusRaw);
            if (in_array($candidate, ['ACTIVE', 'INACTIVE'], true)) {
                $status = $candidate;
            } else {
                $issues[] = ImportIssue::make(ImportIssue::STATUS_INVALID, 'status');
            }
        }

        return [
            'values' => [
                'institutional_id' => $id === '' ? null : $id,
                'last_name' => $last === '' ? null : $last,
                'first_name' => $first === '' ? null : $first,
                'middle_name' => $middle === '' ? null : $middle,
                'college' => $college === '' ? null : $college,
                'course' => $course === '' ? null : $course,
                'year_level' => $year,
                'year_level_source' => $yearRaw === '' ? null : $yearRaw,
                'status' => $status,
            ],
            'issues' => $issues,
        ];
    }
}
