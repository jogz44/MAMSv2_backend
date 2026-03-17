<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],  // Allow all paths

    'allowed_methods' => ['*'],

    'allowed_origins' => ['http://localhost:9000'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,
];
