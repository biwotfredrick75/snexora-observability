<?php

return [
    'gateway_url' => env('ETIMS_GATEWAY_URL', 'http://localhost:8080'),
    'api_key'     => env('ETIMS_API_KEY', 'your-secret-key-here'),
    'tin'         => env('ETIMS_TIN', ''),
    'bhf_id'      => env('ETIMS_BHF_ID', '00'),
    'dvc_srl_no'  => env('ETIMS_DVC_SRL_NO', ''),
    'env'         => env('ETIMS_ENV', 'test'),
];
