<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
request_method('GET');

try {
    $pdo = db();
    $pdo->query('SELECT 1');
    json_response([
        'ok' => true,
        'database' => 'connected',
        'php' => PHP_VERSION,
    ]);
} catch (Throwable $e) {
    error_log('SafeMap health error: ' . $e->getMessage());
    json_response([
        'ok' => false,
        'database' => 'connection_failed',
    ], 500);
}
