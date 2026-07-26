<?php

return [
    // Shared bearer token SigNoz's "nexora-erp" webhook channel must send
    // as its Password field. Empty = accept unauthenticated (dev only).
    'webhook_token' => env('ALERTS_WEBHOOK_TOKEN', ''),
];
