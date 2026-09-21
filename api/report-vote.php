<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
request_method('POST');

if (!verify_csrf((string) ($_POST['csrf_token'] ?? ''))) {
    json_response(['ok' => false, 'message' => 'Security token invalid or expired.'], 403);
}

$reportId = filter_var($_POST['report_id'] ?? null, FILTER_VALIDATE_INT);
$vote = (string) ($_POST['vote'] ?? '');
if (!$reportId || $reportId < 1 || !in_array($vote, ['yes', 'no'], true)) {
    json_response(['ok' => false, 'message' => 'Invalid vote.'], 422);
}
if (!rate_limit('report-vote:' . client_ip(), 30, 3600, true)) {
    json_response(['ok' => false, 'message' => 'অনেকগুলো ভোট হয়েছে। পরে আবার চেষ্টা করুন।'], 429);
}

start_secure_session();
if (empty($_SESSION['voter_id'])) {
    $_SESSION['voter_id'] = bin2hex(random_bytes(32));
}
$voterHash = hash('sha256', (string) $_SESSION['voter_id']);
$pdo = db();

try {
    $exists = $pdo->prepare('SELECT 1 FROM reports WHERE id = ?');
    $exists->execute([$reportId]);
    if (!$exists->fetchColumn()) {
        json_response(['ok' => false, 'message' => 'Report not found.'], 404);
    }
    $pdo->beginTransaction();
    $insert = $pdo->prepare('INSERT INTO report_votes (report_id, voter_hash, vote) VALUES (?, ?, ?)');
    try {
        $insert->execute([$reportId, $voterHash, $vote]);
    } catch (PDOException $error) {
        if ($error->getCode() === '23000') {
            $pdo->rollBack();
            json_response(['ok' => false, 'already_voted' => true, 'message' => 'আপনি ইতিমধ্যে ভোট দিয়েছেন।'], 409);
        }
        throw $error;
    }

    $column = $vote === 'yes' ? 'yes_count' : 'no_count';
    $pdo->exec("UPDATE reports SET {$column} = {$column} + 1 WHERE id = " . (int) $reportId);
    $counts = $pdo->prepare('SELECT yes_count, no_count FROM reports WHERE id = ?');
    $counts->execute([$reportId]);
    $result = $counts->fetch();
    if (!$result) {
        throw new RuntimeException('Report not found.');
    }
    $pdo->commit();
    json_response([
        'ok' => true,
        'voted' => $vote,
        'yes' => (int) $result['yes_count'],
        'no' => (int) $result['no_count'],
    ]);
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Madok report vote error: ' . $error->getMessage());
    json_response(['ok' => false, 'message' => 'ভোট সংরক্ষণ করা যায়নি।'], 500);
}
