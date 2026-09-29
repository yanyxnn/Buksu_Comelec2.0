<?php

namespace Database\Factories;

use App\Models\AdminUser;
use App\Models\Student;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

/** @extends Factory<Student> */
class StudentFactory extends Factory
{
    protected $model = Student::class;

    public function definition(): array
    {
        $id = fake()->unique()->numerify('##-#####');

        return [
            'institutional_id' => $id,
            'institutional_email' => strtolower(str_replace('-', '', $id)).'@'.config('comelec.student_email_domain', 'students.example.test'),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'current_college' => 'College of Testing',
            'current_course' => 'BS Testing',
            'current_year_level' => '2nd Year',
            'status' => 'ACTIVE',
            'google_subject' => null,
            // Students normally arrive through the official Data Center import.
            'last_import_batch_id' => fn () => self::importBatchId(),
        ];
    }

    /** A student row that did NOT come from the Data Center import (must not be able to log in). */
    public function unimported(): static
    {
        return $this->state(fn () => ['last_import_batch_id' => null]);
    }

    /** Test helper: one shared import batch (created on demand, uploaded by an existing admin if any). */
    public static function importBatchId(): int
    {
        return DB::table('import_batches')->value('id') ?? DB::table('import_batches')->insertGetId([
            'source_filename' => 'test-import.csv',
            'academic_year' => '2026-2027',
            'semester' => '1st',
            'uploaded_by' => AdminUser::query()->value('id') ?? AdminUser::factory()->create()->id,
            'status' => 'COMPLETED',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function linked(?string $sub = null): static
    {
        return $this->state(fn () => ['google_subject' => $sub ?? (string) fake()->unique()->numerify('2####################')]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['status' => 'INACTIVE']);
    }
}
