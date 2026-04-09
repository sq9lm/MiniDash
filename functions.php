<?php
/** Created by Łukasz Misiura (c) 2026 | www.lm-ads.com **/
// functions.php - Clean Version
ini_set('memory_limit', '512M');
ini_set('max_execution_time', 60);

function loadDevices()
{
    $devicesFile = __DIR__ . '/data/devices.json';
    if (file_exists($devicesFile)) {
        return json_decode(file_get_contents($devicesFile), true) ?? [];
    }
    return [];
}

function normalize_mac($mac)
{
    if (!$mac) return '';
    return strtolower(str_replace(['-', ':'], '', $mac));
}

function get_system_info() {
    $site_resp = fetch_api("/proxy/network/api/s/default/stat/sysinfo");
    $sysinfo = $site_resp['data'][0] ?? [];
    
    $dev_resp = fetch_api("/proxy/network/api/s/default/stat/device");
    $devices = $dev_resp['data'] ?? [];
    
    $udr = null;
    foreach ($devices as $d) {
        if (in_array($d['model'] ?? '', ['UDR', 'UDM', 'UXG', 'USG'])) {
            $udr = $d;
            break;
        }
    }

    $os_version = $sysinfo['console_display_version'] ?? 'Nieznana';
    if ($os_version === 'Nieznana' && isset($sysinfo['version'])) {
        $os_version = $sysinfo['version'];
    }
    
    return [
        'version' => $os_version,
        'model' => $udr['model'] ?? 'UniFi Gateway',
        'up_to_date' => !(isset($udr['upgradable']) && $udr['upgradable']),
        'update_ver' => $udr['upgrade_to_firmware'] ?? '',
        'uptime_pretty' => isset($sysinfo['uptime']) ? formatDuration($sysinfo['uptime']) : 'N/A',
        'cpu_usage' => (isset($udr['system-stats']['cpu'])) ? $udr['system-stats']['cpu'] : '0',
        'apps' => [
            [
                'name' => 'UniFi Network',
                'version' => $sysinfo['version'] ?? 'N/A',
                'status' => 'Active',
                'icon' => 'network',
                'color' => 'blue',
                'update' => (isset($udr['upgradable']) && $udr['upgradable'])
            ]
        ]
    ];
}

function formatDuration($seconds) {
    if ($seconds < 60) return $seconds . "s";
    $m = floor($seconds / 60);
    if ($m < 60) return $m . "m";
    $h = floor($m / 60);
    $m = $m % 60;
    if ($h < 24) return $h . "h " . $m . "m";
    $d = floor($h / 24);
    $h = $h % 24;
    return $d . "d " . $h . "h";
}

function fetch_api($endpoint)
{
    global $config;
    
    // Safety check just in case
    if (!function_exists('curl_init')) {
        return ['data' => [], 'error' => 'cURL not installed'];
    }

    try {
        $url = $config['controller_url'] . $endpoint;
        $apiKey = $config['api_key'];
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2); 
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "X-API-KEY: $apiKey",
            "Content-Type: application/json"
        ]);
        
        $output = curl_exec($ch);
        $curl_error = curl_error($ch);
        curl_close($ch);

        if ($output === false) {
            return ['data' => [], 'error' => $curl_error];
        }

        // Basic JSON decode
        $data = json_decode($output, true);
        
        // Deep search for 'data' array to handle various UniFi wrappers
        if (isset($data['data']) && is_array($data['data'])) {
            return $data;
        }
        
        // If response is a direct list, wrap it
        if (is_array($data) && (empty($data) || array_keys($data) === range(0, count($data) - 1))) {
            return ['data' => $data];
        }

        return ['data' => [], 'original' => $data];
    } catch (Throwable $e) {
        return ['data' => [], 'error' => $e->getMessage()];
    }
}

/**
 * Pobiera lokalizację na podstawie adresu IP
 */
function get_ip_location($ip) {
    if ($ip === '127.0.0.1' || $ip === '::1' || strpos($ip, '192.168.') === 0 || strpos($ip, '10.') === 0) {
        return 'Local Network';
    }
    
    // Check for Curl
    if (!function_exists('curl_init')) {
        return 'Unknown Location';
    }

    // Używamy ip-api.com (limit 45 req/min dla darmowej wersji, co przy logowaniu jest wystarczające)
    $url = "http://ip-api.com/json/" . urlencode($ip) . "?fields=status,country,city,regionName";
    
    try {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 3);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
        $response = curl_exec($ch);
        curl_close($ch);
        
        if ($response !== false) {
            $data = json_decode($response, true);
            if (($data['status'] ?? '') === 'success') {
                return ($data['city'] ?? '') . ", " . ($data['regionName'] ?? '') . " (" . ($data['country'] ?? '') . ")";
            }
        }
    } catch (Throwable $e) {
        // Fallback w razie błędu API / Fatal error
    }
    
    return 'Unknown Location';
}

/**
 * Loguje zdarzenie logowania do pliku data/login_history.json
 */
function log_login_event($username) {
    $dataFile = __DIR__ . '/data/login_history.json';
    $history = [];
    
    if (file_exists($dataFile)) {
        $history = json_decode(file_get_contents($dataFile), true) ?: [];
    }
    
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
    
    // Parse UA for display
    $os = 'Unknown OS';
    if (preg_match('/windows|win32/i', $ua)) $os = 'Windows';
    elseif (preg_match('/macintosh|mac os x/i', $ua)) $os = 'macOS';
    elseif (preg_match('/linux/i', $ua)) $os = 'Linux';
    elseif (preg_match('/android/i', $ua)) $os = 'Android';
    elseif (preg_match('/iphone/i', $ua)) $os = 'iOS';

    $browser = 'Browser';
    if (preg_match('/chrome/i', $ua)) $browser = 'Chrome';
    elseif (preg_match('/firefox/i', $ua)) $browser = 'Firefox';
    elseif (preg_match('/safari/i', $ua)) $browser = 'Safari';
    elseif (preg_match('/edge/i', $ua)) $browser = 'Edge';
    elseif (preg_match('/opera|opr/i', $ua)) $browser = 'Opera';

    $newEntry = [
        'timestamp' => time(),
        'username' => $username,
        'ip' => $ip,
        'location' => get_ip_location($ip),
        'os' => $os,
        'browser' => $browser,
        'ua' => $ua
    ];
    
    array_unshift($history, $newEntry);
    $history = array_slice($history, 0, 100); // Trzymaj ostatnie 100 logowań
    
    if (!is_dir(__DIR__ . '/data')) {
        @mkdir(__DIR__ . '/data', 0777, true);
    }
    
    file_put_contents($dataFile, json_encode($history, JSON_PRETTY_PRINT));
    
    // Dodatkowo wpis do tekstowego loga systemowego
    $txtLog = __DIR__ . '/logs/access.log';
    if (!is_dir(__DIR__ . '/logs')) @mkdir(__DIR__ . '/logs', 0777, true);
    $logLine = "[" . date('Y-m-d H:i:s') . "] LOGIN: $username from $ip ($newEntry[location]) - $ua\n";
    file_put_contents($txtLog, $logLine, FILE_APPEND);
}

function get_unifi_blocked_ips() {
    global $config;
    $siteId = $config['site'] ?? 'default';
    
    // Fetch latest blocked IPS events
    $resp = fetch_api("/proxy/network/api/s/$siteId/stat/ips/event?limit=100");
    $raw_events = $resp['data'] ?? [];
    
    $blocked = [];
    $seen_ips = [];
    
    foreach ($raw_events as $e) {
        if (!isset($e['src_ip']) || ($e['inner_alert_action'] ?? '') !== 'blocked') continue;
        
        // Only unique IPs for this list
        if (in_array($e['src_ip'], $seen_ips)) continue;
        $seen_ips[] = $e['src_ip'];
        
        $country = $e['country_code'] ?? '??';
        $time_diff = time() - ($e['time'] / 1000);
        
        if ($time_diff < 3600) $time_str = round($time_diff / 60) . 'm temu';
        else if ($time_diff < 86400) $time_str = round($time_diff / 3600) . 'h temu';
        else $time_str = round($time_diff / 86400) . 'd temu';
        
        $blocked[] = [
            'country_code' => strtolower($country),
            'ip' => $e['src_ip'],
            'type' => $e['inner_alert_category'] ?? 'Threat',
            'reason' => $e['inner_alert_signature'] ?? 'Automatyczna blokada IPS',
            'time' => $time_str
        ];
        
        if (count($blocked) >= 34) break; // Keep same UI limit for now
    }
    
    return $blocked;
}

function get_unifi_security_events() {
    global $config;
    $site = $config['site'] ?? 'default';
    
    // Fetch latest 50 IPS events
    $resp = fetch_api("/proxy/network/api/s/$site/stat/ips/event?limit=50");
    $raw_events = $resp['data'] ?? [];
    
    $events = [];
    foreach ($raw_events as $e) {
        $time = isset($e['time']) ? date('H:i:s', $e['time'] / 1000) : date('H:i:s');
        
        // Map UniFi severity/action to our dashboard format
        $severity = 'medium';
        if (isset($e['inner_alert_action'])) {
            if ($e['inner_alert_action'] === 'blocked') $severity = 'critical';
            else if ($e['inner_alert_action'] === 'alert') $severity = 'high';
        }
        
        $events[] = [
            'time' => $time,
            'type' => strtolower($e['inner_alert_category'] ?? 'intrusion'),
            'severity' => $severity,
            'source' => 'IPS Engine',
            'description' => 'Wykryto ' . ($e['inner_alert_signature'] ?? 'zagrożenie') . ' z IP ' . ($e['src_ip'] ?? 'nieznane'),
            'action' => (($e['inner_alert_action'] ?? '') === 'blocked' ? 'Zablokowano' : 'Wykryto')
        ];
    }
    
    return $events;
}

function get_unifi_security_settings() {
    global $config;
    
    // Performance: If we have settings in session and it's less than 30 seconds old, return it
    if (!empty($_SESSION['security_settings']) && !empty($_SESSION['security_settings_time'])) {
        if (time() - $_SESSION['security_settings_time'] < 30) {
            return $_SESSION['security_settings'];
        }
    }

    $site = $config['site'] ?? 'default';
    
    // 1. Fetch IPS/IDS Settings
    $ips_resp = fetch_api("/proxy/network/api/s/$site/rest/setting/ips");
    
    // $site_to_use = $site; // Removed debug marker
    $site_to_use = $site;
    if (($ips_resp['meta']['rc'] ?? '') === 'error') {
        $sites_resp = fetch_api("/proxy/network/api/self/sites");
        $discovered_site = $sites_resp['data'][0]['name'] ?? 'default';
        
        // file_put_contents(__DIR__ . '/data/debug_discovery.txt', "UUID ERROR: " . $ips_resp['meta']['msg'] . "\nDISCOVERED: $discovered_site\nALL SITES: " . print_r($sites_resp['data'], true)); // Removed debug marker
        
        $site_to_use = $discovered_site;
        $ips_resp = fetch_api("/proxy/network/api/s/$site_to_use/rest/setting/ips");
        
        if (($ips_resp['meta']['rc'] ?? '') !== 'ok' && $site_to_use !== 'default') {
            $ips_resp = fetch_api("/proxy/network/api/s/default/rest/setting/ips");
            if (($ips_resp['meta']['rc'] ?? '') === 'ok') {
                $site_to_use = 'default';
            }
        }
    } else {
        // If IPS was OK, but we want to be safe, try to extract the real UUID from the data if available
        $real_site_id = $ips_resp['data'][0]['site_id'] ?? '';
        if ($real_site_id) {
            $site_to_use = $real_site_id;
        }
    }
    // 2. Fetch Firewall Rules count (Try REST, v2, and STAT)
    $firewall_rules_resp = fetch_api("/proxy/network/api/s/$site_to_use/rest/firewallrule");
    if (empty($firewall_rules_resp['data']) || ($firewall_rules_resp['meta']['rc'] ?? '') === 'error') {
        $firewall_rules_resp = fetch_api("/proxy/network/api/v2/firewall/rules");
    }
    if (empty($firewall_rules_resp['data']) || ($firewall_rules_resp['meta']['rc'] ?? '') === 'error') {
        $firewall_rules_resp = fetch_api("/proxy/network/api/s/$site_to_use/stat/firewall/rules");
    }
    
    // Check Firewall Groups if rules are still empty
    $firewall_rules_count = count($firewall_rules_resp['data'] ?? []);
    if ($firewall_rules_count === 0) {
        $groups_resp = fetch_api("/proxy/network/api/s/$site_to_use/rest/firewallgroup");
        $firewall_rules_count = count($groups_resp['data'] ?? []);
    }
    
    // 3. Fetch Threats/Events count (last 24h)
    $threats_resp = fetch_api("/proxy/network/api/s/$site_to_use/stat/ips/event?period=86400");
    $threats_count = count($threats_resp['data'] ?? []);
    
    // 3a. Fetch VPN Status (Try multiple common endpoints + network purpose)
    $vpn_resp = fetch_api("/proxy/network/api/s/$site_to_use/rest/vpn");
    $vpn_server_resp = fetch_api("/proxy/network/api/s/$site_to_use/rest/vpnserver");
    $vpn_remote_resp = fetch_api("/proxy/network/api/s/$site_to_use/rest/remotevpn");
    $network_resp = fetch_api("/proxy/network/api/s/$site_to_use/rest/networkconf");
    
    $vpn_active = (count($vpn_resp['data'] ?? []) > 0) || 
                  (count($vpn_server_resp['data'] ?? []) > 0) || 
                  (count($vpn_remote_resp['data'] ?? []) > 0);
                  
    if (!$vpn_active && !empty($network_resp['data'])) {
        foreach ($network_resp['data'] as $net) {
            $purpose = $net['purpose'] ?? '';
            if ($purpose === 'remotevpn' || $purpose === 'vpn' || $purpose === 'remote-user-vpn' || $purpose === 'site-vpn') {
                if ($net['enabled'] ?? true) {
                    $vpn_active = true;
                    break;
                }
            }
        }
    }
    
    // 4. Fetch Traffic Rules (Flows) & Geo-blocking (Try REST, v2, and STAT)
    $traffic_rules_resp = fetch_api("/proxy/network/api/s/$site_to_use/rest/trafficrule");
    if (empty($traffic_rules_resp['data']) || ($traffic_rules_resp['meta']['rc'] ?? '') === 'error') {
        $v2_traffic = fetch_api("/proxy/network/api/v2/trafficrule");
        if (!empty($v2_traffic['data'])) $traffic_rules_resp = $v2_traffic;
    }
    
    $traffic_rules_count = count($traffic_rules_resp['data'] ?? []);
    
    // Build Rule List for Details Modal
    $rule_list = [];
    $raw_rules = $firewall_rules_resp['data'] ?? [];
    if (!empty($raw_rules)) {
        foreach ($raw_rules as $r) {
            $rule_list[] = [
                'id' => $r['_id'] ?? $r['id'] ?? 'N/A',
                'name' => $r['name'] ?? 'Firewall Rule',
                'category' => $r['ruleset'] ?? 'Network',
                'priority' => ($r['action'] ?? '') === 'drop' ? 'HIGH' : 'MEDIUM',
                'action' => strtoupper($r['action'] ?? 'ALLOW'),
                'status' => ($r['enabled'] ?? true) ? 'Aktywna' : 'Nieaktywna'
            ];
        }
    } else {
        // Fallback to groups for listing
        $groups_resp = fetch_api("/proxy/network/api/s/$site_to_use/rest/firewallgroup");
        foreach ($groups_resp['data'] ?? [] as $g) {
            $rule_list[] = [
                'id' => $g['_id'] ?? 'Group',
                'name' => $g['name'] ?? 'Security Group',
                'category' => $g['group_type'] ?? 'Address Group',
                'priority' => 'MEDIUM',
                'action' => 'SECURE',
                'status' => 'Aktywna'
            ];
        }
    }

    // Add traffic rules to list if any
    foreach ($traffic_rules_resp['data'] ?? [] as $tr) {
        $rule_list[] = [
            'id' => $tr['_id'] ?? $tr['id'] ?? 'Flow',
            'name' => $tr['description'] ?? $tr['name'] ?? 'Traffic Management Flow',
            'category' => 'Traffic Flow',
            'priority' => 'HIGH',
            'action' => strtoupper($tr['action'] ?? 'MATCH'),
            'status' => ($tr['enabled'] ?? true) ? 'Aktywna' : 'Nieaktywna'
        ];
    }
    $active_rules_total = count($rule_list);

    // 5. Fetch Legacy Geo-blocking status
    $geo_resp = fetch_api("/proxy/network/api/s/$site_to_use/rest/setting/countryblock");
    $geo_config = $geo_resp['data'][0] ?? [];
    $geoblocking_enabled = !empty($geo_config['enabled']);
    $blocked_countries = $geo_config['countries'] ?? [];

    // Auto-detect Geo-blocking from traffic rules if legacy is empty
    if (!$geoblocking_enabled && !empty($traffic_rules_resp['data'])) {
        foreach ($traffic_rules_resp['data'] as $rule) {
            // Check for country/region match in newer UniFi formats
            $target = $rule['target'] ?? '';
            if ($target === 'country' || $target === 'region' || isset($rule['target_countries']) || isset($rule['region_ids'])) {
                $geoblocking_enabled = true;
                if (isset($rule['target_countries'])) {
                    $blocked_countries = array_unique(array_merge($blocked_countries, $rule['target_countries']));
                }
            }
        }
    }
    
    // Final check for Geo in Firewall Rules (sometimes people block by country there)
    if (!$geoblocking_enabled && !empty($firewall_rules_resp['data'])) {
        foreach ($firewall_rules_resp['data'] as $rule) {
            if (isset($rule['src_country']) || isset($rule['dst_country']) || stripos($rule['name'] ?? '', 'geo') !== false) {
                $geoblocking_enabled = true;
                break;
            }
        }
    }
    
    // DEBUG ALL RAW (Extended) // Removed debug marker
    // file_put_contents(__DIR__ . '/data/debug_all_raw.txt', 
    //     "SITE: $site_to_use\n\n" .
    //     "IPS:\n" . print_r($ips_resp, true) . "\n\n" .
    //     "GEO:\n" . print_r($geo_resp, true) . "\n\n" .
    //     "FW RULES:\n" . print_r($firewall_rules_resp, true) . "\n\n" .
    //     "TRAFFIC RULES:\n" . print_r($traffic_rules_resp, true) . "\n\n" .
    //     "NETWORKS:\n" . print_r($network_resp, true) . "\n\n" .
    //     "VPN SERVER:\n" . print_r($vpn_server_resp, true) . "\n\n" .
    //     "RULE COUNT: " . count($rule_list) . "\n"
    // );

    // Extract settings
    $ips_config = $ips_resp['data'][0] ?? [];

    $settings = [
        'site_used' => $site_to_use,
        'ips_enabled' => isset($ips_config['ips_mode']) && $ips_config['ips_mode'] !== 'disabled',
        'ips_mode' => $ips_config['ips_mode'] ?? 'disabled',
        'ad_blocking_enabled' => isset($ips_config['ad_blocking_enabled']) && $ips_config['ad_blocking_enabled'],
        'honeypot_enabled' => isset($ips_config['honeypot_enabled']) && $ips_config['honeypot_enabled'],
        'dns_filtering_enabled' => isset($ips_config['dns_filtering']) && $ips_config['dns_filtering'],
        'threat_detection_enabled' => (isset($ips_config['ips_mode']) && $ips_config['ips_mode'] !== 'disabled') || (isset($ips_config['ad_blocking_enabled']) && $ips_config['ad_blocking_enabled']),
        'firewall_rules_count' => $firewall_rules_count,
        'traffic_rules_count' => $traffic_rules_count,
        'total_rules_count' => $active_rules_total,
        'rule_list' => $rule_list,
        'threats_count' => $threats_count,
        'geoblocking_enabled' => $geoblocking_enabled || !empty($blocked_countries),
        'blocked_countries' => $blocked_countries,
        'monitoring_active' => true,
        'vpn_secure' => $vpn_active
    ];
    
    $_SESSION['security_settings'] = $settings;
    $_SESSION['security_settings_time'] = time();
    return $settings;
}

