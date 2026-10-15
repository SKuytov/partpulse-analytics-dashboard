<?php
declare(strict_types=1);

return [
    'app_name' => 'PartPulse Intelligence',
    'timezone' => 'Europe/Sofia',
    'session_name' => 'PARTPULSE_ADMIN',
    'db' => [
        'host' => 'localhost',
        'port' => 3306,
        'name' => 'cpaneluser_partpulse',
        'user' => 'cpaneluser_partpulse',
        'pass' => 'CHANGE_ME',
        'charset' => 'utf8mb4',
    ],
    'upload_dir' => __DIR__ . '/uploads',
    'max_upload_bytes' => 25 * 1024 * 1024,
    'admin' => [
        'username' => 'admin',
        // Generate with: php -r "echo password_hash('YOUR_STRONG_PASSWORD', PASSWORD_DEFAULT), PHP_EOL;"
        'password_hash' => '$2y$12$REPLACE_WITH_A_REAL_PASSWORD_HASH',
    ],
];
