<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
admin_require_method('GET');
admin_require_permission('statistics.view');
$pdo = db();
$reportTypes = $pdo->query(
    "SELECT r.report_type AS type, COUNT(*) AS total
     FROM reports r INNER JOIN locations l ON l.id = r.location_id
     WHERE r.deleted_at IS NULL AND l.deleted_at IS NULL
     GROUP BY r.report_type ORDER BY r.report_type"
)->fetchAll();
$reportsByDay = $pdo->query(
    "SELECT DATE(r.created_at) AS date, COUNT(*) AS total
     FROM reports r INNER JOIN locations l ON l.id = r.location_id
     WHERE r.deleted_at IS NULL AND l.deleted_at IS NULL
       AND r.created_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
     GROUP BY DATE(r.created_at) ORDER BY date"
)->fetchAll();
$reportsByDivision = $pdo->query(
    "SELECT COALESCE(udv.name, sdv.name, 'Unknown') AS division, COUNT(r.id) AS total
     FROM reports r INNER JOIN locations l ON l.id = r.location_id
     LEFT JOIN upazilas u ON u.id = l.upazila_id
    LEFT JOIN police_stations ps ON ps.id = l.police_station_id
    LEFT JOIN districts ud ON ud.id = u.district_id
    LEFT JOIN divisions udv ON udv.id = ud.division_id
    LEFT JOIN districts sd ON sd.id = ps.district_id
    LEFT JOIN divisions sdv ON sdv.id = sd.division_id
     WHERE r.deleted_at IS NULL AND l.deleted_at IS NULL
    GROUP BY COALESCE(udv.id, sdv.id), COALESCE(udv.name, sdv.name)
    ORDER BY total DESC, division LIMIT 20"
)->fetchAll();
$reportsByDistrict = $pdo->query(
    "SELECT COALESCE(ud.name, sd.name, 'Unknown') AS district,
            COALESCE(udv.name, sdv.name, 'Unknown') AS division, COUNT(r.id) AS total
     FROM reports r INNER JOIN locations l ON l.id = r.location_id
     LEFT JOIN upazilas u ON u.id = l.upazila_id
    LEFT JOIN police_stations ps ON ps.id = l.police_station_id
    LEFT JOIN districts ud ON ud.id = u.district_id
    LEFT JOIN divisions udv ON udv.id = ud.division_id
    LEFT JOIN districts sd ON sd.id = ps.district_id
    LEFT JOIN divisions sdv ON sdv.id = sd.division_id
     WHERE r.deleted_at IS NULL AND l.deleted_at IS NULL
    GROUP BY COALESCE(ud.id, sd.id), COALESCE(ud.name, sd.name), COALESCE(udv.name, sdv.name)
    ORDER BY total DESC, division, district LIMIT 30"
)->fetchAll();
$reportsByUpazila = $pdo->query(
    "SELECT COALESCE(u.name, 'Unknown') AS upazila,
            COALESCE(d.name, 'Unknown') AS district, COUNT(r.id) AS total
     FROM reports r INNER JOIN locations l ON l.id = r.location_id
     LEFT JOIN upazilas u ON u.id = l.upazila_id
    LEFT JOIN police_stations ps ON ps.id = l.police_station_id
    LEFT JOIN districts d ON d.id = COALESCE(u.district_id, ps.district_id)
     WHERE r.deleted_at IS NULL AND l.deleted_at IS NULL
    GROUP BY u.id, u.name, d.name ORDER BY total DESC, district, upazila LIMIT 30"
)->fetchAll();
$locationsByType = $pdo->query(
    "SELECT type, COUNT(*) AS total FROM locations WHERE deleted_at IS NULL AND report_count > 0 GROUP BY type ORDER BY type"
)->fetchAll();
foreach ([$reportTypes, $reportsByDay, $reportsByDivision, $reportsByDistrict, $reportsByUpazila, $locationsByType] as &$rows) {
    foreach ($rows as &$row) $row['total'] = (int) $row['total'];
    unset($row);
}
unset($rows);
admin_success([
    'reports_by_type' => $reportTypes,
    'reports_by_day' => $reportsByDay,
    'reports_by_division' => $reportsByDivision,
    'reports_by_district' => $reportsByDistrict,
    'reports_by_upazila' => $reportsByUpazila,
    'locations_by_type' => $locationsByType,
]);
