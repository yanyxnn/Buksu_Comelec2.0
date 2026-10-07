<?php

namespace Tests\Support;

use App\Models\AdminUser;
use App\Models\ImportBatch;
use App\Models\Student;
use App\Services\StudentImport\ImportBatchService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Feature-test helpers for the Data Center import. All data is synthetic. */
final class ImportScenario
{
    public static function service(): ImportBatchService
    {
        return app(ImportBatchService::class);
    }

    public static function admin(): AdminUser
    {
        return makeAdminRoster()->first();
    }

    /** @param array<string, mixed> $overrides */
    public static function student(string $id, array $overrides = []): Student
    {
        return Student::factory()->create(array_replace([
            'institutional_id' => $id,
            'institutional_email' => 'student'.strtolower(preg_replace('/\W/', '', $id)).'@'.studentDomain(),
            'current_college' => 'CON',
            'current_course' => 'BSN',
            'current_year_level' => '2nd Year',
        ], $overrides));
    }

    public static function stage(string $contents, string $name = 'roster.csv'): ImportBatch
    {
        $path = ImportTestKit::tempPath('.'.pathinfo($name, PATHINFO_EXTENSION));
        file_put_contents($path, $contents);

        try {
            return self::service()->stage($path, $name, self::admin()->id, '2026-2027', '1st');
        } finally {
            @unlink($path);
        }
    }

    public static function preview(string $contents, string $name = 'roster.csv'): ImportBatch
    {
        return self::service()->validate(self::stage($contents, $name));
    }

    /** stage -> validate -> confirm -> process all chunks -> complete (synchronously, no queue). */
    public static function complete(string $contents, string $name = 'roster.csv'): ImportBatch
    {
        $batch = self::preview($contents, $name);
        self::service()->confirm($batch);
        self::service()->beginProcessing($batch);

        return self::service()->runProcessing($batch);
    }

    /** @return Collection<string, object> staged rows keyed by institutional id (first occurrence wins per key; use rows() for all) */
    public static function rowsById(ImportBatch $batch): Collection
    {
        return DB::table('import_batch_rows')->where('import_batch_id', $batch->id)->orderBy('source_row_number')->get()->keyBy('institutional_id');
    }

    /** @return Collection<int, object> */
    public static function rows(ImportBatch $batch): Collection
    {
        return DB::table('import_batch_rows')->where('import_batch_id', $batch->id)->orderBy('source_row_number')->get();
    }

    /** @return list<string> */
    public static function issueCodes(object $row): array
    {
        return array_column(json_decode((string) $row->issues_json, true) ?? [], 'code');
    }

    public static function enrollmentCount(?int $batchId = null): int
    {
        $query = DB::table('student_enrollments');

        return (int) ($batchId === null ? $query->count() : $query->where('import_batch_id', $batchId)->count());
    }

    /** Every table an import must NOT touch, as a comparable fingerprint. */
    public static function adminFingerprint(): string
    {
        return json_encode(DB::table('admin_users')->orderBy('id')->get()->all());
    }
}
