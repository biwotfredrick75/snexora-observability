<?php

return [
    // Redpanda REST Proxy URL (no PHP extension required)
    'rest_proxy_url' => env('KAFKA_REST_PROXY_URL', 'http://localhost:18082'),

    // Shared secret for HMAC verification of Go → Laravel callbacks
    'callback_secret' => env('KAFKA_CALLBACK_SECRET', 'change-me-in-production'),
];
