<?php

declare(strict_types=1);

require_once __DIR__ . '/_common.php';

request_method('GET');

try {

    $pdo = db();

    /*
    |--------------------------------------------------------------------------
    | Division filter
    |--------------------------------------------------------------------------
    */

    $division = trim(
        (string) ($_GET['division'] ?? 'all')
    );

    $allowedDivisions = [
        'all',
        'dhaka',
        'chattogram',
        'rajshahi',
        'khulna',
        'barishal',
        'sylhet',
        'rangpur',
        'mymensingh'
    ];

    if (!in_array($division, $allowedDivisions, true)) {
        $division = 'all';
    }


    /*
    |--------------------------------------------------------------------------
    | Division WHERE
    |--------------------------------------------------------------------------
    */

    $divisionWhere = '';
    $params = [];

    if ($division !== 'all') {

        $divisionWhere = "
            WHERE dv.slug = ?
        ";

        $params[] = $division;
    }


    /*
    |--------------------------------------------------------------------------
    | REPORT STATISTICS
    |--------------------------------------------------------------------------
    */

    $reportSql = "
        SELECT

            COUNT(r.id) AS total_reports,

            COALESCE(
                SUM(
                    CASE
                        WHEN r.report_type = 'use'
                        THEN 1
                        ELSE 0
                    END
                ),
                0
            ) AS use_reports,

            COALESCE(
                SUM(
                    CASE
                        WHEN r.report_type = 'sale'
                        THEN 1
                        ELSE 0
                    END
                ),
                0
            ) AS sale_reports

        FROM reports r

        INNER JOIN locations l
            ON l.id = r.location_id

        LEFT JOIN upazilas u
            ON u.id = l.upazila_id

        LEFT JOIN districts d
            ON d.id = u.district_id

        LEFT JOIN divisions dv
            ON dv.id = d.division_id

        $divisionWhere
    ";

    $stmt = $pdo->prepare($reportSql);
    $stmt->execute($params);

    $reportStats = $stmt->fetch() ?: [];


    /*
    |--------------------------------------------------------------------------
    | LOCATION STATISTICS
    |--------------------------------------------------------------------------
    */

    $locationSql = "
        SELECT

            COUNT(l.id) AS total_locations,

            COALESCE(
                SUM(
                    CASE
                        WHEN l.sale_count > 0
                        THEN 1
                        ELSE 0
                    END
                ),
                0
            ) AS sale_locations,

            COALESCE(
                SUM(
                    CASE
                        WHEN l.use_count > 0
                        THEN 1
                        ELSE 0
                    END
                ),
                0
            ) AS use_locations,

            COALESCE(
                SUM(
                    CASE
                        WHEN l.type = 'both'
                        THEN 1
                        ELSE 0
                    END
                ),
                0
            ) AS both_locations

        FROM locations l

        LEFT JOIN upazilas u
            ON u.id = l.upazila_id

        LEFT JOIN districts d
            ON d.id = u.district_id

        LEFT JOIN divisions dv
            ON dv.id = d.division_id

        $divisionWhere
    ";

    $stmt = $pdo->prepare($locationSql);
    $stmt->execute($params);

    $locationStats = $stmt->fetch() ?: [];


    /*
    |--------------------------------------------------------------------------
    | ALL UPAZILAS
    |
    | Important:
    |
    | division = all
    |     -> ALL upazilas from ALL divisions
    |
    | division = dhaka
    |     -> ALL upazilas belonging to Dhaka division
    |
    | No police_stations table is used here.
    |--------------------------------------------------------------------------
    */

    $stationSql = "
        SELECT

            u.id,

            COALESCE(
                NULLIF(u.bn_name, ''),
                u.name
            ) AS station,

            d.id AS district_id,

            COALESCE(NULLIF(d.bn_name, ''), d.name) AS district,

            d.slug AS district_slug,

            dv.id AS division_id,

            COALESCE(NULLIF(dv.bn_name, ''), dv.name) AS division,

            dv.slug AS division_slug,

            COALESCE(
                SUM(
                    CASE
                        WHEN r.report_type = 'sale'
                        THEN 1
                        ELSE 0
                    END
                ),
                0
            ) AS sale_count,

            COALESCE(
                SUM(
                    CASE
                        WHEN r.report_type = 'use'
                        THEN 1
                        ELSE 0
                    END
                ),
                0
            ) AS use_count,

            COUNT(r.id) AS total_count

        FROM upazilas u

        INNER JOIN districts d
            ON d.id = u.district_id

        INNER JOIN divisions dv
            ON dv.id = d.division_id

        LEFT JOIN locations l
            ON l.upazila_id = u.id

        LEFT JOIN reports r
            ON r.location_id = l.id
    ";

    if ($division !== 'all') {

        $stationSql .= "
            WHERE dv.slug = ?
        ";
    }

    $stationSql .= "

        GROUP BY

            u.id,
            u.name,
            u.bn_name,
            d.id,
            d.name,
            d.slug,
            dv.id,
            dv.name,
            dv.slug

        ORDER BY

            dv.name ASC,
            d.name ASC,
            u.name ASC

    ";


    $stmt = $pdo->prepare($stationSql);

    if ($division !== 'all') {

        $stmt->execute([
            $division
        ]);

    } else {

        $stmt->execute();
    }


    /*
    |--------------------------------------------------------------------------
    | Build station array
    |--------------------------------------------------------------------------
    |
    | Frontend can continue using the existing "station" property.
    | Here "station" actually represents the Upazila.
    |--------------------------------------------------------------------------
    */

    $stations = [];

    while ($row = $stmt->fetch()) {

        $stations[] = [

            'id' =>
                (int) $row['id'],

            'station' =>
                (string) $row['station'],

            'district_id' =>
                (int) $row['district_id'],

            'district' =>
                (string) $row['district'],

            'district_slug' =>
                (string) $row['district_slug'],

            'division_id' =>
                (int) $row['division_id'],

            'division' =>
                (string) $row['division'],

            'division_slug' =>
                (string) $row['division_slug'],

            'sale' =>
                (int) $row['sale_count'],

            'use' =>
                (int) $row['use_count'],

            'total' =>
                (int) $row['total_count']
        ];
    }


    /*
    |--------------------------------------------------------------------------
    | Upazila count
    |--------------------------------------------------------------------------
    */

    $stationCountSql = "
        SELECT COUNT(DISTINCT l.upazila_id)

        FROM upazilas u

        INNER JOIN districts d
            ON d.id = u.district_id

        INNER JOIN divisions dv
            ON dv.id = d.division_id

        INNER JOIN locations l
            ON l.upazila_id = u.id
    ";

    if ($division !== 'all') {

        $stationCountSql .= "
            WHERE dv.slug = ?
        ";
    }

    $stmt = $pdo->prepare($stationCountSql);

    if ($division !== 'all') {

        $stmt->execute([
            $division
        ]);

    } else {

        $stmt->execute();
    }

    $totalStations =
        (int) $stmt->fetchColumn();

    $rankedLocationWhere = $division === 'all'
        ? ''
        : 'WHERE COALESCE(udv.slug, sdv.slug) = ?';

    $rankedLocationSql = "
        SELECT
            l.id,
            l.title,
            l.type,
            l.report_count,
            l.updated_at,
            l.latitude AS lat,
            l.longitude AS lng,
            (
                SELECT r.description
                FROM reports r
                WHERE r.location_id = l.id
                ORDER BY r.created_at DESC, r.id DESC
                LIMIT 1
            ) AS description,
            COALESCE(ps.name, NULLIF(u.bn_name, ''), u.name) AS station,
            COALESCE(NULLIF(ud.bn_name, ''), NULLIF(sd.bn_name, ''), ud.name, sd.name) AS district,
            COALESCE(NULLIF(udv.bn_name, ''), NULLIF(sdv.bn_name, ''), udv.name, sdv.name) AS division
        FROM locations l
        LEFT JOIN police_stations ps ON ps.id = l.police_station_id
        LEFT JOIN upazilas u ON u.id = l.upazila_id
        LEFT JOIN districts sd ON sd.id = ps.district_id
        LEFT JOIN divisions sdv ON sdv.id = sd.division_id
        LEFT JOIN districts ud ON ud.id = u.district_id
        LEFT JOIN divisions udv ON udv.id = ud.division_id
        $rankedLocationWhere
        ORDER BY %s
        LIMIT %d
    ";

    $readRankedLocations = static function (string $order, int $limit) use ($pdo, $rankedLocationSql, $division): array {
        $stmt = $pdo->prepare(sprintf($rankedLocationSql, $order, $limit));
        $stmt->execute($division === 'all' ? [] : [$division]);
        $items = [];

        while ($row = $stmt->fetch()) {
            $items[] = [
                'id' => (int) $row['id'],
                'title' => (string) $row['title'],
                'type' => (string) $row['type'],
                'reports' => (int) $row['report_count'],
                'updated_at' => (string) $row['updated_at'],
                'lat' => (float) $row['lat'],
                'lng' => (float) $row['lng'],
                'description' => $row['description'] !== null ? (string) $row['description'] : null,
                'station' => $row['station'] !== null ? (string) $row['station'] : 'থানা / উপজেলা নির্ধারণ করা হয়নি',
                'district' => $row['district'] !== null ? (string) $row['district'] : null,
                'division' => $row['division'] !== null ? (string) $row['division'] : null
            ];
        }

        return $items;
    };

    $lastReportedLocations = $readRankedLocations('l.updated_at DESC, l.id DESC', 5);
    $mostReportedLocations = $readRankedLocations('l.report_count DESC, l.updated_at DESC, l.id DESC', 5);


    /*
    |--------------------------------------------------------------------------
    | Response
    |--------------------------------------------------------------------------
    */

    json_response([

        'ok' => true,

        'filter' => [

            'division' =>
                $division
        ],

        'statistics' => [

            'total_reports' =>
                (int) (
                    $reportStats['total_reports']
                    ?? 0
                ),

            'use_reports' =>
                (int) (
                    $reportStats['use_reports']
                    ?? 0
                ),

            'sale_reports' =>
                (int) (
                    $reportStats['sale_reports']
                    ?? 0
                ),

            'total_locations' =>
                (int) (
                    $locationStats['total_locations']
                    ?? 0
                ),

            'use_locations' =>
                (int) (
                    $locationStats['use_locations']
                    ?? 0
                ),

            'sale_locations' =>
                (int) (
                    $locationStats['sale_locations']
                    ?? 0
                ),

            'both_locations' =>
                (int) (
                    $locationStats['both_locations']
                    ?? 0
                ),

            'total_stations' =>
                $totalStations
        ],

        'stations' =>
            $stations,

        'last_reported_locations' =>
            $lastReportedLocations,

        'most_reported_locations' =>
            $mostReportedLocations
    ]);

} catch (Throwable $e) {

    error_log(
        'Madok statistics error: '
        . $e->getMessage()
    );

    json_response([

        'ok' => false,

        'message' =>
            'Statistics data load করা যায়নি।',

        'debug' =>
            $e->getMessage()

    ], 500);
}