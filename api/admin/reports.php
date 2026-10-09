<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
admin_require_method('GET', 'PUT', 'PATCH', 'DELETE');
$pdo = db();

function admin_recalculate_location(PDO $pdo, int $locationId): void
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) AS total,
                COALESCE(SUM(report_type = 'use'), 0) AS uses,
                COALESCE(SUM(report_type = 'sale'), 0) AS sales,
                (SELECT title FROM reports WHERE location_id = ? AND deleted_at IS NULL ORDER BY created_at DESC, id DESC LIMIT 1) AS latest_title
         FROM reports WHERE location_id = ? AND deleted_at IS NULL"
    );
    $stmt->execute([$locationId, $locationId]);
    $counts = $stmt->fetch();
    $type = (int) $counts['uses'] > 0 && (int) $counts['sales'] > 0
        ? 'both'
        : ((int) $counts['sales'] > 0 ? 'sale' : 'use');
    $update = $pdo->prepare('UPDATE locations SET report_count = ?, use_count = ?, sale_count = ?, type = ?, title = COALESCE(?, title), updated_at = NOW() WHERE id = ?');
    $update->execute([(int) $counts['total'], (int) $counts['uses'], (int) $counts['sales'], $type, $counts['latest_title'], $locationId]);
}

function admin_report_query_base(): string
{
    return "FROM reports r INNER JOIN locations l ON l.id = r.location_id
            LEFT JOIN police_stations ps ON ps.id = l.police_station_id
            LEFT JOIN upazilas u ON u.id = l.upazila_id
            LEFT JOIN districts d ON d.id = u.district_id
            LEFT JOIN divisions dv ON dv.id = d.division_id";
}

function admin_report_filters(): array
{
    $where = [];
    $params = [];
    $status = (string) ($_GET['status'] ?? 'active');
    if ($status === 'active') $where[] = 'r.deleted_at IS NULL AND l.deleted_at IS NULL';
    elseif ($status === 'deleted') $where[] = '(r.deleted_at IS NOT NULL OR l.deleted_at IS NOT NULL)';
    elseif ($status !== 'all') admin_error('Validation failed.', 422, ['status' => 'Choose active, deleted, or all.']);
    $search = clean_text($_GET['search'] ?? '', 160);
    if ($search !== '') { $where[] = '(r.title LIKE ? OR r.description LIKE ? OR l.title LIKE ?)'; array_push($params, ...array_fill(0, 3, '%' . $search . '%')); }
    $type = (string) ($_GET['type'] ?? '');
    if ($type !== '') { if (!in_array($type, ['use', 'sale'], true)) admin_error('Validation failed.', 422, ['type' => 'Choose use or sale.']); $where[] = 'r.report_type = ?'; $params[] = $type; }
    $locationId = filter_var($_GET['location_id'] ?? null, FILTER_VALIDATE_INT);
    if ($locationId && $locationId > 0) { $where[] = 'r.location_id = ?'; $params[] = (int) $locationId; }
    foreach (['from' => '>=', 'to' => '<='] as $key => $operator) {
        $date = clean_text($_GET[$key] ?? '', 10);
        if ($date !== '') {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) admin_error('Validation failed.', 422, [$key => 'Use YYYY-MM-DD.']);
            $where[] = 'DATE(r.created_at) ' . $operator . ' ?'; $params[] = $date;
        }
    }
    return [$where, $params];
}

