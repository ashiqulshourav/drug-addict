<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, max-age=0');

require_once dirname(__DIR__) . '/config/database.php';

function enforce_request_security(): void
{
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $fetchSite = strtolower((string) ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? ''));
    if ($fetchSite !== '' && !in_array($fetchSite, ['same-origin', 'same-site', 'none'], true)) {
        json_response(['ok' => false, 'message' => 'Cross-origin request blocked.'], 403);
    }

    if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        $host = $_SERVER['HTTP_HOST'] ?? '';
        if ($origin !== '' && parse_url($origin, PHP_URL_HOST) !== $host) {
            json_response(['ok' => false, 'message' => 'Cross-origin request blocked.'], 403);
        }
    }
}

function rate_limit(string $key, int $maxRequests, int $windowSeconds, bool $failClosed = false): bool
{
    $directory = dirname(__DIR__) . '/storage/rate-limit';
    if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
        return !$failClosed;
    }
    $file = $directory . '/' . hash('sha256', $key) . '.json';
    $handle = @fopen($file, 'c+');
    if ($handle === false || !flock($handle, LOCK_EX)) {
        if (is_resource($handle)) @fclose($handle);
        return !$failClosed;
    }
    $raw = stream_get_contents($handle);
    $timestamps = json_decode($raw ?: '', true);
    $now = time();
    $timestamps = is_array($timestamps) ? $timestamps : [];
    $timestamps = array_values(array_filter($timestamps, static fn($time): bool => is_numeric($time) && (int) $time > $now - $windowSeconds));
    $allowed = count($timestamps) < $maxRequests;
    if ($allowed) $timestamps[] = $now;
    ftruncate($handle, 0);
    rewind($handle);
    fwrite($handle, json_encode($timestamps));
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);
    return $allowed;
}

function enforce_api_rate_limit(): void
{
    $ip = client_ip();
    if (!rate_limit('global:' . $ip, 120, 60)) {
        json_response(['ok' => false, 'message' => 'অনেকগুলো অনুরোধ হয়েছে। কিছুক্ষণ পরে আবার চেষ্টা করুন।'], 429);
    }
}

enforce_request_security();
enforce_api_rate_limit();

function json_response(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function request_method(string $method): void
{
    if ($_SERVER['REQUEST_METHOD'] !== $method) {
        header('Allow: ' . $method);
        json_response(['ok' => false, 'message' => 'Method not allowed'], 405);
    }
}

function clamp_float(mixed $value, float $min, float $max): float
{
    if (!is_numeric($value)) {
        json_response(['ok' => false, 'message' => 'Invalid coordinate'], 422);
    }
    $v = (float)$value;
    if ($v < $min || $v > $max) {
        json_response(['ok' => false, 'message' => 'Invalid coordinate'], 422);
    }
    return $v;
}

function clean_text(mixed $value, int $max): string
{
    $value = trim((string)$value);
    return function_exists('mb_substr') ? mb_substr($value, 0, $max, 'UTF-8') : substr($value, 0, $max);
}

function client_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function ip_hash(): string
{
    return hash('sha256', client_ip() . '|' . ($_SERVER['HTTP_USER_AGENT'] ?? ''));
}
