<?php

namespace Tests\Support;

use App\Services\StudentImport\CanonicalValueIndex;
use App\Services\StudentImport\HeaderMapper;
use App\Services\StudentImport\PlacementRule;
use App\Services\StudentImport\RowClassifier;
use App\Services\StudentImport\RowNormalizer;
use RuntimeException;
use ZipArchive;

/**
 * Builders for Phase 03A tests. Everything here is SYNTHETIC: names/IDs are generated, no real
 * student data is ever read or committed.
 */
final class ImportTestKit
{
    public const HEADERS = ['No.', 'Code', 'Last Name', 'First Name', 'Middle Name', 'Sex', 'Colleges', 'Course', 'Year'];

    /** @return array<string, mixed> the `import` block of config/comelec.php (defaults) */
    public static function config(): array
    {
        static $config = null;

        return $config ??= (require dirname(__DIR__, 2).'/config/comelec.php')['import'];
    }

    public static function mapper(): HeaderMapper
    {
        return new HeaderMapper(self::config()['header_aliases']);
    }

    public static function normalizer(): RowNormalizer
    {
        return new RowNormalizer(self::config()['year_levels']);
    }

    public static function rule(): PlacementRule
    {
        return new PlacementRule(array_key_first(self::config()['year_levels']));
    }

    public static function classifier(?CanonicalValueIndex $index = null): RowClassifier
    {
        return new RowClassifier(self::rule(), $index ?? new CanonicalValueIndex);
    }

    /**
     * A raw mapped row (field => raw cell) with sensible synthetic defaults.
     *
     * @param  array<string, string|null>  $overrides
     * @return array<string, string|null>
     */
    public static function raw(array $overrides = []): array
    {
        return array_replace([
            'institutional_id' => '2020-00001',
            'last_name' => 'Santos',
            'first_name' => 'Ana',
            'middle_name' => 'Reyes',
            'college' => 'CON',
            'course' => 'BSN',
            'year_level' => '2',
            'status' => null,
        ], $overrides);
    }

    /** @return array<string, string|null> a students-table style record */
    public static function student(array $overrides = []): array
    {
        return array_replace([
            'first_name' => 'Ana',
            'middle_name' => 'Reyes',
            'last_name' => 'Santos',
            'current_college' => 'CON',
            'current_course' => 'BSN',
            'current_year_level' => '2nd Year',
            'status' => 'ACTIVE',
        ], $overrides);
    }

    /**
     * CSV text in the historical source shape (No., Code, Last Name, ..., Year; Sex ignored).
     *
     * @param  list<array<string, string|int|null>>  $rows  keys: id,last,first,middle,college,course,year
     */
    public static function csv(array $rows, bool $bom = false): string
    {
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, self::HEADERS, ',', '"', '');
        $n = 0;
        foreach ($rows as $r) {
            $n++;
            fputcsv($handle, [
                $n, $r['id'] ?? '', $r['last'] ?? 'Dela Cruz', $r['first'] ?? 'Juan', $r['middle'] ?? '',
                'F', $r['college'] ?? 'CON', $r['course'] ?? 'BSN', $r['year'] ?? '1',
            ], ',', '"', '');
        }
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return ($bom ? "\xEF\xBB\xBF" : '').$csv;
    }

    public static function tempPath(string $suffix = ''): string
    {
        $path = tempnam(sys_get_temp_dir(), 'imp');
        if ($path === false) {
            throw new RuntimeException('tempnam failed');
        }
        if ($suffix !== '') {
            rename($path, $path.$suffix);
            $path .= $suffix;
        }

        return $path;
    }

    /**
     * Writes a minimal valid .xlsx. Cell values: string => shared string, int/float => numeric
     * cell, ['inline' => 'text'] => inline string, null => empty cell.
     *
     * @param  list<list<string|int|float|array<string, string>|null>>  $rows
     */
    public static function writeXlsx(string $path, array $rows): void
    {
        $shared = [];
        $sheetRows = '';
        foreach ($rows as $r => $cells) {
            $xmlCells = '';
            foreach ($cells as $c => $value) {
                if ($value === null) {
                    continue;
                }
                $ref = self::columnLetters($c).($r + 1);
                if (is_array($value)) {
                    $xmlCells .= '<c r="'.$ref.'" t="inlineStr"><is><t>'.htmlspecialchars($value['inline'], ENT_XML1).'</t></is></c>';
                } elseif (is_string($value)) {
                    $shared[] = $value;
                    $xmlCells .= '<c r="'.$ref.'" t="s"><v>'.(count($shared) - 1).'</v></c>';
                } else {
                    $xmlCells .= '<c r="'.$ref.'"><v>'.$value.'</v></c>';
                }
            }
            $sheetRows .= '<row r="'.($r + 1).'">'.$xmlCells.'</row>';
        }

        $ns = 'xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"';
        $sst = '';
        foreach ($shared as $s) {
            $sst .= '<si><t xml:space="preserve">'.htmlspecialchars($s, ENT_XML1).'</t></si>';
        }

        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?><workbook '.$ns.' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Data" sheetId="1" r:id="rId1"/><sheet name="Other" sheetId="2" r:id="rId2"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="x" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="x" Target="worksheets/sheet2.xml"/></Relationships>');
        $zip->addFromString('xl/sharedStrings.xml', '<?xml version="1.0" encoding="UTF-8"?><sst '.$ns.'>'.$sst.'</sst>');
        $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0" encoding="UTF-8"?><worksheet '.$ns.'><sheetData>'.$sheetRows.'</sheetData></worksheet>');
        $zip->addFromString('xl/worksheets/sheet2.xml', '<?xml version="1.0" encoding="UTF-8"?><worksheet '.$ns.'><sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>SHOULD NOT BE READ</t></is></c></row></sheetData></worksheet>');
        $zip->close();
    }

    public static function columnLetters(int $index): string
    {
        $letters = '';
        $n = $index + 1;
        while ($n > 0) {
            $mod = ($n - 1) % 26;
            $letters = chr(65 + $mod).$letters;
            $n = intdiv($n - 1, 26);
        }

        return $letters;
    }
}
