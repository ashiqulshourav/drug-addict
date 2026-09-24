<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
request_method('POST');

if (!verify_csrf((string) ($_POST['csrf_token'] ?? ''))) {
    json_response(['ok' => false, 'message' => 'Security token invalid or expired. Please reload the page.'], 403);
}

$reportWindow = max(60, (int) env_value('REPORT_RATE_LIMIT_WINDOW', '3600'));
$reportMax = max(1, (int) env_value('REPORT_RATE_LIMIT_MAX_REQUESTS', '20'));
if (!rate_limit('report-burst:' . client_ip(), max(1, (int) env_value('REPORT_BURST_MAX_REQUESTS', '3')), max(10, (int) env_value('REPORT_BURST_WINDOW', '60')), true)) {
    json_response(['ok' => false, 'message' => 'অল্প সময়ে অনেকগুলো অনুরোধ হয়েছে। পরে আবার চেষ্টা করুন।'], 429);
}
if (!rate_limit('report:' . client_ip(), $reportMax, $reportWindow, true)) {
    json_response(['ok' => false, 'message' => 'অল্প সময়ের মধ্যে অনেকগুলো রিপোর্ট হয়েছে। কিছুক্ষণ পরে আবার চেষ্টা করুন।'], 429);
}
if (!rate_limit('report:global', max(1, (int) env_value('GLOBAL_REPORT_RATE_LIMIT_MAX_REQUESTS', '200')), max(60, (int) env_value('GLOBAL_REPORT_RATE_LIMIT_WINDOW', '3600')), true)) {
    json_response(['ok' => false, 'message' => 'এই মুহূর্তে অনেকগুলো রিপোর্ট জমা পড়েছে। পরে আবার চেষ্টা করুন।'], 429);
}

if (!verify_turnstile((string) ($_POST['cf-turnstile-response'] ?? ''), 'report')) {
    json_response(['ok' => false, 'message' => 'Security verification failed. Please try again.'], 403);
}

if (!isset($_POST['reportType'], $_POST['title'], $_POST['latitude'], $_POST['longitude'])) {
    json_response(['ok' => false, 'message' => 'Required fields are missing.'], 422);
}

$type = (string)$_POST['reportType'];
if (!in_array($type, ['use', 'sale'], true)) {
    json_response(['ok' => false, 'message' => 'Invalid report type.'], 422);
}

$title = clean_text($_POST['title'], 160);
$description = clean_text($_POST['description'] ?? '', 1000);
$lat = clamp_float($_POST['latitude'], -90, 90);
$lng = clamp_float($_POST['longitude'], -180, 180);

$willing = ($_POST['willingToContact'] ?? 'no') === 'yes';
$contact = $willing ? clean_text($_POST['contactInfo'] ?? '', 190) : null;

if ($title === '') {
    json_response(['ok' => false, 'message' => 'Title is required.'], 422);
}
if ($willing && $contact === '') {
    json_response(['ok' => false, 'message' => 'Contact information is required.'], 422);
}

/* Keep the database check as a second layer for older or shared deployments. */
$pdo = db();
/*
|--------------------------------------------------------------------------
| Find nearest Upazila
|--------------------------------------------------------------------------
*/

$upazilaLatDelta =
    20 / 111.32;

$upazilaCos =
    max(
        0.15,
        cos(
            deg2rad($lat)
        )
    );

$upazilaLngDelta =
    20 /
    (
        111.32 *
        $upazilaCos
    );

$upazilaMinLat =
    max(
        -90,
        $lat -
        $upazilaLatDelta
    );

$upazilaMaxLat =
    min(
        90,
        $lat +
        $upazilaLatDelta
    );

$upazilaMinLng =
    max(
        -180,
        $lng -
        $upazilaLngDelta
    );

$upazilaMaxLng =
    min(
        180,
        $lng +
        $upazilaLngDelta
    );


