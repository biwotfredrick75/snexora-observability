<?php

return [
    'gateway_url' => env('SACCO_GATEWAY_URL', 'http://localhost:8090'),
    'api_key'     => env('SACCO_API_KEY', 'dev-key-change-me'),
    'org_slug'    => env('SACCO_ORG_SLUG', ''),
];