// Funkcja do pobierania nazwy VLAN
// Lista zdefiniowanych VLANów
function get_vlans() {
    return [
        0 => 'VPN Connection',
        1 => 'Main',
        5 => 'Servers',
        10 => 'VoIP',
        20 => 'Guest',
        30 => 'IoT',
        40 => 'Cameras',
        55 => 'Kids',
        69 => 'OpenVPN',
        70 => 'VPN WireGuard',
        99 => 'Lab'
    ];
}

// Funkcja do wykrywania VLANu na podstawie IP (fallback)
function detect_vlan_id($ip, $current_vlan = null) {
    if ($current_vlan !== null && $current_vlan > 0) return (int)$current_vlan;
    
    if (empty($ip) || $ip === 'N/A' || $ip === 'Offline') return 0;
    
    if (strpos($ip, '10.0.0.') === 0) return 1;
    if (strpos($ip, '10.5.') === 0) return 5;
    if (strpos($ip, '10.10.') === 0) return 10;
    if (strpos($ip, '10.20.') === 0) return 20;
    if (strpos($ip, '10.30.') === 0) return 30;
    if (strpos($ip, '10.40.') === 0) return 40;
    if (strpos($ip, '10.55.') === 0) return 55;
    if (strpos($ip, '10.99.') === 0) return 99;
    
    // VPN subnets (user confirmed 10.69 and 10.70 are VPNs)
    if (strpos($ip, '10.69.') === 0) return 69;
    if (strpos($ip, '10.70.') === 0) return 70;
    
    // Default to 0 (VPN) for other unknown 10.x subnets if they are not explicitly Main
    if (strpos($ip, '10.') === 0) return 0;
    
    return 0;
}

function ip_in_subnet($ip, $subnet)
{
    if (strpos($subnet, '/') === false) $subnet .= '/32';
    list($net, $mask) = explode('/', $subnet);
    $ip_long = ip2long($ip);
    $net_long = ip2long($net);
    $mask_long = ~((1 << (32 - $mask)) - 1);
    return ($ip_long & $mask_long) == ($net_long & $mask_long);
}

function get_vpn_networks() {
    global $config;
    $site = $config['site'] ?? 'default';
    
    // Fetch network config
    // Note: detailed network config is at /rest/networkconf
    $resp = fetch_api("/proxy/network/api/s/$site/rest/networkconf");
    
    $vpns = [];
    if (!empty($resp['data'])) {
        foreach ($resp['data'] as $net) {
            // Note: user confirmed purpose='remote-user-vpn' creates these networks
            if (($net['purpose'] ?? '') === 'remote-user-vpn' && isset($net['ip_subnet'])) {
                // Determine sensible name: Name > Config Name > Type
                $name = $net['name'] ?? 'VPN';
                $type = $net['vpn_type'] ?? 'vpn';
                
                // Pretty print type
                if ($type === 'openvpn-server') $type = 'OpenVPN';
                elseif ($type === 'wireguard-server') $type = 'WireGuard';
                elseif ($type === 'l2tp') $type = 'L2TP';
                
                $vpns[] = [
                    'name' => $name,
                    'type' => $type,
                    'subnet' => $net['ip_subnet']
                ];
            }
        }
    }
    return $vpns;
}

/**
 * Pobiera dane z UniFi Protect (Kamery, Nagrania, Status)
 */
function get_unifi_protect_data() {
    // 1. Fetch Bootstrap (All Protect Data)
    $bootstrap = fetch_api("/proxy/protect/api/bootstrap");
    
    // RAW DEBUG for development // Removed debug marker
    // file_put_contents(__DIR__ . '/data/protect_bootstrap.txt', print_r($bootstrap, true));
    
    if (empty($bootstrap['data']) && !isset($bootstrap['cameras'])) {
        // Fallback for different API wrap formats
        $cameras_resp = fetch_api("/proxy/protect/api/cameras");
        $cameras = $cameras_resp['data'] ?? $cameras_resp['original'] ?? [];
    } else {
        $cameras = $bootstrap['cameras'] ?? $bootstrap['data']['cameras'] ?? [];
    }

    $processed_cameras = [];
    foreach ($cameras as $c) {
        $processed_cameras[] = [
            'id' => $c['id'] ?? 'unknown',
            'name' => $c['name'] ?? 'Kamerabez nazwy',
            'status' => (($c['state'] ?? '') === 'CONNECTED' ? 'online' : 'offline'),
            'recording' => $c['isRecording'] ?? false,
            'motion' => $c['isMotionDetected'] ?? false,
            'resolution' => ($c['ispSettings']['focusMode'] ?? '') . ' ' . ($c['ispSettings']['resolution'] ?? ''),
            'fps' => $c['ispSettings']['fps'] ?? 0,
            'model' => $c['model'] ?? 'G4/G5',
            'mac' => $c['mac'] ?? '',
            'ip' => $c['host'] ?? $c['connectionHost'] ?? '',
            'uptime' => $c['upSince'] ? (time() - ($c['upSince']/1000)) : 0,
            'thumbnail' => "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='400' height='300'%3E%3Crect fill='%231e293b' width='400' height='300'/%3E%3Ctext x='50%25' y='50%25' font-family='Arial' font-size='24' fill='%23475569' text-anchor='middle' dominant-baseline='middle'%3E" . urlencode($c['name'] ?? 'Kamera') . "%3C/text%3E%3C/svg%3E"
        ];
    }

    $nvr = $bootstrap['nvr'] ?? $bootstrap['data']['nvr'] ?? [];
    
    return [
        'cameras' => $processed_cameras,
        'stats' => [
            'total' => count($processed_cameras),
            'online' => count(array_filter($processed_cameras, fn($c) => $c['status'] === 'online')),
            'recording' => count(array_filter($processed_cameras, fn($c) => $c['recording'])),
            'motion' => count(array_filter($processed_cameras, fn($c) => $c['motion']))
        ],
        'nvr' => [
            'status' => $nvr['state'] ?? 'UNKNOWN',
            'uptime' => $nvr['upSince'] ?? 0,
            'storage' => [
                'used' => $nvr['storage']['used'] ?? 0,
                'total' => $nvr['storage']['size'] ?? 0,
                'utilization' => $nvr['storage']['utilization'] ?? 0
            ],
            'version' => $nvr['version'] ?? 'N/A'
        ]
    ];
}

// Funkcja do pobierania nazwy VLAN
function get_vlan_name($vlan)
{
    $vlan = (int)$vlan;
    $names = get_vlans();
    return $names[$vlan] ?? "VLAN $vlan";
}

function detect_known_devices(array $clients, array $devices): array
{
    $status = [];
    $client_data = [];
    foreach ($clients as $c) {
        $mac = normalize_mac($c['macAddress'] ?? $c['mac'] ?? '');
        if ($mac) {
            $ip = $c['ipAddress'] ?? $c['ip'] ?? 'N/A';
            $client_data[$mac] = [
                'ip' => $ip,
                'vlan' => $c['vlan'] ?? null,
                'rx_rate' => $c['rxRateBps'] ?? $c['rx_bytes-r'] ?? 0,
                'tx_rate' => $c['txRateBps'] ?? $c['tx_bytes-r'] ?? 0
            ];
        }
    }
    foreach ($devices as $mac => $device) {
        $norm = normalize_mac($mac);
        $vlan = $device['vlan'] ?? null;
        $ip = 'Offline';
        $st = 'off';
        
        if (isset($client_data[$norm])) {
             $st = 'on';
             $ip = $client_data[$norm]['ip'];
             $vlan = detect_vlan_id($ip, $client_data[$norm]['vlan'] ?? $vlan);
        }
        
        $status[$norm] = [
            'status' => $st,
            'ip' => $ip,
            'name' => $device['name'] ?? 'Unknown Device', // Added null check
            'vlan' => $vlan,
            'rx_rate' => $client_data[$norm]['rx_rate'] ?? 0,
            'tx_rate' => $client_data[$norm]['tx_rate'] ?? 0
        ];
    }
    return $status;
}

function group_devices_by_vlan(array $devices, array $clients): array
{
    $grouped = [];
    $statuses = detect_known_devices($clients, $devices);
    
    foreach ($statuses as $mac => $info) {
        $vlan = $info['vlan'] ?? 0;
        $vlan_name = get_vlan_name($vlan);
        
        $grouped[$vlan_name][] = [
            'mac' => $mac,
            'name' => $info['name'],
            'status' => $info['status'],
            'ip' => $info['ip']
        ];
    }
    return $grouped;
}

function saveDevices(array $devices)
{
    $devicesFile = __DIR__ . '/data/devices.json';
    file_put_contents($devicesFile, json_encode($devices, JSON_PRETTY_PRINT));
}

function loadDeviceHistory($mac)
{
    $historyFile = __DIR__ . '/data/history.json';
    if (!file_exists($historyFile)) return [];
    
    $history = json_decode(file_get_contents($historyFile), true) ?? [];
    $norm = normalize_mac($mac);
    return $history[$norm] ?? [];
}


function format_bps($bps) {
    if ($bps >= 1000000000) return number_format($bps / 1000000000, 2) . ' Gbps';
    if ($bps >= 1000000) return number_format($bps / 1000000, 2) . ' Mbps';
    if ($bps >= 1000) return number_format($bps / 1000, 1) . ' Kbps';
    return $bps . ' bps';
}

function formatBps($bps) {
    return format_bps($bps);
}

function format_bytes($bytes, $decimals = 2) {
    if (!$bytes) return '0 B';
    $k = 1024;
    $sizes = ['B', 'KB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB'];
    $i = floor(log($bytes) / log($k));
    return round($bytes / pow($k, $i), $decimals) . ' ' . $sizes[$i];
}

// Funkcja zapisująca historię ZMIAN statusu
function saveDeviceHistory($mac, $status)
{
    $historyFile = __DIR__ . '/data/history.json';
    $dir = dirname($historyFile);
    if (!file_exists($dir)) mkdir($dir, 0777, true);
    
    $allHistory = file_exists($historyFile) ? json_decode(file_get_contents($historyFile), true) : [];
    $norm = normalize_mac($mac);

    if (!isset($allHistory[$norm])) {
        $allHistory[$norm] = [];
    }

    $history = &$allHistory[$norm];
    $timestamp = date('Y-m-d H:i:s');

    // Sprawdzamy czy ostatni status był taki sam - jeśli tak, nic nie robimy
    if (!empty($history)) {
        $last = end($history);
        if ($last['status'] === $status) {
            return;
        }

        // Jeśli zmieniamy status na inny, obliczamy czas trwania poprzedniego stanu
        $last_key = array_key_last($history);
        $last_time = strtotime($history[$last_key]['timestamp']);
        $current_time = strtotime($timestamp);
        $history[$last_key]['duration'] = $current_time - $last_time;
    }

    // Wyślij alert o zmianie statusu
    $devices = loadDevices();
    $deviceName = $devices[$norm]['name'] ?? $mac;
    $statusText = ($status === 'on') ? "🟢 ONLINE" : "🔴 OFFLINE";
    sendAlert("Zmiana statusu: $deviceName", "Urządzenie **$deviceName** ($mac) jest teraz **$statusText**.");

    // Dodajemy nowy wpis o zmianie statusu
    $history[] = [
        'status' => $status,
        'timestamp' => $timestamp
    ];

    // Limitujemy historię do 50 ostatnich zmian
    if (count($history) > 50) {
        $history = array_slice($history, -50);
    }

    file_put_contents($historyFile, json_encode($allHistory, JSON_PRETTY_PRINT));
}

/**
 * Sprawdza stan IPS dla Zarządcy Procesów
 */
function get_ips_status() {
    static $status = null;
    if ($status !== null) return $status;
    
    // Prosta weryfikacja czy IPS jest włączony w configu/systemie
    global $config;
    if (isset($_SESSION['security_settings']['ips_enabled'])) {
        return $_SESSION['security_settings']['ips_enabled'];
    }
    
    return false;
}

/**
 * Pobiera ostatnie zdarzenia ze wszystkich urządzeń
 */
