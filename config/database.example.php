<?php
declare(strict_types=1);

/* Copy this file to config/database.php. Keep the real .env outside the document root. */
function database_env(): array
{
    static $values = null;
    if (is_array($values)) {
        return $values;
    }

    $path = getenv('ENV_FILE') ?: dirname(__DIR__) . '/.env';
    $values = [];
    if (!is_file($path) || !is_readable($path)) {
        return $values;
    }

    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if (strlen($value) >= 2 && (($value[0] === '"' && str_ends_with($value, '"')) || ($value[0] === "'" && str_ends_with($value, "'")))) {
            $value = substr($value, 1, -1);
        }
        if ($key !== '') {
            $values[$key] = $value;
        }
    }
    return $values;
}

$databaseValues = database_env();
define('DB_HOST', getenv('DB_HOST') ?: ($databaseValues['DB_HOST'] ?? 'localhost'));
define('DB_NAME', getenv('DB_NAME') ?: ($databaseValues['DB_NAME'] ?? 'drug'));
define('DB_USER', getenv('DB_USER') ?: ($databaseValues['DB_USER'] ?? ''));
define('DB_PASS', getenv('DB_PASS') ?: ($databaseValues['DB_PASS'] ?? ''));

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    if (DB_USER === '' || DB_PASS === '' || str_starts_with(DB_USER, 'replace_') || str_starts_with(DB_PASS, 'replace_')) {
        throw new RuntimeException('Database credentials are not configured.');
    }

    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
    return $pdo;
}
