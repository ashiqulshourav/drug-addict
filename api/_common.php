<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, max-age=0');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');

require_once dirname(__DIR__) . '/config/database.php';

function env_value(string $key, ?string $default = null): ?string
{
    static $values = null;
    if ($values === null) {
        $values = database_env();
    }

    $environmentValue = getenv($key);
    return $environmentValue === false ? ($values[$key] ?? $default) : $environmentValue;
}

function start_secure_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    ini_set('session.use_strict_mode', '1');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function owns_report(int $reportId): bool
{
    start_secure_session();
    return !empty($_SESSION['owned_reports'][(string) $reportId]);
}

function csrf_token(): string
{
    start_secure_session();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return (string) $_SESSION['csrf_token'];
}

function verify_csrf(string $token): bool
{
    start_secure_session();
    return $token !== '' && isset($_SESSION['csrf_token']) && hash_equals((string) $_SESSION['csrf_token'], $token);
}

function verify_turnstile(string $token, string $expectedAction): bool
{
    $config = turnstile_config();
    if (!$config['enabled']) {
        return true;
    }

    $secret = $config['secret'];
    $hostnames = $config['hostnames'];
    if ($secret === '' || $token === '' || strlen($token) > 2048 || $hostnames === []) {
        return false;
    }

    $payload = http_build_query([
        'secret' => $secret,
        'response' => $token,
        'remoteip' => client_ip(),
    ]);
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => $payload,
            'timeout' => 5,
        ],
    ]);
    $response = @file_get_contents('https://challenges.cloudflare.com/turnstile/v0/siteverify', false, $context);
    $result = json_decode($response ?: '', true);

    if (!is_array($result) || empty($result['success'])) {
        return false;
    }

    return ($result['action'] ?? '') === $expectedAction
        && in_array((string) ($result['hostname'] ?? ''), $hostnames, true);
}

function turnstile_config(): array
{
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    $host = preg_replace('/:\d+$/', '', $host) ?: $host;
    $isLocal = in_array($host, ['localhost', '127.0.0.1', '0.0.0.0'], true);

    if ($isLocal && filter_var(env_value('TURNSTILE_LOCAL_ENABLED', 'false'), FILTER_VALIDATE_BOOL)) {
        return [
            'enabled' => true,
            'local_test' => true,
            'site_key' => (string) env_value('TURNSTILE_LOCAL_SITE_KEY', ''),
            'secret' => trim((string) env_value('TURNSTILE_LOCAL_SECRET_KEY', '')),
            'hostnames' => [$host],
        ];
    }

    return [
        'enabled' => filter_var(env_value('TURNSTILE_ENABLED', 'false'), FILTER_VALIDATE_BOOL),
        'local_test' => false,
        'site_key' => (string) env_value('TURNSTILE_SITE_KEY', ''),
        'secret' => trim((string) env_value('TURNSTILE_SECRET_KEY', '')),
        'hostnames' => array_values(array_filter(array_map('trim', explode(',', (string) env_value('TURNSTILE_HOSTNAMES', ''))))),
    ];
}

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
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
}

function ip_hash(): string
{
    return hash('sha256', client_ip() . '|' . ($_SERVER['HTTP_USER_AGENT'] ?? ''));
}
