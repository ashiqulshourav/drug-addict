<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';

request_method('GET');

try {
    $pdo = db();

    /*
     * ---------------------------------------------------------
     * Overall report statistics
     * ---------------------------------------------------------
     */

    $stmt = $pdo->query("
        SELECT
            COUNT(*) AS total_reports,

            COALESCE(
                SUM(report_type = 'use'),
                0
            ) AS use_reports,

            COALESCE(
                SUM(report_type = 'sale'),
                0
            ) AS sale_reports

        FROM reports
    ");

    $overall = $stmt->fetch() ?: [];


    /*
     * ---------------------------------------------------------
     * Location statistics
     * ---------------------------------------------------------
     */

    $stmt = $pdo->query("
        SELECT
            COUNT(*) AS total_locations,

            COALESCE(
                SUM(type = 'use'),
                0
            ) AS use_locations,

            COALESCE(
                SUM(type = 'sale'),
                0
            ) AS sale_locations,

            COALESCE(
                SUM(type = 'both'),
                0
            ) AS both_locations

        FROM locations
    ");

    $locationStats = $stmt->fetch() ?: [];


    /*
     * ---------------------------------------------------------
     * Police station statistics
     * ---------------------------------------------------------
     */

    $stmt = $pdo->query("
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
            ) AS sale,

            COALESCE(
                SUM(
                    CASE
                        WHEN r.report_type = 'use'
                        THEN 1
                        ELSE 0
                    END
                ),
                0
            ) AS use,

            COUNT(r.id) AS total

        FROM police_stations ps

        INNER JOIN districts d
            ON d.id = ps.district_id

        INNER JOIN divisions dv
            ON dv.id = d.division_id

        LEFT JOIN locations l
            ON l.police_station_id = ps.id

        LEFT JOIN reports r
            ON r.location_id = l.id

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
    ");

    $stations = [];

    while ($row = $stmt->fetch()) {

        $stations[] = [
            'id' => (int) $row['id'],

            'station' => (string) $row['station'],

            'district' => (string) $row['district'],

            'division' => (string) $row['division'],

            'division_slug' =>
                (string) $row['division_slug'],

            'sale' => (int) $row['sale'],

            'use' => (int) $row['use'],

            'total' => (int) $row['total']
        ];
    }


    /*
     * ---------------------------------------------------------
     * Final response
     * ---------------------------------------------------------
     */

    json_response([

        'ok' => true,

        'statistics' => [

            'total_reports' =>
                (int) ($overall['total_reports'] ?? 0),

            'use_reports' =>
                (int) ($overall['use_reports'] ?? 0),

            'sale_reports' =>
                (int) ($overall['sale_reports'] ?? 0),

            'total_locations' =>
                (int) ($locationStats['total_locations'] ?? 0),

            'use_locations' =>
                (int) ($locationStats['use_locations'] ?? 0),

            'sale_locations' =>
                (int) ($locationStats['sale_locations'] ?? 0),

            'both_locations' =>
                (int) ($locationStats['both_locations'] ?? 0)
        ],

        'stations' => $stations
    ]);

} catch (Throwable $e) {

    error_log(
        'SafeMap statistics error: ' .
        $e->getMessage()
    );

    json_response([
        'ok' => false,
        'message' => 'Statistics data load করা যায়নি।'
    ], 500);
}