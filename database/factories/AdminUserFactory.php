<?php

namespace Database\Factories;

use App\Models\AdminUser;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Default state: a PRE-AUTHORIZED administrator who has not logged in yet
 * (authorized_email known, google_subject NULL). Use linked() for one whose
 * stable Google subject is already bound.
 *
 * @extends Factory<AdminUser>
 */
class AdminUserFactory extends Factory
{
    protected $model = AdminUser::class;

    public function definition(): array
    {
        return [
            'authorized_email' => fake()->unique()->numerify('admin####').'@admins.example.test',
            'google_subject' => null,
            'display_name' => fake()->name(),
            'role' => AdminUser::ROLE,
        ];
    }

    /** Already bound to a Google subject (has completed first login). */
    public function linked(?string $sub = null): static
    {
        return $this->state(fn () => ['google_subject' => $sub ?? (string) fake()->unique()->numerify('1####################')]);
    }
}
