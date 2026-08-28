<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
request_method('GET');

$pdo = db();

$stats = $pdo->query(
    "SELECT
       COUNT(*) AS total_reports,
       COUNT(DISTINCT location_id) AS total_locations,
       COUNT(DISTINCT CASE WHEN report_type='sale' THEN location_id END) AS sale_locations,
       COUNT(DISTINCT CASE WHEN report_type='use' THEN location_id END) AS use_locations
     FROM reports"
)->fetch();

$stations = $pdo->query(
    "SELECT
       ps.id,
       ps.name AS station,
       d.name AS district,
       COALESCE(SUM(CASE WHEN r.report_type='sale' THEN 1 ELSE 0 END),0) AS sale,
       COALESCE(SUM(CASE WHEN r.report_type='use' THEN 1 ELSE 0 END),0) AS use_count
     FROM police_stations ps
     JOIN districts d ON d.id = ps.district_id
     LEFT JOIN locations l ON l.police_station_id = ps.id
     LEFT JOIN reports r ON r.location_id = l.id
     GROUP BY ps.id, ps.name, d.name
     ORDER BY d.name, ps.name"
)->fetchAll();

json_response([
    'ok' => true,
    'stats' => [
        'totalReports' => (int)$stats['total_reports'],
        'totalLocations' => (int)$stats['total_locations'],
        'saleLocations' => (int)$stats['sale_locations'],
        'useLocations' => (int)$stats['use_locations'],
    ],
    'stations' => array_map(static function(array $row): array {
        return [
            'station' => $row['station'],
            'district' => $row['district'],
            'sale' => (int)$row['sale'],
            'use' => (int)$row['use_count'],
        ];
    }, $stations),
]);
