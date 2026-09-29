<?php

namespace Database\Factories;

use App\Models\AdminUser;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AdminUser> */
class AdminUserFactory extends Factory
{
    protected $model = AdminUser::class;

    public function definition(): array
    {
        return [
            'google_subject' => (string) fake()->unique()->numerify('1####################'),
            'display_name' => fake()->name(),
            'role' => AdminUser::ROLE,
        ];
    }
}
