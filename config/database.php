<?php
return [
    'default' => env('DB_CONNECTION', 'pgsql'),
    'connections' => ['pgsql' => [
        'driver' => 'pgsql', 'host' => env('DB_HOST', 'postgres'), 'port' => env('DB_PORT', '5432'),
        'database' => env('DB_DATABASE', 'accesspolicy'), 'username' => env('DB_USERNAME', 'accesspolicy'),
        'password' => env('DB_PASSWORD'), 'charset' => 'utf8', 'prefix' => '',
        'search_path' => 'public', 'sslmode' => env('DB_SSLMODE', 'prefer'),
    ]],
    'migrations' => ['table' => 'migrations', 'update_date_on_publish' => true],
];
