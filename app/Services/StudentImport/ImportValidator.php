<?php

namespace App\Services\StudentImport;

use App\Models\ImportBatch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Validation/classification pass: turns the stored upload into classified `import_batch_rows`
 * for the preview/review step. It writes ONLY staging data (import_batch_rows and the batch
 * counters). It never touches students or student_enrollments, never decides eligibility and
 * never reads or writes administrator data.
 *
 * Two streaming passes over the file keep memory bounded by the number of DISTINCT ids, not by
 * row content:
 *   pass 1 - duplicate groups (+ whether duplicates conflict) and case-variant statistics;
 *   pass 2 - classify each row against the master data and stage it in chunks.
 * The whole run is one transaction: a failure leaves the batch with no half-staged rows.
 */
class ImportValidator
{
    private readonly HeaderMapper $mapper;

    private readonly RowNormalizer $normalizer;

    private readonly PlacementRule $placement;

    public function __construct()
    {
        $config = config('comelec.import');

        $this->mapper = new HeaderMapper($config['header_aliases']);
        $this->normalizer = new RowNormalizer($config['year_levels']);
        $this->placement = new PlacementRule((string) array_key_first($config['year_levels']));
    }

    public static function storagePath(object $batch): string
    {
        $extension = strtolower(pathinfo((string) $batch->source_filename, PATHINFO_EXTENSION));

        return trim((string) config('comelec.import.directory'), '/').'/'.$batch->id.'.'.$extension;
    }

    /**
     * @throws SourceFileException
     */
    public function run(ImportBatch $batch): void
    {
        $extension = strtolower(pathinfo((string) $batch->source_filename, PATHINFO_EXTENSION));
        $reader = RowReaderFactory::forExtension($extension, (int) config('comelec.import.max_uncompressed_part_bytes'));

        [$localPath, $cleanup] = $this->localCopy($batch);

        try {
            DB::transaction(function () use ($batch, $reader, $localPath) {
                DB::table('import_batch_rows')->where('import_batch_id', $batch->id)->delete();

                $stats = $this->firstPass($reader, $localPath);
                $this->secondPass($batch, $reader, $localPath, $stats);
                $this->writeCounters($batch->id, $stats['total']);
            });
        } finally {
            $cleanup();
        }
    }

    /**
     * @return array{0: string, 1: callable}
     */
    private function localCopy(ImportBatch $batch): array
    {
        $disk = Storage::disk((string) config('comelec.import.disk'));
        $relative = self::storagePath($batch);

        if (! $disk->exists($relative)) {
            throw new SourceFileException(SourceFileException::FILE_UNREADABLE);
        }

        $tmp = tempnam(sys_get_temp_dir(), 'imp');
        $in = $disk->readStream($relative);
        $out = fopen($tmp, 'wb');

        if ($in === null || $in === false || $out === false) {
            throw new SourceFileException(SourceFileException::FILE_UNREADABLE);
        }

        stream_copy_to_stream($in, $out);
        fclose($out);
        fclose($in);

        return [$tmp, static function () use ($tmp) {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }];
    }

    /**
     * @return array{total: int, groups: array<string, array{first: int, count: int, sig: string, conflict: bool}>, index: CanonicalValueIndex, headers: list<string|null>, map: array<int, string>}
     */
    private function firstPass(RowReader $reader, string $path): array
    {
        $index = new CanonicalValueIndex;
        $index->addKnownValues('college', DB::table('students')->distinct()->pluck('current_college')->all());
        $index->addKnownValues('course', DB::table('students')->distinct()->pluck('current_course')->all());

        $groups = [];
        $total = 0;
        $map = null;
        $headers = [];

        foreach ($reader->rows($path) as $rowNumber => $cells) {
            if ($map === null) {
                $headers = $cells;
                $map = $this->mapper->map($cells);

                continue;
            }

            $values = $this->normalizer->normalize($this->mapper->extract($cells, $map))['values'];
            $total++;

            $index->addFileValue('college', $values['college']);
            $index->addFileValue('course', $values['course']);

            if ($values['institutional_id'] !== null) {
                $key = CanonicalValueIndex::fold($values['institutional_id']);
                $sig = $this->signature($values);

                if (! isset($groups[$key])) {
                    $groups[$key] = ['first' => $rowNumber, 'count' => 1, 'sig' => $sig, 'conflict' => false];
                } else {
                    $groups[$key]['count']++;
                    $groups[$key]['conflict'] = $groups[$key]['conflict'] || $groups[$key]['sig'] !== $sig;
                }
            }
        }

        if ($map === null || $total === 0) {
            throw new SourceFileException(SourceFileException::EMPTY_FILE);
        }

        return ['total' => $total, 'groups' => $groups, 'index' => $index, 'headers' => $headers, 'map' => $map];
    }

