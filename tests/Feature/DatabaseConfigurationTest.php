<?php

test('.env.example declares MySQL as the intended default database connection', function () {
    $envExamplePath = base_path('.env.example');

    expect(file_exists($envExamplePath))->toBeTrue(
        '.env.example is missing from the project root.'
    );

    $contents = file_get_contents($envExamplePath);

    expect($contents)->toContain('DB_CONNECTION=mysql');
});

test('the mysql connection is fully defined in config/database.php', function () {
    $mysql = config('database.connections.mysql');

    expect($mysql)->not->toBeNull();
    expect($mysql['driver'])->toBe('mysql');
    expect($mysql['charset'])->toBe('utf8mb4');
    expect($mysql['collation'])->toBe('utf8mb4_unicode_ci');
});