$upazilaStmt =
    $pdo->prepare(
        "
        SELECT

            id,

            name,

            bn_name,

            district_id,

            latitude,

            longitude,

            (
                6371000 * 2 * ASIN(
                    SQRT(

                        POWER(
                            SIN(
                                RADIANS(
                                    latitude - ?
                                ) / 2
                            ),
                            2
                        )

                        +

                        COS(
                            RADIANS(?)
                        )

                        *

                        COS(
                            RADIANS(
                                latitude
                            )
                        )

                        *

                        POWER(
                            SIN(
                                RADIANS(
                                    longitude - ?
                                ) / 2
                            ),
                            2
                        )
                    )
                )
            ) AS distance_m

        FROM upazilas

        WHERE
            latitude IS NOT NULL

            AND longitude IS NOT NULL

            AND latitude
                BETWEEN ?
                AND ?

            AND longitude
                BETWEEN ?
                AND ?

        ORDER BY
            distance_m ASC

        LIMIT 1
        "
    );


$upazilaStmt->execute([

    $lat,

    $lat,

    $lng,

    $upazilaMinLat,

    $upazilaMaxLat,

    $upazilaMinLng,

    $upazilaMaxLng
]);


$nearestUpazila =
    $upazilaStmt->fetch();


$upazilaId =
    $nearestUpazila
        ? (int)
            $nearestUpazila['id']
        : null;


$hash = ip_hash();
$rate = $pdo->prepare(
    "SELECT COUNT(*) FROM reports
     WHERE ip_hash = ? AND created_at >= (NOW() - INTERVAL 1 HOUR)"
);
$rate->execute([$hash]);
if ((int)$rate->fetchColumn() >= 20) {
    json_response(['ok' => false, 'message' => 'অল্প সময়ের মধ্যে অনেকগুলো রিপোর্ট হয়েছে। কিছুক্ষণ পরে আবার চেষ্টা করুন।'], 429);
}

/* Honeypot: silently accept and ignore bot submissions. */
if (!empty($_POST['website'])) {
    json_response(['ok' => true, 'ignored' => true, 'message' => 'Report received.']);
}

$imagePath = null;

