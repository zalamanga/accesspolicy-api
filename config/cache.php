<?php
return [
    'default' => env('CACHE_STORE', 'database'),
    'stores' => ['database' => ['driver' => 'database', 'table' => 'cache', 'lock_table' => 'cache_locks', 'connection' => null, 'lock_connection' => null]],
    'prefix' => 'accesspolicy_',
];
