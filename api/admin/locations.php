<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
admin_require_method('GET', 'PUT', 'PATCH', 'DELETE');
$pdo = db();
$base = 'FROM locations l LEFT JOIN police_stations ps ON ps.id = l.police_station_id LEFT JOIN upazilas u ON u.id = l.upazila_id LEFT JOIN districts d ON d.id = COALESCE(u.district_id, ps.district_id) LEFT JOIN divisions dv ON dv.id = d.division_id';

if ($method === 'GET') {
    $actor = admin_require_permission('locations.view');
    $idValue = $_GET['id'] ?? null;
    $pagination = admin_pagination();
    $where = [];
    $params = [];
    $status = (string) ($_GET['status'] ?? 'active');
    if ($status === 'active') $where[] = 'l.deleted_at IS NULL';
    elseif ($status === 'deleted') $where[] = 'l.deleted_at IS NOT NULL';
    elseif ($status !== 'all') admin_error('Validation failed.', 422, ['status' => 'Choose active, deleted, or all.']);
    $search = clean_text($_GET['search'] ?? '', 160);
    if ($search !== '') { $where[] = '(l.title LIKE ? OR d.name LIKE ? OR u.name LIKE ? OR ps.name LIKE ?)'; array_push($params, ...array_fill(0, 4, '%' . $search . '%')); }
    $type = (string) ($_GET['type'] ?? '');
    if ($type !== '') { if (!in_array($type, ['use', 'sale', 'both'], true)) admin_error('Validation failed.', 422, ['type' => 'Invalid location type.']); $where[] = 'l.type = ?'; $params[] = $type; }
    foreach (['district' => 'd.name', 'upazila' => 'u.name', 'police_station' => 'ps.name'] as $key => $column) {
        $value = clean_text($_GET[$key] ?? '', 120);
        if ($value !== '') { $where[] = $column . ' = ?'; $params[] = $value; }
    }
    if ($idValue !== null) {
        $id = admin_positive_id($idValue);
        $stmt = $pdo->prepare('SELECT l.id, l.title, l.type, l.latitude, l.longitude, l.police_station_id, l.upazila_id, l.report_count, l.use_count, l.sale_count, l.created_at, l.updated_at, l.deleted_at, l.delete_reason, ps.name AS police_station, u.name AS upazila, d.name AS district, dv.name AS division ' . $base . ' WHERE l.id = ? LIMIT 1');
        $stmt->execute([$id]);
        $item = $stmt->fetch();
        if (!$item) admin_error('Location not found.', 404);
        admin_audit((int) $actor['id'], 'location.view', 'location', $id, 'Viewed location details.');
        admin_success(['item' => $item]);
    }
    $clause = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $count = $pdo->prepare('SELECT COUNT(*) ' . $base . ' ' . $clause);
    $count->execute($params);
    $total = (int) $count->fetchColumn();
    $sortFields = ['id' => 'l.id', 'title' => 'l.title', 'type' => 'l.type', 'created_at' => 'l.created_at', 'updated_at' => 'l.updated_at', 'report_count' => 'l.report_count', 'district' => 'd.name'];
    $sort = $sortFields[(string) ($_GET['sort'] ?? 'updated_at')] ?? $sortFields['updated_at'];
    $direction = strtoupper((string) ($_GET['direction'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';
    $stmt = $pdo->prepare('SELECT l.id, l.title, l.type, l.latitude, l.longitude, l.police_station_id, l.upazila_id, l.report_count, l.use_count, l.sale_count, l.created_at, l.updated_at, l.deleted_at, ps.name AS police_station, u.name AS upazila, d.name AS district, dv.name AS division ' . $base . " {$clause} ORDER BY {$sort} {$direction}, l.id DESC LIMIT {$pagination['per_page']} OFFSET {$pagination['offset']}");
    $stmt->execute($params);
    admin_success(admin_paged_data($stmt->fetchAll(), $pagination['page'], $pagination['per_page'], $total));
}

$id = admin_positive_id($_GET['id'] ?? null);
$actor = admin_current_user();
$body = [];
if (in_array($method, ['PUT', 'PATCH', 'DELETE'], true)) { admin_require_csrf(); $body = admin_body(); }
$find = $pdo->prepare('SELECT id, title, deleted_at FROM locations WHERE id = ?');
$find->execute([$id]);
$location = $find->fetch();
if (!$location) admin_error('Location not found.', 404);

if ($method === 'PUT') {
    admin_require_permission('locations.edit');
    $fields = [];
    $values = [];
    if (array_key_exists('title', $body)) { $fields[] = 'title = ?'; $values[] = admin_text($body, 'title', 160, true); }
    foreach (['latitude' => [-90, 90], 'longitude' => [-180, 180]] as $key => [$minimum, $maximum]) {
        if (array_key_exists($key, $body)) {
            if (!is_numeric($body[$key]) || (float) $body[$key] < $minimum || (float) $body[$key] > $maximum) admin_error('Validation failed.', 422, [$key => 'Enter a valid coordinate.']);
            $fields[] = $key . ' = ?'; $values[] = (float) $body[$key];
        }
    }
    foreach (['upazila_id' => 'upazilas', 'police_station_id' => 'police_stations'] as $key => $table) {
        if (array_key_exists($key, $body)) {
            $value = $body[$key] === null || $body[$key] === '' ? null : admin_positive_id($body[$key], $key);
            if ($value !== null) { $check = $pdo->prepare("SELECT 1 FROM {$table} WHERE id = ?"); $check->execute([$value]); if (!$check->fetchColumn()) admin_error('Validation failed.', 422, [$key => 'The selected record does not exist.']); }
            $fields[] = $key . ' = ?'; $values[] = $value;
        }
    }
    if ($fields === []) admin_error('No editable fields were provided.', 422);
    $fields[] = 'updated_at = NOW()';
    $values[] = $id;
    $pdo->beginTransaction();
    try {
        $lock = $pdo->prepare('SELECT deleted_at FROM locations WHERE id = ? FOR UPDATE');
        $lock->execute([$id]);
        $deletedAt = $lock->fetchColumn();
        if ($deletedAt === false) admin_error('Location not found.', 404);
        if ($deletedAt !== null) admin_error('Restore the location before editing.', 409);
        $pdo->prepare('UPDATE locations SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($values);
        admin_audit((int) $actor['id'], 'location.update', 'location', $id, 'Updated location fields.');
        $pdo->commit();
        admin_success(['id' => $id]);
    } catch (Throwable $error) { if ($pdo->inTransaction()) $pdo->rollBack(); error_log('Madok location update failed: ' . $error->getMessage()); admin_error('Unable to update location.', 500); }
}

if ($method === 'PATCH') {
    $action = (string) ($body['action'] ?? '');
    if (!in_array($action, ['restore', 'permanent_delete'], true)) admin_error('Validation failed.', 422, ['action' => 'Choose restore or permanent_delete.']);
    if ($action === 'restore') {
        admin_require_permission('locations.restore');
        $pdo->beginTransaction();
        try {
            $lock = $pdo->prepare('SELECT deleted_at FROM locations WHERE id = ? FOR UPDATE');
            $lock->execute([$id]);
            $deletedAt = $lock->fetchColumn();
            if ($deletedAt === false) admin_error('Location not found.', 404);
            if ($deletedAt === null) admin_error('Location is not deleted.', 409);
            $restore = $pdo->prepare('UPDATE locations SET deleted_at = NULL, deleted_by = NULL, delete_reason = NULL WHERE id = ? AND deleted_at IS NOT NULL');
            $restore->execute([$id]);
            if ($restore->rowCount() !== 1) admin_error('Location state changed. Refresh and try again.', 409);
            admin_audit((int) $actor['id'], 'location.restore', 'location', $id, 'Restored location.');
            $pdo->commit();
            admin_success(['id' => $id, 'restored' => true]);
        } catch (Throwable $error) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $error; }
    }
    admin_require_permission('locations.permanent_delete');
    if (!admin_setting_enabled('locations.allow_delete')) admin_error('Location deletion is disabled by settings.', 403);
    if (($body['confirm'] ?? '') !== 'DELETE') admin_error('Explicit confirmation is required.', 422, ['confirm' => 'Enter DELETE to permanently remove this location.']);
    admin_rate_limit('locations-permanent-delete', 10, 3600);
    $pdo->beginTransaction();
    try {
        $lock = $pdo->prepare('SELECT id FROM locations WHERE id = ? FOR UPDATE');
        $lock->execute([$id]);
        if (!$lock->fetchColumn()) admin_error('Location not found.', 404);
        $reports = $pdo->prepare('SELECT COUNT(*) FROM reports WHERE location_id = ?');
        $reports->execute([$id]);
        if ((int) $reports->fetchColumn() > 0) admin_error('This location has reports. Permanently delete or reassign every report first.', 409);
        $delete = $pdo->prepare('DELETE FROM locations WHERE id = ?');
        $delete->execute([$id]);
        if ($delete->rowCount() !== 1) admin_error('Location state changed. Refresh and try again.', 409);
        admin_audit((int) $actor['id'], 'location.permanent_delete', 'location', $id, 'Permanently deleted location with no reports.');
        $pdo->commit();
        admin_success(['id' => $id, 'deleted' => true]);
    } catch (Throwable $error) { if ($pdo->inTransaction()) $pdo->rollBack(); error_log('Madok permanent location deletion failed: ' . $error->getMessage()); admin_error('Unable to permanently delete location.', 500); }
}

if ($method === 'DELETE') {
    $actor = admin_require_permission('locations.delete');
    if (!admin_setting_enabled('locations.allow_delete')) admin_error('Location deletion is disabled by settings.', 403);
    if ($location['deleted_at'] !== null) admin_error('Location is already deleted.', 409);
    admin_rate_limit('locations-delete', 30, 3600);
    $reason = admin_text($body, 'reason', 500);
    $pdo->beginTransaction();
    try {
        $lock = $pdo->prepare('SELECT deleted_at FROM locations WHERE id = ? FOR UPDATE');
        $lock->execute([$id]);
        $deletedAt = $lock->fetchColumn();
        if ($deletedAt === false) admin_error('Location not found.', 404);
        if ($deletedAt !== null) admin_error('Location is already deleted.', 409);
        $delete = $pdo->prepare('UPDATE locations SET deleted_at = NOW(), deleted_by = ?, delete_reason = ?, updated_at = NOW() WHERE id = ? AND deleted_at IS NULL');
        $delete->execute([(int) $actor['id'], $reason !== '' ? $reason : null, $id]);
        if ($delete->rowCount() !== 1) admin_error('Location state changed. Refresh and try again.', 409);
        admin_audit((int) $actor['id'], 'location.delete', 'location', $id, 'Soft-deleted location.');
        $pdo->commit();
        admin_success(['id' => $id, 'deleted' => true]);
    } catch (Throwable $error) { if ($pdo->inTransaction()) $pdo->rollBack(); error_log('Madok location deletion failed: ' . $error->getMessage()); admin_error('Unable to delete location.', 500); }
}
admin_error('Method not allowed.', 405);