if (isset($_FILES['image']) && (int) ($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
    try {
        $imagePath = store_compressed_report_image($_FILES['image']);
    } catch (RuntimeException $imageError) {
        json_response(['ok' => false, 'message' => $imageError->getMessage()], 422);
    }
}

/*
 * 100m merge:
 * 1) indexed latitude/longitude bounding-box filter
 * 2) exact Haversine distance in SQL
 *
 * This avoids scanning the full locations table for every report.
 */
$latDelta = 10 / 111320;
$cos = max(0.15, cos(deg2rad($lat)));
$lngDelta = 10 / (111320 * $cos);

$minLat = max(-90, $lat - $latDelta);
$maxLat = min(90, $lat + $latDelta);
$minLng = max(-180, $lng - $lngDelta);
$maxLng = min(180, $lng + $lngDelta);

/*
 * ---------------------------------------------------------
 * Automatically find nearest police station
 * ---------------------------------------------------------
 *
 * IMPORTANT:
 * police_stations.latitude / longitude অবশ্যই populated থাকতে হবে।
 *
 * আমরা প্রথমে 20km bounding box দিয়ে candidate কমিয়ে নিচ্ছি।
 * তারপর exact Haversine distance দিয়ে nearest station বের করছি।
 */

$stationLatDelta = 20 / 111.32;

$stationCos = max(
    0.15,
    cos(deg2rad($lat))
);

$stationLngDelta =
    20 / (111.32 * $stationCos);

$stationMinLat = max(
    -90,
    $lat - $stationLatDelta
);

$stationMaxLat = min(
    90,
    $lat + $stationLatDelta
);

$stationMinLng = max(
    -180,
    $lng - $stationLngDelta
);

$stationMaxLng = min(
    180,
    $lng + $stationLngDelta
);

$stationStmt = $pdo->prepare("
    SELECT
        ps.id,

        ps.name,

        ps.latitude,

        ps.longitude,

        (
            6371000 * 2 * ASIN(
                SQRT(
                    POWER(
                        SIN(
                            RADIANS(ps.latitude - ?) / 2
                        ),
                        2
                    )
                    +
                    COS(RADIANS(?))
                    *
                    COS(RADIANS(ps.latitude))
                    *
                    POWER(
                        SIN(
                            RADIANS(ps.longitude - ?) / 2
                        ),
                        2
                    )
                )
            )
        ) AS distance_m

    FROM police_stations ps

    WHERE
        ps.latitude IS NOT NULL
        AND ps.longitude IS NOT NULL

        AND ps.latitude BETWEEN ? AND ?
        AND ps.longitude BETWEEN ? AND ?

    ORDER BY distance_m ASC

    LIMIT 1
");

$stationStmt->execute([
    $lat,
    $lat,
    $lng,

    $stationMinLat,
    $stationMaxLat,

    $stationMinLng,
    $stationMaxLng
]);

$nearestStation = $stationStmt->fetch();

$policeStationId = $nearestStation
    ? (int) $nearestStation['id']
    : null;

$pdo->beginTransaction();

try {
    $sql = "
        SELECT id, latitude, longitude, type, report_count, use_count, sale_count
        FROM locations
        WHERE latitude BETWEEN ? AND ?
          AND longitude BETWEEN ? AND ?
          AND (
            6371000 * 2 * ASIN(
              SQRT(
                POWER(SIN(RADIANS(latitude - ?) / 2), 2) +
                COS(RADIANS(?)) * COS(RADIANS(latitude)) *
                POWER(SIN(RADIANS(longitude - ?) / 2), 2)
              )
            )
          ) <= 100
        ORDER BY
          (
            6371000 * 2 * ASIN(
              SQRT(
                POWER(SIN(RADIANS(latitude - ?) / 2), 2) +
                COS(RADIANS(?)) * COS(RADIANS(latitude)) *
                POWER(SIN(RADIANS(longitude - ?) / 2), 2)
              )
            )
          ) ASC
        LIMIT 1
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        $minLat, $maxLat, $minLng, $maxLng,
        $lat, $lat, $lng,
        $lat, $lat, $lng
    ]);
    $location = $stmt->fetch();

    if ($location) {
        $sameLocation = $pdo->prepare(
            'SELECT COUNT(*) FROM reports WHERE ip_hash = ? AND location_id = ? AND created_at >= (NOW() - INTERVAL 1 HOUR)'
        );
        $sameLocation->execute([$hash, (int) $location['id']]);
        if ((int) $sameLocation->fetchColumn() >= max(1, (int) env_value('REPORT_SAME_LOCATION_MAX_REQUESTS', '3'))) {
            $pdo->rollBack();
            json_response(['ok' => false, 'message' => 'এই লোকেশনে অল্প সময়ে অনেকগুলো রিপোর্ট হয়েছে।'], 429);
        }
        $newType = $location['type'] === $type
            ? $type
            : 'both';

        $useInc = $type === 'use' ? 1 : 0;
        $saleInc = $type === 'sale' ? 1 : 0;

        $update = $pdo->prepare(
            "
            UPDATE locations

            SET

                type = ?,

                report_count =
                    report_count + 1,

                use_count =
                    use_count + ?,

                sale_count =
                    sale_count + ?,

                title = ?,

                upazila_id =
                    COALESCE(
                        ?,
                        upazila_id
                    ),

                police_station_id =
                    COALESCE(
                        ?,
                        police_station_id
                    ),

                updated_at =
                    NOW()

            WHERE id = ?
            "
        );

        $update->execute([
            $newType,
            $useInc,
            $saleInc,
            $title,
            $upazilaId,
            $policeStationId,
            $location['id']
        ]);
        $locationId = (int)$location['id'];
        $merged = true;
    } else {
        $useCount = $type === 'use' ? 1 : 0;
        $saleCount = $type === 'sale' ? 1 : 0;

        $insert = $pdo->prepare(
        "
        INSERT INTO locations
        (
            latitude,
            longitude,
            title,
            type,
            police_station_id,
            upazila_id,
            report_count,
            use_count,
            sale_count
        )

        VALUES
        (
            ?,
            ?,
            ?,
            ?,
            ?,
            ?,
            1,
            ?,
            ?
        )
        "
    );

        $insert->execute([
            $lat,
            $lng,
            $title,
            $type,
            $policeStationId,
            $upazilaId,
            $useCount,
            $saleCount
        ]);
        $locationId = (int)$pdo->lastInsertId();
        $merged = false;
    }

    $report = $pdo->prepare(
        "INSERT INTO reports
         (location_id, report_type, title, description, latitude, longitude,
          image_path, willing_to_contact, contact_info, ip_hash, user_agent)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $report->execute([
        $locationId, $type, $title, $description ?: null, $lat, $lng,
        $imagePath, $willing ? 1 : 0, $contact ?: null, $hash,
        clean_text($_SERVER['HTTP_USER_AGENT'] ?? '', 255)
    ]);

    $reportId = (int)$pdo->lastInsertId();
    $pdo->commit();
    start_secure_session();
    $_SESSION['owned_reports'][(string) $reportId] = true;

    json_response([
        'ok' => true,
        'message' => 'রিপোর্ট সফলভাবে গ্রহণ করা হয়েছে।',
        'report_id' => $reportId,
        'location_id' => $locationId,
        'can_edit' => true,
        'merged_with_existing_location' => $merged
    ]);
} catch (Throwable $e) {
    $pdo->rollBack();
    if ($imagePath) {
        @unlink(dirname(__DIR__) . '/' . $imagePath);
    }
    error_log('Madok report error: ' . $e->getMessage());
    json_response(['ok' => false, 'message' => 'রিপোর্ট save করা যায়নি।'], 500);
}


$stationStmt = $pdo->prepare(
    "SELECT
        id,
        name,
        latitude,
        longitude
    FROM police_stations
    WHERE latitude IS NOT NULL
      AND longitude IS NOT NULL
      AND (
        6371000 * 2 * ASIN(
            SQRT(
                POWER(
                    SIN(
                        RADIANS(latitude - ?) / 2
                    ),
                    2
                )
                +
                COS(RADIANS(?))
                *
                COS(RADIANS(latitude))
                *
                POWER(
                    SIN(
                        RADIANS(longitude - ?) / 2
                    ),
                    2
                )
            )
        )
    ) <= 10000
    ORDER BY (
        6371000 * 2 * ASIN(
            SQRT(
                POWER(
                    SIN(
                        RADIANS(latitude - ?) / 2
                    ),
                    2
                )
                +
                COS(RADIANS(?))
                *
                COS(RADIANS(latitude))
                *
                POWER(
                    SIN(
                        RADIANS(longitude - ?) / 2
                    ),
                    2
                )
            )
        )
    )
    LIMIT 1
");

$stationStmt->execute([
    $lat,
    $lat,
    $lng,

    $lat,
    $lat,
    $lng
]);

$station = $stationStmt->fetch();

$policeStationId = $station ? (int) $station['id'] : null;


$insert = $pdo->prepare(
    "INSERT INTO locations
    (
        latitude,
        longitude,
        title,
        type,
        police_station_id,
        upazila_id,
        report_count,
        use_count,
        sale_count
    )

    VALUES
    (
        ?,
        ?,
        ?,
        ?,
        ?,
        ?,
        1,
        ?,
        ?
    )
    "
);

$insert->execute([
    $lat,
    $lng,
    $title,
    $type,
    $policeStationId,
    $upazilaId,
    $useCount,
    $saleCount
]);