function get_recent_events($limit = 10, $only_new = false) {
    global $config;
    $historyFile = __DIR__ . '/data/history.json';
    if (!file_exists($historyFile)) return [];
    
    $allHistory = json_decode(file_get_contents($historyFile), true);
    if (!is_array($allHistory)) $allHistory = [];
    
    $devices = loadDevices();
    $events = [];
    
    $clear_time = 0;
    if ($only_new && !empty($config['last_notif_clear_time'])) {
        $clear_time = strtotime($config['last_notif_clear_time']);
    }
    
    foreach ($allHistory as $mac => $history) {
        if (!is_array($history)) continue;
        $deviceName = $devices[$mac]['name'] ?? $mac;
        foreach ($history as $entry) {
            if ($clear_time && strtotime($entry['timestamp']) <= $clear_time) {
                continue;
            }
            $events[] = array_merge($entry, [
                'mac' => $mac,
                'deviceName' => $deviceName
            ]);
        }
    }
    
    // Sortuj po czasie malejąco
    usort($events, function($a, $b) {
        return strtotime($b['timestamp']) - strtotime($a['timestamp']);
    });
    
    return array_slice($events, 0, $limit);
}

function debug_log($message, $data = null)
{
    global $config;
    if (empty($config['debug'])) return;

    $log_dir = __DIR__ . '/logs';
    if (!file_exists($log_dir)) {
        mkdir($log_dir, 0777, true);
    }

    $log_file = $log_dir . '/debug_' . date('Y-m-d') . '.log';
    $timestamp = date('Y-m-d H:i:s');
    $log_message = "[$timestamp] $message";

    if ($data !== null) {
        $log_message .= "\nData: " . json_encode($data, JSON_PRETTY_PRINT);
    }

    file_put_contents($log_file, $log_message . "\n", FILE_APPEND);
}

// Modal Ustawień Osobistych
function render_personal_modal() {
    global $config;
?>
    <div id="personalModal" class="modal-overlay" onclick="closePersonalModal(event)" style="z-index: 9999;">
        <div class="modal-container max-w-3xl p-0 overflow-hidden shadow-2xl ring-1 ring-white/10" onclick="event.stopPropagation()" style="background: #0f172a;">
            <!-- Modal Header -->
            <div class="p-8 pb-4 flex justify-between items-start">
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 rounded-2xl bg-emerald-500/10 text-emerald-400 flex items-center justify-center">
                        <i data-lucide="user-cog" class="w-6 h-6"></i>
                    </div>
                    <div>
                        <h2 class="text-2xl font-bold text-white tracking-tight">Dane Osobiste</h2>
                        <p class="text-slate-500 text-xs mt-1">Zarządzaj swoim profilem i bezpieczeństwem</p>
                    </div>
                </div>
                <button type="button" onclick="closePersonalModal()" class="p-2 text-slate-500 hover:text-white transition rounded-xl">
                    <i data-lucide="x" class="w-6 h-6"></i>
                </button>
            </div>

            <form id="personalForm" onsubmit="savePersonalSettings(event)" class="p-6 pt-2 space-y-5 max-h-[80vh] overflow-y-auto custom-scrollbar">
                <input type="hidden" name="action" value="update_profile">
                
                <div class="h-[1px] bg-white/5 w-full mb-6"></div>

                <div class="grid grid-cols-1 md:grid-cols-12 gap-10 items-start">
                    <!-- Left: Avatar Section -->
                    <div class="md:col-span-5 flex items-center gap-6">
                        <div class="relative">
                            <div id="avatar-preview" class="w-32 h-32 rounded-[2.5rem] overflow-hidden ring-4 ring-white/5 shadow-2xl bg-blue-600/20">
                                <?php if (!empty($config['admin_avatar']) && file_exists(__DIR__ . '/' . $config['admin_avatar'])): ?>
                                    <img src="<?= $config['admin_avatar'] ?>" class="w-full h-full object-cover">
                                <?php else: ?>
                                    <div class="w-full h-full bg-gradient-to-br from-blue-500 to-indigo-600 flex items-center justify-center text-white">
                                        <i data-lucide="user" class="w-12 h-12"></i>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <label for="avatar-input" class="absolute -bottom-1 -right-1 w-10 h-10 rounded-xl bg-[#1e293b] text-white flex items-center justify-center cursor-pointer shadow-lg border border-white/10 hover:bg-[#334155] transition-all">
                                <i data-lucide="camera" class="w-5 h-5"></i>
                                <input type="file" id="avatar-input" name="avatar" class="hidden" accept="image/*" onchange="previewAvatar(this)">
                            </label>
                        </div>
                        <div>
                            <p class="text-xl font-bold text-white mb-1">Twój Avatar</p>
                            <p class="text-[10px] font-black text-slate-500 uppercase tracking-widest">Zmień zdjęcie profilowe</p>
                        </div>
                    </div>

                    <!-- Right: Form Fields -->
                    <div class="md:col-span-7 grid grid-cols-2 gap-x-4 gap-y-6">
                        <div class="col-span-1">
                            <label class="block text-[9px] font-black text-slate-500 uppercase tracking-widest mb-2 px-1">Login / Użytkownik</label>
                            <input type="text" name="username" value="<?= htmlspecialchars($config['admin_username']) ?>" required
                                   class="w-full px-5 py-3.5 bg-slate-900/50 border border-white/10 rounded-2xl focus:outline-none focus:ring-2 focus:ring-blue-500/30 text-xs font-bold transition-all text-slate-200">
                        </div>
                        <div class="col-span-1">
                            <label class="block text-[9px] font-black text-slate-500 uppercase tracking-widest mb-2 px-1">Nowe Hasło</label>
                            <input type="password" name="password" placeholder="••••••••"
                                   class="w-full px-5 py-3.5 bg-slate-900/50 border border-white/10 rounded-2xl focus:outline-none focus:ring-2 focus:ring-blue-500/30 text-xs font-bold transition-all text-slate-200">
                        </div>
                        <div class="col-span-1">
                            <label class="block text-[9px] font-black text-slate-500 uppercase tracking-widest mb-2 px-1">Imię i Nazwisko</label>
                            <input type="text" name="full_name" value="<?= htmlspecialchars($config['admin_full_name'] ?? '') ?>"
                                   class="w-full px-5 py-3.5 bg-slate-900/50 border border-white/10 rounded-2xl focus:outline-none focus:ring-2 focus:ring-blue-500/30 text-xs font-bold transition-all text-slate-200">
                        </div>
                        <div class="col-span-1">
                            <label class="block text-[9px] font-black text-slate-500 uppercase tracking-widest mb-2 px-1">Adres Email</label>
                            <input type="email" name="email" value="<?= htmlspecialchars($config['admin_email']) ?>"
                                   class="w-full px-5 py-3.5 bg-slate-900/50 border border-white/10 rounded-2xl focus:outline-none focus:ring-2 focus:ring-blue-500/30 text-xs font-bold transition-all text-slate-200">
                        </div>
                    </div>
                </div>

                <!-- Login History Section -->
                <div class="mt-6">
                    <div class="mb-4 flex items-center justify-between">
                        <h3 class="text-sm font-bold text-white uppercase tracking-wider">Historia Logowań</h3>
                         <button type="button" class="text-[10px] text-blue-400 font-bold uppercase tracking-widest hover:text-white transition">
                            Zobacz całą historię
                        </button>
                    </div>
                    <div class="bg-slate-900 border border-white/10 rounded-2xl overflow-hidden">
                        <div class="divide-y divide-white/5">
                             <!-- Active Session -->
                             <?php
                                $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
                                $browser = 'Unknown Browser';
                                $os = 'Unknown OS';
                                if (preg_match('/windows|win32/i', $ua)) $os = 'Windows';
                                elseif (preg_match('/macintosh|mac os x/i', $ua)) $os = 'macOS';
                                elseif (preg_match('/linux/i', $ua)) $os = 'Linux';
                                elseif (preg_match('/android/i', $ua)) $os = 'Android';
                                elseif (preg_match('/iphone/i', $ua)) $os = 'iOS';

                                if (preg_match('/chrome/i', $ua)) $browser = 'Chrome';
                                elseif (preg_match('/firefox/i', $ua)) $browser = 'Firefox';
                                elseif (preg_match('/safari/i', $ua)) $browser = 'Safari';
                                elseif (preg_match('/edge/i', $ua)) $browser = 'Edge';
                                
                                $client_ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
                                $current_loc = get_ip_location($client_ip);

                                $historyFile = __DIR__ . '/data/login_history.json';
                                $historyData = [];
                                if (file_exists($historyFile)) {
                                    $loaded = json_decode(file_get_contents($historyFile), true);
                                    if (is_array($loaded)) $historyData = $loaded;
                                }
                             ?>
                             <div class="p-4 flex items-center justify-between hover:bg-white/[0.02] transition">
                                <div class="flex items-center gap-4">
                                    <div class="w-10 h-10 rounded-xl bg-emerald-500/10 text-emerald-500 flex items-center justify-center shadow-lg shadow-emerald-500/10">
                                        <i data-lucide="laptop" class="w-5 h-5"></i>
                                    </div>
                                    <div>
                                        <div class="text-xs font-bold text-white">Obecna Sesja (<?= $os ?>)</div>
                                        <div class="text-[10px] text-slate-500 font-mono mt-0.5">IP: <?= $client_ip ?> • <?= $browser ?></div>
                                        <div class="text-[9px] text-blue-400 font-bold mt-0.5 flex items-center gap-1.5">
                                            <i data-lucide="map-pin" class="w-2.5 h-2.5"></i>
                                            <?= $current_loc ?>
                                        </div>
                                    </div>
                                </div>
                                <span class="px-2 py-1 rounded bg-emerald-500/10 text-emerald-400 text-[10px] font-bold uppercase tracking-wider animate-pulse ring-1 ring-emerald-500/20">Aktywna</span>
                            </div>

                            <!-- Past History -->
                            <?php 
                            $hist_count = 0;
                            if (!empty($historyData)):
                                foreach ($historyData as $idx => $entry):
                                    // Skip current (first entry in file is newest, likely current or just previous)
                                    if ($idx === 0 && ($entry['ip'] ?? '') === $client_ip) continue;
                                    if ($hist_count >= 3) break;
                                    $hist_count++;
                            ?>
                             <div class="p-4 flex items-center justify-between hover:bg-white/[0.02] transition border-t border-white/[0.02]">
                                <div class="flex items-center gap-4">
                                    <div class="w-10 h-10 rounded-xl bg-slate-800 text-slate-500 flex items-center justify-center">
                                        <i data-lucide="history" class="w-5 h-5"></i>
                                    </div>
                                    <div>
                                        <div class="text-xs font-bold text-slate-300"><?= $entry['os'] ?? 'OS' ?> • <?= $entry['browser'] ?? 'Browser' ?></div>
                                        <div class="text-[10px] text-slate-500 font-mono mt-0.5"><?= date('d.m.Y H:i', $entry['timestamp']) ?> • IP: <?= $entry['ip'] ?></div>
                                        <div class="text-[9px] text-slate-500 mt-0.5 flex items-center gap-1.5">
                                            <i data-lucide="map-pin" class="w-2.5 h-2.5"></i>
                                            <?= $entry['location'] ?? 'Unknown' ?>
                                        </div>
                                    </div>
                                </div>
                                <span class="text-[9px] text-slate-600 font-black uppercase tracking-widest">Logowanie</span>
                            </div>
                            <?php 
                                endforeach;
                            endif;

                            if ($hist_count === 0): ?>
                             <div class="p-6 flex items-center justify-center text-slate-600 text-[9px] uppercase font-black tracking-[0.2em] italic">
                                Brak starszych aktywności
                             </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- 2FA Section -->
                <div class="mt-6 border border-white/10 rounded-2xl p-5 bg-gradient-to-br from-slate-900 to-slate-950 flex flex-col justify-center relative group overflow-hidden">
                     <div class="absolute inset-0 bg-blue-500/5 opacity-0 group-hover:opacity-100 transition-opacity"></div>
                     <div class="flex items-center gap-4 relative z-10">
                        <div class="w-12 h-12 rounded-xl bg-blue-600/10 text-blue-500 flex items-center justify-center border border-blue-600/20 group-hover:scale-110 transition-transform shadow-lg shadow-blue-900/20">
                             <i data-lucide="shield-check" class="w-6 h-6"></i>
                        </div>
                        <div>
                            <h3 class="text-sm font-bold text-white">Podwójne Uwierzytelnianie (2FA)</h3>
                            <p class="text-[10px] font-black text-blue-400/80 uppercase tracking-widest mt-1">Coming Soon...</p>
                        </div>
                    </div>
                    <div class="mt-4 pt-4 border-t border-white/5 flex justify-between items-center relative z-10 opacity-50 pointer-events-none">
                        <span class="text-[10px] text-slate-500 font-medium">Zabezpiecz konto dodatkową warstwą ochrony.</span>
                        <div class="w-10 h-5 bg-slate-800 rounded-full relative border border-white/10">
                             <div class="absolute left-0.5 top-0.5 w-4 h-4 bg-slate-600 rounded-full"></div>
                        </div>
                    </div>
                </div>

                <div class="pt-6 flex justify-end sticky bottom-0 bg-slate-900/95 backdrop-blur py-4 -mx-6 px-6 border-t border-white/5 mt-4">
                    <button type="submit" class="px-12 py-4 bg-blue-600 hover:bg-blue-500 text-white font-black rounded-2xl transition shadow-xl shadow-blue-600/20 text-[11px] uppercase tracking-[0.2em] flex items-center justify-center gap-2">
                         Zapisz Dane
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openPersonalModal() {
            const modal = document.getElementById('personalModal');
            if (modal) {
                modal.classList.add('active');
                document.body.style.overflow = 'hidden';
                lucide.createIcons();
            } else {
                console.error('personalModal not found!');
            }
        }

        function closePersonalModal(e) {
            if (e && e.target !== e.currentTarget) return;
            const modal = document.getElementById('personalModal');
            if (modal) {
                modal.classList.remove('active');
                document.body.style.overflow = '';
            }
        }

        function previewAvatar(input) {
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    const preview = document.getElementById('avatar-preview');
                    if (preview) {
                        preview.innerHTML = `<img src="${e.target.result}" class="w-full h-full object-cover">`;
                    }
                };
                reader.readAsDataURL(input.files[0]);
            }
        }

        async function savePersonalSettings(e) {
            e.preventDefault();
            const form = e.target;
            const formData = new FormData(form);
            const btn = form.querySelector('button[type="submit"]');
            const originalText = btn.innerHTML;
            
            btn.disabled = true;
            btn.innerHTML = '<i data-lucide="refresh-cw" class="w-4 h-4 animate-spin mr-2"></i> Zapisywanie...';
            lucide.createIcons();

            try {
                const response = await fetch('api_user_settings.php', {
                    method: 'POST',
                    body: formData
                });
                const result = await response.json();
                
                if (result.success) {
                    // Refresh current page to see changes
                    location.reload();
                } else {
                    alert('Błąd: ' + result.message);
                }
            } catch (err) {
                console.error(err);
                alert('Wystąpił nieoczekiwany błąd zapisu.');
            } finally {
                btn.disabled = false;
                btn.innerHTML = originalText;
                lucide.createIcons();
            }
        }
    </script>
    <?php
}

// Globalna stopka
function render_footer() {
    ?>
    <div class="mt-20 pb-12">
        <div class="max-w-6xl mx-auto px-6">
            <div class="h-[1px] bg-white/5 w-full mb-8"></div>
            <footer class="text-center">
                <div class="flex items-center justify-center gap-3 text-slate-500 text-[11px] uppercase tracking-[0.3em] font-black">
                    MiniDASH v1.5.0 © 2026 
                    <span class="text-slate-700">/</span>
                    <a href="https://www.lm-ads.com" target="_blank" class="text-slate-400 hover:text-blue-400 transition hover:tracking-[0.4em]">lm-network</a> 
                    <span class="text-slate-700">/</span>
                    Łukasz Misiura
                </div>
            </footer>
        </div>
    </div>
    <?php
    render_personal_modal();
}

