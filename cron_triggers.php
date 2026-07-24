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

// Fatal-error safety net: a try/catch(Exception) does NOT catch Error/parse/fatal,
// nor anything thrown during the require_once bootstrap below. Without this, a fatal
// (e.g. missing sodium extension in CLI php) kills the cron silently — no log, no alert.
// Registered BEFORE the requires so it also captures bootstrap failures.
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        @file_put_contents(
            __DIR__ . '/logs/cron_errors.log',
            date('Y-m-d H:i:s') . " CRON FATAL: {$e['message']} in {$e['file']}:{$e['line']}\n",
            FILE_APPEND
        );
    }
});

// Guard: sodium is required to decrypt config credentials. In the web SAPI it is
// loaded, but the CLI php used by cron may not have it — fail loudly, not silently.
if (!function_exists('sodium_crypto_secretbox_open')) {
    @file_put_contents(
        __DIR__ . '/logs/cron_errors.log',
        date('Y-m-d H:i:s') . " CRON FATAL: sodium extension not loaded in CLI php ("
            . PHP_BINARY . ") — cannot decrypt config; enable extension=sodium for CLI.\n",
        FILE_APPEND
    );
    exit(1);
}

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
    // PRIMARY: Traditional stat/sta — zwraca WSZYSTKICH klientów ze WSZYSTKICH VLANów
    // zawiera też: vlan, essid, is_wired, sw_mac, sw_port, ap_mac, ip, rx_rate, tx_rate
    $trad_sta = fetch_api("/proxy/network/api/s/$tradSite/stat/sta");
    $all_active_clients = $trad_sta['data'] ?? [];

    // ENRICHMENT: Integration API v1 — dodaje rxRateBps/txRateBps gdy traditional rates = 0
    $integ_resp = fetch_api("/proxy/network/integration/v1/sites/$siteId/clients?limit=1000");
    $integ_map = [];
    foreach (($integ_resp['data'] ?? []) as $ic) {
        $imac = normalize_mac($ic['macAddress'] ?? $ic['mac'] ?? '');
        if ($imac) $integ_map[$imac] = $ic;
    }
    foreach ($all_active_clients as &$c) {
        $cmac = normalize_mac($c['mac'] ?? '');
        if (isset($integ_map[$cmac])) {
            $ic = $integ_map[$cmac];
            // Przenosimy pod oryginalnymi nazwami, bo client_rate_bps() rozpoznaje
            // rxRateBps/txRateBps jako bity/s. Wpisanie ich do rx_rate/tx_rate mieszaloby
            // je z predkoscia linku Wi-Fi, ktora stat/sta trzyma pod tymi samymi kluczami.
            if (!isset($c['rxRateBps']) && isset($ic['rxRateBps'])) $c['rxRateBps'] = $ic['rxRateBps'];
            if (!isset($c['txRateBps']) && isset($ic['txRateBps'])) $c['txRateBps'] = $ic['txRateBps'];
        }
    }
    unset($c);

    // === TRIGGER: Monitored Device Status ===
    $monitored_config = loadDevices();
    if (!empty($monitored_config)) {
        $statuses = detect_known_devices($all_active_clients, $monitored_config);
        foreach ($statuses as $mac => $info) {
            saveDeviceHistory($mac, $info['status']);
        }
    }

    // === TRIGGER: Speed Spikes (wszyscy aktywni klienci) ===
    // Alert obejmuje cala siec, nie tylko liste monitorowanych: urzadzenie zapychajace
    // lacze zwykle nie jest tym, ktore ktos wczesniej dodal do obserwowanych.
    if ($config['triggers']['speed_alert_enabled'] ?? false) {
        $threshold_bps = ($config['triggers']['speed_threshold_mbps'] ?? 100) * 1000 * 1000;
        $last_speeds_file = __DIR__ . '/data/last_speeds.json';
        $last_speeds = file_exists($last_speeds_file) ? json_decode(file_get_contents($last_speeds_file), true) : [];
        if (!is_array($last_speeds)) $last_speeds = [];

        $biezace_predkosci = [];
        foreach ($all_active_clients as $klient) {
            $mac = normalize_mac($klient['mac'] ?? '');
            if (!$mac) continue;

            $rx = client_rate_bps($klient, 'rx');
            $tx = client_rate_bps($klient, 'tx');
            $predkosc = max($rx, $tx);
            $biezace_predkosci[$mac] = $predkosc;

            // Zbocze narastajace: alert leci raz, w momencie przekroczenia progu, a nie
            // co minute przez caly czas trwania transferu.
            if ($predkosc > $threshold_bps && ($last_speeds[$mac] ?? 0) <= $threshold_bps) {
                $nazwa = $klient['name'] ?? $klient['hostname'] ?? $mac;
                $mbps = round($predkosc / 1000000, 1);
                $kierunek = $tx > $rx ? 'wysylanie' : 'pobieranie';
                sendAlert(
                    "Wzrost transferu: $nazwa",
                    "Urządzenie **$nazwa** ($mac) generuje duzy ruch: **$mbps Mbps** ($kierunek).",
                    'warning'
                );
            }
        }

        // Zapisujemy wylacznie klientow widzianych w tym cyklu — inaczej plik rosl by
        // w nieskonczonosc o MAC-i urzadzen, ktore dawno zniknely z sieci.
        file_put_contents($last_speeds_file, json_encode($biezace_predkosci));
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
                $uplink_device = $client['sw_mac'] ?? $client['ap_mac'] ?? '';
                $sw_port = $client['sw_port'] ?? 0;
                if ($can_alert && $new_count < 3) {
                    $details = "📡 IP: $ip | 🏷️ $vlan_name";
                    if ($is_wired) {
                        if ($uplink_device) {
                            $uplink_name = get_infra_device_name_by_mac($uplink_device);
                            $uplink_label = $uplink_name ?: strtoupper(substr($uplink_device, -8));
                            $details .= " | 🔌 Uplink: $uplink_label" . ($sw_port ? ":$sw_port" : '');
                        } else {
                            $details .= " | 🔌 Ethernet";
                        }
                    } else {
                        if ($network) $details .= " | 📶 $network";
                    }
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
                        "❗ $cat | 🌍 $cc | 🛡️ $sig\nZrodlo: **$src** → $dst" . ($port ? ":$port" : "") . " ($proto)",
                        'attack'
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

    // === TRIGGER: VPN Connection Alert (poll + diff) ===
    // stat/event zwraca 404 na tym UDR, wiec zamiast feedu zdarzen pollujemy liste aktywnych
    // sesji (stat/remoteuservpn) i wykrywamy roznice. Rozlaczenie ma 1-cyklowy grace, bo
    // WireGuard (connectionless) potrafi chwilowo zniknac miedzy handshake'ami → falszywe alerty.
    $vpn_debug = __DIR__ . '/logs/vpn_debug.log';
    if ($config['triggers']['vpn_alert_enabled'] ?? false) {
        $vpn_resp = fetch_api('/proxy/network/api/s/default/stat/remoteuservpn');
        $vpn_labels = ['openvpn-server' => 'OpenVPN', 'wireguard-server' => 'WireGuard', 'l2tp-server' => 'L2TP'];

        // Mapa biezacych sesji, klucz = user|remote_ip|vpn_type
        $current = [];
        foreach (($vpn_resp['data'] ?? []) as $s) {
            if (isset($s['up']) && !$s['up']) continue;
            $key = ($s['user'] ?? '?') . '|' . ($s['remote_ip'] ?? '?') . '|' . ($s['vpn_type'] ?? '?');
            $current[$key] = [
                'user'       => $s['user'] ?? '?',
                'remote_ip'  => $s['remote_ip'] ?? '?',
                'tunnel_ip'  => $s['tunnel_ip'] ?? '',
                'vpn_type'   => $s['vpn_type'] ?? '',
                'assoc_time' => $s['assoc_time'] ?? time(),
                'misses'     => 0,
            ];
        }

        $state_file = __DIR__ . '/data/vpn_sessions.json';
        $prev = file_exists($state_file) ? json_decode(file_get_contents($state_file), true) : null;
        $is_first_run = !is_array($prev);
        if (!is_array($prev)) $prev = [];

        @file_put_contents($vpn_debug, date('Y-m-d H:i:s') . " Sessions: " . count($current)
            . " | " . implode(', ', array_keys($current)) . "\n", FILE_APPEND);

        $next = $current; // stan do zapisania (current + sesje w grace)
        if (!$is_first_run) {
            // Nowe sesje → polaczono
            foreach ($current as $key => $s) {
                if (!isset($prev[$key])) {
                    $label = $vpn_labels[$s['vpn_type']] ?? $s['vpn_type'];
                    sendAlert(
                        "VPN Połączono: {$s['user']}",
                        "Użytkownik **{$s['user']}** połączył się ($label).\n🌍 Źródło: **{$s['remote_ip']}** | 📡 Tunel: {$s['tunnel_ip']}",
                        'info'
                    );
                }
            }
            // Zniknięte sesje → rozłączono (1-cyklowy grace dla WireGuard)
            foreach ($prev as $key => $s) {
                if (isset($current[$key])) continue;
                $misses = ($s['misses'] ?? 0) + 1;
                if ($misses >= 2) {
                    $label = $vpn_labels[$s['vpn_type'] ?? ''] ?? ($s['vpn_type'] ?? '');
                    $dur = isset($s['assoc_time']) ? formatDuration(time() - (int)$s['assoc_time']) : '';
                    sendAlert(
                        "VPN Rozłączono: {$s['user']}",
                        "Użytkownik **{$s['user']}** rozłączył się ($label)." . ($dur ? "\n⏱️ Czas sesji: $dur" : ''),
                        'warning'
                    );
                    // misses>=2 → nie trafia do $next, czyli usuniete ze stanu
                } else {
                    $s['misses'] = $misses;
                    $next[$key] = $s; // grace: trzymamy w stanie, bez alertu
                }
            }
        }

        file_put_contents($state_file, json_encode($next));
    }

} catch (Exception $e) {
    // Log error for debugging
    $log = date('Y-m-d H:i:s') . " CRON ERROR: " . $e->getMessage() . "\n";
    @file_put_contents(__DIR__ . '/logs/cron_errors.log', $log, FILE_APPEND);
}
