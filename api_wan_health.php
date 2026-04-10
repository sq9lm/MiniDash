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

try {
    // Get gateway device stats (most reliable source for WAN metrics)
    $dev_resp = fetch_api("/proxy/network/api/s/default/stat/device");
    $gw = null;
    foreach (($dev_resp['data'] ?? []) as $d) {
        if (in_array($d['type'] ?? '', ['ugw', 'udm', 'uxg'])) {
            $gw = $d;
            break;
        }
    }

    $wan_health = [
        'packet_loss' => 0.0,
        'uptime' => 0,
        'latency' => 0,
        'status' => 'unknown',
        'speedtest_down' => 0,
        'speedtest_up' => 0,
    ];

    if ($gw) {
        // WAN1 data from gateway
        $wan1 = $gw['wan1'] ?? [];
        $wan_health['latency'] = (float)($wan1['latency'] ?? $gw['uplink']['latency'] ?? 0);
        $wan_health['uptime'] = (int)($gw['uptime'] ?? 0);
        $wan_health['status'] = ($gw['state'] ?? 0) == 1 ? 'ok' : 'down';

        // Packet loss — check wan1 or uplink
        $wan_health['packet_loss'] = (float)($wan1['packet_loss'] ?? $gw['uplink']['packet_loss'] ?? 0);

        // Speedtest results if available
        $wan_health['speedtest_down'] = (float)($gw['speedtest-status']['xput_download'] ?? 0);
        $wan_health['speedtest_up'] = (float)($gw['speedtest-status']['xput_upload'] ?? 0);

        // If latency still 0, try stat/health as fallback
        if ($wan_health['latency'] == 0) {
            $health_resp = fetch_api("/proxy/network/api/s/default/stat/health");
            foreach (($health_resp['data'] ?? []) as $h) {
                if (($h['subsystem'] ?? '') === 'wan') {
                    if ($h['latency'] ?? 0) $wan_health['latency'] = (float)$h['latency'];
                    if ($h['packet_loss'] ?? 0) $wan_health['packet_loss'] = (float)$h['packet_loss'];
                    break;
                }
            }
        }
    }

    echo json_encode(['success' => true, 'data' => $wan_health]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