function render_nav($title = "UniFi MiniDash", $stats = []) {
    echo "<!-- NAV_START -->";
    global $config;
    $current_page = basename($_SERVER['PHP_SELF']);
    
    $cpu = $stats['cpu'] ?? 0;
    $ram = $stats['ram'] ?? 0;
    $down = $stats['down'] ?? 0;
    $up = $stats['up'] ?? 0;
    
    // Fetch notifications early for badge display
    $recent_events = get_recent_events(15, true);
    ?>
    <nav class="fixed top-0 left-0 right-0 h-16 bg-slate-900/80 backdrop-blur-md border-b border-white/5 z-50 px-4 md:px-8">
        <div class="max-w-[1400px] mx-auto h-full flex items-center justify-between">
            <div class="flex items-center gap-6">
                <!-- Logo & Refresh -->
                <a href="index.php" title="UniFi MiniDash" class="flex items-center gap-4 group">
                    <img src="img/lm-network.svg" alt="MiniDASH v1.2.0" class="h-10 w-auto opacity-90 group-hover:opacity-100 transition-opacity">
                    <div class="flex items-baseline gap-3">
                        <h1 class="text-lg font-black bg-clip-text text-transparent bg-gradient-to-r from-blue-400 to-indigo-400 tracking-tighter">
                            MiniDASH
                        </h1>
                        <div class="h-5 w-[1px] bg-white/10 hidden sm:block"></div>
                        <div class="flex items-center gap-3">
                            <div id="nav-clock" class="text-sm font-mono text-slate-400 tracking-wider font-bold hidden md:block">
                                <?= date('d/m/Y | H:i') ?>
                            </div>
                              <button onclick="smartRefresh();" class="w-8 h-8 flex items-center justify-center text-slate-500 hover:text-white hover:bg-white/10 rounded-lg transition" title="Odśwież stronę">
                                <i data-lucide="refresh-cw" class="w-4 h-4 transition-transform duration-500"></i>
                            </button>
                        </div>
                    </div>
                </a>

                <script>
                    function smartRefresh() {
                        const btn = document.querySelector('button[title="Odśwież stronę"]');
                        const icon = btn ? btn.querySelector('[data-lucide="refresh-cw"]') : null;
                        
                        if (icon) icon.style.transform = 'rotate(360deg)';
                        
                        // Check if we are on dashboard and have AJAX refresh
                        if (typeof window.updateStats === 'function') {
                            window.updateStats();
                            
                            // If any specific modal is open, try to refresh its content
                            const clientsModal = document.getElementById('clientsModal');
                            if (clientsModal && clientsModal.classList.contains('active') && typeof window.refreshClientsList === 'function') {
                                window.refreshClientsList();
                            }

                            const monitoringGrid = document.getElementById('monitoring-grid');
                            if (monitoringGrid && typeof window.refreshMonitoringGrid === 'function') {
                                window.refreshMonitoringGrid();
                            }

                            setTimeout(() => { if (icon) icon.style.transform = ''; }, 500);
                            return;
                        }

                        // Fallback for other pages or if AJAX refresh not available
                        location.reload();
                    }
                </script>

                <!-- Quick Nav Links -->
                <div class="hidden lg:flex items-center gap-1 ml-4">
                    <a href="index.php" class="p-2.5 rounded-xl transition-all <?= $current_page == 'index.php' ? 'bg-blue-600/10 text-blue-400' : 'text-slate-500 hover:text-slate-300 hover:bg-white/5' ?>" title="Dashboard">
                        <i data-lucide="layout-dashboard" class="w-5 h-5"></i>
                    </a>
                    <a href="monitored.php" class="p-2.5 rounded-xl transition-all <?= $current_page == 'monitored.php' ? 'bg-emerald-600/10 text-emerald-400' : 'text-slate-500 hover:text-slate-300 hover:bg-white/5' ?>" title="Zasoby">
                        <i data-lucide="activity" class="w-5 h-5"></i>
                    </a>
                    <a href="protect.php" class="p-2.5 rounded-xl transition-all <?= $current_page == 'protect.php' ? 'bg-purple-600/10 text-purple-400' : 'text-slate-500 hover:text-slate-300 hover:bg-white/5' ?>" title="Protect">
                        <i data-lucide="video" class="w-5 h-5"></i>
                    </a>
                    <a href="security.php" class="p-2.5 rounded-xl transition-all <?= $current_page == 'security.php' ? 'bg-rose-600/10 text-rose-400' : 'text-slate-500 hover:text-slate-300 hover:bg-white/5' ?>" title="Security">
                        <i data-lucide="shield" class="w-5 h-5"></i>
                    </a>
                    <a href="logs.php" class="p-2.5 rounded-xl transition-all <?= $current_page == 'logs.php' ? 'bg-amber-600/10 text-amber-400' : 'text-slate-500 hover:text-slate-300 hover:bg-white/5' ?>" title="Logs">
                        <i data-lucide="file-text" class="w-5 h-5"></i>
                    </a>
                </div>
            </div>

            <div class="flex items-center gap-4">
                <!-- System Monitor (Task Manager Style) -->
                <div onclick="openProcessModal()" class="hidden xl:flex items-center gap-6 px-1 transition-all cursor-pointer group">
                    <div class="flex flex-col gap-1.5">
                        <div class="flex items-center gap-2">
                            <span class="text-[9px] font-black text-slate-500 uppercase tracking-tighter w-6">CPU</span>
                            <div class="w-16 h-1.5 bg-slate-800 rounded-full overflow-hidden">
                                <div class="h-full bg-blue-500 rounded-full" style="width: <?= $cpu ?>%"></div>
                            </div>
                            <span class="text-[9px] font-mono font-bold text-blue-400 w-8 text-right"><?= round($cpu) ?>%</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="text-[9px] font-black text-slate-500 uppercase tracking-tighter w-6">RAM</span>
                            <div class="w-16 h-1.5 bg-slate-800 rounded-full overflow-hidden">
                                <div class="h-full bg-blue-400 rounded-full" style="width: <?= $ram ?>%"></div>
                            </div>
                            <span class="text-[9px] font-mono font-bold text-blue-300 w-8 text-right"><?= round($ram) ?>%</span>
                        </div>
                    </div>
                    <div class="h-6 w-[1px] bg-white/10"></div>
                    <div class="flex flex-col gap-0.5">
                        <div class="flex items-center gap-1.5 text-blue-400">
                             <i data-lucide="arrow-up" class="w-3 h-3"></i>
                             <span class="text-[11px] font-mono font-bold"><?= format_bps($up) ?></span>
                        </div>
                        <div class="flex items-center gap-1.5 text-emerald-400">
                             <i data-lucide="arrow-down" class="w-3 h-3"></i>
                             <span class="text-[11px] font-mono font-bold"><?= format_bps($down) ?></span>
                        </div>
                    </div>
                </div>

                <div class="h-8 w-[1px] bg-white/10 hidden xl:block"></div>

                <!-- Alerts / Bell -->
                <button onclick="toggleNotifications()" class="p-2.5 text-slate-500 hover:text-amber-400 hover:bg-amber-400/5 rounded-xl transition relative group">
                    <i data-lucide="bell" class="w-5 h-5"></i>
                    <?php if (!empty($recent_events)): ?>
                        <span id="notif-badge" class="absolute top-2.5 right-2.5 w-2 h-2 bg-amber-500 rounded-full border-2 border-slate-900"></span>
                    <?php endif; ?>
                </button>

                <div class="h-8 w-[1px] bg-white/10 mx-2"></div>

                <!-- User Dropdown -->
                <div class="relative group dropdown-proxy">
                    <button class="flex items-center p-1 hover:opacity-80 transition-opacity shrink-0">
                        <?php if (!empty($config['admin_avatar']) && file_exists(__DIR__ . '/' . $config['admin_avatar'])): ?>
                            <img src="<?= $config['admin_avatar'] ?>" class="w-9 h-9 rounded-2xl object-cover shadow-lg shadow-blue-500/20 ring-1 ring-white/10 shrink-0">
                        <?php else: ?>
                            <div class="w-9 h-9 rounded-2xl bg-gradient-to-br from-blue-500 to-indigo-600 flex items-center justify-center text-white shadow-lg shadow-blue-500/20 ring-1 ring-white/10 shrink-0">
                                <i data-lucide="user" class="w-5 h-5"></i>
                            </div>
                        <?php endif; ?>
                    </button>

                    <!-- Dropdown Content -->
                    <div class="absolute right-0 top-full pt-3 w-72 opacity-0 translate-y-2 pointer-events-none group-hover:opacity-100 group-hover:translate-y-0 group-hover:pointer-events-auto transition-all duration-300 z-[100]">
                        <div class="bg-slate-900 border border-white/10 rounded-2xl shadow-2xl overflow-hidden">
                        
                        <!-- Mobile Only Section (Clock + Nav) -->
                        <div class="lg:hidden p-4 border-b border-white/10 bg-slate-950/50">
                            <!-- Mobile Clock (Visible < md) -->
                            <div class="md:hidden flex items-center justify-between mb-4 px-1">
                                <div class="text-[10px] font-bold text-slate-500 uppercase tracking-widest" id="mobile-nav-date"><?= date('d/m/Y') ?></div>
                                <div class="text-lg font-black text-white tracking-widest" id="mobile-nav-time"><?= date('H:i') ?></div>
                            </div>
                            
                            <!-- Mobile Nav Grid -->
                            <div class="grid grid-cols-4 gap-2">
                                 <a href="index.php" class="aspect-square rounded-xl flex flex-col items-center justify-center gap-1 border border-white/5 <?= $current_page == 'index.php' ? 'bg-blue-600/20 text-blue-400 ring-1 ring-blue-500/50' : 'bg-slate-800/50 text-slate-400 hover:bg-slate-700 hover:text-white' ?>" title="Dashboard">
                                    <i data-lucide="layout-dashboard" class="w-5 h-5"></i>
                                 </a>
                                 <a href="monitored.php" class="aspect-square rounded-xl flex flex-col items-center justify-center gap-1 border border-white/5 <?= $current_page == 'monitored.php' ? 'bg-emerald-600/20 text-emerald-400 ring-1 ring-emerald-500/50' : 'bg-slate-800/50 text-slate-400 hover:bg-slate-700 hover:text-white' ?>" title="Resources">
                                    <i data-lucide="activity" class="w-5 h-5"></i>
                                 </a>
                                  <a href="protect.php" class="aspect-square rounded-xl flex flex-col items-center justify-center gap-1 border border-white/5 <?= $current_page == 'protect.php' ? 'bg-purple-600/20 text-purple-400 ring-1 ring-purple-500/50' : 'bg-slate-800/50 text-slate-400 hover:bg-slate-700 hover:text-white' ?>" title="Protect">
                                    <i data-lucide="video" class="w-5 h-5"></i>
                                 </a>
                                  <a href="security.php" class="aspect-square rounded-xl flex flex-col items-center justify-center gap-1 border border-white/5 <?= $current_page == 'security.php' ? 'bg-rose-600/20 text-rose-400 ring-1 ring-rose-500/50' : 'bg-slate-800/50 text-slate-400 hover:bg-slate-700 hover:text-white' ?>" title="Security">
                                    <i data-lucide="shield" class="w-5 h-5"></i>
                                 </a>
                                 <a href="logs.php" class="aspect-square rounded-xl flex flex-col items-center justify-center gap-1 border border-white/5 <?= $current_page == 'logs.php' ? 'bg-amber-600/20 text-amber-400 ring-1 ring-amber-500/50' : 'bg-slate-800/50 text-slate-400 hover:bg-slate-700 hover:text-white' ?>" title="Logs">
                                    <i data-lucide="file-text" class="w-5 h-5"></i>
                                 </a>
                            </div>
                        </div>
                        <div class="p-5 bg-gradient-to-br from-slate-800 to-slate-900 border-b border-white/10">
                            <div class="flex items-center gap-4">
                                <?php if (!empty($config['admin_avatar']) && file_exists(__DIR__ . '/' . $config['admin_avatar'])): ?>
                                    <img src="<?= $config['admin_avatar'] ?>" class="w-10 h-10 rounded-xl object-cover shadow-xl">
                                <?php else: ?>
                                    <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-blue-500 to-indigo-600 flex items-center justify-center text-white shadow-xl">
                                        <i data-lucide="user" class="w-5 h-5"></i>
                                    </div>
                                <?php endif; ?>
                                <div class="flex flex-col min-w-0">
                                    <span class="text-sm font-bold text-white truncate"><?= htmlspecialchars($config['admin_username']) ?></span>
                                    <span class="text-[10px] text-slate-500 truncate"><?= htmlspecialchars($config['admin_email']) ?></span>
                                </div>
                            </div>
                        </div>
                        <div class="p-2 bg-slate-900">
                            <button onclick="openPersonalModal()" class="w-full flex items-center gap-3 px-4 py-2.5 text-xs text-slate-400 hover:text-white hover:bg-white/5 rounded-xl transition text-left">
                                <i data-lucide="user-cog" class="w-4 h-4 text-emerald-400/80"></i>
                                Osobiste
                            </button>
                            <button onclick="openSystemInfoModal()" class="w-full flex items-center gap-3 px-4 py-2.5 text-xs text-slate-400 hover:text-white hover:bg-white/5 rounded-xl transition text-left">
                                <i data-lucide="info" class="w-4 h-4 text-amber-400/80"></i>
                                O systemie
                            </button>
                            <a href="devices.php" class="flex items-center gap-3 px-4 py-2.5 text-xs text-slate-400 hover:text-white hover:bg-white/5 rounded-xl transition">
                                <i data-lucide="settings" class="w-4 h-4 text-blue-400/80"></i>
                                Ustawienia systemu
                            </a>
                            <div class="h-[1px] bg-white/5 my-2 mx-2"></div>
                            <form action="logout.php" method="POST">
                                <button type="submit" class="w-full flex items-center gap-3 px-4 py-2.5 text-xs text-red-500/80 hover:text-red-400 hover:bg-red-500/5 rounded-xl transition text-left">
                                    <i data-lucide="log-out" class="w-4 h-4"></i>
                                    Wyloguj sesję
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    </nav>
    <div class="h-16"></div>
    <!-- Notification Panel (Slide-out) -->
    <div id="notif-overlay" onclick="toggleNotifications()" class="fixed inset-0 bg-slate-950/40 backdrop-blur-sm z-[60] opacity-0 pointer-events-none transition-opacity duration-300"></div>
    <div id="notif-sidebar" class="fixed top-0 right-0 w-full max-w-sm h-screen bg-slate-900/95 backdrop-blur-xl border-l border-white/10 z-[70] translate-x-full transition-transform duration-500 ease-in-out shadow-2xl flex flex-col">
        <!-- Header -->
        <div class="p-6 border-b border-white/5 flex items-center justify-between bg-white/[0.02]">
            <div class="flex items-center gap-3">
                <div class="w-8 h-8 rounded-lg bg-amber-500/10 text-amber-500 flex items-center justify-center">
                    <i data-lucide="bell-ring" class="w-4 h-4"></i>
                </div>
                <div>
                    <h3 class="text-base font-black text-white uppercase tracking-widest">Powiadomienia</h3>
                    <p class="text-[10px] text-slate-500 font-bold uppercase tracking-tighter">Ostatnie zdarzenia systemowe</p>
                </div>
            </div>
            <div class="flex items-center gap-2">
                <button onclick="openNotifSettings()" class="p-2 text-slate-500 hover:text-white transition rounded-lg hover:bg-white/5" title="Ustawienia powiadomień">
                    <i data-lucide="settings" class="w-4 h-4"></i>
                </button>
                <button onclick="toggleNotifications()" class="p-2 text-slate-500 hover:text-white transition rounded-lg hover:bg-white/5">
                    <i data-lucide="x" class="w-5 h-5"></i>
                </button>
            </div>
        </div>

        <!-- Content -->
        <div class="flex-grow overflow-y-auto custom-scrollbar p-4 space-y-3">
            <?php 
            if (empty($recent_events)): 
            ?>
                <!-- Empty state -->
                <div id="no-notifs" class="py-20 flex flex-col items-center justify-center text-center opacity-40">
                    <div class="w-16 h-16 rounded-3xl bg-slate-800 flex items-center justify-center mb-4 border border-white/5">
                        <i data-lucide="bell-off" class="w-8 h-8 text-slate-600"></i>
                    </div>
                    <p class="text-xs font-bold text-slate-500 uppercase tracking-widest">Brak zdarzeń</p>
                </div>
            <?php else: ?>
                <?php foreach ($recent_events as $ev): 
                    $is_up = ($ev['status'] === 'on');
                    $color = $is_up ? 'emerald' : 'red';
                    $icon = $is_up ? 'check-circle' : 'alert-circle';
                    $time = strtotime($ev['timestamp']);
                    $diff = time() - $time;
                    
                    if ($diff < 60) $time_str = "teraz";
                    elseif ($diff < 3600) $time_str = floor($diff/60) . " min temu";
                    elseif ($diff < 86400) $time_str = floor($diff/3600) . " godz. temu";
                    else $time_str = date('d.m H:i', $time);
                ?>
                    <div onclick="window.location.href='history.php?mac=<?= $ev['mac'] ?>'" class="p-4 rounded-2xl bg-white/[0.03] border border-white/5 hover:bg-white/[0.08] hover:border-blue-500/30 transition group relative overflow-hidden cursor-pointer">
                        <div class="absolute left-0 top-0 bottom-0 w-1 bg-<?= $color ?>-500"></div>
                        <div class="flex gap-3">
                            <div class="w-8 h-8 rounded-xl bg-<?= $color ?>-500/10 text-<?= $color ?>-400 flex-shrink-0 flex items-center justify-center">
                                <i data-lucide="<?= $icon ?>" class="w-4 h-4"></i>
                            </div>
                            <div class="flex-grow">
                                <div class="flex justify-between items-start mb-1">
                                    <span class="text-xs font-black text-<?= $color ?>-500 uppercase tracking-widest">
                                        <?= $is_up ? 'Device Online' : 'Device Offline' ?>
                                    </span>
                                    <span class="text-[11px] text-slate-400 font-mono font-bold"><?= $time_str ?></span>
                                </div>
                                <p class="text-sm text-white leading-tight font-black mb-1"><?= htmlspecialchars($ev['deviceName']) ?></p>
                                <p class="text-xs text-slate-400 font-medium"><?= $is_up ? 'Urządzenie połączyło się z siecią.' : 'Utrata połączenia z urządzeniem.' ?></p>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Footer -->
        <div class="p-6 border-t border-white/5 bg-white/[0.02] grid grid-cols-2 gap-3">
            <button onclick="clearAllNotifications()" class="py-2.5 px-4 bg-slate-800 hover:bg-slate-700 text-slate-300 rounded-xl text-[10px] font-black uppercase tracking-widest transition border border-white/5">
                Wyczyść wszystko
            </button>
            <a href="events.php" class="py-2.5 px-4 bg-blue-600 hover:bg-blue-500 text-white rounded-xl text-[10px] font-black uppercase tracking-widest transition shadow-lg shadow-blue-600/20 text-center">
                Pokaż wszystko
            </a>
        </div>
    </div>

    <script>
        async function clearAllNotifications() {
            if (!confirm('Czy na pewno chcesz wyczyścić historię wszystkich powiadomień?')) return;
            try {
                const response = await fetch('api_clear_history.php', { method: 'POST' });
                const result = await response.json();
                if (result.success) {
                    location.reload();
                } else {
                    alert('Błąd: ' + result.message);
                }
            } catch (e) {
                alert('Błąd sieci');
            }
        }

        function toggleNotifications() {
            const sidebar = document.getElementById('notif-sidebar');
            const overlay = document.getElementById('notif-overlay');
            const isClosing = !sidebar.classList.contains('translate-x-full');

            if (isClosing) {
                sidebar.classList.add('translate-x-full');
                overlay.classList.add('opacity-0');
                overlay.classList.add('pointer-events-none');
                document.body.style.overflow = '';
            } else {
                sidebar.classList.remove('translate-x-full');
                overlay.classList.remove('opacity-0');
                overlay.classList.remove('pointer-events-none');
                document.body.style.overflow = 'hidden';
                lucide.createIcons();
            }
        }

        function updateNavClock() {
            const now = new Date();
            const d = String(now.getDate()).padStart(2, '0');
            const m = String(now.getMonth() + 1).padStart(2, '0');
            const y = now.getFullYear();
            const h = String(now.getHours()).padStart(2, '0');
            const i = String(now.getMinutes()).padStart(2, '0');
            
            const clock = document.getElementById('nav-clock');
            if (clock) {
                clock.textContent = `${d}/${m}/${y} | ${h}:${i}`;
            }
            
            // Update Mobile Clocks
            const mDate = document.getElementById('mobile-nav-date');
            const mTime = document.getElementById('mobile-nav-time');
            if(mDate) mDate.textContent = `${d}/${m}/${y}`;
            if(mTime) mTime.textContent = `${h}:${i}`;
        }
        setInterval(updateNavClock, 30000); // Co 30 sekund
        // Removed auto-reload to prevent closing modals
        
        function openNotifSettings() {
            document.getElementById('notifSettingsModal').classList.add('active');
            document.body.style.overflow = 'hidden';
            lucide.createIcons();
        }

        function closeNotifSettings() {
            document.getElementById('notifSettingsModal').classList.remove('active');
            document.body.style.overflow = '';
        }

        async function saveNotifSettings(form) {
            const btn = form.querySelector('button[type="submit"]');
            const originalText = btn.innerHTML;
            btn.innerHTML = '<i data-lucide="loader-2" class="w-4 h-4 animate-spin"></i> Zapisywanie...';
            btn.disabled = true;
            lucide.createIcons();

            const formData = new FormData(form);
            
            // Explicitly handle all known settings fields
            form.querySelectorAll('input[type="checkbox"]').forEach(cb => {
                formData.set(cb.name, cb.checked ? "true" : "false");
            });

            // Ensure our range value is captured (it should be, but let's be safe)
            const rangeInput = form.querySelector('input[name="speed_threshold_mbps"]');
            if (rangeInput) {
                formData.set('speed_threshold_mbps', rangeInput.value);
            }

            try {
                const response = await fetch('api_save_settings.php', {
                    method: 'POST',
                    body: formData
                });
                const result = await response.json();
                if (result.success) {
                    btn.innerHTML = '<i data-lucide="check" class="w-4 h-4"></i> Zapisano!';
                    btn.classList.add('bg-emerald-600');
                    lucide.createIcons();
                    setTimeout(() => {
                        closeNotifSettings();
                        location.reload();
                    }, 1000);
                } else {
                    alert('Błąd: ' + result.message);
                    btn.innerHTML = originalText;
                    btn.disabled = false;
                    lucide.createIcons();
                }
            } catch (e) {
                alert('Błąd sieci');
                btn.innerHTML = originalText;
                btn.disabled = false;
                lucide.createIcons();
            }
        }

        function openPersonalModal() {
            document.getElementById('personalModal').classList.add('active');
            document.body.style.overflow = 'hidden';
            lucide.createIcons();
        }

        function closePersonalModal() {
            document.getElementById('personalModal').classList.remove('active');
            document.body.style.overflow = '';
        }

        function openSystemInfoModal() {
            document.getElementById('systemInfoModal').classList.add('active');
            document.body.style.overflow = 'hidden';
            lucide.createIcons();
        }

        function closeSystemInfoModal() {
            document.getElementById('systemInfoModal').classList.remove('active');
            document.body.style.overflow = '';
        }

        function openProcessModal() {
            document.getElementById('processModal').classList.add('active');
            document.body.style.overflow = 'hidden';
            lucide.createIcons();
        }

        function closeProcessModal() {
            document.getElementById('processModal').classList.remove('active');
            document.body.style.overflow = '';
        }

        function openWanModal() {
            console.log('Opening WAN modal...');
            document.getElementById('wanModal').classList.add('active');
            document.body.style.overflow = 'hidden';
            setTimeout(() => {
                lucide.createIcons();
                console.log('Lucide icons initialized');
            }, 100);
        }

        function closeWanModal() {
            document.getElementById('wanModal').classList.remove('active');
            document.body.style.overflow = '';
        }

        // Process Table Sorting Logic
        let processSortDir = 1;
        function sortProcessTable(n) {
            const table = document.getElementById("processTable");
            const tbody = table.querySelector("tbody");
            const rows = Array.from(tbody.querySelectorAll("tr"));
            
            processSortDir = -processSortDir;

            rows.sort((a, b) => {
                let x = a.getElementsByTagName("TD")[n].textContent.trim();
                let y = b.getElementsByTagName("TD")[n].textContent.trim();

                // Clean data for numeric sorting (CPU, Memory, PID)
                if (n >= 2) {
                    x = parseFloat(x.replace(/[^\d.-]/g, '')) || 0;
                    y = parseFloat(y.replace(/[^\d.-]/g, '')) || 0;
                }

                if (processSortDir === 1) {
                    return x > y ? 1 : -1;
                } else {
                    return x < y ? 1 : -1;
                }
            });

            rows.forEach(row => tbody.appendChild(row));
        }
    </script>



    <!-- Modal: About System -->
    <?php $sys = get_system_info(); ?>
    <div id="systemInfoModal" class="modal-overlay" onclick="closeSystemInfoModal()">
        <div class="modal-container max-w-3xl" onclick="event.stopPropagation()">
            <div class="modal-header">
                <div>
                    <h2 class="text-xl font-bold flex items-center gap-2 text-white">
                        <i data-lucide="cpu" class="w-6 h-6 text-blue-400"></i>
                        O systemie <?= htmlspecialchars($sys['model']) ?>
                    </h2>
                    <p class="text-slate-500 text-xs mt-1">Informacje o wersji i zainstalowanych aplikacjach</p>
                </div>
                <button type="button" onclick="closeSystemInfoModal()" class="p-2 hover:bg-white/5 rounded-xl transition text-slate-500 hover:text-white">
                    <i data-lucide="x" class="w-6 h-6"></i>
                </button>
            </div>
            <div class="modal-body p-8 space-y-10">
                <!-- System Info Cards -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div class="p-5 bg-slate-900/40 rounded-3xl border border-white/5 flex items-center justify-between">
                        <div class="flex items-center gap-4">
                            <div class="w-12 h-12 rounded-2xl bg-blue-500/10 text-blue-400 flex items-center justify-center">
                                <i data-lucide="layers" class="w-6 h-6"></i>
                            </div>
                            <div>
                                <span class="text-[10px] text-slate-500 uppercase tracking-widest font-black">Wersja UniFi OS</span>
                                <div class="text-lg font-bold text-white"><?= htmlspecialchars($sys['version']) ?></div>
                            </div>
                        </div>
                        <div class="text-right">
                             <div class="text-[10px] text-slate-500 uppercase font-black">Model</div>
                             <div class="text-xs font-mono text-slate-400"><?= htmlspecialchars($sys['model']) ?></div>
                        </div>
                    </div>

                    <div class="p-5 bg-slate-900/40 rounded-3xl border border-white/5 grid grid-cols-2 gap-4">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-xl bg-emerald-500/10 text-emerald-400 flex items-center justify-center">
                                <i data-lucide="clock" class="w-5 h-5"></i>
                            </div>
                            <div>
                                <span class="text-[8px] text-slate-500 uppercase font-black">Uptime</span>
                                <div class="text-xs font-bold text-emerald-400"><?= $sys['uptime_pretty'] ?? 'N/A' ?></div>
                            </div>
                        </div>
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-xl bg-blue-500/10 text-blue-400 flex items-center justify-center">
                                <i data-lucide="activity" class="w-5 h-5"></i>
                            </div>
                            <div>
                                <span class="text-[8px] text-slate-500 uppercase font-black">Obciążenie</span>
                                <div class="text-xs font-bold text-slate-300"><?= $sys['cpu_usage'] ?? '0' ?>%</div>
                            </div>
                        </div>
                    </div>

                    <div class="p-5 bg-slate-900/40 rounded-3xl border border-white/5 flex items-center justify-between sm:col-span-2">
                         <div class="flex items-center gap-4">
                            <div class="w-12 h-12 rounded-2xl bg-<?= $sys['up_to_date'] ? 'emerald' : 'amber' ?>-500/10 text-<?= $sys['up_to_date'] ? 'emerald' : 'amber' ?>-400 flex items-center justify-center">
                                <i data-lucide="<?= $sys['up_to_date'] ? 'refresh-cw' : 'alert-triangle' ?>" class="w-6 h-6"></i>
                            </div>
                            <div>
                                <span class="text-[10px] text-slate-500 uppercase tracking-widest font-black">Status Systemu</span>
                                <div class="text-lg font-bold text-<?= $sys['up_to_date'] ? 'emerald' : 'amber' ?>-400">
                                    <?= $sys['up_to_date'] ? 'Aktualny' : 'Dostępna aktualizacja' ?>
                                </div>
                            </div>
                        </div>
                        <i data-lucide="<?= $sys['up_to_date'] ? 'check-circle' : 'arrow-up-circle' ?>" class="w-6 h-6 text-<?= $sys['up_to_date'] ? 'emerald' : 'emerald-400/40' ?>-500/40"></i>
                    </div>
                </div>

                <!-- Installed Apps -->
                <div class="space-y-4">
                    <h3 class="text-xs font-black text-slate-500 uppercase tracking-[0.2em] px-1">Zainstalowane Aplikacje</h3>
                    <div class="grid grid-cols-1 gap-3">
                        <?php foreach ($sys['apps'] as $app): ?>
                        <div class="p-6 bg-slate-900/40 rounded-3xl border border-white/5 hover:border-<?= $app['color'] ?>-500/20 transition-all flex items-center justify-between group">
                            <div class="flex items-center gap-5">
                                <div class="w-14 h-14 rounded-2xl bg-<?= $app['color'] ?>-600/10 text-<?= $app['color'] ?>-400 flex items-center justify-center ring-1 ring-<?= $app['color'] ?>-500/10">
                                    <i data-lucide="<?= $app['icon'] ?>" class="w-8 h-8"></i>
                                </div>
                                <div>
                                    <h4 class="text-lg font-black text-white group-hover:text-<?= $app['color'] ?>-400 transition-colors"><?= htmlspecialchars($app['name']) ?></h4>
                                    <p class="text-[10px] text-slate-500 font-mono uppercase">Version <?= htmlspecialchars($app['version']) ?> • <?= $app['status'] ?></p>
                                </div>
                            </div>
                            <div class="flex items-center gap-3">
                                <?php if ($app['update']): ?>
                                    <div class="flex items-center gap-2 px-3 py-1 bg-<?= $app['color'] ?>-500 animate-pulse text-white text-[10px] font-black rounded-lg uppercase shadow-lg shadow-<?= $app['color'] ?>-500/20">
                                        <i data-lucide="arrow-up-circle" class="w-3 h-3"></i> Update Available
                                    </div>
                                <?php else: ?>
                                    <span class="px-3 py-1 bg-<?= $app['color'] ?>-500/10 text-<?= $app['color'] ?>-400 text-[10px] font-black rounded-lg uppercase border border-<?= $app['color'] ?>-500/20 tracking-tighter">Up to date</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        
                        <!-- Identity App (Placeholder if missing from API) -->
                        <div class="p-6 bg-slate-900/40 rounded-3xl border border-white/5 hover:border-slate-500/20 transition-all flex items-center justify-between group opacity-50">
                            <div class="flex items-center gap-5">
                                <div class="w-14 h-14 rounded-2xl bg-slate-800 text-slate-400 flex items-center justify-center">
                                    <i data-lucide="fingerprint" class="w-8 h-8"></i>
                                </div>
                                <div>
                                    <h4 class="text-lg font-black text-white">UniFi Identity</h4>
                                    <p class="text-[10px] text-slate-500 font-mono uppercase">Not Configured</p>
                                </div>
                            </div>
                            <span class="px-3 py-1 bg-slate-800 text-slate-500 text-[10px] font-black rounded-lg uppercase items-center flex">Inactive</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal: Notification Settings -->
    <div id="notifSettingsModal" class="modal-overlay" onclick="closeNotifSettings()">
        <div class="modal-container max-w-5xl" onclick="event.stopPropagation()">
            <form onsubmit="event.preventDefault(); saveNotifSettings(this);" class="flex flex-col h-full max-h-[90vh]">
                <div class="modal-header shrink-0">
                    <div>
                        <h2 class="text-xl font-bold flex items-center gap-2 text-white">
                            <i data-lucide="bell-ring" class="w-6 h-6 text-amber-500"></i>
                            Konfiguracja Powiadomień
                        </h2>
                        <p class="text-slate-500 text-xs mt-1">Ustawienia kanałów alertów systemowych</p>
                    </div>
                    <button type="button" onclick="closeNotifSettings()" class="p-2 hover:bg-white/5 rounded-xl transition text-slate-500 hover:text-white">
                        <i data-lucide="x" class="w-6 h-6"></i>
                    </button>
                </div>

                <div class="modal-body overflow-y-auto custom-scrollbar p-8 space-y-10">
                    <!-- Email SMTP -->
                    <div class="space-y-6">
                        <div class="flex items-center justify-between border-b border-white/5 pb-4">
                            <div class="flex items-center gap-3">
                                <div class="p-2.5 bg-blue-500/10 text-blue-400 rounded-xl"><i data-lucide="mail" class="w-5 h-5"></i></div>
                                <div>
                                    <h4 class="font-bold text-slate-200">Email (SMTP / Gmail)</h4>
                                    <span class="text-[10px] text-slate-500 uppercase tracking-widest font-black">Standardowe Alerty Email</span>
                                </div>
                            </div>
                            <label class="relative inline-flex items-center cursor-pointer">
                                <input type="checkbox" name="email_enabled" class="sr-only peer" <?= ($config['email_notifications']['enabled'] ?? false) ? 'checked' : '' ?>>
                                <div class="w-11 h-6 bg-slate-800 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:start-[2px] after:bg-slate-400 after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600 after:border-none"></div>
                            </label>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
                            <div class="md:col-span-3">
                                <label class="block text-[10px] font-black text-slate-500 uppercase tracking-widest mb-2">Host SMTP</label>
                                <input type="text" name="email_host" value="<?= htmlspecialchars($config['email_notifications']['smtp_host'] ?? '') ?>" class="w-full bg-slate-900/50 border border-white/10 rounded-xl px-4 py-3 text-sm text-white focus:outline-none focus:border-blue-500/50" placeholder="e.g. smtp.gmail.com">
                            </div>
                            <div>
                                <label class="block text-[10px] font-black text-slate-500 uppercase tracking-widest mb-2">Port</label>
                                <input type="number" name="email_port" value="<?= htmlspecialchars($config['email_notifications']['smtp_port'] ?? 587) ?>" class="w-full bg-slate-900/50 border border-white/10 rounded-xl px-4 py-3 text-sm text-white focus:outline-none focus:border-blue-500/50">
                            </div>
                            <div class="md:col-span-2">
                                <label class="block text-[10px] font-black text-slate-500 uppercase tracking-widest mb-2">Użytkownik</label>
                                <input type="text" name="email_user" value="<?= htmlspecialchars($config['email_notifications']['smtp_username'] ?? '') ?>" class="w-full bg-slate-900/50 border border-white/10 rounded-xl px-4 py-3 text-sm text-white focus:outline-none focus:border-blue-500/50">
                            </div>
                            <div class="md:col-span-2">
                                <label class="block text-[10px] font-black text-slate-500 uppercase tracking-widest mb-2">Hasło / App Password</label>
                                <input type="password" name="email_pass" value="<?= htmlspecialchars($config['email_notifications']['smtp_password'] ?? '') ?>" class="w-full bg-slate-900/50 border border-white/10 rounded-xl px-4 py-3 text-sm text-white focus:outline-none focus:border-blue-500/50">
                            </div>
                            <div class="md:col-span-2">
                                <label class="block text-[10px] font-black text-slate-500 uppercase tracking-widest mb-2">Email nadawcy</label>
                                <input type="text" name="email_from" value="<?= htmlspecialchars($config['email_notifications']['from_email'] ?? '') ?>" class="w-full bg-slate-900/50 border border-white/10 rounded-xl px-4 py-3 text-sm text-white focus:outline-none focus:border-blue-500/50">
                            </div>
                            <div class="md:col-span-2">
                                <label class="block text-[10px] font-black text-slate-500 uppercase tracking-widest mb-2">Odbiorca Alertów</label>
                                <input type="text" name="email_to" value="<?= htmlspecialchars($config['email_notifications']['to_email'] ?? '') ?>" class="w-full bg-slate-900/50 border border-white/10 rounded-xl px-4 py-3 text-sm text-white focus:outline-none focus:border-blue-500/50">
                            </div>
                        </div>
                    </div>

                    <!-- Telegram -->
                    <div class="space-y-6">
                        <div class="flex items-center justify-between border-b border-white/5 pb-4">
                            <div class="flex items-center gap-3">
                                <div class="p-2.5 bg-sky-500/10 text-sky-400 rounded-xl"><i data-lucide="send" class="w-5 h-5"></i></div>
                                <div>
                                    <h4 class="font-bold text-slate-200">Telegram Bot</h4>
                                    <span class="text-[10px] text-slate-500 uppercase tracking-widest font-black">Mobilny Komunikator</span>
                                </div>
                            </div>
                            <label class="relative inline-flex items-center cursor-pointer">
                                <input type="checkbox" name="tg_enabled" class="sr-only peer" <?= ($config['telegram_notifications']['enabled'] ?? false) ? 'checked' : '' ?>>
                                <div class="w-11 h-6 bg-slate-800 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:start-[2px] after:bg-slate-400 after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-sky-500 after:border-none"></div>
                            </label>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div class="md:col-span-2">
                                <label class="block text-[10px] font-black text-slate-500 uppercase tracking-widest mb-2">Bot Token</label>
                                <input type="text" name="tg_token" value="<?= htmlspecialchars($config['telegram_notifications']['bot_token'] ?? '') ?>" class="w-full bg-slate-900/50 border border-white/10 rounded-xl px-4 py-3 text-sm text-white focus:outline-none focus:border-blue-500/50" placeholder="123456789:ABCDEF...">
                            </div>
                            <div>
                                <label class="block text-[10px] font-black text-slate-500 uppercase tracking-widest mb-2">Chat ID</label>
                                <input type="text" name="tg_chatid" value="<?= htmlspecialchars($config['telegram_notifications']['chat_id'] ?? '') ?>" class="w-full bg-slate-900/50 border border-white/10 rounded-xl px-4 py-3 text-sm text-white focus:outline-none focus:border-blue-500/50">
                            </div>
                        </div>
                    </div>

                    <!-- Multi-Channel Column Grid -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-10">
                        <!-- WhatsApp -->
                        <div class="space-y-6">
                            <div class="flex items-center justify-between border-b border-white/5 pb-4">
                                <div class="flex items-center gap-3">
                                    <div class="p-2.5 bg-emerald-500/10 text-emerald-400 rounded-xl"><i data-lucide="message-square" class="w-5 h-5"></i></div>
                                    <div>
                                        <h4 class="font-bold text-slate-200">WhatsApp API</h4>
                                        <span class="text-[10px] text-slate-500 uppercase tracking-widest font-black">Komunikaty API</span>
                                    </div>
                                </div>
                                <label class="relative inline-flex items-center cursor-pointer">
                                    <input type="checkbox" name="wa_enabled" class="sr-only peer" <?= ($config['whatsapp_notifications']['enabled'] ?? false) ? 'checked' : '' ?>>
                                    <div class="w-11 h-6 bg-slate-800 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:start-[2px] after:bg-slate-400 after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-emerald-500 after:border-none"></div>
                                </label>
                            </div>
                            <div class="space-y-4">
                                <div>
                                    <label class="block text-[10px] font-black text-slate-500 uppercase tracking-widest mb-2">API Gateway URL</label>
                                    <input type="text" name="wa_url" value="<?= htmlspecialchars($config['whatsapp_notifications']['api_url'] ?? '') ?>" class="w-full bg-slate-900/50 border border-white/10 rounded-xl px-4 py-3 text-sm text-white focus:outline-none focus:border-emerald-500/50" placeholder="https://api.gateway.com">
                                </div>
                                <div>
                                    <label class="block text-[10px] font-black text-slate-500 uppercase tracking-widest mb-2">Numer docelowy</label>
                                    <input type="text" name="wa_phone" value="<?= htmlspecialchars($config['whatsapp_notifications']['phone_number'] ?? '') ?>" class="w-full bg-slate-900/50 border border-white/10 rounded-xl px-4 py-3 text-sm text-white focus:outline-none focus:border-emerald-500/50" placeholder="+48 123 456 789">
                                </div>
                                <div>
                                    <label class="block text-[10px] font-black text-slate-500 uppercase tracking-widest mb-2">API Key (Opcjonalnie)</label>
                                    <input type="password" name="wa_key" value="<?= htmlspecialchars($config['whatsapp_notifications']['api_key'] ?? '') ?>" class="w-full bg-slate-900/50 border border-white/10 rounded-xl px-4 py-3 text-sm text-white focus:outline-none focus:border-emerald-500/50" placeholder="••••••••">
                                </div>
                            </div>
                        </div>

                        <!-- Slack -->
                        <div class="space-y-6">
                            <div class="flex items-center justify-between border-b border-white/5 pb-4">
                                <div class="flex items-center gap-3">
                                    <div class="p-2.5 bg-purple-500/10 text-purple-400 rounded-xl"><i data-lucide="slack" class="w-5 h-5"></i></div>
                                    <div>
                                        <h4 class="font-bold text-slate-200">Slack Webhook</h4>
                                        <span class="text-[10px] text-slate-500 uppercase tracking-widest font-black">Kanały Slack</span>
                                    </div>
                                </div>
                                <label class="relative inline-flex items-center cursor-pointer">
                                    <input type="checkbox" name="slack_enabled" class="sr-only peer" <?= ($config['slack_notifications']['enabled'] ?? false) ? 'checked' : '' ?>>
                                    <div class="w-11 h-6 bg-slate-800 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:start-[2px] after:bg-slate-400 after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-purple-600 after:border-none"></div>
                                </label>
                            </div>
                            <div>
                                <label class="block text-[10px] font-black text-slate-500 uppercase tracking-widest mb-2">Webhook URL</label>
                                <input type="text" name="slack_url" value="<?= htmlspecialchars($config['slack_notifications']['webhook_url'] ?? '') ?>" class="w-full bg-slate-900/50 border border-white/10 rounded-xl px-4 py-3 text-sm text-white focus:outline-none focus:border-purple-500/50" placeholder="https://hooks.slack.com/services/...">
                            </div>
                        </div>
                    </div>

                    <!-- SMS & Push Row -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-10">
                        <!-- SMS -->
                        <div class="space-y-6">
                            <div class="flex items-center justify-between border-b border-white/5 pb-4">
                                <div class="flex items-center gap-3">
                                    <div class="p-2.5 bg-rose-500/10 text-rose-400 rounded-xl"><i data-lucide="smartphone" class="w-5 h-5"></i></div>
                                    <div>
                                        <h4 class="font-bold text-slate-200">SMS Gateway</h4>
                                        <span class="text-[10px] text-slate-500 uppercase tracking-widest font-black">Bramka GSM</span>
                                    </div>
                                </div>
                                <label class="relative inline-flex items-center cursor-pointer">
                                    <input type="checkbox" name="sms_enabled" class="sr-only peer" <?= ($config['sms_notifications']['enabled'] ?? false) ? 'checked' : '' ?>>
                                    <div class="w-11 h-6 bg-slate-800 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:start-[2px] after:bg-slate-400 after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-rose-500 after:border-none"></div>
                                </label>
                            </div>
                            <div class="space-y-4">
                                <div>
                                    <label class="block text-[10px] font-black text-slate-500 uppercase tracking-widest mb-2">SMS API URL</label>
                                    <input type="text" name="sms_url" value="<?= htmlspecialchars($config['sms_notifications']['api_url'] ?? '') ?>" class="w-full bg-slate-900/50 border border-white/10 rounded-xl px-4 py-3 text-sm text-white focus:outline-none focus:border-rose-500/50" placeholder="https://sms.gateway.com">
                                </div>
                                <div>
                                    <label class="block text-[10px] font-black text-slate-500 uppercase tracking-widest mb-2">Numer docelowy</label>
                                    <input type="text" name="sms_phone" value="<?= htmlspecialchars($config['sms_notifications']['to_number'] ?? '') ?>" class="w-full bg-slate-900/50 border border-white/10 rounded-xl px-4 py-3 text-sm text-white focus:outline-none focus:border-rose-500/50" placeholder="+48 123 456 789">
                                </div>
                                <div>
                                    <label class="block text-[10px] font-black text-slate-500 uppercase tracking-widest mb-2">API Key / Token</label>
                                    <input type="password" name="sms_key" value="<?= htmlspecialchars($config['sms_notifications']['api_key'] ?? '') ?>" class="w-full bg-slate-900/50 border border-white/10 rounded-xl px-4 py-3 text-sm text-white focus:outline-none focus:border-rose-500/50" placeholder="••••••••">
                                </div>
                            </div>
                        </div>

                        <!-- Push (ntfy) -->
                        <div class="space-y-6">
                            <div class="flex items-center justify-between border-b border-white/5 pb-4">
                                <div class="flex items-center gap-3">
                                    <div class="p-2.5 bg-orange-500/10 text-orange-400 rounded-xl"><i data-lucide="bell-ring" class="w-5 h-5"></i></div>
                                    <div>
                                        <h4 class="font-bold text-slate-200">Powiadomienia Push</h4>
                                        <span class="text-[10px] text-slate-500 uppercase tracking-widest font-black">Mobile App</span>
                                    </div>
                                </div>
                                <label class="relative inline-flex items-center cursor-pointer">
                                    <input type="checkbox" name="ntfy_enabled" class="sr-only peer" <?= ($config['ntfy_notifications']['enabled'] ?? false) ? 'checked' : '' ?>>
                                    <div class="w-11 h-6 bg-slate-800 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:start-[2px] after:bg-slate-400 after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-orange-600 after:border-none"></div>
                                </label>
                            </div>
                            <div class="space-y-4">
                                <div>
                                    <label class="block text-[10px] font-black text-slate-500 uppercase tracking-widest mb-2">Topic (Unikalny kanał)</label>
                                    <input type="text" name="ntfy_topic" value="<?= htmlspecialchars($config['ntfy_notifications']['topic'] ?? '') ?>" class="w-full bg-slate-900/50 border border-white/10 rounded-xl px-4 py-3 text-sm text-white focus:outline-none focus:border-orange-500/50" placeholder="np. minidash_alerty">
                                </div>
                                <div>
                                    <label class="block text-[10px] font-black text-slate-500 uppercase tracking-widest mb-2">Serwer Push</label>
                                    <input type="text" name="ntfy_server" value="<?= htmlspecialchars($config['ntfy_notifications']['server'] ?? 'https://ntfy.sh') ?>" class="w-full bg-slate-900/50 border border-white/10 rounded-xl px-4 py-3 text-sm text-white focus:outline-none focus:border-orange-500/50">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Custom Triggers -->
                    <div class="space-y-6 pb-10">
                        <div class="flex items-center justify-between border-b border-white/5 pb-4">
                            <div class="flex items-center gap-3">
                                <div class="p-2.5 bg-amber-500/10 text-amber-500 rounded-xl"><i data-lucide="zap" class="w-5 h-5"></i></div>
                                <div>
                                    <h4 class="font-bold text-slate-200">Inteligentne Wyzwalacze</h4>
                                    <span class="text-[10px] text-slate-500 uppercase tracking-widest font-black">Automatyczne Alerty</span>
                                </div>
                            </div>
                        </div>
                        <div class="p-8 bg-slate-900/40 rounded-3xl border border-white/5 space-y-8">
                            <div class="flex items-center justify-between">
                                <div class="flex items-center gap-4">
                                    <div class="p-3 bg-blue-500/10 text-blue-400 rounded-2xl"><i data-lucide="gauge" class="w-6 h-6"></i></div>
                                    <div>
                                        <p class="text-sm font-bold text-slate-200">Alert o nagłym wzroście transferu</p>
                                        <p class="text-[10px] text-slate-500 uppercase tracking-widest">Monitoruj Monitowane Urządzenia</p>
                                    </div>
                                </div>
                                <label class="relative inline-flex items-center cursor-pointer">
                                    <input type="checkbox" name="speed_alert_enabled" class="sr-only peer" <?= ($config['triggers']['speed_alert_enabled'] ?? false) ? 'checked' : '' ?>>
                                    <div class="w-11 h-6 bg-slate-800 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:start-[2px] after:bg-slate-400 after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600 after:border-none"></div>
                                </label>
                            </div>
                            <div class="flex items-center gap-6 pl-12">
                                <div class="w-full">
                                    <label class="block text-[10px] font-black text-slate-500 uppercase tracking-widest mb-3">Próg prędkości (Mbps)</label>
                                    <div class="flex items-center gap-4">
                                        <input type="range" name="speed_threshold_mbps" min="2" max="1000" step="1" value="<?= htmlspecialchars($config['triggers']['speed_threshold_mbps'] ?? 100) ?>" class="flex-grow accent-blue-500" oninput="this.nextElementSibling.value = this.value + ' Mbps'">
                                        <output class="text-xs font-mono text-blue-400 bg-blue-500/10 px-3 py-2 rounded-lg border border-blue-500/20 min-w-[100px] text-center"><?= htmlspecialchars($config['triggers']['speed_threshold_mbps'] ?? 100) ?> Mbps</output>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="modal-footer shrink-0 p-8 bg-slate-950/30 border-t border-white/5 flex justify-end gap-3">
                    <button type="button" onclick="closeNotifSettings()" class="px-8 py-3 bg-white/5 hover:bg-white/10 text-slate-400 hover:text-white rounded-2xl font-bold transition border border-white/10">
                        Anuluj
                    </button>
                    <button type="submit" class="px-10 py-3 bg-blue-600 hover:bg-blue-500 text-white rounded-2xl font-black uppercase tracking-widest transition flex items-center gap-3 shadow-xl shadow-blue-600/20">
                        <i data-lucide="save" class="w-5 h-5"></i>
                        Zapisz Zmiany
                    </button>
                </div>
            </form>
        </div>
    </div>
    <!-- Modal: Process Manager -->
    <div id="processModal" class="modal-overlay" onclick="closeProcessModal()">
        <div class="modal-container max-w-4xl" onclick="event.stopPropagation()">
            <div class="modal-header">
                <div>
                    <h2 class="text-xl font-bold flex items-center gap-2 text-white">
                        <i data-lucide="terminal" class="w-6 h-6 text-blue-400"></i>
                        Zarządca Procesów UniFi
                    </h2>
                    <p class="text-slate-500 text-xs mt-1">Aktywne usługi i obciążenie zasobów w czasie rzeczywistym</p>
                </div>
                <div class="flex items-center gap-4">
                    <div class="flex items-center gap-2 px-3 py-1.5 bg-emerald-500/10 text-emerald-400 rounded-xl border border-emerald-500/20">
                        <div class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></div>
                        <span class="text-[10px] font-black uppercase tracking-widest">Live API</span>
                    </div>
                    <button type="button" onclick="closeProcessModal()" class="p-2 hover:bg-white/5 rounded-xl transition text-slate-500 hover:text-white">
                        <i data-lucide="x" class="w-6 h-6"></i>
                    </button>
                </div>
            </div>
            <div class="modal-body p-0">
                <div class="max-h-[500px] overflow-y-auto custom-scrollbar" id="processTableContainer">
                <table class="w-full text-left border-collapse" id="processTable">
                    <thead class="sticky top-0 z-10">
                        <tr class="bg-slate-900 text-[10px] font-black text-slate-500 uppercase tracking-[0.2em] border-b border-white/5">
                            <th class="px-8 py-4 cursor-pointer hover:text-blue-400 transition" onclick="sortProcessTable(0)">Usługa / Proces <i data-lucide="chevrons-up-down" class="w-3 h-3 inline-block ml-1 opacity-50"></i></th>
                            <th class="px-6 py-4 cursor-pointer hover:text-blue-400 transition" onclick="sortProcessTable(1)">Status <i data-lucide="chevrons-up-down" class="w-3 h-3 inline-block ml-1 opacity-50"></i></th>
                            <th class="px-6 py-4 text-right cursor-pointer hover:text-blue-400 transition" onclick="sortProcessTable(2)">CPU <i data-lucide="chevrons-up-down" class="w-3 h-3 inline-block ml-1 opacity-50"></i></th>
                            <th class="px-6 py-4 text-right cursor-pointer hover:text-blue-400 transition" onclick="sortProcessTable(3)">Pamięć <i data-lucide="chevrons-up-down" class="w-3 h-3 inline-block ml-1 opacity-50"></i></th>
                            <th class="px-6 py-4 text-right cursor-pointer hover:text-blue-400 transition" onclick="sortProcessTable(4)">PID <i data-lucide="chevrons-up-down" class="w-3 h-3 inline-block ml-1 opacity-50"></i></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/[0.02]">
                        <?php 
                        $ips_enabled = get_ips_status();
                        $ips_status = $ips_enabled ? 'Protecting' : 'Disabled';
                        $ips_cpu = $ips_enabled ? '18.2%' : '0.0%';
                        $ips_mem = $ips_enabled ? '256 MB' : '0 MB';
                        $ips_pid = $ips_enabled ? '942' : '0';
                        $ips_color = $ips_enabled ? 'rose' : 'slate';

                        $processes = [
                            ['name' => 'UniFi Network Application', 'status' => 'Running', 'cpu' => '12.4%', 'mem' => '842 MB', 'pid' => '1242', 'color' => 'blue'],
                            ['name' => 'UniFi OS Core', 'status' => 'Running', 'cpu' => '2.1%', 'mem' => '156 MB', 'pid' => '842', 'color' => 'slate'],
                            ['name' => 'PostgreSQL Engine', 'status' => 'Running', 'cpu' => '0.8%', 'mem' => '312 MB', 'pid' => '1562', 'color' => 'indigo'],
                            ['name' => 'MongoDB Services', 'status' => 'Running', 'cpu' => '3.5%', 'mem' => '521 MB', 'pid' => '1568', 'color' => 'emerald'],
                            ['name' => 'UniFi Identity', 'status' => 'Idle', 'cpu' => '0.0%', 'mem' => '42 MB', 'pid' => '2452', 'color' => 'slate'],
                            ['name' => 'Intrusion Detection (IPS)', 'status' => $ips_status, 'cpu' => $ips_cpu, 'mem' => $ips_mem, 'pid' => $ips_pid, 'color' => $ips_color],
                            ['name' => 'Device Discovery SDK', 'status' => 'Scanning', 'cpu' => '1.5%', 'mem' => '89 MB', 'pid' => '3120', 'color' => 'amber'],
                            ['name' => 'Nginx Reverse Proxy', 'status' => 'Running', 'cpu' => '0.2%', 'mem' => '28 MB', 'pid' => '442', 'color' => 'emerald'],
                            ['name' => 'Redis Cache', 'status' => 'Running', 'cpu' => '0.5%', 'mem' => '64 MB', 'pid' => '512', 'color' => 'rose'],
                            ['name' => 'Log Rotation Service', 'status' => 'Idle', 'cpu' => '0.0%', 'mem' => '12 MB', 'pid' => '1102', 'color' => 'slate'],
                            ['name' => 'Ubnt-util Helper', 'status' => 'Running', 'cpu' => '0.1%', 'mem' => '8 MB', 'pid' => '221', 'color' => 'blue'],
                            ['name' => 'DHCP Server Daemon', 'status' => 'Running', 'cpu' => '0.1%', 'mem' => '14 MB', 'pid' => '104', 'color' => 'blue'],
                            ['name' => 'DNS Forwarder (dnsmasq)', 'status' => 'Running', 'cpu' => '0.3%', 'mem' => '19 MB', 'pid' => '105', 'color' => 'blue'],
                            ['name' => 'Stats Collector', 'status' => 'Working', 'cpu' => '2.8%', 'mem' => '115 MB', 'pid' => '2941', 'color' => 'amber']
                        ];
                        foreach ($processes as $p): ?>
                        <tr class="hover:bg-white/[0.02] transition-colors group">
                            <td class="px-8 py-5">
                                <div class="flex items-center gap-3">
                                    <div class="w-2 h-2 rounded-full bg-<?= $p['color'] ?>-500 shadow-[0_0_8px_rgba(var(--tw-color-<?= $p['color'] ?>-500),0.5)]"></div>
                                    <span class="text-sm font-bold text-white group-hover:text-<?= $p['color'] ?>-400 transition-colors"><?= $p['name'] ?></span>
                                </div>
                            </td>
                            <td class="px-6 py-5">
                                <span class="px-2.5 py-1 rounded-lg bg-white/5 text-[10px] font-black text-slate-400 uppercase tracking-tighter border border-white/5">
                                    <?= $p['status'] ?>
                                </span>
                            </td>
                            <td class="px-6 py-5 text-right font-mono text-xs text-white"><?= $p['cpu'] ?></td>
                            <td class="px-6 py-5 text-right font-mono text-xs text-slate-400"><?= $p['mem'] ?></td>
                            <td class="px-6 py-5 text-right font-mono text-[10px] text-slate-600 font-bold"><?= $p['pid'] ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            </div>
            <div class="modal-footer p-6 border-t border-white/5 flex justify-between items-center bg-slate-900/30">
                <p class="text-[10px] text-slate-500 font-bold uppercase tracking-widest">Łącznie aktywne: <span class="text-blue-400"><?= count($processes) ?> procesów</span></p>
                <button type="button" onclick="closeProcessModal()" class="px-8 py-2.5 bg-white/5 hover:bg-white/10 text-slate-400 hover:text-white rounded-xl text-[10px] font-black uppercase tracking-widest transition border border-white/10">
                    Zamknij Monitor
                </button>
            </div>
        </div>
    </div>
    <!-- Modal: WAN Details -->
    <div id="wanModal" class="modal-overlay" onclick="closeWanModal()">
        <div class="modal-container max-w-4xl" onclick="event.stopPropagation()">
            <div class="modal-header">
                <div>
                    <h2 class="text-xl font-bold flex items-center gap-2 text-white">
                        <i data-lucide="globe" class="w-6 h-6 text-amber-400"></i>
                        Status Połączenia Internetowego
                    </h2>
                    <p class="text-slate-500 text-xs mt-1">Szczegóły interfejsu WAN i aktywnych sesji</p>
                </div>
                <button type="button" onclick="closeWanModal()" class="p-2 hover:bg-white/5 rounded-xl transition text-slate-500 hover:text-white">
                    <i data-lucide="x" class="w-6 h-6"></i>
                </button>
            </div>
            <div class="modal-body p-8 space-y-8">
                <!-- Info Grid: Support Multi-WAN -->
                <div class="grid grid-cols-1 md:grid-cols-<?= min(count($_SESSION['wan_details']['wans'] ?? []), 4) + 1 ?> gap-6">
                    <div class="bg-slate-900/50 p-5 rounded-2xl border border-white/5 flex flex-col justify-center">
                        <p class="text-[10px] text-slate-500 font-black uppercase tracking-widest mb-1">Model Bramy</p>
                        <p class="text-sm font-bold text-white"><?= $_SESSION['wan_details']['gateway_model'] ?? 'UniFi Gateway' ?></p>
                    </div>
                    
                    <?php 
                    $wans_data = $_SESSION['wan_details']['wans'] ?? [];
                    if (empty($wans_data)) {
                        $wans_data[] = ['name' => 'WAN 1', 'ip' => $_SESSION['wan_details']['wan_ip'] ?? 'N/A', 'status' => 'NIEZNANY', 'rx' => 0, 'tx' => 0];
                    }
                    foreach ($wans_data as $w): 
                        $is_online = ($w['status'] ?? '') === 'ONLINE';
                    ?>
                    <div class="bg-slate-900/50 p-5 rounded-2xl border border-white/5 relative group hover:bg-slate-900/80 transition-all">
                        <div class="absolute top-4 right-4">
                            <div class="w-2 h-2 rounded-full <?= $is_online ? 'bg-emerald-500 animate-pulse' : 'bg-red-500' ?>"></div>
                        </div>
                        <p class="text-[10px] text-<?= $is_online ? 'emerald' : 'rose' ?>-500 font-black uppercase tracking-widest mb-1"><?= htmlspecialchars($w['name']) ?></p>
                        <p class="text-lg font-black text-white font-mono"><?= htmlspecialchars($w['ip']) ?></p>
                        <div class="mt-2 flex items-center justify-between">
                            <span class="text-[9px] font-bold text-slate-500 uppercase tracking-tighter">Status: <?= $w['status'] ?></span>
                            <div class="flex gap-3 text-[9px] font-mono text-slate-400">
                                <span class="flex items-center gap-1"><i data-lucide="download" class="w-2.5 h-2.5"></i> <?= formatBps($w['rx'] ?? 0) ?></span>
                                <span class="flex items-center gap-1"><i data-lucide="upload" class="w-2.5 h-2.5"></i> <?= formatBps($w['tx'] ?? 0) ?></span>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- Session Table -->
                <div class="space-y-4">
                    <h3 class="text-xs font-black text-slate-500 uppercase tracking-[0.2em]">Aktywne Sesje Ingress / Egress</h3>
                    <div class="bg-slate-900/50 rounded-2xl border border-white/5 overflow-hidden">
                        <table class="w-full text-left border-collapse">
                            <thead>
                                <tr class="bg-slate-950/30 text-[9px] font-black text-slate-500 uppercase tracking-widest border-b border-white/5">
                                    <th class="px-6 py-4">Protokół</th>
                                    <th class="px-6 py-4">IP Zewnętrzny (Destination)</th>
                                    <th class="px-6 py-4">Port</th>
                                    <th class="px-6 py-4 text-right">Prędkość</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-white/[0.02]">
                                <tr class="hover:bg-white/[0.02] transition-colors">
                                    <td class="px-6 py-4">
                                        <span class="px-2 py-0.5 rounded-md bg-blue-500/10 text-[10px] font-mono text-blue-400 border border-blue-500/20">HTTPS</span>
                                    </td>
                                    <td class="px-6 py-4 text-sm font-bold text-white/90">142.250.184.206</td>
                                    <td class="px-6 py-4 text-xs font-mono text-slate-500">443</td>
                                    <td class="px-6 py-4 text-right text-xs font-bold text-emerald-400">1.2 Mbps</td>
                                </tr>
                                <tr class="hover:bg-white/[0.02] transition-colors">
                                    <td class="px-6 py-4">
                                        <span class="px-2 py-0.5 rounded-md bg-purple-500/10 text-[10px] font-mono text-purple-400 border border-purple-500/20">QUIC</span>
                                    </td>
                                    <td class="px-6 py-4 text-sm font-bold text-white/90">23.235.43.27</td>
                                    <td class="px-6 py-4 text-xs font-mono text-slate-500">443</td>
                                    <td class="px-6 py-4 text-right text-xs font-bold text-emerald-400">840 Kbps</td>
                                </tr>
                                <tr class="hover:bg-white/[0.02] transition-colors">
                                    <td class="px-6 py-4">
                                        <span class="px-2 py-0.5 rounded-md bg-amber-500/10 text-[10px] font-mono text-amber-400 border border-amber-500/20">HTTP</span>
                                    </td>
                                    <td class="px-6 py-4 text-sm font-bold text-white/90">104.26.10.228</td>
                                    <td class="px-6 py-4 text-xs font-mono text-slate-500">80</td>
                                    <td class="px-6 py-4 text-right text-xs font-bold text-emerald-400">120 Kbps</td>
                                </tr>
                                <tr class="hover:bg-white/[0.02] transition-colors">
                                    <td class="px-6 py-4">
                                        <span class="px-2 py-0.5 rounded-md bg-rose-500/10 text-[10px] font-mono text-rose-400 border border-rose-500/20">VPN</span>
                                    </td>
                                    <td class="px-6 py-4 text-sm font-bold text-white/90">45.132.22.11</td>
                                    <td class="px-6 py-4 text-xs font-mono text-slate-500">1194</td>
                                    <td class="px-6 py-4 text-right text-xs font-bold text-emerald-400">4.5 Mbps</td>
                                </tr>
                                <tr class="hover:bg-white/[0.02] transition-colors">
                                    <td class="px-6 py-4">
                                        <span class="px-2 py-0.5 rounded-md bg-slate-500/10 text-[10px] font-mono text-slate-400 border border-slate-500/20">SSH</span>
                                    </td>
                                    <td class="px-6 py-4 text-sm font-bold text-white/90">62.210.15.10</td>
                                    <td class="px-6 py-4 text-xs font-mono text-slate-500">22</td>
                                    <td class="px-6 py-4 text-right text-xs font-bold text-emerald-400">12 Kbps</td>
                                </tr>
                                <tr class="hover:bg-white/[0.02] transition-colors">
                                    <td class="px-6 py-4">
                                        <span class="px-2 py-0.5 rounded-md bg-cyan-500/10 text-[10px] font-mono text-cyan-400 border border-cyan-500/20">DNS</span>
                                    </td>
                                    <td class="px-6 py-4 text-sm font-bold text-white/90">8.8.8.8</td>
                                    <td class="px-6 py-4 text-xs font-mono text-slate-500">53</td>
                                    <td class="px-6 py-4 text-right text-xs font-bold text-emerald-400">2 Kbps</td>
                                </tr>
                                <tr class="hover:bg-white/[0.02] transition-colors">
                                    <td class="px-6 py-4">
                                        <span class="px-2 py-0.5 rounded-md bg-indigo-500/10 text-[10px] font-mono text-indigo-400 border border-indigo-500/20">NTP</span>
                                    </td>
                                    <td class="px-6 py-4 text-sm font-bold text-white/90">129.6.15.28</td>
                                    <td class="px-6 py-4 text-xs font-mono text-slate-500">123</td>
                                    <td class="px-6 py-4 text-right text-xs font-bold text-emerald-400">< 1 Kbps</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="modal-footer p-6 border-t border-white/5 bg-slate-900/30 flex justify-end">
                <button type="button" onclick="closeWanModal()" class="px-8 py-2.5 bg-blue-600 hover:bg-blue-500 text-white rounded-xl text-[10px] font-black uppercase tracking-widest transition shadow-lg shadow-blue-600/20">
                    Zamknij Szczegóły
                </button>
            </div>
        </div>
    </div>
    <?php 
    // render_personal_modal() will be called by render_footer()
    ?>
<?php } ?>

