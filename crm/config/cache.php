<?php

return [
    'default' => env('CACHE_STORE', env('CACHE_DRIVER', 'database')),

    'stores' => [
        'database' => [
            'driver' => 'database',
            'table' => 'cache',
            'connection' => null,
            'lock_connection' => null,
            'lock_table' => 'cache_locks',
        ],

        'file' => [
            'driver' => 'file',
            'path' => storage_path('framework/cache/data'),
            'lock_path' => storage_path('framework/cache/data'),
        ],

        'array' => [
            'driver' => 'array',
            'serialize' => false,
        ],
    ],

    'prefix' => env('CACHE_PREFIX', 'tsf_crm_cache_'),
];
