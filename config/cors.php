<?php

return [

    'paths' => ['api/*', 'oauth/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        'https://nexora.m-strategicinvestments.com',
        'https://nexoraerp.m-strategicinvestments.com',
        'http://localhost:5173',
        'https://nexora-frontend-iota.vercel.app',
    ],

    // Vercel mints a new *.vercel.app URL for every preview deployment of
    // this project — match them all rather than editing this file per deploy.
    'allowed_origins_patterns' => [
        '#^https://nexora-frontend-[a-z0-9-]+\.vercel\.app$#',
    ],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 86400,

    'supports_credentials' => false,

];
