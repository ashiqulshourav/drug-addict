<?php

declare(strict_types=1);

require_once __DIR__ . '/_common.php';

request_method('GET');

try {

    $pdo = db();

    /*
     * ---------------------------------------------------------
     * Division filter
     * ---------------------------------------------------------
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
     * ---------------------------------------------------------
     * WHERE clause
     * ---------------------------------------------------------
     */

    $divisionWhere = '';
    $divisionParams = [];

    if ($division !== 'all') {

        $divisionWhere = "
            WHERE dv.slug = ?
        ";

        $divisionParams[] = $division;
    }


    /*
     * ---------------------------------------------------------
     * Report statistics
     * ---------------------------------------------------------
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

        LEFT JOIN police_stations ps
            ON ps.id = l.police_station_id

        LEFT JOIN districts d
            ON d.id = ps.district_id

        LEFT JOIN divisions dv
            ON dv.id = d.division_id

        $divisionWhere
    ";

    $stmt = $pdo->prepare($reportSql);

    $stmt->execute(
        $divisionParams
    );

    $overall =
        $stmt->fetch()
        ?: [];


    /*
     * ---------------------------------------------------------
     * Location statistics
     * ---------------------------------------------------------
     */

    $locationSql = "
        SELECT

            COUNT(l.id) AS total_locations,

            COALESCE(
                SUM(l.sale_count > 0),
                0
            ) AS sale_locations,

            COALESCE(
                SUM(l.use_count > 0),
                0
            ) AS use_locations,

            COALESCE(
                SUM(l.type = 'both'),
                0
            ) AS both_locations

        FROM locations l

        LEFT JOIN police_stations ps
            ON ps.id = l.police_station_id

        LEFT JOIN districts d
            ON d.id = ps.district_id

        LEFT JOIN divisions dv
            ON dv.id = d.division_id

        $divisionWhere
    ";

    $stmt = $pdo->prepare($locationSql);

    $stmt->execute(
        $divisionParams
    );

    $locationStats =
        $stmt->fetch()
        ?: [];


    /*
     * ---------------------------------------------------------
     * Police station statistics
     *
     * IMPORTANT:
     * LEFT JOIN means stations with zero reports
     * will also appear.
     * ---------------------------------------------------------
     */

    $stationSql = "
        SELECT

            ps.id,

            ps.name AS station,

            d.name AS district,

            dv.name AS division,

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

        FROM police_stations ps

        INNER JOIN districts d
            ON d.id = ps.district_id

        INNER JOIN divisions dv
            ON dv.id = d.division_id

        LEFT JOIN locations l
            ON l.police_station_id = ps.id

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
            ps.id,
            ps.name,
            d.name,
            dv.name,
            dv.slug

        ORDER BY
            dv.name ASC,
            d.name ASC,
            ps.name ASC
    ";


    $stmt = $pdo->prepare(
        $stationSql
    );

    if ($division !== 'all') {

        $stmt->execute([
            $division
        ]);

    } else {

        $stmt->execute();
    }


    $stations = [];

    while ($row = $stmt->fetch()) {

        $stations[] = [

            'id' =>
                (int) $row['id'],

            'station' =>
                (string) $row['station'],

            'district' =>
                (string) $row['district'],

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
     * ---------------------------------------------------------
     * Number of police stations
     * ---------------------------------------------------------
     */

    $stationCountSql = "
        SELECT COUNT(*)

        FROM police_stations ps

        INNER JOIN districts d
            ON d.id = ps.district_id

        INNER JOIN divisions dv
            ON dv.id = d.division_id
    ";

    if ($division !== 'all') {

        $stationCountSql .= "
            WHERE dv.slug = ?
        ";
    }

    $stmt = $pdo->prepare(
        $stationCountSql
    );

    if ($division !== 'all') {

        $stmt->execute([
            $division
        ]);

    } else {

        $stmt->execute();
    }

    $totalStations =
        (int) $stmt->fetchColumn();


    /*
     * ---------------------------------------------------------
     * Final response
     * ---------------------------------------------------------
     */

    json_response([

        'ok' => true,

        'filter' => [
            'division' => $division
        ],

        'statistics' => [

            'total_reports' =>
                (int) (
                    $overall['total_reports']
                    ?? 0
                ),

            'use_reports' =>
                (int) (
                    $overall['use_reports']
                    ?? 0
                ),

            'sale_reports' =>
                (int) (
                    $overall['sale_reports']
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
            $stations
    ]);

} catch (Throwable $e) {

    error_log(
        'SafeMap statistics error: '
        . $e->getMessage()
    );

    json_response([

        'ok' => false,

        'message' =>
            'Statistics data load করা যায়নি।'

    ], 500);
}