<?php

use App\Models\User;

test('appearance settings page is displayed', function () {
    $this->actingAs(User::factory()->create());

    $this->get('/settings/appearance')->assertOk();
});