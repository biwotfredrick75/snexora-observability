<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Blockchain micro-service connection
    |--------------------------------------------------------------------------
    | The Go blockchain service runs independently on port 9000 (default).
    | Set BLOCKCHAIN_URL and BLOCKCHAIN_API_KEY in .env to override.
    */
    'url'             => env('BLOCKCHAIN_URL', 'http://localhost:9000'),
    'api_key'         => env('BLOCKCHAIN_API_KEY', 'change-me-in-production'),
    'timeout_seconds' => (int) env('BLOCKCHAIN_TIMEOUT', 10),
];