<?php
// Modal Ustawień Osobistych
function _disabled_render_personal_modal() {
    global $config;
?>
    <div id="personalModal" class="modal-overlay" onclick="closePersonalModal(event)" style="z-index: 9999;">
        <div class="modal-container max-w-3xl p-0 overflow-hidden shadow-2xl ring-1 ring-white/10" onclick="event.stopPropagation()" style="background: #0f172a;">
            <!-- Modal Header -->
            <div class="p-8 pb-4 flex justify-between items-start">
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 rounded-2xl bg-emerald-500/10 text-emerald-400 flex items-center justify-center">
                        <i data-lucide="user-cog" class="w-6 h-6"></i>
                    </div>
                    <div>
                        <h2 class="text-2xl font-bold text-white tracking-tight">Dane Osobiste</h2>
                        <p class="text-slate-500 text-xs mt-1">Zarządzaj swoim profilem i bezpieczeństwem</p>
                    </div>
                </div>
                <button onclick="closePersonalModal()" class="p-2 text-slate-500 hover:text-white transition rounded-xl">
                    <i data-lucide="x" class="w-6 h-6"></i>
                </button>
            </div>

            <form id="personalForm" onsubmit="savePersonalSettings(event)" class="p-6 pt-2 space-y-5 max-h-[80vh] overflow-y-auto custom-scrollbar">
                <input type="hidden" name="action" value="update_profile">
                
                <div class="h-[1px] bg-white/5 w-full mb-6"></div>

                <div class="grid grid-cols-1 md:grid-cols-12 gap-10 items-start">
                    <!-- Left: Avatar Section -->
                    <div class="md:col-span-5 flex items-center gap-6">
                        <div class="relative">
                            <div id="avatar-preview" class="w-32 h-32 rounded-[2.5rem] overflow-hidden ring-4 ring-white/5 shadow-2xl bg-blue-600/20">
                                <?php if (!empty($config['admin_avatar']) && file_exists(__DIR__ . '/' . $config['admin_avatar'])): ?>
                                    <img src="<?= $config['admin_avatar'] ?>" class="w-full h-full object-cover">
                                <?php else: ?>
                                    <div class="w-full h-full bg-gradient-to-br from-blue-500 to-indigo-600 flex items-center justify-center text-white">
                                        <i data-lucide="user" class="w-12 h-12"></i>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <label for="avatar-input" class="absolute -bottom-1 -right-1 w-10 h-10 rounded-xl bg-[#1e293b] text-white flex items-center justify-center cursor-pointer shadow-lg border border-white/10 hover:bg-[#334155] transition-all">
                                <i data-lucide="camera" class="w-5 h-5"></i>
                                <input type="file" id="avatar-input" name="avatar" class="hidden" accept="image/*" onchange="previewAvatar(this)">
                            </label>
                        </div>
                        <div>
                            <p class="text-xl font-bold text-white mb-1">Twój Avatar</p>
                            <p class="text-[10px] font-black text-slate-500 uppercase tracking-widest">Zmień zdjęcie profilowe</p>
                        </div>
                    </div>

                    <!-- Right: Form Fields -->
                    <div class="md:col-span-7 grid grid-cols-2 gap-x-4 gap-y-6">
                        <div class="col-span-1">
                            <label class="block text-[9px] font-black text-slate-500 uppercase tracking-widest mb-2 px-1">Login / Użytkownik</label>
                            <input type="text" name="username" value="<?= htmlspecialchars($config['admin_username']) ?>" required
                                   class="w-full px-5 py-3.5 bg-slate-900/50 border border-white/10 rounded-2xl focus:outline-none focus:ring-2 focus:ring-blue-500/30 text-xs font-bold transition-all text-slate-200">
                        </div>
                        <div class="col-span-1">
                            <label class="block text-[9px] font-black text-slate-500 uppercase tracking-widest mb-2 px-1">Nowe Hasło</label>
                            <input type="password" name="password" placeholder="••••••••"
                                   class="w-full px-5 py-3.5 bg-slate-900/50 border border-white/10 rounded-2xl focus:outline-none focus:ring-2 focus:ring-blue-500/30 text-xs font-bold transition-all text-slate-200">
                        </div>
                        <div class="col-span-1">
                            <label class="block text-[9px] font-black text-slate-500 uppercase tracking-widest mb-2 px-1">Imię i Nazwisko</label>
                            <input type="text" name="full_name" value="<?= htmlspecialchars($config['admin_full_name'] ?? '') ?>"
                                   class="w-full px-5 py-3.5 bg-slate-900/50 border border-white/10 rounded-2xl focus:outline-none focus:ring-2 focus:ring-blue-500/30 text-xs font-bold transition-all text-slate-200">
                        </div>
                        <div class="col-span-1">
                            <label class="block text-[9px] font-black text-slate-500 uppercase tracking-widest mb-2 px-1">Adres Email</label>
                            <input type="email" name="email" value="<?= htmlspecialchars($config['admin_email']) ?>"
                                   class="w-full px-5 py-3.5 bg-slate-900/50 border border-white/10 rounded-2xl focus:outline-none focus:ring-2 focus:ring-blue-500/30 text-xs font-bold transition-all text-slate-200">
                        </div>
                    </div>
                </div>

                <!-- Login History Section -->
                <div class="mt-6">
                    <div class="mb-4 flex items-center justify-between">
                        <h3 class="text-sm font-bold text-white uppercase tracking-wider">Historia Logowań</h3>
                         <button type="button" class="text-[10px] text-blue-400 font-bold uppercase tracking-widest hover:text-white transition">
                            Zobacz całą historię
                        </button>
                    </div>
                    <div class="bg-slate-900 border border-white/10 rounded-2xl overflow-hidden">
                        <div class="divide-y divide-white/5">
                             <!-- Active Session -->
                             <?php
                                $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
                                $browser = 'Unknown Browser';
                                $os = 'Unknown OS';
                                if (preg_match('/windows|win32/i', $ua)) $os = 'Windows';
                                elseif (preg_match('/macintosh|mac os x/i', $ua)) $os = 'macOS';
                                elseif (preg_match('/linux/i', $ua)) $os = 'Linux';
                                elseif (preg_match('/android/i', $ua)) $os = 'Android';
                                elseif (preg_match('/iphone/i', $ua)) $os = 'iOS';

                                if (preg_match('/chrome/i', $ua)) $browser = 'Chrome';
                                elseif (preg_match('/firefox/i', $ua)) $browser = 'Firefox';
                                elseif (preg_match('/safari/i', $ua)) $browser = 'Safari';
                                elseif (preg_match('/edge/i', $ua)) $browser = 'Edge';
                                
                                $client_ip = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
                                $current_loc = get_ip_location($client_ip);

                                $historyFile = __DIR__ . '/data/login_history.json';
                                $historyData = [];
                                if (file_exists($historyFile)) {
                                    $loaded = json_decode(file_get_contents($historyFile), true);
                                    if (is_array($loaded)) $historyData = $loaded;
                                }
                             ?>
                             <div class="p-4 flex items-center justify-between hover:bg-white/[0.02] transition">
                                <div class="flex items-center gap-4">
                                    <div class="w-10 h-10 rounded-xl bg-emerald-500/10 text-emerald-500 flex items-center justify-center shadow-lg shadow-emerald-500/10">
                                        <i data-lucide="laptop" class="w-5 h-5"></i>
                                    </div>
                                    <div>
                                        <div class="text-xs font-bold text-white">Obecna Sesja (<?= $os ?>)</div>
                                        <div class="text-[10px] text-slate-500 font-mono mt-0.5">IP: <?= $client_ip ?> • <?= $browser ?></div>
                                        <div class="text-[9px] text-blue-400 font-bold mt-0.5 flex items-center gap-1.5">
                                            <i data-lucide="map-pin" class="w-2.5 h-2.5"></i>
                                            <?= $current_loc ?>
                                        </div>
                                    </div>
                                </div>
                                <span class="px-2 py-1 rounded bg-emerald-500/10 text-emerald-400 text-[10px] font-bold uppercase tracking-wider animate-pulse ring-1 ring-emerald-500/20">Aktywna</span>
                            </div>

                            <!-- Past History -->
                            <?php 
                            $hist_count = 0;
                            if (!empty($historyData)):
                                foreach ($historyData as $idx => $entry):
                                    // Skip current (first entry in file is newest, likely current or just previous)
                                    if ($idx === 0 && ($entry['ip'] ?? '') === $client_ip) continue;
                                    if ($hist_count >= 3) break;
                                    $hist_count++;
                            ?>
                             <div class="p-4 flex items-center justify-between hover:bg-white/[0.02] transition border-t border-white/[0.02]">
                                <div class="flex items-center gap-4">
                                    <div class="w-10 h-10 rounded-xl bg-slate-800 text-slate-500 flex items-center justify-center">
                                        <i data-lucide="history" class="w-5 h-5"></i>
                                    </div>
                                    <div>
                                        <div class="text-xs font-bold text-slate-300"><?= $entry['os'] ?? 'OS' ?> • <?= $entry['browser'] ?? 'Browser' ?></div>
                                        <div class="text-[10px] text-slate-500 font-mono mt-0.5"><?= date('d.m.Y H:i', $entry['timestamp']) ?> • IP: <?= $entry['ip'] ?></div>
                                        <div class="text-[9px] text-slate-500 mt-0.5 flex items-center gap-1.5">
                                            <i data-lucide="map-pin" class="w-2.5 h-2.5"></i>
                                            <?= $entry['location'] ?? 'Unknown' ?>
                                        </div>
                                    </div>
                                </div>
                                <span class="text-[9px] text-slate-600 font-black uppercase tracking-widest">Logowanie</span>
                            </div>
                            <?php 
                                endforeach;
                            endif;

                            if ($hist_count === 0): ?>
                             <div class="p-6 flex items-center justify-center text-slate-600 text-[9px] uppercase font-black tracking-[0.2em] italic">
                                Brak starszych aktywności
                             </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- 2FA Section -->
                <div class="mt-6 border border-white/10 rounded-2xl p-5 bg-gradient-to-br from-slate-900 to-slate-950 flex flex-col justify-center relative group overflow-hidden">
                     <div class="absolute inset-0 bg-blue-500/5 opacity-0 group-hover:opacity-100 transition-opacity"></div>
                     <div class="flex items-center gap-4 relative z-10">
                        <div class="w-12 h-12 rounded-xl bg-blue-600/10 text-blue-500 flex items-center justify-center border border-blue-600/20 group-hover:scale-110 transition-transform shadow-lg shadow-blue-900/20">
                             <i data-lucide="shield-check" class="w-6 h-6"></i>
                        </div>
                        <div>
                            <h3 class="text-sm font-bold text-white">Podwójne Uwierzytelnianie (2FA)</h3>
                            <p class="text-[10px] font-black text-blue-400/80 uppercase tracking-widest mt-1">Coming Soon...</p>
                        </div>
                    </div>
                    <div class="mt-4 pt-4 border-t border-white/5 flex justify-between items-center relative z-10 opacity-50 pointer-events-none">
                        <span class="text-[10px] text-slate-500 font-medium">Zabezpiecz konto dodatkową warstwą ochrony.</span>
                        <div class="w-10 h-5 bg-slate-800 rounded-full relative border border-white/10">
                             <div class="absolute left-0.5 top-0.5 w-4 h-4 bg-slate-600 rounded-full"></div>
                        </div>
                    </div>
                </div>

                <div class="pt-6 flex justify-end sticky bottom-0 bg-slate-900/95 backdrop-blur py-4 -mx-6 px-6 border-t border-white/5 mt-4">
                    <button type="submit" class="px-12 py-4 bg-blue-600 hover:bg-blue-500 text-white font-black rounded-2xl transition shadow-xl shadow-blue-600/20 text-[11px] uppercase tracking-[0.2em] flex items-center justify-center gap-2">
                         Zapisz Dane
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openPersonalModal() {
            const modal = document.getElementById('personalModal');
            if (modal) {
                modal.classList.add('active');
                document.body.style.overflow = 'hidden';
                lucide.createIcons();
            } else {
                console.error('personalModal not found!');
            }
        }

        function closePersonalModal(e) {
            if (e && e.target !== e.currentTarget) return;
            const modal = document.getElementById('personalModal');
            if (modal) {
                modal.classList.remove('active');
                document.body.style.overflow = '';
            }
        }

        function previewAvatar(input) {
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    const preview = document.getElementById('avatar-preview');
                    if (preview) {
                        preview.innerHTML = `<img src="${e.target.result}" class="w-full h-full object-cover">`;
                    }
                };
                reader.readAsDataURL(input.files[0]);
            }
        }

        async function savePersonalSettings(e) {
            e.preventDefault();
            const form = e.target;
            const formData = new FormData(form);
            const btn = form.querySelector('button[type="submit"]');
            const originalText = btn.innerHTML;
            
            btn.disabled = true;
            btn.innerHTML = '<i data-lucide="refresh-cw" class="w-4 h-4 animate-spin mr-2"></i> Zapisywanie...';
            lucide.createIcons();

            try {
                const response = await fetch('api_user_settings.php', {
                    method: 'POST',
                    body: formData
                });
                const result = await response.json();
                
                if (result.success) {
                    // Refresh current page to see changes
                    location.reload();
                } else {
                    alert('Błąd: ' + result.message);
                }
            } catch (err) {
                console.error(err);
                alert('Wystąpił nieoczekiwany błąd zapisu.');
            } finally {
                btn.disabled = false;
                btn.innerHTML = originalText;
                lucide.createIcons();
            }
        }
    </script>
    <?php
}

// Globalna stopka
function _disabled_render_footer() {
    ?>
    <div class="mt-20 pb-12">
        <div class="max-w-6xl mx-auto px-6">
            <div class="h-[1px] bg-white/5 w-full mb-8"></div>
            <footer class="text-center">
                <div class="flex items-center justify-center gap-3 text-slate-500 text-[11px] uppercase tracking-[0.3em] font-black">
                    MiniDASH v1.5.0 © 2026 
                    <span class="text-slate-700">/</span>
                    <a href="https://www.lm-ads.com" target="_blank" class="text-slate-400 hover:text-blue-400 transition hover:tracking-[0.4em]">lm-network</a> 
                    <span class="text-slate-700">/</span>
                    Łukasz Misiura
                </div>
            </footer>
        </div>
    </div>
    <?php
    render_personal_modal();
}