if ($method === 'GET') {
    $actor = admin_require_permission('reports.view');
    $idValue = $_GET['id'] ?? null;
    if ($idValue !== null) {
        $id = admin_positive_id($idValue);
        $stmt = $pdo->prepare('SELECT r.id, r.location_id, r.report_type, r.title, r.description, r.latitude, r.longitude, r.image_path, r.yes_count, r.no_count, r.created_at, r.deleted_at, r.delete_reason, l.deleted_at AS location_deleted_at, l.title AS location_title, l.type AS location_type, l.report_count, l.use_count, l.sale_count, ps.name AS police_station, u.name AS upazila, d.name AS district, dv.name AS division ' . admin_report_query_base() . ' WHERE r.id = ? LIMIT 1');
        $stmt->execute([$id]);
        $report = $stmt->fetch();
        if (!$report) admin_error('Report not found.', 404);
        admin_audit((int) $actor['id'], 'report.view', 'report', $id, 'Viewed report details.');
        admin_success(['item' => $report]);
    }
    [$where, $params] = admin_report_filters();
    $pagination = admin_pagination();
    $clause = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $count = $pdo->prepare('SELECT COUNT(*) ' . admin_report_query_base() . ' ' . $clause);
    $count->execute($params);
    $total = (int) $count->fetchColumn();
    $sortFields = ['id' => 'r.id', 'title' => 'r.title', 'type' => 'r.report_type', 'created_at' => 'r.created_at', 'location' => 'l.title', 'yes_count' => 'r.yes_count', 'no_count' => 'r.no_count'];
    $sort = $sortFields[(string) ($_GET['sort'] ?? 'created_at')] ?? $sortFields['created_at'];
    $direction = strtoupper((string) ($_GET['direction'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';
    $stmt = $pdo->prepare('SELECT r.id, r.location_id, r.report_type AS type, r.title, r.description, r.latitude, r.longitude, r.image_path, r.yes_count, r.no_count, r.created_at, r.deleted_at, l.deleted_at AS location_deleted_at, l.title AS location_title, ps.name AS police_station, u.name AS upazila, d.name AS district, dv.name AS division ' . admin_report_query_base() . " {$clause} ORDER BY {$sort} {$direction}, r.id DESC LIMIT {$pagination['per_page']} OFFSET {$pagination['offset']}");
    $stmt->execute($params);
    admin_success(admin_paged_data($stmt->fetchAll(), $pagination['page'], $pagination['per_page'], $total));
}

$id = admin_positive_id($_GET['id'] ?? null);
$actor = admin_current_user();
$body = [];
if (in_array($method, ['PUT', 'PATCH', 'DELETE'], true)) {
    admin_require_csrf();
    $body = admin_body();
}
$find = $pdo->prepare('SELECT id, location_id, title, image_path, deleted_at FROM reports WHERE id = ? LIMIT 1');
$find->execute([$id]);
$report = $find->fetch();
if (!$report) admin_error('Report not found.', 404);
$locationId = (int) $report['location_id'];

if ($method === 'PUT') {
    admin_require_permission('reports.edit');
    $fields = [];
    $values = [];
    if (array_key_exists('title', $body)) { $fields[] = 'title = ?'; $values[] = admin_text($body, 'title', 160, true); }
    if (array_key_exists('description', $body)) { $description = admin_text($body, 'description', 1000); $fields[] = 'description = ?'; $values[] = $description !== '' ? $description : null; }
    if (array_key_exists('type', $body)) { $type = (string) $body['type']; if (!in_array($type, ['use', 'sale'], true)) admin_error('Validation failed.', 422, ['type' => 'Choose use or sale.']); $fields[] = 'report_type = ?'; $values[] = $type; }
    if ($fields === []) admin_error('No editable fields were provided.', 422);
    $pdo->beginTransaction();
    try {
        $state = $pdo->prepare('SELECT r.deleted_at, l.deleted_at AS location_deleted_at FROM reports r INNER JOIN locations l ON l.id = r.location_id WHERE r.id = ? FOR UPDATE');
        $state->execute([$id]);
        $recordState = $state->fetch();
        if (!$recordState || $recordState['deleted_at'] !== null || $recordState['location_deleted_at'] !== null) admin_error('Restore the report and its location before editing.', 409);
        $values[] = $id;
        $pdo->prepare('UPDATE reports SET ' . implode(', ', $fields) . ' WHERE id = ?')->execute($values);
        admin_recalculate_location($pdo, $locationId);
        admin_audit((int) $actor['id'], 'report.update', 'report', $id, 'Updated report fields.');
        $pdo->commit();
        admin_success(['id' => $id]);
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Madok admin report update failed: ' . $error->getMessage());
        admin_error('Unable to update report.', 500);
    }
}

if ($method === 'PATCH') {
    $action = (string) ($body['action'] ?? '');
    if (!in_array($action, ['restore', 'permanent_delete'], true)) admin_error('Validation failed.', 422, ['action' => 'Choose restore or permanent_delete.']);
    if ($action === 'restore') {
        admin_require_permission('reports.restore');
        $pdo->beginTransaction();
        try {
            $state = $pdo->prepare('SELECT r.deleted_at, l.deleted_at AS location_deleted_at FROM reports r INNER JOIN locations l ON l.id = r.location_id WHERE r.id = ? FOR UPDATE');
            $state->execute([$id]);
            $recordState = $state->fetch();
            if (!$recordState || $recordState['deleted_at'] === null) admin_error('Report is not deleted.', 409);
            if ($recordState['location_deleted_at'] !== null) admin_error('Restore the location before restoring its reports.', 409);
            $restore = $pdo->prepare('UPDATE reports SET deleted_at = NULL, deleted_by = NULL, delete_reason = NULL WHERE id = ? AND deleted_at IS NOT NULL');
            $restore->execute([$id]);
            if ($restore->rowCount() !== 1) admin_error('Report state changed. Refresh and try again.', 409);
            admin_recalculate_location($pdo, $locationId);
            admin_audit((int) $actor['id'], 'report.restore', 'report', $id, 'Restored report.');
            $pdo->commit();
            admin_success(['id' => $id, 'restored' => true]);
        } catch (Throwable $error) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $error; }
    }
    admin_require_permission('reports.permanent_delete');
    if (!admin_setting_enabled('reports.allow_delete')) admin_error('Report deletion is disabled by settings.', 403);
    if (($body['confirm'] ?? '') !== 'DELETE') admin_error('Explicit confirmation is required.', 422, ['confirm' => 'Enter DELETE to permanently remove this report.']);
    admin_rate_limit('reports-permanent-delete', 10, 3600);
    $pdo->beginTransaction();
    try {
        $lock = $pdo->prepare('SELECT id FROM reports WHERE id = ? FOR UPDATE');
        $lock->execute([$id]);
        if (!$lock->fetchColumn()) admin_error('Report not found.', 404);
        $delete = $pdo->prepare('DELETE FROM reports WHERE id = ?');
        $delete->execute([$id]);
        if ($delete->rowCount() !== 1) admin_error('Report state changed. Refresh and try again.', 409);
        admin_recalculate_location($pdo, $locationId);
        admin_audit((int) $actor['id'], 'report.permanent_delete', 'report', $id, 'Permanently deleted report.');
        $pdo->commit();
    } catch (Throwable $error) { if ($pdo->inTransaction()) $pdo->rollBack(); error_log('Madok permanent report deletion failed: ' . $error->getMessage()); admin_error('Unable to permanently delete report.', 500); }
    $relative = (string) ($report['image_path'] ?? '');
    $imagePath = realpath(dirname(__DIR__, 2) . '/' . $relative);
    $uploadRoot = realpath(dirname(__DIR__, 2) . '/uploads/reports');
    if ($imagePath && $uploadRoot && str_starts_with($imagePath, $uploadRoot . DIRECTORY_SEPARATOR) && preg_match('/^[a-f0-9]{32}\.(?:webp|jpg|png)$/i', basename($imagePath))) @unlink($imagePath);
    admin_success(['id' => $id, 'deleted' => true]);
}

if ($method === 'DELETE') {
    $actor = admin_require_permission('reports.delete');
    if (!admin_setting_enabled('reports.allow_delete')) admin_error('Report deletion is disabled by settings.', 403);
    if ($report['deleted_at'] !== null) admin_error('Report is already deleted.', 409);
    admin_rate_limit('reports-delete', 30, 3600);
    $reason = admin_text($body, 'reason', 500);
    $pdo->beginTransaction();
    try {
        $lock = $pdo->prepare('SELECT deleted_at FROM reports WHERE id = ? FOR UPDATE');
        $lock->execute([$id]);
        $deletedAt = $lock->fetchColumn();
        if ($deletedAt === false) admin_error('Report not found.', 404);
        if ($deletedAt !== null) admin_error('Report is already deleted.', 409);
        $delete = $pdo->prepare('UPDATE reports SET deleted_at = NOW(), deleted_by = ?, delete_reason = ? WHERE id = ? AND deleted_at IS NULL');
        $delete->execute([(int) $actor['id'], $reason !== '' ? $reason : null, $id]);
        if ($delete->rowCount() !== 1) admin_error('Report state changed. Refresh and try again.', 409);
        admin_recalculate_location($pdo, $locationId);
        admin_audit((int) $actor['id'], 'report.delete', 'report', $id, 'Soft-deleted report.');
        $pdo->commit();
        admin_success(['id' => $id, 'deleted' => true]);
    } catch (Throwable $error) { if ($pdo->inTransaction()) $pdo->rollBack(); error_log('Madok report deletion failed: ' . $error->getMessage()); admin_error('Unable to delete report.', 500); }
}

admin_error('Method not allowed.', 405);
