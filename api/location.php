<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
request_method('GET');
$type = (string) ($_GET['type'] ?? '');
$slug = clean_text($_GET['slug'] ?? '', 180);
if (!in_array($type, ['division', 'district', 'upazila'], true) || !preg_match('/^[a-z0-9\/-]{1,180}$/i', $slug)) {
    json_response(['ok' => false, 'message' => 'Invalid location.'], 422);
}

try {
    $pdo = db();
    $where = match ($type) {
        'division' => 'dv.slug = ?',
        'district' => 'd.slug = ?',
        'upazila' => 'CONCAT(d.slug, \'/\', u.slug) = ?',
    };
    $stmt = $pdo->prepare("SELECT
        COALESCE(NULLIF(dv.bn_name, ''), dv.name) AS division,
        COALESCE(NULLIF(d.bn_name, ''), d.name) AS district,
        COALESCE(NULLIF(u.bn_name, ''), u.name) AS upazila,
        COUNT(DISTINCT r.id) AS reports,
        COUNT(DISTINCT l.id) AS locations,
        COUNT(DISTINCT d.id) AS districts,
        COUNT(DISTINCT COALESCE(u.id, ps.id)) AS stations
        FROM divisions dv
        LEFT JOIN districts d ON d.division_id = dv.id
        LEFT JOIN upazilas u ON u.district_id = d.id
        LEFT JOIN police_stations ps ON ps.district_id = d.id
        LEFT JOIN locations l ON l.upazila_id = u.id OR l.police_station_id = ps.id
        LEFT JOIN reports r ON r.location_id = l.id
        WHERE {$where}");
    $stmt->execute([$slug]);
    $stats = $stmt->fetch();
    if (!$stats || ($stats['division'] ?? '') === '') json_response(['ok' => false, 'message' => 'Location not found.'], 404);

    $map = $pdo->prepare("SELECT DISTINCT
            l.id,
            l.latitude AS lat,
            l.longitude AS lng,
            l.title,
            l.type,
            l.report_count AS reports,
            (
                SELECT r.description
                FROM reports r
                WHERE r.location_id = l.id
                ORDER BY r.created_at DESC, r.id DESC
                LIMIT 1
            ) AS latest_description,
            ps.name AS police_station,
            COALESCE(NULLIF(d.bn_name, ''), d.name) AS district,
            COALESCE(NULLIF(u.bn_name, ''), u.name) AS upazila,
            COALESCE(NULLIF(dv.bn_name, ''), dv.name) AS division
        FROM locations l
        LEFT JOIN upazilas u ON u.id = l.upazila_id
        LEFT JOIN districts d ON d.id = u.district_id
        LEFT JOIN divisions dv ON dv.id = d.division_id
        LEFT JOIN police_stations ps ON ps.id = l.police_station_id
        WHERE {$where} AND l.latitude IS NOT NULL AND l.longitude IS NOT NULL
        ORDER BY l.updated_at DESC LIMIT 500");
    $map->execute([$slug]);
    $locations = array_map(static fn(array $row): array => [
        'id' => (int) $row['id'],
        'lat' => (float) $row['lat'],
        'lng' => (float) $row['lng'],
        'title' => (string) $row['title'],
        'description' => $row['latest_description'] !== null ? (string) $row['latest_description'] : null,
        'type' => (string) $row['type'],
        'reports' => (int) $row['reports'],
        'station' => $row['police_station'] !== null
            ? (string) $row['police_station']
            : ($row['upazila'] !== null ? (string) $row['upazila'] : 'থানা / উপজেলা নির্ধারণ করা হয়নি'),
        'district' => $row['district'] !== null ? (string) $row['district'] : null,
        'upazila' => $row['upazila'] !== null ? (string) $row['upazila'] : null,
        'division' => $row['division'] !== null ? (string) $row['division'] : null,
    ], $map->fetchAll());
    json_response(['ok' => true, 'type' => $type, 'slug' => $slug, 'location' => [
        'division' => $stats['division'], 'district' => $stats['district'], 'upazila' => $stats['upazila'],
        'reports' => (int) $stats['reports'], 'locations' => (int) $stats['locations'],
        'districts' => (int) $stats['districts'], 'stations' => (int) $stats['stations'],
    ], 'locations' => $locations]);
} catch (Throwable $error) {
    error_log('Madok location error: ' . $error->getMessage());
    json_response(['ok' => false, 'message' => 'Location data load করা যায়নি।'], 500);
}
