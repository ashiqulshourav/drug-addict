<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
admin_require_method('GET', 'POST', 'PUT', 'PATCH', 'DELETE');
$pdo = db();

function admin_role_is_assignable(int $roleId, array $actor): bool
{
    $pdo = db();
    $stmt = $pdo->prepare('SELECT name FROM admin_roles WHERE id = ?');
    $stmt->execute([$roleId]);
    if ($stmt->fetchColumn() === false) {
        admin_error('Role not found.', 404);
    }
    $stmt = $pdo->prepare(
        'SELECT p.name FROM admin_role_permissions rp
         INNER JOIN admin_permissions p ON p.id = rp.permission_id WHERE rp.role_id = ?'
    );
    $stmt->execute([$roleId]);
    return array_diff(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN)), $actor['permissions']) === [];
}

if ($method === 'GET') {
    $actor = admin_require_permission('users.view');
    if (($_GET['resource'] ?? '') === 'roles') {
        $roles = $pdo->query('SELECT id, name, slug FROM admin_roles ORDER BY name')->fetchAll();
        $assignable = [];
        foreach ($roles as $role) {
            $permissionStmt = $pdo->prepare(
                'SELECT p.name FROM admin_role_permissions rp INNER JOIN admin_permissions p ON p.id = rp.permission_id WHERE rp.role_id = ?'
            );
            $permissionStmt->execute([(int) $role['id']]);
            $permissions = array_map('strval', $permissionStmt->fetchAll(PDO::FETCH_COLUMN));
            if (array_diff($permissions, $actor['permissions']) === []) {
                $assignable[] = ['id' => (int) $role['id'], 'name' => (string) $role['name']];
            }
        }
        admin_success(['items' => $assignable]);
    }
    if (isset($_GET['id'])) {
        $id = admin_positive_id($_GET['id']);
        $stmt = $pdo->prepare(
            'SELECT u.id, u.name, u.email, u.status, u.last_login_at, u.created_at, u.updated_at,
                    r.id AS role_id, r.name AS role
             FROM admin_users u INNER JOIN admin_roles r ON r.id = u.role_id WHERE u.id = ? LIMIT 1'
        );
        $stmt->execute([$id]);
        $item = $stmt->fetch();
        if (!$item) admin_error('Administrator not found.', 404);
        admin_success(['item' => $item]);
    }
    $pagination = admin_pagination();
    $search = clean_text($_GET['search'] ?? '', 120);
    $status = (string) ($_GET['status'] ?? '');
    $roleId = filter_var($_GET['role_id'] ?? null, FILTER_VALIDATE_INT);
    $where = [];
    $params = [];
    if ($search !== '') {
        $where[] = '(u.name LIKE ? OR u.email LIKE ?)';
        $params[] = '%' . $search . '%';
        $params[] = '%' . $search . '%';
    }
    if (in_array($status, ['active', 'disabled'], true)) {
        $where[] = 'u.status = ?';
        $params[] = $status;
    }
    if ($roleId && $roleId > 0) {
        $where[] = 'u.role_id = ?';
        $params[] = (int) $roleId;
    }
    $clause = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $count = $pdo->prepare("SELECT COUNT(*) FROM admin_users u INNER JOIN admin_roles r ON r.id = u.role_id {$clause}");
    $count->execute($params);
    $total = (int) $count->fetchColumn();
    $sql = "SELECT u.id, u.name, u.email, u.status, u.last_login_at, u.created_at, u.updated_at,
                   r.id AS role_id, r.name AS role
            FROM admin_users u INNER JOIN admin_roles r ON r.id = u.role_id
            {$clause} ORDER BY u.created_at DESC, u.id DESC LIMIT {$pagination['per_page']} OFFSET {$pagination['offset']}";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    admin_success(admin_paged_data($stmt->fetchAll(), $pagination['page'], $pagination['per_page'], $total));
}

