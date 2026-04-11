<?php
/** Created by Łukasz Misiura (c) 2025 | dev.lm-ads.com **/
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$mac = normalize_mac($_GET['mac'] ?? '');
if (!$mac) {
    echo json_encode(['error' => 'Missing MAC']);
    exit;
}

$stats_24h = ['rx' => 0, 'tx' => 0, 'total' => 0];
$stats_7d = ['rx' => 0, 'tx' => 0, 'total' => 0];

if (isset($db)) {
    // 24h from SQLite client_history
    $stmt = $db->prepare("SELECT SUM(rx_bytes) as rx, SUM(tx_bytes) as tx FROM client_history WHERE mac = ? AND seen_at >= datetime('now', '-1 day')");
    $stmt->execute([$mac]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $stats_24h = ['rx' => (int)($row['rx'] ?? 0), 'tx' => (int)($row['tx'] ?? 0), 'total' => (int)(($row['rx'] ?? 0) + ($row['tx'] ?? 0))];
    }

    // 7d from SQLite
    $stmt = $db->prepare("SELECT SUM(rx_bytes) as rx, SUM(tx_bytes) as tx FROM client_history WHERE mac = ? AND seen_at >= datetime('now', '-7 days')");
    $stmt->execute([$mac]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $stats_7d = ['rx' => (int)($row['rx'] ?? 0), 'tx' => (int)($row['tx'] ?? 0), 'total' => (int)(($row['rx'] ?? 0) + ($row['tx'] ?? 0))];
    }
}

echo json_encode([
    'mac' => $mac,
    'stats_24h' => $stats_24h,
    'stats_7d' => $stats_7d
]);
