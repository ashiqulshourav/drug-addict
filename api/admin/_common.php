<?php
declare(strict_types=1);

ini_set('display_errors', '0');
set_exception_handler(static function (Throwable $error): void {
    error_log('Madok admin API failure: ' . $error->getMessage());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode(['ok' => false, 'message' => 'The administration request could not be completed.']);
});

require_once dirname(__DIR__) . '/_common.php';

function admin_success(array $data = [], int $status = 200): never
{
    json_response(['ok' => true, 'data' => $data], $status);
}

function admin_error(string $message, int $status = 400, array $errors = []): never
{
    $response = ['ok' => false, 'message' => $message];
    if ($errors !== []) {
        $response['errors'] = $errors;
    }
    json_response($response, $status);
}

function admin_body(): array
{
    $length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($length > 1048576) {
        admin_error('Request body is too large.', 413);
    }

    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $body = json_decode($raw, true);
    if (!is_array($body)) {
        admin_error('Request body must be a JSON object.', 400);
    }

    return $body;
}

function admin_require_method(string ...$methods): void
{
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if (!in_array($method, $methods, true)) {
        header('Allow: ' . implode(', ', $methods));
        admin_error('Method not allowed.', 405);
    }
}

function admin_csrf_token(): string
{
    start_secure_session();
    if (empty($_SESSION['admin_csrf_token'])) {
        $_SESSION['admin_csrf_token'] = bin2hex(random_bytes(32));
    }
    return (string) $_SESSION['admin_csrf_token'];
}

function admin_require_csrf(): void
{
    start_secure_session();
    $provided = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    $expected = (string) ($_SESSION['admin_csrf_token'] ?? '');
    if ($provided === '' || $expected === '' || !hash_equals($expected, $provided)) {
        admin_error('Security token is invalid or expired.', 419);
    }
}

function admin_current_user(bool $required = true): ?array
{
    start_secure_session();
    $userId = filter_var($_SESSION['admin_user_id'] ?? null, FILTER_VALIDATE_INT);
    if (!$userId || $userId < 1) {
        if ($required) {
            admin_error('Authentication required.', 401);
        }
        return null;
    }

    $pdo = db();
    $stmt = $pdo->prepare(
        "SELECT u.id, u.name, u.email, u.status, u.last_login_at, u.created_at, u.updated_at,
                r.id AS role_id, r.name AS role, r.slug AS role_slug
         FROM admin_users u
         INNER JOIN admin_roles r ON r.id = u.role_id
         WHERE u.id = ?
         LIMIT 1"
    );
    $stmt->execute([(int) $userId]);
    $user = $stmt->fetch();

    if (!$user || $user['status'] !== 'active') {
        admin_clear_session();
        if ($required) {
            admin_error('Session expired. Please sign in again.', 401);
        }
        return null;
    }

    $permissionStmt = $pdo->prepare(
        'SELECT p.name FROM admin_role_permissions rp
         INNER JOIN admin_permissions p ON p.id = rp.permission_id
         WHERE rp.role_id = ? ORDER BY p.name'
    );
    $permissionStmt->execute([(int) $user['role_id']]);
    $user['permissions'] = array_map('strval', $permissionStmt->fetchAll(PDO::FETCH_COLUMN));

    return $user;
}

function admin_require_permission(string $permission): array
{
    $user = admin_current_user();
    if (!in_array($permission, $user['permissions'], true)) {
        admin_error('You do not have permission to perform this action.', 403);
    }
    return $user;
}

function admin_clear_session(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        start_secure_session();
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'] ?? '/',
            'domain' => $params['domain'] ?? '',
            'secure' => (bool) ($params['secure'] ?? false),
            'httponly' => (bool) ($params['httponly'] ?? true),
            'samesite' => $params['samesite'] ?? 'Lax',
        ]);
    }
    session_destroy();
}

function admin_require_csrf_for_mutation(): void
{
    admin_require_csrf();
}

function admin_rate_limit(string $scope, int $maxRequests, int $windowSeconds): void
{
    if (!rate_limit('admin:' . $scope . ':' . client_ip(), $maxRequests, $windowSeconds, true)) {
        header('Retry-After: ' . $windowSeconds);
        admin_error('Too many requests. Please try again later.', 429);
    }
}

