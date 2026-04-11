<?php
/** Created by Łukasz Misiura (c) 2025 | dev.lm-ads.com **/
/**
 * MiniDash - Update WAN Stats
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
    $tradSite = get_trad_site_id($config['site']);
    $trad_resp = fetch_api("/proxy/network/api/s/{$tradSite}/stat/device");
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
                // Traditional API: rx_rate/tx_rate are in bps, rx_bytes-r is bytes/s (needs *8)
                $rx = $td['wan1']['rx_rate'] ?? $td['wan1']['rxRateBps'] ?? 0;
                if ($rx == 0) $rx = ($td['wan1']['rx_bytes-r'] ?? 0) * 8;
                $tx = $td['wan1']['tx_rate'] ?? $td['wan1']['txRateBps'] ?? 0;
                if ($tx == 0) $tx = ($td['wan1']['tx_bytes-r'] ?? 0) * 8;
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
            
            // Record Client Stats History
            if (isset($db) && $info['status'] === 'on') {
                $stmt_hist = $db->prepare("INSERT INTO client_history (mac, rx_bytes, tx_bytes, ip, vlan, seen_at) VALUES (?, ?, ?, ?, ?, datetime('now'))");
                $stmt_hist->execute([
                    $mac, 
                    $info['rx_bytes'] ?? 0, 
                    $info['tx_bytes'] ?? 0,
                    $info['ip'] ?? '',
                    $info['vlan'] ?? 0
                ]);
            }

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

    // === TRIGGER: New Device Detection ===
    if ($config['triggers']['new_device_alert_enabled'] ?? false) {
        $known_macs_file = __DIR__ . '/data/known_macs.json';
        $known_macs = file_exists($known_macs_file) ? json_decode(file_get_contents($known_macs_file), true) : [];
        if (!is_array($known_macs)) $known_macs = [];
        $is_first_run = !isset($known_macs['_initialized']);

        // Cooldown: max 1 alert batch per 5 minutes
        $last_new_device_alert = $known_macs['_last_alert'] ?? 0;
        $can_alert = !$is_first_run && (time() - $last_new_device_alert > 300);

        $sta_resp = fetch_api('/proxy/network/api/s/default/stat/sta');
        $new_count = 0;
        foreach (($sta_resp['data'] ?? []) as $client) {
            $mac = strtolower($client['mac'] ?? '');
            if (!$mac) continue;
            if (!isset($known_macs[$mac])) {
                $name = $client['name'] ?? $client['hostname'] ?? $mac;
                $ip = $client['ip'] ?? $client['last_ip'] ?? '';
                $known_macs[$mac] = ['name' => $name, 'first_seen' => date('Y-m-d H:i:s')];
                if ($can_alert && $new_count < 3) {
                    sendAlert(
                        "Nowe urzadzenie: $name",
                        "Wykryto nieznane urzadzenie **$name** (MAC: $mac, IP: $ip) w sieci."
                    );
                    $new_count++;
                    $known_macs['_last_alert'] = time();
                }
            }
        }
        $known_macs['_initialized'] = true;
        file_put_contents($known_macs_file, json_encode($known_macs));
    }

    // === TRIGGER: IPS/IDS Alert ===
    if ($config['triggers']['ips_alert_enabled'] ?? false) {
        $last_ips_check = $_SESSION['last_ips_alert_check'] ?? 0;
        $now = time();
        if ($now - $last_ips_check > 60) { // Check every 60s max
            $ips_resp = fetch_api('/proxy/network/api/s/default/rest/alarm?limit=5');
            $ips_events = $ips_resp['data'] ?? [];
            $last_ips_id_file = __DIR__ . '/data/last_ips_event_id.txt';
            $last_id = file_exists($last_ips_id_file) ? trim(file_get_contents($last_ips_id_file)) : '';

            foreach ($ips_events as $evt) {
                $evt_id = $evt['_id'] ?? '';
                if ($evt_id === $last_id) break; // Already seen
                $action = $evt['inner_alert_action'] ?? '';
                if ($action === 'blocked') {
                    $src = $evt['src_ip'] ?? '?';
                    $sig = $evt['inner_alert_signature'] ?? $evt['inner_alert_category'] ?? 'Unknown threat';
                    $cc = strtoupper($evt['srcipCountry'] ?? '??');
                    sendAlert(
                        "🛡️ IPS Zablokowany atak",
                        "Zablokowano atak z **$src** ($cc): $sig"
                    );
                    break; // Only alert on newest blocked event
                }
            }
            if (!empty($ips_events[0]['_id'])) {
                file_put_contents($last_ips_id_file, $ips_events[0]['_id']);
            }
            $_SESSION['last_ips_alert_check'] = $now;
        }
    }

    // === TRIGGER: High Latency ===
    if ($config['triggers']['latency_alert_enabled'] ?? false) {
        $latency_threshold = $config['triggers']['latency_threshold_ms'] ?? 100;
        $dev_resp_lat = fetch_api('/proxy/network/api/s/default/stat/device');
        foreach (($dev_resp_lat['data'] ?? []) as $d) {
            if (in_array($d['type'] ?? '', ['ugw', 'udm', 'uxg'])) {
                $latency = $d['wan1']['latency'] ?? $d['uplink']['latency'] ?? 0;
                if ($latency > $latency_threshold) {
                    $last_lat_alert = $_SESSION['last_latency_alert'] ?? 0;
                    if (time() - $last_lat_alert > 300) { // 5 min cooldown
                        sendAlert(
                            "⚠️ Wysoka latencja WAN: {$latency}ms",
                            "Opoznienie lacza WAN wynosi **{$latency}ms** (prog: {$latency_threshold}ms)."
                        );
                        $_SESSION['last_latency_alert'] = time();
                    }
                }
                break;
            }
        }
    }

    // === TRIGGER: VPN Connection Alert ===
    if ($config['triggers']['vpn_alert_enabled'] ?? false) {
        $last_vpn_check = $_SESSION['last_vpn_alert_check'] ?? 0;
        if (time() - $last_vpn_check > 30) {
            $vpn_resp = fetch_api('/proxy/network/api/s/default/rest/alarm?limit=10');
            $last_vpn_id_file = __DIR__ . '/data/last_vpn_event_id.txt';
            $last_vpn_id = file_exists($last_vpn_id_file) ? trim(file_get_contents($last_vpn_id_file)) : '';

            foreach (($vpn_resp['data'] ?? []) as $evt) {
                $evt_id = $evt['_id'] ?? '';
                if ($evt_id === $last_vpn_id) break;
                $key = $evt['key'] ?? '';
                if (strpos($key, 'EVT_VPN') !== false || strpos($key, 'VPN_Client') !== false) {
                    $msg = $evt['msg'] ?? 'VPN event';
                    $is_connect = strpos($key, 'Connected') !== false || strpos($msg, 'connected') !== false;
                    $icon = $is_connect ? 'VPN Polaczono' : 'VPN Rozlaczono';
                    sendAlert(
                        "$icon",
                        $msg
                    );
                    break; // Only newest VPN event
                }
            }
            if (!empty($vpn_resp['data'][0]['_id'])) {
                file_put_contents($last_vpn_id_file, $vpn_resp['data'][0]['_id']);
            }
            $_SESSION['last_vpn_alert_check'] = time();
        }
    }

} catch (Exception $e) {
    // Silently continue
}

ob_end_clean();
echo json_encode(!empty($history) ? $history : []);




