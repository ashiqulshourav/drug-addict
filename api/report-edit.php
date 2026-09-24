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
    if (isset($_FILES['image']) && (int) ($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        try {
            $imagePath = store_compressed_report_image($_FILES['image']);
        } catch (RuntimeException $imageError) {
            $pdo->rollBack();
            json_response(['ok' => false, 'message' => $imageError->getMessage()], 422);
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