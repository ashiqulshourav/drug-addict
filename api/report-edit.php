<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
request_method('POST');

if (!verify_csrf((string) ($_POST['csrf_token'] ?? ''))) {
    json_response(['ok' => false, 'message' => 'Security token invalid or expired. Please reload the page.'], 403);
}

if (!rate_limit('report-edit:' . client_ip(), 30, 3600, true)) {
    json_response(['ok' => false, 'message' => 'অনেকগুলো পরিবর্তনের অনুরোধ হয়েছে। পরে আবার চেষ্টা করুন।'], 429);
}

$reportId = filter_input(INPUT_POST, 'reportId', FILTER_VALIDATE_INT);
$action = (string) ($_POST['action'] ?? 'update');
if (!$reportId || $reportId < 1 || !in_array($action, ['update', 'delete'], true)) {
    json_response(['ok' => false, 'message' => 'Invalid report request.'], 422);
}

$pdo = db();
$oldImage = null;

try {
    $pdo->beginTransaction();
    $stmt = $pdo->prepare(
        'SELECT r.*, l.report_count, l.use_count, l.sale_count
         FROM reports r INNER JOIN locations l ON l.id = r.location_id
         WHERE r.id = ? FOR UPDATE'
    );
    $stmt->execute([$reportId]);
    $report = $stmt->fetch();

    if (!$report || !owns_report($reportId)) {
        $pdo->rollBack();
        json_response(['ok' => false, 'message' => 'এই রিপোর্ট edit করার অনুমতি নেই।'], 403);
    }

    $oldImage = $report['image_path'] ? (string) $report['image_path'] : null;

    if ($action === 'delete') {
        $delete = $pdo->prepare('DELETE FROM reports WHERE id = ?');
        $delete->execute([$reportId]);

        $remaining = $pdo->prepare(
            'SELECT COUNT(*) AS total, SUM(report_type = "use") AS uses, SUM(report_type = "sale") AS sales,
                    (SELECT title FROM reports WHERE location_id = ? ORDER BY created_at DESC, id DESC LIMIT 1) AS latest_title
             FROM reports WHERE location_id = ?'
        );
        $remaining->execute([(int) $report['location_id'], (int) $report['location_id']]);
        $counts = $remaining->fetch() ?: ['total' => 0, 'uses' => 0, 'sales' => 0, 'latest_title' => null];
        $newType = ((int) $counts['uses'] > 0 && (int) $counts['sales'] > 0)
            ? 'both'
            : ((int) $counts['sales'] > 0 ? 'sale' : 'use');

        if ((int) $counts['total'] === 0) {
            $locationDelete = $pdo->prepare('DELETE FROM locations WHERE id = ?');
            $locationDelete->execute([(int) $report['location_id']]);
        } else {
            $locationUpdate = $pdo->prepare(
                'UPDATE locations SET report_count = ?, use_count = ?, sale_count = ?, type = ?, title = ? WHERE id = ?'
            );
            $locationUpdate->execute([
                (int) $counts['total'], (int) $counts['uses'], (int) $counts['sales'], $newType,
                $counts['latest_title'], (int) $report['location_id']
            ]);
        }
        $pdo->commit();
        start_secure_session();
        unset($_SESSION['owned_reports'][(string) $reportId]);
        if ($oldImage) {
            @unlink(dirname(__DIR__) . '/' . $oldImage);
        }
        json_response(['ok' => true, 'deleted' => true, 'location_id' => (int) $report['location_id']]);
    }

    $title = clean_text($_POST['title'] ?? '', 160);
    $description = clean_text($_POST['description'] ?? '', 1000);
    if ($title === '') {
        $pdo->rollBack();
        json_response(['ok' => false, 'message' => 'Title is required.'], 422);
    }

    $imagePath = $oldImage;
    if (($_POST['removeImage'] ?? '') === '1') {
        $imagePath = null;
    }
    if (isset($_FILES['image']) && $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE) {
        $file = $_FILES['image'];
        if ($file['error'] !== UPLOAD_ERR_OK || $file['size'] > 5 * 1024 * 1024) {
            $pdo->rollBack();
            json_response(['ok' => false, 'message' => 'ছবি upload করা যায়নি বা size 5MB-এর বেশি।'], 422);
        }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        $info = @getimagesize($file['tmp_name']);
        if (!isset($allowed[$mime]) || $info === false || ($info[0] ?? 0) < 1 || ($info[1] ?? 0) < 1 || ($info[0] ?? 0) > 8000 || ($info[1] ?? 0) > 8000) {
            $pdo->rollBack();
            json_response(['ok' => false, 'message' => 'শুধু valid JPG, PNG অথবা WebP ছবি দিন।'], 422);
        }
        $dir = dirname(__DIR__) . '/uploads/reports';
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            $pdo->rollBack();
            json_response(['ok' => false, 'message' => 'Upload directory তৈরি করা যায়নি।'], 500);
        }
        $base = bin2hex(random_bytes(16));
        $saved = false;
        if (function_exists('imagecreatefromstring') && function_exists('imagewebp')) {
            $source = @imagecreatefromstring((string) file_get_contents($file['tmp_name']));
            if ($source !== false) {
                $width = imagesx($source);
                $height = imagesy($source);
                $scale = min(1, 1600 / max($width, $height));
                $newWidth = max(1, (int) round($width * $scale));
                $newHeight = max(1, (int) round($height * $scale));
                $canvas = imagecreatetruecolor($newWidth, $newHeight);
                imagealphablending($canvas, false);
                imagesavealpha($canvas, true);
                imagecopyresampled($canvas, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
                $filename = $base . '.webp';
                $saved = @imagewebp($canvas, $dir . '/' . $filename, 75);
                imagedestroy($canvas);
                imagedestroy($source);
                if ($saved) $imagePath = 'uploads/reports/' . $filename;
            }
        }
        if (!$saved) {
            $filename = $base . '.' . $allowed[$mime];
            if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $filename)) {
                $pdo->rollBack();
                json_response(['ok' => false, 'message' => 'ছবি সংরক্ষণ করা যায়নি।'], 500);
            }
            $imagePath = 'uploads/reports/' . $filename;
        }
    }

    $update = $pdo->prepare('UPDATE reports SET title = ?, description = ?, image_path = ? WHERE id = ?');
    $update->execute([$title, $description ?: null, $imagePath, $reportId]);
    $pdo->commit();
    if ($oldImage && $oldImage !== $imagePath) {
        @unlink(dirname(__DIR__) . '/' . $oldImage);
    }
    json_response(['ok' => true, 'report_id' => $reportId, 'image' => $imagePath]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Madok report edit error: ' . $e->getMessage());
    json_response(['ok' => false, 'message' => 'রিপোর্ট update করা যায়নি।'], 500);
}