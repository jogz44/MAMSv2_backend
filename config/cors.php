<?php

$allowedOrigins = array_filter(array_map('trim', explode(',', (string) env(
    'CORS_ALLOWED_ORIGINS',
    'http://localhost:9000,http://127.0.0.1:9000,http://192.168.150.188:9000'
))));

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

<<<<<<< HEAD
    'allowed_origins' => [
        'http://localhost:9000',
        'http://127.0.0.1:9000',
    ],
=======
    'allowed_origins' => $allowedOrigins,
>>>>>>> d8707714d1c8057a47abf27f02764f5ff675a24d

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,
];
