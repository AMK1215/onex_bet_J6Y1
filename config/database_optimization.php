<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Database Performance Optimization Settings
    |--------------------------------------------------------------------------
    |
    | These settings help optimize database performance for high-frequency
    | operations like game transactions and wallet operations.
    |
    */

    'wallet_optimization' => [
        // Enable query caching for wallet operations
        'enable_query_cache' => env('WALLET_QUERY_CACHE', true),
        
        // Cache TTL for wallet balances (in seconds)
        'balance_cache_ttl' => env('WALLET_BALANCE_CACHE_TTL', 300),
        
        // Batch size for bulk operations
        'batch_size' => env('WALLET_BATCH_SIZE', 100),
        
        // Enable connection pooling
        'connection_pooling' => env('WALLET_CONNECTION_POOLING', true),
    ],

    'transaction_optimization' => [
        // Use raw queries for high-frequency operations
        'use_raw_queries' => env('TRANSACTION_RAW_QUERIES', true),
        
        // Enable transaction batching
        'enable_batching' => env('TRANSACTION_BATCHING', true),
        
        // Batch size for transactions
        'batch_size' => env('TRANSACTION_BATCH_SIZE', 50),
        
        // Enable prepared statements
        'use_prepared_statements' => env('TRANSACTION_PREPARED_STATEMENTS', true),
    ],

    'cache_settings' => [
        // Cache driver for wallet operations
        'driver' => env('WALLET_CACHE_DRIVER', 'redis'),
        
        // Cache prefix
        'prefix' => env('WALLET_CACHE_PREFIX', 'wallet_'),
        
        // Enable cache compression
        'compress' => env('WALLET_CACHE_COMPRESS', true),
    ],
];
