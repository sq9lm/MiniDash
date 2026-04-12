<?php
/** Created by Łukasz Misiura (c) 2025 | dev.lm-ads.com **/
/**
 * MiniDash - Background Trigger Runner (cron)
 * Runs independently of user sessions — called by cron every 60 seconds.
 * Checks: new devices, IPS/IDS, latency, VPN, speed spikes, device status.
 */

// Block external access — only CLI or localhost
if (php_sapi_name() !== 'cli' && ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1' && ($_SERVER['REMOTE_ADDR'] ?? '') !== '::1') {
    http_response_code(403);
    exit('Forbidden');
}

error_reporting(E_ALL & ~E_NOTICE);
ini_set('display_errors', 0);

// Minimal session-less bootstrap
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

$siteId = $config['site'];
$cooldown_dir = __DIR__ . '/data';

// Helper: file-based cooldown (replaces $_SESSION)
function check_cooldown(string $key, int $seconds): bool {
    global $cooldown_dir;
    $file = "$cooldown_dir/.cooldown_$key";
    if (file_exists($file) && (time() - filemtime($file)) < $seconds) {
        return false; // still in cooldown
    }
    touch($file);
    return true; // can proceed
}

try {
    $tradSite = get_trad_site_id($config['site']);

    // === Fetch clients (shared by multiple triggers) ===
    $clients_resp = fetch_api("/proxy/network/integration/v1/sites/$siteId/clients?limit=1000");
    $all_active_clients = $clients_resp['data'] ?? [];

    // Enrich with traditional API data
    $trad_sta = fetch_api("/proxy/network/api/s/$tradSite/stat/sta");
    $trad_map = [];
    foreach (($trad_sta['data'] ?? []) as $tc) {
        $trad_map[normalize_mac($tc['mac'] ?? '')] = $tc;
    }
    foreach ($all_active_clients as &$c) {
        $cmac = normalize_mac($c['macAddress'] ?? $c['mac'] ?? '');
        if (isset($trad_map[$cmac])) {
            $tc = $trad_map[$cmac];
            $c['rx_bytes'] = $tc['rx_bytes'] ?? 0;
            $c['tx_bytes'] = $tc['tx_bytes'] ?? 0;
            $c['rx_rate'] = $tc['rx_rate'] ?? (($tc['rx_bytes-r'] ?? 0) * 8);
            $c['tx_rate'] = $tc['tx_rate'] ?? (($tc['tx_bytes-r'] ?? 0) * 8);
        }
    }
    unset($c);

    // === TRIGGER: Monitored Device Status + Speed Spikes ===
    $monitored_config = loadDevices();
    if (!empty($monitored_config)) {
        $statuses = detect_known_devices($all_active_clients, $monitored_config);
        $last_speeds_file = __DIR__ . '/data/last_speeds.json';
        $last_speeds = file_exists($last_speeds_file) ? json_decode(file_get_contents($last_speeds_file), true) : [];
        if (!is_array($last_speeds)) $last_speeds = [];

        $threshold_bps = ($config['triggers']['speed_threshold_mbps'] ?? 100) * 1000 * 1000;
        $speed_alert_enabled = $config['triggers']['speed_alert_enabled'] ?? false;

        foreach ($statuses as $mac => $info) {
            saveDeviceHistory($mac, $info['status']);

            if ($speed_alert_enabled && $info['status'] === 'on') {
                $current_speed = max($info['rx_rate'] ?? 0, $info['tx_rate'] ?? 0);
                $last_speed = $last_speeds[$mac] ?? 0;

                if ($current_speed > $threshold_bps && $last_speed <= $threshold_bps) {
                    $mbps = round($current_speed / 1000000, 1);
                    $name = $info['name'] ?? $mac;
                    sendAlert(
                        "Wzrost transferu: $name",
                        "Urządzenie **$name** ($mac) generuje duzy ruch: **$mbps Mbps**.",
                        'warning'
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
                $vlan_id = detect_vlan_id($ip, $client['vlan'] ?? null);
                $vlan_name = get_vlan_name($vlan_id);
                $network = $client['essid'] ?? $client['network'] ?? '';
                $is_wired = !empty($client['is_wired']);
                $known_macs[$mac] = ['name' => $name, 'first_seen' => date('Y-m-d H:i:s')];
                if ($can_alert && $new_count < 3) {
                    $type = $is_wired ? '🔌 Wired' : '📶 WiFi';
                    $details = "📡 IP: $ip | $type";
                    if ($network) $details .= ": $network";
                    $details .= " | 🏷️ VLAN: $vlan_name";
                    sendAlert(
                        "Nowe urzadzenie: $name",
                        "$details\nMAC: $mac",
                        'warning'
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
        if (check_cooldown('ips', 60)) {
            $ips_resp = fetch_api('/proxy/network/api/s/default/rest/alarm?limit=5');
            $ips_events = $ips_resp['data'] ?? [];
            $last_ips_id_file = __DIR__ . '/data/last_ips_event_id.txt';
            $last_id = file_exists($last_ips_id_file) ? trim(file_get_contents($last_ips_id_file)) : '';

            foreach ($ips_events as $evt) {
                $evt_id = $evt['_id'] ?? '';
                if ($evt_id === $last_id) break;
                $action = $evt['inner_alert_action'] ?? '';
                if ($action === 'blocked') {
                    $src = $evt['src_ip'] ?? '?';
                    $dst = $evt['dest_ip'] ?? 'Local';
                    $port = $evt['dest_port'] ?? '';
                    $proto = $evt['proto'] ?? 'TCP';
                    $sig = $evt['inner_alert_signature'] ?? 'Unknown';
                    $cat = $evt['inner_alert_category'] ?? 'Threat';
                    $cc = strtoupper($evt['srcipCountry'] ?? '??');
                    sendAlert(
                        "Zablokowano Atak!",
                        "⚠️ $cat | 🌍 $cc | 🛡️ $sig\nZrodlo: **$src** → $dst" . ($port ? ":$port" : "") . " ($proto)",
                        'critical'
                    );
                    break;
                }
            }
            if (!empty($ips_events[0]['_id'])) {
                file_put_contents($last_ips_id_file, $ips_events[0]['_id']);
            }
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
                    if (check_cooldown('latency', 300)) {
                        sendAlert(
                            "Wysoka latencja WAN: {$latency}ms",
                            "Opoznienie lacza WAN wynosi **{$latency}ms** (prog: {$latency_threshold}ms).",
                            'warning'
                        );
                    }
                }
                break;
            }
        }
    }

    // === TRIGGER: VPN Connection Alert ===
    if ($config['triggers']['vpn_alert_enabled'] ?? false) {
        if (check_cooldown('vpn', 30)) {
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
                    sendAlert("$icon", $msg);
                    break;
                }
            }
            if (!empty($vpn_resp['data'][0]['_id'])) {
                file_put_contents($last_vpn_id_file, $vpn_resp['data'][0]['_id']);
            }
        }
    }

} catch (Exception $e) {
    // Log error for debugging
    $log = date('Y-m-d H:i:s') . " CRON ERROR: " . $e->getMessage() . "\n";
    @file_put_contents(__DIR__ . '/logs/cron_errors.log', $log, FILE_APPEND);
}