function admin_audit(?int $userId, string $action, ?string $entityType = null, ?int $entityId = null, string $description = ''): void
{
    $ipHash = hash_hmac('sha256', client_ip(), (string) env_value('APP_KEY', DB_PASS));
    $userAgent = clean_text($_SERVER['HTTP_USER_AGENT'] ?? '', 255);
    $stmt = db()->prepare(
        'INSERT INTO admin_audit_logs (admin_user_id, action, entity_type, entity_id, description, ip_hash, user_agent)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([$userId, $action, $entityType, $entityId, clean_text($description, 500), $ipHash, $userAgent]);
}

function admin_pagination(): array
{
    $page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT);
    $perPage = filter_var($_GET['per_page'] ?? 25, FILTER_VALIDATE_INT);
    $page = max(1, (int) ($page ?: 1));
    $page = min(1000000, $page);
    $perPage = min(100, max(1, (int) ($perPage ?: 25)));
    return ['page' => $page, 'per_page' => $perPage, 'offset' => ($page - 1) * $perPage];
}

function admin_paged_data(array $items, int $page, int $perPage, int $total): array
{
    return [
        'items' => $items,
        'page' => $page,
        'per_page' => $perPage,
        'total' => $total,
        'total_pages' => (int) ceil($total / max(1, $perPage)),
    ];
}

function admin_text(array $body, string $key, int $maxLength, bool $required = false): string
{
    $value = trim((string) ($body[$key] ?? ''));
    if ($required && $value === '') {
        admin_error('Validation failed.', 422, [$key => 'This field is required.']);
    }
    $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    if ($length > $maxLength) {
        admin_error('Validation failed.', 422, [$key => 'This field is too long.']);
    }
    return $value;
}

function admin_positive_id(mixed $value, string $field = 'id'): int
{
    $id = filter_var($value, FILTER_VALIDATE_INT);
    if (!$id || $id < 1) {
        admin_error('Validation failed.', 422, [$field => 'A valid ID is required.']);
    }
    return (int) $id;
}

function admin_public_user(array $user): array
{
    return [
        'id' => (int) $user['id'],
        'name' => (string) $user['name'],
        'email' => (string) $user['email'],
        'role' => (string) $user['role'],
        'role_id' => (int) $user['role_id'],
        'status' => (string) $user['status'],
        'last_login_at' => $user['last_login_at'] !== null ? (string) $user['last_login_at'] : null,
        'created_at' => (string) $user['created_at'],
        'updated_at' => (string) $user['updated_at'],
    ];
}

function admin_setting_value(string $key, ?string $default = null): ?string
{
    $stmt = db()->prepare('SELECT setting_value FROM admin_settings WHERE setting_key = ? LIMIT 1');
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();
    return $value === false ? $default : (string) $value;
}

function admin_setting_enabled(string $key, bool $default = false): bool
{
    return filter_var(admin_setting_value($key, $default ? 'true' : 'false'), FILTER_VALIDATE_BOOL);
}

function admin_assert_last_super_admin(int $userId, ?int $newRoleId = null, ?string $newStatus = null): void
{
    $pdo = db();
    $stmt = $pdo->prepare(
        "SELECT r.slug, u.status FROM admin_users u
         INNER JOIN admin_roles r ON r.id = u.role_id WHERE u.id = ? LIMIT 1"
    );
    $stmt->execute([$userId]);
    $target = $stmt->fetch();
    if (!$target || $target['slug'] !== 'super-admin' || $target['status'] !== 'active') {
        return;
    }

    $isRemoving = ($newStatus !== null && $newStatus !== 'active');
    if ($newRoleId !== null) {
        $role = $pdo->prepare('SELECT slug FROM admin_roles WHERE id = ?');
        $role->execute([$newRoleId]);
        $isRemoving = $isRemoving || $role->fetchColumn() !== 'super-admin';
    }
    if (!$isRemoving) {
        return;
    }

        $activeRows = $pdo->query(
            "SELECT u.id FROM admin_users u
         INNER JOIN admin_roles r ON r.id = u.role_id
            WHERE r.slug = 'super-admin' AND u.status = 'active' FOR UPDATE"
        )->fetchAll(PDO::FETCH_COLUMN);
        if (count($activeRows) <= 1) {
        admin_error('The last active SUPER ADMIN cannot be disabled or demoted.', 409);
    }
}
