<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';

request_method('GET');

try {
    $pdo = db();

    $sql = "
        SELECT
            l.id,
            l.latitude,
            l.longitude,
            l.title,
            l.type,
            l.report_count,
            l.use_count,
            l.sale_count,

            ps.id AS police_station_id,
            ps.name AS police_station,

            d.id AS district_id,
            d.name AS district,

            dv.id AS division_id,
            dv.name AS division,
            dv.slug AS division_slug

        FROM locations l

        LEFT JOIN police_stations ps
            ON ps.id = l.police_station_id

        LEFT JOIN districts d
            ON d.id = ps.district_id

        LEFT JOIN divisions dv
            ON dv.id = d.division_id

        ORDER BY l.updated_at DESC, l.id DESC
    ";

    $stmt = $pdo->query($sql);

    $locations = [];

    while ($row = $stmt->fetch()) {
        $locations[] = [
            'id' => (int) $row['id'],

            'lat' => (float) $row['latitude'],
            'lng' => (float) $row['longitude'],

            'title' => (string) $row['title'],
            'type' => (string) $row['type'],

            'reports' => (int) $row['report_count'],
            'use_count' => (int) $row['use_count'],
            'sale_count' => (int) $row['sale_count'],

            'station' => $row['police_station']
                ? (string) $row['police_station']
                : 'থানা নির্ধারণ করা হয়নি',

            'district' => $row['district']
                ? (string) $row['district']
                : null,

            'division' => $row['division']
                ? (string) $row['division']
                : null,

            'division_slug' => $row['division_slug']
                ? (string) $row['division_slug']
                : null,

            'police_station_id' => $row['police_station_id'] !== null
                ? (int) $row['police_station_id']
                : null
        ];
    }

    json_response([
        'ok' => true,
        'count' => count($locations),
        'locations' => $locations
    ]);

} catch (Throwable $e) {

    error_log(
        'SafeMap locations error: ' .
        $e->getMessage()
    );

    json_response([
        'ok' => false,
        'message' => 'লোকেশন data load করা যায়নি।'
    ], 500);
}