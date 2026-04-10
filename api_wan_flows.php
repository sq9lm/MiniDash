<?php
/** Created by Łukasz Misiura (c) 2025 | dev.lm-ads.com **/
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    header('Content-Type: application/json');
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json');

// Get active clients with real-time transfer rates
$sta_resp = fetch_api('/proxy/network/api/s/default/stat/sta');
$clients = $sta_resp['data'] ?? [];

// Build top active clients list sorted by transfer rate
$active_flows = [];
foreach ($clients as $c) {
    $rx = $c['rx_bytes-r'] ?? $c['wired-rx_bytes-r'] ?? 0;
    $tx = $c['tx_bytes-r'] ?? $c['wired-tx_bytes-r'] ?? 0;
    $total = $rx + $tx;

    if ($total > 100) { // At least 100 bytes/s to show
        $active_flows[] = [
            'name' => $c['name'] ?? $c['hostname'] ?? $c['mac'] ?? 'Unknown',
            'mac' => $c['mac'] ?? '',
            'ip' => $c['ip'] ?? $c['last_ip'] ?? '',
            'rx_bps' => round($rx * 8),
            'tx_bps' => round($tx * 8),
            'total_bps' => round($total * 8),
            'is_wired' => !empty($c['is_wired']),
            'network' => $c['network'] ?? $c['essid'] ?? '',
            'vlan' => $c['vlan'] ?? 0,
        ];
    }
}

// Sort by total rate descending, top 20
usort($active_flows, fn($a, $b) => $b['total_bps'] <=> $a['total_bps']);
$active_flows = array_slice($active_flows, 0, 20);

echo json_encode([
    'success' => true,
    'count' => count($active_flows),
    'data' => $active_flows
]);
