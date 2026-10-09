<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../api/admin/_common.php';

$lockFile = dirname(__DIR__) . '/storage/admin-setup-complete';
if (is_file($lockFile)) {
    fwrite(STDERR, "Initial administrator setup is already locked.\n");
    exit(1);
}

$pdo = db();
$email = strtolower(trim((string) ($argv[1] ?? '')));
$name = trim((string) ($argv[2] ?? ''));
$nameLength = function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name);
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190 || $name === '' || $nameLength > 120) {
    fwrite(STDERR, "Usage: php tools/create_admin.php admin@example.com \"Administrator name\"\n");
    exit(1);
}

fwrite(STDOUT, "Choose a unique password (12+ characters, uppercase, lowercase, number, and symbol): ");
$password = rtrim((string) fgets(STDIN), "\r\n");
if (strlen($password) < 12 || !preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password) || !preg_match('/[0-9]/', $password) || !preg_match('/[^A-Za-z0-9]/', $password)) {
    fwrite(STDERR, "Password does not meet the required policy.\n");
    exit(1);
}

try {
    $pdo->beginTransaction();
    $superRole = $pdo->prepare("SELECT id FROM admin_roles WHERE slug = 'super-admin' LIMIT 1 FOR UPDATE");
    $superRole->execute();
    $roleId = (int) $superRole->fetchColumn();
    if ($roleId < 1) {
        $pdo->rollBack();
        fwrite(STDERR, "Admin schema is missing. Apply database.admin.sql first.\n");
        exit(1);
    }
    $existing = $pdo->prepare('SELECT id FROM admin_users WHERE role_id = ? LIMIT 1 FOR UPDATE');
    $existing->execute([$roleId]);
    if ($existing->fetchColumn() !== false) {
        $pdo->rollBack();
        fwrite(STDERR, "A SUPER ADMIN already exists. Initial setup is no longer available.\n");
        exit(1);
    }
    $insert = $pdo->prepare("INSERT INTO admin_users (role_id, name, email, password_hash, status) VALUES (?, ?, ?, ?, 'active')");
    $insert->execute([$roleId, $name, $email, password_hash($password, PASSWORD_DEFAULT)]);
    $userId = (int) $pdo->lastInsertId();
    $pdo->commit();

    if (!is_dir(dirname($lockFile)) && !mkdir(dirname($lockFile), 0700, true) && !is_dir(dirname($lockFile))) {
        throw new RuntimeException('Unable to create private storage directory.');
    }
    if (file_put_contents($lockFile, gmdate('c') . PHP_EOL, LOCK_EX) === false) {
        throw new RuntimeException('Administrator created but setup lock could not be written. Remove this setup script and lock setup at the server.');
    }
    @chmod($lockFile, 0600);
    fwrite(STDOUT, "Initial SUPER ADMIN created (ID {$userId}). Remove tools/create_admin.php from the deployed server.\n");
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Initial admin setup failed: ' . $error->getMessage());
    fwrite(STDERR, "Unable to create the initial administrator. Check the server error log.\n");
    exit(1);
}
