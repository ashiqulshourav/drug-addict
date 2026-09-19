<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
request_method('GET');
start_secure_session();

json_response([
    'ok' => true,
    'csrf_token' => csrf_token(),
    'turnstile' => [
        'enabled' => turnstile_config()['enabled'],
        'site_key' => turnstile_config()['site_key'],
    ],
]);