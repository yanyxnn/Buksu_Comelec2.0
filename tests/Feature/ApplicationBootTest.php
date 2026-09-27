<?php

test('the application boots and responds successfully to the home page', function () {
    $response = $this->get('/');

    $response->assertStatus(200);
});