<?php
/** Created by Łukasz Misiura (c) 2026 | www.lm-ads.com **/
/**
 * UniFi MiniDash - Update WAN Stats
 */
error_reporting(0);
ob_start();

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

header('Content-Type: application/json');

$siteId = $config['site'];
$file = __DIR__ . '/data/wan_stats.json';
$history = [];

try {
    // 1. Fetch Traditional Device Stats (more reliable for real-time rates)
    $trad_resp = fetch_api("/proxy/network/api/s/default/stat/device");
    $trad_devices = $trad_resp['data'] ?? [];
    
    // 2. Fetch Infrastructure (Modern list)
    $resp = fetch_api("/proxy/network/integration/v1/sites/$siteId/devices");
    if (empty($resp['data']) && $siteId !== 'default') {
        $siteId = 'default';
        $resp = fetch_api("/proxy/network/integration/v1/sites/default/devices");
    }
    $devices = $resp['data'] ?? [];
    
    $gateway = null;
    foreach ($devices as $d) {
        $model = strtoupper($d['model'] ?? '');
        $type = strtolower($d['type'] ?? '');
        if (in_array($model, ['UDR', 'UDM', 'UXG', 'USG']) || 
            strpos($model, 'DREAM') !== false || 
            $type === 'udm' || 
            $type === 'gateway' ||
            isset($d['wan1'])) {
            $gateway = $d;
            break;
        }
    }
    
    $rx = 0;
    $tx = 0;
    
    if ($gateway) {
        $g_mac = normalize_mac($gateway['macAddress'] ?? $gateway['mac'] ?? '');
        
        // Find gateway in traditional stats
        foreach ($trad_devices as $td) {
            if (normalize_mac($td['mac'] ?? '') === $g_mac) {
                // Traditionally, UniFi stores current rates in wan1 sub-object
                $rx = $td['wan1']['rxRateBps'] ?? $td['wan1']['rx_bytes-r'] ?? 0;
                $tx = $td['wan1']['txRateBps'] ?? $td['wan1']['tx_bytes-r'] ?? 0;
                break;
            }
        }
        
        // Fallback to integration stats if 0
        if ($rx == 0) {
            $rx = $gateway['uplink']['rxRateBps'] ?? $gateway['wan1']['rxRateBps'] ?? 0;
            $tx = $gateway['uplink']['txRateBps'] ?? $gateway['wan1']['txRateBps'] ?? 0;
        }
    }
    
    // 3. Save History for Chart
    $history = file_exists($file) ? json_decode(file_get_contents($file), true) : [];
    if (!is_array($history)) $history = [];
    
    $history[] = [
        'timestamp' => time(),
        'rx' => (float)$rx,
        'tx' => (float)$tx
    ];
    
    if (count($history) > 60) $history = array_slice($history, -60);
    file_put_contents($file, json_encode($history));

    if (isset($db)) {
        $stmt = $db->prepare("INSERT INTO wan_stats (rx_bytes, tx_bytes) VALUES (?, ?)");
        $stmt->execute([$rx ?? 0, $tx ?? 0]);
    }

    // 4. Update Monitored Devices Status History
    $monitored_config = loadDevices();
    if (!empty($monitored_config)) {
        $clients_resp = fetch_api("/proxy/network/integration/v1/sites/$siteId/clients?limit=1000");
        $all_active_clients = $clients_resp['data'] ?? [];
        
        $statuses = detect_known_devices($all_active_clients, $monitored_config);
        $last_speeds_file = __DIR__ . '/data/last_speeds.json';
        $last_speeds = file_exists($last_speeds_file) ? json_decode(file_get_contents($last_speeds_file), true) : [];
        if (!is_array($last_speeds)) $last_speeds = [];

        $threshold_bps = ($config['triggers']['speed_threshold_mbps'] ?? 100) * 1000 * 1000;
        $speed_alert_enabled = $config['triggers']['speed_alert_enabled'] ?? false;

        foreach ($statuses as $mac => $info) {
            saveDeviceHistory($mac, $info['status']);

            // Speed Spike Check
            if ($speed_alert_enabled && $info['status'] === 'on') {
                $current_speed = max($info['rx_rate'], $info['tx_rate']);
                $last_speed = $last_speeds[$mac] ?? 0;

                if ($current_speed > $threshold_bps && $last_speed <= $threshold_bps) {
                    $mbps = round($current_speed / 1000000, 1);
                    $name = $info['name'] ?? $mac;
                    sendAlert(
                        "🚀 Nagły wzrost transferu: $name",
                        "Urządzenie **$name** ($mac) generuje obecnie duży ruch: **$mbps Mbps**."
                    );
                }
                $last_speeds[$mac] = $current_speed;
            }
        }
        file_put_contents($last_speeds_file, json_encode($last_speeds));
    }

} catch (Exception $e) {
    // Silently continue
}

ob_end_clean();
echo json_encode(!empty($history) ? $history : []);