if ($method === 'POST') {
    $actor = admin_require_permission('users.create');
    admin_require_csrf();
    admin_rate_limit('users-create', 10, 3600);
    $body = admin_body();
    $name = admin_text($body, 'name', 120, true);
    $email = strtolower(admin_text($body, 'email', 190, true));
    $password = (string) ($body['password'] ?? '');
    $roleId = admin_positive_id($body['role_id'] ?? null, 'role_id');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        admin_error('Validation failed.', 422, ['email' => 'Enter a valid email address.']);
    }
    if (strlen($password) < 12 || strlen($password) > 1024 || !preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password) || !preg_match('/[0-9]/', $password) || !preg_match('/[^A-Za-z0-9]/', $password)) {
        admin_error('Validation failed.', 422, ['password' => 'Use at least 12 characters with uppercase, lowercase, number, and special character.']);
    }
    if (!admin_role_is_assignable($roleId, $actor)) {
        admin_error('You cannot assign permissions you do not have.', 403);
    }
    try {
        $pdo->beginTransaction();
        $insert = $pdo->prepare("INSERT INTO admin_users (role_id, name, email, password_hash, status) VALUES (?, ?, ?, ?, 'active')");
        $insert->execute([$roleId, $name, $email, password_hash($password, PASSWORD_DEFAULT)]);
        $id = (int) $pdo->lastInsertId();
        admin_audit((int) $actor['id'], 'admin_user.create', 'admin_user', $id, 'Created admin user ' . $email . '.');
        $pdo->commit();
        admin_success(['id' => $id], 201);
    } catch (PDOException $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($error->getCode() === '23000') admin_error('An administrator with this email already exists.', 409);
        error_log('Madok admin user create failed: ' . $error->getMessage());
        admin_error('Unable to create administrator.', 500);
    }
}

$id = admin_positive_id($_GET['id'] ?? null);
if ($method === 'PATCH') {
    $actor = admin_require_permission('users.edit');
    admin_require_csrf();
    $body = admin_body();
    $action = (string) ($body['action'] ?? '');
    if ($action === 'change_password') {
        $newPassword = (string) ($body['new_password'] ?? '');
        if (strlen($newPassword) < 12 || strlen($newPassword) > 1024 || !preg_match('/[A-Z]/', $newPassword) || !preg_match('/[a-z]/', $newPassword) || !preg_match('/[0-9]/', $newPassword) || !preg_match('/[^A-Za-z0-9]/', $newPassword)) {
            admin_error('Validation failed.', 422, ['new_password' => 'Use at least 12 characters with uppercase, lowercase, number, and special character.']);
        }
        admin_rate_limit('password-reset', 10, 3600);
        $pdo->beginTransaction();
        try {
            $lock = $pdo->prepare('SELECT id FROM admin_users WHERE id = ? FOR UPDATE');
            $lock->execute([$id]);
            if (!$lock->fetchColumn()) admin_error('Administrator not found.', 404);
            $pdo->prepare('UPDATE admin_users SET password_hash = ? WHERE id = ?')->execute([password_hash($newPassword, PASSWORD_DEFAULT), $id]);
            admin_audit((int) $actor['id'], 'admin_user.password_change', 'admin_user', $id, 'Administrator password changed by an authorized administrator.');
            $pdo->commit();
            admin_success(['id' => $id, 'password_changed' => true]);
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $error;
        }
    }
    if (!in_array($action, ['enable', 'disable'], true)) {
        admin_error('Validation failed.', 422, ['action' => 'Choose enable, disable, or change_password.']);
    }
    $currentStatusStmt = $pdo->prepare('SELECT status FROM admin_users WHERE id = ?');
    $currentStatusStmt->execute([$id]);
    $currentStatus = $currentStatusStmt->fetchColumn();
    if ($currentStatus === false) admin_error('Administrator not found.', 404);
    $nextStatus = $action === 'enable' ? 'active' : 'disabled';
    if ($currentStatus === $nextStatus) admin_error('Administrator already has that status.', 409);
    if ($action === 'disable') {
        if ($id === (int) $actor['id']) admin_error('You cannot disable your own account.', 409);
        admin_require_permission('users.delete');
        admin_rate_limit('users-disable', 30, 3600);
    }
    $status = $nextStatus;
    $pdo->beginTransaction();
    try {
        $lock = $pdo->prepare('SELECT status FROM admin_users WHERE id = ? FOR UPDATE');
        $lock->execute([$id]);
        $lockedStatus = $lock->fetchColumn();
        if ($lockedStatus === false) admin_error('Administrator not found.', 404);
        if ($lockedStatus === $status) admin_error('Administrator already has that status.', 409);
        if ($action === 'disable') admin_assert_last_super_admin($id, null, 'disabled');
        $stmt = $pdo->prepare('UPDATE admin_users SET status = ? WHERE id = ?');
        $stmt->execute([$status, $id]);
        if ($stmt->rowCount() === 0) {
            $exists = $pdo->prepare('SELECT 1 FROM admin_users WHERE id = ?');
            $exists->execute([$id]);
            if (!$exists->fetchColumn()) { $pdo->rollBack(); admin_error('Administrator not found.', 404); }
        }
        admin_audit((int) $actor['id'], 'admin_user.' . $action, 'admin_user', $id, 'Administrator account ' . $status . '.');
        $pdo->commit();
        admin_success(['id' => $id, 'status' => $status]);
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}

if ($method === 'PUT') {
    $actor = admin_require_permission('users.edit');
    admin_require_csrf();
    $body = admin_body();
    $name = array_key_exists('name', $body) ? admin_text($body, 'name', 120, true) : null;
    $email = array_key_exists('email', $body) ? strtolower(admin_text($body, 'email', 190, true)) : null;
    $roleId = array_key_exists('role_id', $body) ? admin_positive_id($body['role_id'], 'role_id') : null;
    if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) admin_error('Validation failed.', 422, ['email' => 'Enter a valid email address.']);
    if ($roleId !== null && !admin_role_is_assignable($roleId, $actor)) admin_error('You cannot assign permissions you do not have.', 403);
    if ($name === null && $email === null && $roleId === null) admin_error('No editable fields were provided.', 422);
    $updates = [];
    $params = [];
    foreach (['name' => $name, 'email' => $email, 'role_id' => $roleId] as $column => $value) {
        if ($value !== null) { $updates[] = "{$column} = ?"; $params[] = $value; }
    }
    $params[] = $id;
    try {
        $pdo->beginTransaction();
        if ($roleId !== null) admin_assert_last_super_admin($id, $roleId);
        $stmt = $pdo->prepare('UPDATE admin_users SET ' . implode(', ', $updates) . ' WHERE id = ?');
        $stmt->execute($params);
        $exists = $pdo->prepare('SELECT 1 FROM admin_users WHERE id = ?');
        $exists->execute([$id]);
        if (!$exists->fetchColumn()) { $pdo->rollBack(); admin_error('Administrator not found.', 404); }
        admin_audit((int) $actor['id'], 'admin_user.update', 'admin_user', $id, 'Updated administrator account.');
        $pdo->commit();
        admin_success(['id' => $id]);
    } catch (PDOException $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($error->getCode() === '23000') admin_error('An administrator with this email already exists.', 409);
        error_log('Madok admin user update failed: ' . $error->getMessage());
        admin_error('Unable to update administrator.', 500);
    }
}

