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

    if ($config['local_test']) {
        start_secure_session();
        return $token !== ''
            && isset($_SESSION['local_turnstile_token'])
            && hash_equals((string) $_SESSION['local_turnstile_token'], $token);
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

function local_turnstile_token(): string
{
    start_secure_session();
    if (empty($_SESSION['local_turnstile_token'])) {
        $_SESSION['local_turnstile_token'] = bin2hex(random_bytes(32));
    }
    return (string) $_SESSION['local_turnstile_token'];
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
            'hostnames' => ['example.com'],
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

/**
 * Validate, re-encode and store an uploaded report image.
 *
 * Security:
 *  - real upload check (is_uploaded_file)
 *  - size + dimension limits
 *  - magic-byte type detection (getimagesize) and MIME cross-check (finfo)
 *  - random filename, fixed safe extension; PHP execution is blocked in
 *    the uploads directory through .htaccess
 *  - re-encoding through GD strips EXIF/metadata and any embedded payload
 *
 * Size:
 *  - resized to max 1600px, converted to WebP and the quality is stepped
 *    down until the file fits the target size budget.
 *
 * Returns the relative path (e.g. uploads/reports/abc.webp).
 * Throws RuntimeException with a user-safe message on failure.
 */
function store_compressed_report_image(array $file, string $relativeDir = 'uploads/reports'): string
{
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error !== UPLOAD_ERR_OK) {
        throw new RuntimeException('ছবি upload করা যায়নি।');
    }

    if ((int) ($file['size'] ?? 0) > 5 * 1024 * 1024) {
        throw new RuntimeException('ছবির সর্বোচ্চ size 5MB।');
    }

    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        throw new RuntimeException('শুধু valid image upload করুন।');
    }

    $info = @getimagesize($tmp);
    if ($info === false) {
        throw new RuntimeException('শুধু valid image upload করুন।');
    }

    $width = (int) ($info[0] ?? 0);
    $height = (int) ($info[1] ?? 0);
    if ($width < 1 || $height < 1 || $width > 5000 || $height > 5000 || ($width * $height) > 12000000) {
        throw new RuntimeException('ছবির dimension সর্বোচ্চ 5000x5000 হতে পারে।');
    }

    $allowedMime = [
        IMAGETYPE_JPEG => 'image/jpeg',
        IMAGETYPE_PNG => 'image/png',
        IMAGETYPE_WEBP => 'image/webp',
    ];
    $detectedType = (int) ($info[2] ?? 0);
    if (!isset($allowedMime[$detectedType])) {
        throw new RuntimeException('JPG, PNG অথবা WebP ছবি দিন।');
    }

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
    if (!in_array($mime, array_values($allowedMime), true)) {
        throw new RuntimeException('JPG, PNG অথবা WebP ছবি দিন।');
    }

    $relativeDir = trim($relativeDir, '/');
    $uploadDir = dirname(__DIR__) . '/' . $relativeDir;
    if (!is_dir($uploadDir) && !@mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
        throw new RuntimeException('Upload directory তৈরি করা যায়নি।');
    }

    $base = bin2hex(random_bytes(16));

    /* ---------------- WebP re-encode (preferred) ---------------- */
    if (function_exists('imagecreatefromstring') && function_exists('imagewebp')) {
        $raw = (string) @file_get_contents($tmp);
        $source = $raw !== '' ? @imagecreatefromstring($raw) : false;

        if ($source !== false) {
            $sourceWidth = imagesx($source);
            $sourceHeight = imagesy($source);
            $scale = min(1, 1600 / max(1, max($sourceWidth, $sourceHeight)));
            $newWidth = max(1, (int) round($sourceWidth * $scale));
            $newHeight = max(1, (int) round($sourceHeight * $scale));

            $canvas = @imagecreatetruecolor($newWidth, $newHeight);

            if ($canvas === false) {
                imagedestroy($source);
            } else {
                imagealphablending($canvas, false);
                imagesavealpha($canvas, true);
                $transparent = @imagecolorallocatealpha($canvas, 0, 0, 0, 127);
                if ($transparent !== false) {
                    imagefilledrectangle($canvas, 0, 0, $newWidth, $newHeight, $transparent);
                }
                imagecopyresampled($canvas, $source, 0, 0, 0, 0, $newWidth, $newHeight, $sourceWidth, $sourceHeight);
                imagedestroy($source);

                $target = $uploadDir . '/' . $base . '.webp';
                $quality = 78;
                $saved = @imagewebp($canvas, $target, $quality);

                /* Keep stepping the quality down while the file stays too big. */
                while ($saved && (int) @filesize($target) > 400 * 1024 && $quality > 45) {
                    $quality -= 12;
                    $saved = @imagewebp($canvas, $target, $quality);
                }

                imagedestroy($canvas);

                if ($saved && (int) @filesize($target) > 0) {
                    @chmod($target, 0644);
                    return $relativeDir . '/' . $base . '.webp';
                }

                if (is_file($target)) {
                    @unlink($target);
                }
            }
        }
    }

    /* ---------------- Fallback: store the validated original ---------------- */
    $extension = match ($mime) {
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        default => 'webp',
    };

    $target = $uploadDir . '/' . $base . '.' . $extension;
    if (!move_uploaded_file($tmp, $target)) {
        throw new RuntimeException('ছবি সংরক্ষণ করা যায়নি।');
    }
    @chmod($target, 0644);

    return $relativeDir . '/' . $base . '.' . $extension;
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
