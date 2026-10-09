<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
admin_require_method('GET');
admin_require_permission('audit.view');
$pdo = db();
$page = admin_pagination();
$where = [];
$params = [];
$search = clean_text($_GET['search'] ?? '', 120);
$action = clean_text($_GET['action'] ?? '', 100);
$entity = clean_text($_GET['entity'] ?? '', 80);
$adminId = filter_var($_GET['admin_user_id'] ?? null, FILTER_VALIDATE_INT);
$from = clean_text($_GET['from'] ?? '', 10);
$to = clean_text($_GET['to'] ?? '', 10);
if ($search !== '') { $where[] = '(a.description LIKE ? OR a.action LIKE ? OR u.name LIKE ? OR u.email LIKE ?)'; array_push($params, ...array_fill(0, 4, '%' . $search . '%')); }
if ($action !== '') { $where[] = 'a.action = ?'; $params[] = $action; }
if ($entity !== '') { $where[] = 'a.entity_type = ?'; $params[] = $entity; }
if ($adminId && $adminId > 0) { $where[] = 'a.admin_user_id = ?'; $params[] = (int) $adminId; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) { $where[] = 'a.created_at >= ?'; $params[] = $from . ' 00:00:00'; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) { $where[] = 'a.created_at < DATE_ADD(?, INTERVAL 1 DAY)'; $params[] = $to; }
$clause = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$count = $pdo->prepare("SELECT COUNT(*) FROM admin_audit_logs a LEFT JOIN admin_users u ON u.id = a.admin_user_id {$clause}");
$count->execute($params);
$total = (int) $count->fetchColumn();
$stmt = $pdo->prepare(
    "SELECT a.id, a.admin_user_id, u.name AS admin_name, a.action, a.entity_type, a.entity_id,
            a.description, a.ip_hash, a.user_agent, a.created_at
     FROM admin_audit_logs a LEFT JOIN admin_users u ON u.id = a.admin_user_id
     {$clause} ORDER BY a.created_at DESC, a.id DESC LIMIT {$page['per_page']} OFFSET {$page['offset']}"
);
$stmt->execute($params);
admin_success(admin_paged_data($stmt->fetchAll(), $page['page'], $page['per_page'], $total));
