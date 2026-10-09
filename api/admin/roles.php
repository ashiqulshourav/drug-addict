<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
admin_require_method('GET', 'POST', 'PUT', 'DELETE');
$pdo = db();

function admin_role_permissions(int $roleId): array
{
    $stmt = db()->prepare('SELECT p.name FROM admin_role_permissions rp INNER JOIN admin_permissions p ON p.id = rp.permission_id WHERE rp.role_id = ? ORDER BY p.name');
    $stmt->execute([$roleId]);
    return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function admin_role_slug(string $name): string
{
    $slug = strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $name), '-'));
    if ($slug === '') admin_error('Validation failed.', 422, ['name' => 'Role name must contain letters or numbers.']);
    return $slug;
}

function admin_save_role_permissions(PDO $pdo, int $roleId, array $requested, array $actor): void
{
    $requested = array_values(array_unique(array_map('strval', $requested)));
    $all = $pdo->query('SELECT name FROM admin_permissions')->fetchAll(PDO::FETCH_COLUMN);
    if (array_diff($requested, $all) !== []) admin_error('Validation failed.', 422, ['permissions' => 'One or more permissions are invalid.']);
    if (array_diff($requested, $actor['permissions']) !== []) admin_error('You cannot grant permissions you do not have.', 403);
    $pdo->prepare('DELETE FROM admin_role_permissions WHERE role_id = ?')->execute([$roleId]);
    $insert = $pdo->prepare('INSERT INTO admin_role_permissions (role_id, permission_id) SELECT ?, id FROM admin_permissions WHERE name = ?');
    foreach ($requested as $permission) $insert->execute([$roleId, $permission]);
}

if ($method === 'GET') {
    admin_require_permission('roles.manage');
    $roles = $pdo->query('SELECT id, name, slug, description, is_system, created_at, updated_at FROM admin_roles ORDER BY is_system DESC, name')->fetchAll();
    foreach ($roles as &$role) {
        $role['id'] = (int) $role['id'];
        $role['is_system'] = (bool) $role['is_system'];
        $role['permissions'] = admin_role_permissions((int) $role['id']);
    }
    unset($role);
    $permissions = $pdo->query('SELECT name, description FROM admin_permissions ORDER BY name')->fetchAll();
    $groups = [];
    foreach ($permissions as $permission) {
        $group = explode('.', (string) $permission['name'], 2)[0];
        $groups[$group][] = $permission;
    }
    admin_success(['items' => $roles, 'permissions' => $groups]);
}

$actor = admin_require_permission('roles.manage');
admin_require_csrf();
$body = admin_body();
if ($method === 'POST') {
    $name = admin_text($body, 'name', 80, true);
    $description = admin_text($body, 'description', 255);
    $permissions = $body['permissions'] ?? [];
    if (!is_array($permissions)) admin_error('Validation failed.', 422, ['permissions' => 'Permissions must be an array.']);
    $slug = admin_role_slug($name);
    try {
        $pdo->beginTransaction();
        $insert = $pdo->prepare('INSERT INTO admin_roles (name, slug, description, is_system) VALUES (?, ?, ?, 0)');
        $insert->execute([$name, $slug, $description !== '' ? $description : null]);
        $id = (int) $pdo->lastInsertId();
        admin_save_role_permissions($pdo, $id, $permissions, $actor);
        admin_audit((int) $actor['id'], 'role.create', 'admin_role', $id, 'Created role ' . $name . '.');
        $pdo->commit();
        admin_success(['id' => $id], 201);
    } catch (PDOException $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($error->getCode() === '23000') admin_error('A role with this name already exists.', 409);
        error_log('Madok role create failed: ' . $error->getMessage());
        admin_error('Unable to create role.', 500);
    }
}

$id = admin_positive_id($_GET['id'] ?? $body['id'] ?? null);
$stmt = $pdo->prepare('SELECT id, name, slug, description, is_system FROM admin_roles WHERE id = ?');
$stmt->execute([$id]);
$role = $stmt->fetch();
if (!$role) admin_error('Role not found.', 404);
if ($method === 'PUT') {
    $name = admin_text($body, 'name', 80, true);
    $description = admin_text($body, 'description', 255);
    $permissions = $body['permissions'] ?? [];
    if (!is_array($permissions)) admin_error('Validation failed.', 422, ['permissions' => 'Permissions must be an array.']);
    if ($role['slug'] === 'super-admin') {
        $allPermissions = array_map('strval', $pdo->query('SELECT name FROM admin_permissions')->fetchAll(PDO::FETCH_COLUMN));
        if (array_diff($allPermissions, $permissions) !== []) admin_error('The SUPER ADMIN role must retain every permission.', 409);
    }
    try {
        $pdo->beginTransaction();
        $update = $pdo->prepare('UPDATE admin_roles SET name = ?, slug = ?, description = ? WHERE id = ?');
        $update->execute([$name, (bool) $role['is_system'] ? (string) $role['slug'] : admin_role_slug($name), $description !== '' ? $description : null, $id]);
        admin_save_role_permissions($pdo, $id, $permissions, $actor);
        admin_audit((int) $actor['id'], 'role.update', 'admin_role', $id, 'Updated role ' . $name . '.');
        $pdo->commit();
        admin_success(['id' => $id]);
    } catch (PDOException $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($error->getCode() === '23000') admin_error('A role with this name already exists.', 409);
        error_log('Madok role update failed: ' . $error->getMessage());
        admin_error('Unable to update role.', 500);
    }
}
if ($method === 'DELETE') {
    admin_require_csrf();
    if ((bool) $role['is_system']) admin_error('System roles cannot be deleted.', 409);
    admin_rate_limit('roles-delete', 10, 3600);
    $assigned = $pdo->prepare('SELECT COUNT(*) FROM admin_users WHERE role_id = ?');
    $assigned->execute([$id]);
    if ((int) $assigned->fetchColumn() > 0) admin_error('This role is assigned to administrators and cannot be deleted.', 409);
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM admin_roles WHERE id = ? AND is_system = 0')->execute([$id]);
        admin_audit((int) $actor['id'], 'role.delete', 'admin_role', $id, 'Deleted role ' . $role['name'] . '.');
        $pdo->commit();
        admin_success(['id' => $id, 'deleted' => true]);
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}
admin_error('Method not allowed.', 405);
