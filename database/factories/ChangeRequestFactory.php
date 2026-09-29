<?php

namespace Database\Factories;

use App\Models\AdminUser;
use App\Models\ChangeRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Test/seed helper only. Real requests are created through
 * ChangeRequestService::create(), which also notifies and audits.
 *
 * @extends Factory<ChangeRequest>
 */
class ChangeRequestFactory extends Factory
{
    protected $model = ChangeRequest::class;

    public function definition(): array
    {
        return [
            'action_type' => 'TEST_ONLY_ACTION',
            'status' => ChangeRequest::STATUS_PENDING,
            'requested_by' => AdminUser::factory(),
        ];
    }
}
