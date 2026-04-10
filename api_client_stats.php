<?php
require_once 'config.php';
require_once 'functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$mac = $_GET['mac'] ?? '';
if (!$mac) {
    echo json_encode(['error' => 'Missing MAC']);
    exit;
}

$end = time();
$start_24h = $end - (24 * 3600);
$start_7d = $end - (7 * 24 * 3600);

$tradSite = get_trad_site_id($config['site']);
// Fetch 24h stats
$resp_24h = fetch_api("/proxy/network/api/s/{$tradSite}/stat/report/daily.user?mac=$mac&start=$start_24h&end=$end");
$data_24h = $resp_24h['data'] ?? [];
$rx_24h = 0;
$tx_24h = 0;
foreach ($data_24h as $day) {
    $rx_24h += $day['rx_bytes'] ?? 0;
    $tx_24h += $day['tx_bytes'] ?? 0;
}

// Fetch 7d stats
$resp_7d = fetch_api("/proxy/network/api/s/{$tradSite}/stat/report/daily.user?mac=$mac&start=$start_7d&end=$end");
$data_7d = $resp_7d['data'] ?? [];
$rx_7d = 0;
$tx_7d = 0;
foreach ($data_7d as $day) {
    $rx_7d += $day['rx_bytes'] ?? 0;
    $tx_7d += $day['tx_bytes'] ?? 0;
}

echo json_encode([
    'mac' => $mac,
    'stats_24h' => ['rx' => $rx_24h, 'tx' => $tx_24h, 'total' => $rx_24h + $tx_24h],
    'stats_7d' => ['rx' => $rx_7d, 'tx' => $tx_7d, 'total' => $rx_7d + $tx_7d]
]);
