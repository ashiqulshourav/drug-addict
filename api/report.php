<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
request_method('POST');

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

/* Small abuse guard: 20 reports per IP fingerprint in 1 hour. */
$pdo = db();
$hash = ip_hash();
$rate = $pdo->prepare(
    "SELECT COUNT(*) FROM reports
     WHERE ip_hash = ? AND created_at >= (NOW() - INTERVAL 1 HOUR)"
);
$rate->execute([$hash]);
if ((int)$rate->fetchColumn() >= 20) {
    json_response(['ok' => false, 'message' => 'অল্প সময়ের মধ্যে অনেকগুলো রিপোর্ট হয়েছে। কিছুক্ষণ পরে আবার চেষ্টা করুন।'], 429);
}

/* Honeypot, if added later to the form. */
if (!empty($_POST['website'])) {
    json_response(['ok' => true, 'message' => 'Report received.']);
}

$imagePath = null;

if (isset($_FILES['image']) && $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE) {
    $file = $_FILES['image'];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        json_response(['ok' => false, 'message' => 'ছবি upload করা যায়নি।'], 422);
    }
    if ($file['size'] > 5 * 1024 * 1024) {
        json_response(['ok' => false, 'message' => 'ছবির সর্বোচ্চ size 5MB।'], 422);
    }

    $tmp = $file['tmp_name'];
    $info = @getimagesize($tmp);
    if ($info === false) {
        json_response(['ok' => false, 'message' => 'শুধু valid image upload করুন।'], 422);
    }

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
    $allowed = ['image/jpeg', 'image/png', 'image/webp'];
    if (!in_array($mime, $allowed, true)) {
        json_response(['ok' => false, 'message' => 'JPG, PNG অথবা WebP ছবি দিন।'], 422);
    }

    $uploadDir = dirname(__DIR__) . '/uploads/reports';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
        json_response(['ok' => false, 'message' => 'Upload directory তৈরি করা যায়নি।'], 500);
    }

    $base = bin2hex(random_bytes(16));
    $saved = false;

    /* Prefer WebP to keep storage low. */
    if (function_exists('imagecreatefromstring') && function_exists('imagewebp')) {
        $source = @imagecreatefromstring((string)file_get_contents($tmp));
        if ($source !== false) {
            $w = imagesx($source);
            $h = imagesy($source);
            $max = 1600;
            $scale = min(1, $max / max($w, $h));
            $nw = max(1, (int)round($w * $scale));
            $nh = max(1, (int)round($h * $scale));

            $canvas = imagecreatetruecolor($nw, $nh);
            imagealphablending($canvas, false);
            imagesavealpha($canvas, true);
            imagecopyresampled($canvas, $source, 0, 0, 0, 0, $nw, $nh, $w, $h);

            $filename = $base . '.webp';
            $target = $uploadDir . '/' . $filename;
            $saved = @imagewebp($canvas, $target, 75);
            imagedestroy($canvas);
            imagedestroy($source);

            if ($saved) {
                $imagePath = 'uploads/reports/' . $filename;
            }
        }
    }

    if (!$saved) {
        $ext = match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            default => 'webp',
        };
        $filename = $base . '.' . $ext;
        if (!move_uploaded_file($tmp, $uploadDir . '/' . $filename)) {
            json_response(['ok' => false, 'message' => 'ছবি সংরক্ষণ করা যায়নি।'], 500);
        }
        $imagePath = 'uploads/reports/' . $filename;
    }
}

/*
 * 100m merge:
 * 1) indexed latitude/longitude bounding-box filter
 * 2) exact Haversine distance in SQL
 *
 * This avoids scanning the full locations table for every report.
 */
$latDelta = 100 / 111320;
$cos = max(0.15, cos(deg2rad($lat)));
$lngDelta = 100 / (111320 * $cos);

$minLat = max(-90, $lat - $latDelta);
$maxLat = min(90, $lat + $latDelta);
$minLng = max(-180, $lng - $lngDelta);
$maxLng = min(180, $lng + $lngDelta);

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
        $newType = $location['type'] === $type
            ? $type
            : 'both';

        $useInc = $type === 'use' ? 1 : 0;
        $saleInc = $type === 'sale' ? 1 : 0;

        $update = $pdo->prepare(
            "UPDATE locations
             SET type = ?, report_count = report_count + 1,
                 use_count = use_count + ?, sale_count = sale_count + ?,
                 title = ?, updated_at = NOW()
             WHERE id = ?"
        );
        $update->execute([
            $newType, $useInc, $saleInc, $title, $location['id']
        ]);
        $locationId = (int)$location['id'];
        $merged = true;
    } else {
        $useCount = $type === 'use' ? 1 : 0;
        $saleCount = $type === 'sale' ? 1 : 0;

        $insert = $pdo->prepare(
            "INSERT INTO locations
             (latitude, longitude, title, type, report_count, use_count, sale_count)
             VALUES (?, ?, ?, ?, 1, ?, ?)"
        );
        $insert->execute([$lat, $lng, $title, $type, $useCount, $saleCount]);
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

    json_response([
        'ok' => true,
        'message' => 'রিপোর্ট সফলভাবে গ্রহণ করা হয়েছে।',
        'report_id' => $reportId,
        'location_id' => $locationId,
        'merged_with_existing_location' => $merged
    ]);
} catch (Throwable $e) {
    $pdo->rollBack();
    if ($imagePath) {
        @unlink(dirname(__DIR__) . '/' . $imagePath);
    }
    error_log('SafeMap report error: ' . $e->getMessage());
    json_response(['ok' => false, 'message' => 'রিপোর্ট save করা যায়নি।'], 500);
}


$stationStmt = $pdo->prepare("
    SELECT
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
        report_count,
        use_count,
        sale_count
    )
    VALUES (?, ?, ?, ?, ?, 1, ?, ?)"
);

$insert->execute([
    $lat,
    $lng,
    $title,
    $type,
    $policeStationId,
    $useCount,
    $saleCount
]);