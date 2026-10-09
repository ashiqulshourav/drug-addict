<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
admin_require_method('GET', 'POST');
$actor = admin_current_user();
if ($method === 'GET') {
    admin_success(['user' => admin_public_user($actor), 'permissions' => $actor['permissions']]);
}
if ($method !== 'POST') admin_error('Method not allowed.', 405);
admin_require_csrf();
$body = admin_body();
if (($body['action'] ?? '') !== 'change_password') admin_error('Validation failed.', 422, ['action' => 'Unsupported profile action.']);
admin_rate_limit('password-change', 5, 3600);
$current = (string) ($body['current_password'] ?? '');
$new = (string) ($body['new_password'] ?? '');
$confirm = (string) ($body['confirm_password'] ?? '');
if ($new !== $confirm) admin_error('Validation failed.', 422, ['confirm_password' => 'Passwords do not match.']);
if (strlen($new) < 12 || strlen($new) > 1024 || !preg_match('/[A-Z]/', $new) || !preg_match('/[a-z]/', $new) || !preg_match('/[0-9]/', $new) || !preg_match('/[^A-Za-z0-9]/', $new)) admin_error('Validation failed.', 422, ['new_password' => 'Use 12 to 1024 characters with uppercase, lowercase, number, and special character.']);
$pdo = db();
$check = $pdo->prepare('SELECT password_hash FROM admin_users WHERE id = ?');
$check->execute([(int) $actor['id']]);
$hash = $check->fetchColumn();
if (!is_string($hash) || !password_verify($current, $hash)) admin_error('Current password is incorrect.', 422, ['current_password' => 'Current password is incorrect.']);
$pdo->beginTransaction();
try {
    $lock = $pdo->prepare('SELECT id FROM admin_users WHERE id = ? AND status = \'active\' FOR UPDATE');
    $lock->execute([(int) $actor['id']]);
    if (!$lock->fetchColumn()) admin_error('Session expired. Please sign in again.', 401);
    $pdo->prepare('UPDATE admin_users SET password_hash = ? WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), (int) $actor['id']]);
    admin_audit((int) $actor['id'], 'admin_user.password_change', 'admin_user', (int) $actor['id'], 'Administrator changed their password.');
    $pdo->commit();
    session_regenerate_id(true);
    $_SESSION['admin_csrf_token'] = bin2hex(random_bytes(32));
    admin_success(['csrf_token' => $_SESSION['admin_csrf_token']]);
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Madok admin password change failed: ' . $error->getMessage());
    admin_error('Unable to change password.', 500);
}
