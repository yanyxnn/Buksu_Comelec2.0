<?php

namespace App\Services\StudentImport;

use Generator;
use XMLReader;
use ZipArchive;

/**
 * Minimal streaming reader for the FIRST worksheet of an .xlsx file, built on PHP's zip and
 * XML extensions only (no spreadsheet library dependency). Cell values are returned as the
 * raw text stored in the file: numbers are not converted, so nothing is re-formatted here.
 * Limitation (inherent to the source, not to this reader): an ID typed as a NUMBER in the
 * workbook has already lost any leading zeros; the ID column must be stored as text.
 */
final class XlsxRowReader implements RowReader
{
    public const DEFAULT_MAX_PART_BYTES = 100 * 1024 * 1024;

    public function __construct(private readonly int $maxPartBytes = self::DEFAULT_MAX_PART_BYTES) {}

    public function rows(string $path): Generator
    {
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new SourceFileException(SourceFileException::FILE_UNREADABLE);
        }

        try {
            $sheetPath = $this->firstSheetPath($zip);
            $this->assertBoundedSize($zip, $sheetPath);
            $this->assertBoundedSize($zip, 'xl/sharedStrings.xml');
            $shared = $this->sharedStrings($zip);

            $sheet = $zip->getFromName($sheetPath);
            if ($sheet === false) {
                throw new SourceFileException(SourceFileException::FILE_UNREADABLE);
            }

            yield from $this->parseSheet($sheet, $shared);
        } finally {
            $zip->close();
        }
    }

    /**
     * The upload is size-capped COMPRESSED, but the parts read here are decompressed into memory. A tiny
     * archive can declare a huge part (zip bomb), so the DECLARED uncompressed size is capped before reading.
     * A part that is absent is not an error here (the callers handle that).
     */
    private function assertBoundedSize(ZipArchive $zip, string $name): void
    {
        $stat = $zip->statName($name);

        if ($stat !== false && $stat['size'] > $this->maxPartBytes) {
            throw new SourceFileException(SourceFileException::FILE_TOO_LARGE);
        }
    }

    private function firstSheetPath(ZipArchive $zip): string
    {
        $workbook = $zip->getFromName('xl/workbook.xml');
        $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');

        if ($workbook !== false && $rels !== false) {
            $wb = @simplexml_load_string($workbook);
            $rl = @simplexml_load_string($rels);

            if ($wb !== false && $rl !== false) {
                $wb->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
                $sheets = $wb->xpath('//m:sheets/m:sheet');
                if (! empty($sheets)) {
                    $attrs = $sheets[0]->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
                    $rid = (string) ($attrs['id'] ?? '');
                    foreach ($rl->Relationship as $rel) {
                        if ((string) $rel['Id'] === $rid) {
                            $target = ltrim((string) $rel['Target'], '/');

                            return str_starts_with($target, 'xl/') ? $target : 'xl/'.$target;
                        }
                    }
                }
            }
        }

        return 'xl/worksheets/sheet1.xml';
    }

    /** @return list<string> */
    private function sharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false) {
            return [];
        }

        $reader = new XMLReader;
        $reader->XML($xml, null, LIBXML_NONET | LIBXML_NOWARNING);
        $strings = [];

        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::ELEMENT && $reader->name === 'si') {
                $strings[] = $this->collectText($reader, 'si');
            }
        }
        $reader->close();

        return $strings;
    }

    /** Concatenates <t> text inside the current element, skipping phonetic runs (<rPh>). */
    private function collectText(XMLReader $reader, string $closing): string
    {
        $text = '';
        if ($reader->isEmptyElement) {
            return $text;
        }

        $depth = $reader->depth;
        $skipDepth = null;

        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::END_ELEMENT && $reader->depth === $depth && $reader->name === $closing) {
                break;
            }
            if ($reader->nodeType === XMLReader::ELEMENT && $reader->name === 'rPh') {
                $skipDepth = $reader->depth;

                continue;
            }
            if ($skipDepth !== null) {
                if ($reader->nodeType === XMLReader::END_ELEMENT && $reader->depth === $skipDepth && $reader->name === 'rPh') {
                    $skipDepth = null;
                }

                continue;
            }
            if ($reader->nodeType === XMLReader::ELEMENT && $reader->name === 't' && ! $reader->isEmptyElement) {
                $text .= $reader->readString();
            }
        }

        return $text;
    }

    /**
     * @param  list<string>  $shared
     * @return Generator<int, list<string|null>>
     */
    private function parseSheet(string $xml, array $shared): Generator
    {
        $reader = new XMLReader;
        $reader->XML($xml, null, LIBXML_NONET | LIBXML_NOWARNING);

        $rowNumber = 0;

        while ($reader->read()) {
            if ($reader->nodeType !== XMLReader::ELEMENT || $reader->name !== 'row') {
                continue;
            }

            $declared = $reader->getAttribute('r');
            $rowNumber = $declared !== null && ctype_digit($declared) ? (int) $declared : $rowNumber + 1;

            $cells = [];
            $max = -1;

            if (! $reader->isEmptyElement) {
                $rowDepth = $reader->depth;
                while ($reader->read()) {
                    if ($reader->nodeType === XMLReader::END_ELEMENT && $reader->name === 'row' && $reader->depth === $rowDepth) {
                        break;
                    }
                    if ($reader->nodeType !== XMLReader::ELEMENT || $reader->name !== 'c') {
                        continue;
                    }

                    $ref = (string) $reader->getAttribute('r');
                    $type = (string) $reader->getAttribute('t');
                    $index = $ref !== '' ? self::columnIndex($ref) : $max + 1;
                    $value = $this->cellValue($reader, $type, $shared);

                    $cells[$index] = $value;
                    $max = max($max, $index);
                }
            }

            if ($max < 0) {
                continue;
            }

            $row = [];
            for ($i = 0; $i <= $max; $i++) {
                $row[] = $cells[$i] ?? null;
            }

            if (CsvRowReader::isBlank($row)) {
                continue;
            }

            foreach ($row as $cell) {
                if ($cell !== null && ! mb_check_encoding($cell, 'UTF-8')) {
                    throw new SourceFileException(SourceFileException::INVALID_ENCODING);
                }
            }

            yield $rowNumber => $row;
        }

        $reader->close();
    }

    /** @param list<string> $shared */
    private function cellValue(XMLReader $reader, string $type, array $shared): ?string
    {
        if ($reader->isEmptyElement) {
            return null;
        }

        $depth = $reader->depth;
        $value = null;

        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::END_ELEMENT && $reader->name === 'c' && $reader->depth === $depth) {
                break;
            }
            if ($reader->nodeType !== XMLReader::ELEMENT) {
                continue;
            }
            if ($reader->name === 'v') {
                $raw = $reader->readString();
                $value = $type === 's' ? ($shared[(int) $raw] ?? null) : $raw;
            } elseif ($reader->name === 'is') {
                $value = $this->collectText($reader, 'is');
            }
        }

        return $value;
    }

    /** "AB12" => 27 (zero-based column index). */
    public static function columnIndex(string $ref): int
    {
        $letters = preg_replace('/[^A-Za-z]/', '', $ref) ?? '';
        $n = 0;
        foreach (str_split(strtoupper($letters)) as $char) {
            $n = $n * 26 + (ord($char) - 64);
        }

        return max(0, $n - 1);
    }
}
