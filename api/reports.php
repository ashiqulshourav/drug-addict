<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
request_method('GET');

$locationId = filter_input(INPUT_GET, 'location', FILTER_VALIDATE_INT);
if (!$locationId || $locationId < 1) {
    json_response(['ok' => false, 'message' => 'Invalid location.'], 422);
}

try {
    $pdo = db();
    $stmt = $pdo->prepare(
        "SELECT
            r.id,
            r.report_type,
            r.title,
            r.description,
            r.image_path,
            r.created_at,
            l.report_count,
            ps.name AS station,
            COALESCE(ud.name, sd.name) AS district,
            u.name AS upazila,
            COALESCE(udv.name, sdv.name) AS division
        FROM reports r
        INNER JOIN locations l ON l.id = r.location_id
        LEFT JOIN police_stations ps ON ps.id = l.police_station_id
        LEFT JOIN upazilas u ON u.id = l.upazila_id
        LEFT JOIN districts sd ON sd.id = ps.district_id
        LEFT JOIN divisions sdv ON sdv.id = sd.division_id
        LEFT JOIN districts ud ON ud.id = u.district_id
        LEFT JOIN divisions udv ON udv.id = ud.division_id
        WHERE r.location_id = ?
        ORDER BY r.created_at DESC, r.id DESC"
    );
    $stmt->execute([$locationId]);
    $reports = [];
    $location = null;

    while ($row = $stmt->fetch()) {
        if ($location === null) {
            $location = [
                'station' => $row['station'] ?? 'থানা নির্ধারণ করা হয়নি',
                'district' => $row['district'],
                'upazila' => $row['upazila'],
                'division' => $row['division'],
                'total' => (int) $row['report_count']
            ];
        }

        $reports[] = [
            'id' => (int) $row['id'],
            'type' => (string) $row['report_type'],
            'title' => (string) $row['title'],
            'description' => $row['description'] !== null ? (string) $row['description'] : '',
            'image' => $row['image_path'] !== null ? (string) $row['image_path'] : null,
            'created_at' => (string) $row['created_at']
        ];
    }

    if ($location === null) {
        json_response(['ok' => false, 'message' => 'Reports not found.'], 404);
    }

    json_response([
        'ok' => true,
        'location' => $location,
        'reports' => $reports
    ]);
} catch (Throwable $e) {
    error_log('SafeMap reports error: ' . $e->getMessage());
    json_response(['ok' => false, 'message' => 'রিপোর্ট data load করা যায়নি।'], 500);
}
