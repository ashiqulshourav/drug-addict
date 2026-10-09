<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/_common.php';
admin_require_method('POST');
start_secure_session();
admin_require_csrf();

$body = admin_body();
$email = strtolower(trim((string) ($body['email'] ?? '')));
$password = (string) ($body['password'] ?? '');
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190 || $password === '' || strlen($password) > 1024) {
    admin_error('Validation failed.', 422, ['email' => 'Enter a valid email address.', 'password' => 'Password is required.']);
}

$rateKey = 'admin-login:' . client_ip() . ':' . hash('sha256', $email);
if (!rate_limit($rateKey, 8, 900, true)) {
    header('Retry-After: 900');
    admin_error('Too many sign-in attempts. Please try again later.', 429);
}

$pdo = db();
$stmt = $pdo->prepare(
    "SELECT u.id, u.name, u.email, u.password_hash, u.status, u.last_login_at, u.created_at, u.updated_at,
            r.id AS role_id, r.name AS role, r.slug AS role_slug
     FROM admin_users u INNER JOIN admin_roles r ON r.id = u.role_id
     WHERE u.email = ? LIMIT 1"
);
$stmt->execute([$email]);
$user = $stmt->fetch();
if (!$user || $user['status'] !== 'active' || !password_verify($password, (string) $user['password_hash'])) {
    admin_error('Invalid email or password.', 401);
}

if (password_needs_rehash((string) $user['password_hash'], PASSWORD_DEFAULT)) {
    $rehash = $pdo->prepare('UPDATE admin_users SET password_hash = ? WHERE id = ?');
    $rehash->execute([password_hash($password, PASSWORD_DEFAULT), (int) $user['id']]);
}

session_regenerate_id(true);
$_SESSION = [
    'admin_user_id' => (int) $user['id'],
    'admin_csrf_token' => bin2hex(random_bytes(32)),
];
$pdo->prepare('UPDATE admin_users SET last_login_at = NOW() WHERE id = ?')->execute([(int) $user['id']]);
$permissionStmt = $pdo->prepare(
    'SELECT p.name FROM admin_role_permissions rp
     INNER JOIN admin_permissions p ON p.id = rp.permission_id
     WHERE rp.role_id = ? ORDER BY p.name'
);
$permissionStmt->execute([(int) $user['role_id']]);
$user['last_login_at'] = date('Y-m-d H:i:s');
admin_audit((int) $user['id'], 'auth.login', 'admin_user', (int) $user['id'], 'Administrator signed in.');

admin_success([
    'user' => admin_public_user($user),
    'permissions' => array_map('strval', $permissionStmt->fetchAll(PDO::FETCH_COLUMN)),
    'csrf_token' => (string) $_SESSION['admin_csrf_token'],
]);
