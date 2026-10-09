<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
admin_require_method('GET');
admin_require_permission('dashboard.view');
$pdo = db();
$stats = $pdo->query(
    "SELECT
        (SELECT COUNT(*) FROM reports r INNER JOIN locations l ON l.id = r.location_id WHERE r.deleted_at IS NULL AND l.deleted_at IS NULL) AS total_reports,
        (SELECT COUNT(*) FROM reports r INNER JOIN locations l ON l.id = r.location_id WHERE r.deleted_at IS NULL AND l.deleted_at IS NULL AND r.created_at >= CURDATE()) AS reports_today,
        (SELECT COUNT(*) FROM reports r INNER JOIN locations l ON l.id = r.location_id WHERE r.deleted_at IS NULL AND l.deleted_at IS NULL AND r.created_at >= DATE_SUB(CURDATE(), INTERVAL WEEKDAY(CURDATE()) DAY)) AS reports_this_week,
        (SELECT COUNT(*) FROM reports r INNER JOIN locations l ON l.id = r.location_id WHERE r.deleted_at IS NULL AND l.deleted_at IS NULL AND r.created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')) AS reports_this_month,
        (SELECT COUNT(*) FROM locations WHERE deleted_at IS NULL) AS total_locations,
        (SELECT COUNT(*) FROM locations WHERE deleted_at IS NULL AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) AS recent_locations,
        (SELECT COUNT(*) FROM reports WHERE deleted_at IS NOT NULL) AS deleted_reports,
        (SELECT COUNT(*) FROM locations WHERE deleted_at IS NOT NULL) AS deleted_locations,
        (SELECT COUNT(*) FROM admin_users) AS admin_users,
        (SELECT COUNT(*) FROM admin_users WHERE status = 'active') AS active_admin_users"
)->fetch();
$activity = $pdo->query(
    'SELECT a.id, a.admin_user_id, u.name AS admin_name, a.action, a.entity_type, a.entity_id, a.description, a.created_at
     FROM admin_audit_logs a LEFT JOIN admin_users u ON u.id = a.admin_user_id
     ORDER BY a.created_at DESC, a.id DESC LIMIT 10'
)->fetchAll();
foreach (['total_reports','reports_today','reports_this_week','reports_this_month','total_locations','recent_locations','deleted_reports','deleted_locations','admin_users','active_admin_users'] as $key) {
    $stats[$key] = (int) ($stats[$key] ?? 0);
}
admin_success(['summary' => $stats, 'recent_activity' => $activity]);
