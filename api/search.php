<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
request_method('GET');
$q = clean_text($_GET['q'] ?? '', 80);
if ($q === '') {
    json_response(['ok' => true, 'results' => []]);
}

try {
    $pdo = db();
    $like = '%' . $q . '%';
    $results = [];

    $queries = [
        [
            'division',
            "SELECT slug, name, bn_name, latitude, longitude
             FROM divisions
             WHERE name LIKE ? OR bn_name LIKE ?
             ORDER BY name
             LIMIT 8",
        ],
        [
            'district',
            "SELECT CONCAT(dv.slug, '/', d.slug) AS slug, d.name, d.bn_name, d.latitude, d.longitude,
                    dv.slug AS division_slug
             FROM districts d
             JOIN divisions dv ON dv.id = d.division_id
             WHERE d.name LIKE ? OR d.bn_name LIKE ?
             ORDER BY d.name
             LIMIT 10",
        ],
        [
            'upazila',
            "SELECT CONCAT(d.slug, '/', u.slug) AS slug, u.name, u.bn_name, u.latitude, u.longitude
             FROM upazilas u
             JOIN districts d ON d.id = u.district_id
             WHERE u.name LIKE ? OR u.bn_name LIKE ?
             ORDER BY u.name
             LIMIT 12",
        ],
        [
            'police_station',
            "SELECT CAST(ps.id AS CHAR) AS slug, ps.name, ps.name AS bn_name, ps.latitude, ps.longitude,
                    CONCAT(dv.slug, '/', d.slug) AS district_slug
             FROM police_stations ps
             JOIN districts d ON d.id = ps.district_id
             JOIN divisions dv ON dv.id = d.division_id
             WHERE ps.name LIKE ?
             ORDER BY ps.name
             LIMIT 10",
        ],
    ];

    foreach ($queries as $query) {
        [$type, $sql] = $query;
        $stmt = $pdo->prepare($sql);
        if ($type === 'police_station') {
            $stmt->execute([$like]);
        } else {
            $stmt->execute([$like, $like]);
        }

        foreach ($stmt as $row) {
            $lat = isset($row['latitude']) ? (float) $row['latitude'] : null;
            $lng = isset($row['longitude']) ? (float) $row['longitude'] : null;
            if ($lat !== null && (!is_finite($lat) || $lat < -90 || $lat > 90)) {
                $lat = null;
            }
            if ($lng !== null && (!is_finite($lng) || $lng < -180 || $lng > 180)) {
                $lng = null;
            }

            $item = [
                'type' => $type,
                'label' => (string) (($row['bn_name'] ?? '') !== '' ? $row['bn_name'] : $row['name']),
                'secondary' => (string) $row['name'],
                'slug' => (string) $row['slug'],
                'lat' => $lat,
                'lng' => $lng,
            ];
            if (!empty($row['division_slug'])) {
                $item['division_slug'] = (string) $row['division_slug'];
            }
            if (!empty($row['district_slug'])) {
                $item['district_slug'] = (string) $row['district_slug'];
            }
            $results[] = $item;
        }
    }

    json_response(['ok' => true, 'results' => array_slice($results, 0, 35)]);
} catch (Throwable $error) {
    error_log('Madok search error: ' . $error->getMessage());
    json_response(['ok' => false, 'message' => 'Search load করা যায়নি।'], 500);
}
