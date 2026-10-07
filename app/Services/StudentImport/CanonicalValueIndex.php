<?php

namespace App\Services\StudentImport;

/**
 * Detects case-only inconsistencies ("CON" vs "cON") in college/course values so they go to
 * REVIEW instead of being silently merged OR silently stored as two different colleges.
 *
 * Authority order for a case-insensitive group:
 *   1. values already stored in the master data (known forms) - a value is fine only if it
 *      exactly equals one of them;
 *   2. otherwise the file itself: a single form is fine; with several forms only a UNIQUE
 *      most-frequent form is fine; a tie means every form is ambiguous.
 * Genuinely different values (different letters) are never treated as the same value.
 */
final class CanonicalValueIndex
{
    /** @var array<string, array<string, array<string, int>>> field => foldedKey => exactForm => count (file) */
    private array $file = [];

    /** @var array<string, array<string, array<string, true>>> field => foldedKey => exactForm => true (master data) */
    private array $known = [];

    public static function fold(string $value): string
    {
        return mb_strtolower($value, 'UTF-8');
    }

    public function addFileValue(string $field, ?string $value): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $key = self::fold($value);
        $this->file[$field][$key][$value] = ($this->file[$field][$key][$value] ?? 0) + 1;
    }

    /**
     * @param  iterable<string>  $forms
     */
    public function addKnownValues(string $field, iterable $forms): void
    {
        foreach ($forms as $form) {
            if ($form !== null && $form !== '') {
                $this->known[$field][self::fold($form)][$form] = true;
            }
        }
    }

    public function isAmbiguous(string $field, ?string $value): bool
    {
        if ($value === null || $value === '') {
            return false;
        }

        $key = self::fold($value);

        if (isset($this->known[$field][$key])) {
            return ! isset($this->known[$field][$key][$value]);
        }

        $forms = $this->file[$field][$key] ?? [];

        if (count($forms) <= 1) {
            return false;
        }

        $max = max($forms);
        $top = array_keys(array_filter($forms, fn (int $count) => $count === $max));

        return ! (count($top) === 1 && $top[0] === $value);
    }
}