if ($method === 'DELETE') {
    $actor = admin_require_permission('users.delete');
    admin_require_csrf();
    if ($id === (int) $actor['id']) admin_error('You cannot delete your own account.', 409);
    admin_rate_limit('users-delete', 10, 3600);
    $stmt = $pdo->prepare('SELECT status FROM admin_users WHERE id = ?');
    $stmt->execute([$id]);
    $status = $stmt->fetchColumn();
    if ($status === false) admin_error('Administrator not found.', 404);
    if ($status !== 'disabled') admin_error('Disable the administrator before deleting the account.', 409);
    $pdo->beginTransaction();
    try {
        $lock = $pdo->prepare('SELECT status FROM admin_users WHERE id = ? FOR UPDATE');
        $lock->execute([$id]);
        $lockedStatus = $lock->fetchColumn();
        if ($lockedStatus === false) admin_error('Administrator not found.', 404);
        if ($lockedStatus !== 'disabled') admin_error('Disable the administrator before deleting the account.', 409);
        admin_assert_last_super_admin($id, null, 'disabled');
        $pdo->prepare('DELETE FROM admin_users WHERE id = ? AND status = \'disabled\'')->execute([$id]);
        admin_audit((int) $actor['id'], 'admin_user.delete', 'admin_user', $id, 'Deleted disabled administrator account.');
        $pdo->commit();
        admin_success(['id' => $id, 'deleted' => true]);
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Madok admin user delete failed: ' . $error->getMessage());
        admin_error('Unable to delete administrator.', 500);
    }
}

admin_require_method('GET', 'POST', 'PUT', 'PATCH', 'DELETE');
