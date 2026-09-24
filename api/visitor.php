<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
request_method('GET');

try {
    $pdo = db();
    $now = date('Y-m-d H:i:s');
    $ipHash = hash('sha256', client_ip());
    $path = clean_text($_GET['path'] ?? '/', 180);

    start_secure_session();
    $shouldCount = false;

    // Daily deduplication per session or per IP hash
    $lastVisitDay = $_SESSION['last_visit_day'] ?? null;
    $today = date('Y-m-d');
    if ($lastVisitDay !== $today) {
        $_SESSION['last_visit_day'] = $today;
        $shouldCount = true;
    }

    if ($shouldCount) {
        $stmt = $pdo->prepare('INSERT INTO site_visits (ip_hash, visited_date, visit_count, last_visited_at)
            VALUES (?, ?, 1, ?)
            ON DUPLICATE KEY UPDATE visit_count = visit_count + 1, last_visited_at = VALUES(last_visited_at)');
        $stmt->execute([$ipHash, $today, $now]);
    }

    // Read cached or current total stats
    $stats = $pdo->query('SELECT
        COALESCE(SUM(visit_count), 0) AS total_visits,
        COUNT(DISTINCT ip_hash) AS unique_visitors
        FROM site_visits')->fetch();

    $todayStats = $pdo->prepare('SELECT
        COALESCE(SUM(visit_count), 0) AS today_visits,
        COUNT(DISTINCT ip_hash) AS today_visitors
        FROM site_visits WHERE visited_date = ?');
    $todayStats->execute([$today]);
    $todayRow = $todayStats->fetch();

    json_response([
        'ok' => true,
        'counted' => $shouldCount,
        'totalVisits' => (int) ($stats['total_visits'] ?? 0),
        'uniqueVisitors' => (int) ($stats['unique_visitors'] ?? 0),
        'todayVisits' => (int) ($todayRow['today_visits'] ?? 0),
        'todayVisitors' => (int) ($todayRow['today_visitors'] ?? 0),
    ]);
} catch (Throwable $e) {
    error_log('Madok visitor error: ' . $e->getMessage());
    json_response(['ok' => false, 'message' => 'Visitor counter unavailable'], 500);
}
