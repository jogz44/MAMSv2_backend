<?php

use Laravel\Sanctum\Sanctum;

$configuredStatefulDomains = trim((string) env('SANCTUM_STATEFUL_DOMAINS', ''));
$defaultStatefulDomains = sprintf(
    '%s%s',
    'localhost,localhost:9000,localhost:3000,127.0.0.1,127.0.0.1:9000,127.0.0.1:8000,::1,192.168.150.188,192.168.150.188:9000',
    env('APP_URL') ? ','.parse_url(env('APP_URL'), PHP_URL_HOST) : ''
);
$statefulDomains = $configuredStatefulDomains !== '' ? $configuredStatefulDomains : $defaultStatefulDomains;

return [
    'stateful' => array_values(array_filter(array_map('trim', explode(',', $statefulDomains)))),

    'guard' => ['web'],

    'expiration' => null,

    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', ''),

    'middleware' => [
        'authenticate_session' => Laravel\Sanctum\Http\Middleware\AuthenticateSession::class,
        'encrypt_cookies' => Illuminate\Cookie\Middleware\EncryptCookies::class,
        'validate_csrf_token' => Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
    ],
];