    /** @param array<string, string|null> $values */
    private function signature(array $values): string
    {
        return sha1(json_encode([
            $values['last_name'], $values['first_name'], $values['middle_name'],
            $values['college'], $values['course'], $values['year_level'] ?? $values['year_level_source'], $values['status'],
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<string, mixed>  $stats
     */
    private function secondPass(ImportBatch $batch, RowReader $reader, string $path, array $stats): void
    {
        $classifier = new RowClassifier($this->placement, $stats['index']);
        $chunkSize = max(1, min((int) config('comelec.import.chunk_size', 500), 1000));

        $buffer = [];
        $first = true;

        foreach ($reader->rows($path) as $rowNumber => $cells) {
            if ($first) { // header row
                $first = false;

                continue;
            }

            $buffer[$rowNumber] = $cells;

            if (count($buffer) >= $chunkSize) {
                $this->stageChunk($batch, $classifier, $stats, $buffer);
                $buffer = [];
            }
        }

        if ($buffer !== []) {
            $this->stageChunk($batch, $classifier, $stats, $buffer);
        }
    }

    /**
     * @param  array<string, mixed>  $stats
     * @param  array<int, list<string|null>>  $buffer  source row number => cells
     */
    private function stageChunk(ImportBatch $batch, RowClassifier $classifier, array $stats, array $buffer): void
    {
        $normalized = [];
        $ids = [];

        foreach ($buffer as $rowNumber => $cells) {
            $result = $this->normalizer->normalize($this->mapper->extract($cells, $stats['map']));
            $normalized[$rowNumber] = $result;

            if ($result['values']['institutional_id'] !== null) {
                $ids[$result['values']['institutional_id']] = true;
            }
        }

        $students = [];
        if ($ids !== []) {
            foreach (DB::table('students')->whereIn('institutional_id', array_keys($ids))
                ->get(['id', 'institutional_id', 'first_name', 'middle_name', 'last_name', 'current_college', 'current_course', 'current_year_level', 'status']) as $student) {
                $students[CanonicalValueIndex::fold($student->institutional_id)] = (array) $student;
            }
        }

        $now = now();
        $insert = [];

        foreach ($normalized as $rowNumber => $result) {
            $id = $result['values']['institutional_id'];
            $student = $id !== null ? ($students[CanonicalValueIndex::fold($id)] ?? null) : null;
            $group = $id !== null ? $stats['groups'][CanonicalValueIndex::fold($id)] : null;

            $classified = $classifier->classify($result, $student, [
                'group_size' => $group['count'] ?? 1,
                'is_first' => $group === null || $group['first'] === $rowNumber,
                'conflict' => $group['conflict'] ?? false,
            ]);

            $insert[] = [
                'import_batch_id' => $batch->id,
                'source_row_number' => $rowNumber,
                'institutional_id' => $id ?? '',
                'classification' => $classified['classification'],
                'raw_row_json' => json_encode($this->rawRow($stats['headers'], $buffer[$rowNumber]), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'normalized_json' => json_encode(['values' => $result['values'], 'preview' => $classified['preview']], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'issues_json' => $classified['issues'] === [] ? null : json_encode($classified['issues'], JSON_THROW_ON_ERROR),
                'resolved_student_id' => $student['id'] ?? null,
                'processed_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('import_batch_rows')->insert($insert);
    }

    /**
     * The untouched source row, keyed by its own header text (kept as received, including
     * columns the domain ignores). It stays in staging only.
     *
     * @param  list<string|null>  $headers
     * @param  list<string|null>  $cells
     * @return array<string, string|null>
     */
    private function rawRow(array $headers, array $cells): array
    {
        $raw = [];
        $count = max(count($headers), count($cells));

        for ($i = 0; $i < $count; $i++) {
            $key = trim((string) ($headers[$i] ?? '')) !== '' ? (string) $headers[$i] : 'column_'.($i + 1);
            if (array_key_exists($key, $raw)) {
                $key .= '#'.$i;
            }
            $raw[$key] = $cells[$i] ?? null;
        }

        return $raw;
    }

    private function writeCounters(int $batchId, int $total): void
    {
        $applicable = (int) DB::table('import_batch_rows')
            ->where('import_batch_id', $batchId)
            ->whereIn('classification', [RowClassifier::UPDATED, RowClassifier::NEW])
            ->count();

        DB::table('import_batches')->where('id', $batchId)->update([
            'records_received' => $total,
            'records_created' => 0,
            'records_updated' => 0,
            'records_errored' => $total - $applicable,
            'updated_at' => now(),
        ]);
    }
}
