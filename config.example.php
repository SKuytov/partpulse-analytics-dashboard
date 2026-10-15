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
    'max_upload_bytes' => 64 * 1024 * 1024,
    'admin' => [
        'username' => 'admin',
        // Generate with: php -r "echo password_hash('YOUR_STRONG_PASSWORD', PASSWORD_DEFAULT), PHP_EOL;"
        'password_hash' => '$2y$12$REPLACE_WITH_A_REAL_PASSWORD_HASH',
    ],

    // ---- Order-level history (version 2) --------------------------------

    // Allow uploading the raw PartPulse .sql export directly. The server parses it
    // without executing anything, keeps only reporting fields, archives a sanitized
    // ZIP and deletes the raw dump. Set to false to accept only normalized ZIPs.
    'allow_sql_upload' => true,

    // Time zone of the PartPulse database server (its TIMESTAMP values are UTC).
    'source_timezone' => 'UTC',

    // Optional: protect the COO link. Leave empty for an open link (version 1
    // behaviour). If set, share https://your-domain/partpulse/?key=THIS_VALUE once;
    // the browser remembers it for a year.
    'public_access_key' => '',

    // Merge different spellings of the same person in the activity log.
    'actor_aliases' => [
        'admin' => 'Admin Salim Kuytov',
    ],

    // Days an order may stay in each status before it appears in SLA watch.
    'sla_thresholds' => [
        'New' => 2,
        'Pending' => 3,
        'Quote Requested' => 5,
        'Quote Received' => 3,
        'Quote Under Approval' => 2,
        'Approved' => 3,
        'Ordered' => 14,
        'In Transit' => 7,
    ],

    // Maximum rows returned in the "What changed" log for one period.
    'change_log_limit' => 3000,
];
