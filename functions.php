<?php
/** Created by Łukasz Misiura (c) 2025 | dev.lm-ads.com **/
// functions.php - Clean Version
ini_set('memory_limit', '512M');
ini_set('max_execution_time', 60);

function loadDevices()
{
    global $db;
    if (!isset($db)) {
        // JSON fallback
        $devicesFile = __DIR__ . '/data/devices.json';
        if (file_exists($devicesFile)) {
            return json_decode(file_get_contents($devicesFile), true) ?? [];
        }
        return [];
    }

    $stmt = $db->query("SELECT mac, name, vlan, added_at FROM device_monitors ORDER BY added_at ASC");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $devices = [];
    foreach ($rows as $row) {
        $devices[$row['mac']] = [
            'name'     => $row['name'],
            'vlan'     => $row['vlan'],
            'added_at' => $row['added_at'],
        ];
    }
    return $devices;
}

function normalize_mac($mac)
{
    if (!$mac) return '';
    return strtolower(str_replace(['-', ':'], '', $mac));
}

function get_console_settings() {
    static $console_settings = null;
    if ($console_settings !== null) return $console_settings;

    global $config;
    $site = $_SESSION['site_id'] ?? $config['site'] ?? 'default';
    $tradSite = get_trad_site_id($site);
    $resp = fetch_api("/proxy/network/api/s/$tradSite/get/setting/system");
    $data = $resp['data'] ?? [];
    
    $settings = [
        'language' => 'en',
        'timezone' => 'UTC',
        'time_format' => '24h',
        'date_format' => 'd.m.Y'
    ];

    foreach ($data as $item) {
        if (($item['key'] ?? '') === 'system') {
            $settings['timezone'] = $item['timezone'] ?? $settings['timezone'];
            $settings['time_format'] = ($item['time_format'] ?? '') === 'HH:MM' ? '24h' : '12h';
            // date_format is trickier as UniFi uses custom strings, simplified for now
            break;
        }
    }
    
    $console_settings = $settings;
    return $settings;
}

function get_system_info() {
    // Cache for 60s — this function makes 5 API calls
    $cached = minidash_cache_get('system_info', 60);
    if ($cached !== null) return $cached;

    global $config;

    $site = $_SESSION['site_id'] ?? $config['site'] ?? 'default';
    $tradSite = get_trad_site_id($site);
    $site_resp = fetch_api("/proxy/network/api/s/$tradSite/stat/sysinfo");
    $sysinfo = $site_resp['data'][0] ?? [];

    $dev_resp = fetch_api("/proxy/network/api/s/$tradSite/stat/device");
    $devices = $dev_resp['data'] ?? [];

    $udr = null;
    foreach ($devices as $d) {
        $m = $d['model'] ?? '';
        if (isset($d['wan1']) || in_array($m, ['UDR', 'UDM', 'UXG', 'USG', 'UCG', 'UX', 'UXG-LITE', 'UXG-MAX', 'UDMPRO', 'UDMSE', 'UDM-SE', 'UDM-PRO-MAX'])) {
            $udr = $d;
            break;
        }
    }

    $os_version = $sysinfo['console_display_version'] ?? $sysinfo['version'] ?? 'Nieznana';

    // CPU, RAM, disk from gateway device stats
    $cpu = $udr['system-stats']['cpu'] ?? $udr['cpu'] ?? 0;
    $ram = $udr['system-stats']['mem'] ?? $udr['ram'] ?? 0;
    $disk_used = 0;
    $disk_total = 0;
    if (isset($udr['storage'])) {
        foreach ($udr['storage'] as $s) {
            $disk_total += $s['size'] ?? 0;
            $disk_used += $s['used'] ?? 0;
        }
    }

    // Update channel from sysinfo
    $update_channel = $sysinfo['update_channel'] ?? $sysinfo['release_channel'] ?? 'release';

    // Firmware update info
    $fw_update_available = isset($udr['upgradable']) && $udr['upgradable'];
    $fw_update_version = $udr['upgrade_to_firmware'] ?? '';

    // Installed applications — try /proxy/network/api/s/{$site}/stat/sysinfo for apps list
    // and also check other Protect/Talk/Identity endpoints
    $apps = [];

    // 1. UniFi Network (always present)
    $apps[] = [
        'name' => 'UniFi Network',
        'version' => $sysinfo['version'] ?? 'N/A',
        'status' => 'Active',
        'icon' => 'network',
        'color' => 'blue',
        'update' => $fw_update_available,
        'update_version' => $fw_update_version,
        'channel' => $update_channel,
    ];

    // 2. UniFi Protect — check bootstrap endpoint
    $protect_resp = fetch_api("/proxy/protect/api/bootstrap");
    $protect_data = $protect_resp['data'] ?? $protect_resp['original'] ?? null;
    if ($protect_data && (isset($protect_data['nvr']) || isset($protect_data['cameras']))) {
        $nvr = $protect_data['nvr'] ?? [];
        $protect_version = $nvr['version'] ?? $nvr['firmwareVersion'] ?? '';
        $protect_uptodate = !($nvr['isUpdating'] ?? false) && empty($nvr['availableFirmwareVersion'] ?? '');
        $apps[] = [
            'name' => 'UniFi Protect',
            'version' => $protect_version ?: 'Installed',
            'status' => 'Active',
            'icon' => 'video',
            'color' => 'purple',
            'update' => !$protect_uptodate,
            'update_version' => $nvr['availableFirmwareVersion'] ?? '',
            'channel' => '',
        ];
    }

    // 3. UniFi Talk — check talk endpoint
    $talk_resp = fetch_api("/proxy/talk/api/bootstrap");
    if (!empty($talk_resp['data']) || (isset($talk_resp['original']) && !isset($talk_resp['original']['error']))) {
        $apps[] = [
            'name' => 'UniFi Talk',
            'version' => $talk_resp['data']['version'] ?? $talk_resp['original']['version'] ?? 'Installed',
            'status' => 'Active',
            'icon' => 'phone',
            'color' => 'cyan',
            'update' => false,
            'update_version' => '',
            'channel' => '',
        ];
    }

    // 4. UniFi Access — check access endpoint
    $access_resp = fetch_api("/proxy/access/api/v2/bootstrap");
    if (!empty($access_resp['data']) || (isset($access_resp['original']) && !isset($access_resp['original']['error']))) {
        $apps[] = [
            'name' => 'UniFi Access',
            'version' => $access_resp['data']['version'] ?? $access_resp['original']['version'] ?? 'Installed',
            'status' => 'Active',
            'icon' => 'key',
            'color' => 'amber',
            'update' => false,
            'update_version' => '',
            'channel' => '',
        ];
    }

    $result = [
        'version' => $os_version,
        'model' => $udr['model'] ?? 'UniFi Gateway',
        'up_to_date' => !$fw_update_available,
        'update_ver' => $fw_update_version,
        'update_channel' => $update_channel,
        'uptime_pretty' => isset($sysinfo['uptime']) ? formatDuration($sysinfo['uptime']) : 'N/A',
        'cpu_usage' => $cpu,
        'ram_usage' => $ram,
        'disk_used' => $disk_used,
        'disk_total' => $disk_total,
        'apps' => $apps,
    ];
    minidash_cache_set('system_info', $result);
    return $result;
}

// CSRF Protection
function csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field() {
    return '<input type="hidden" name="_csrf" value="' . csrf_token() . '">';
}

function verify_csrf($token = null) {
    $token = $token ?? ($_POST['_csrf'] ?? $_GET['_csrf'] ?? '');
    return hash_equals(csrf_token(), $token);
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
        $url = rtrim($config['controller_url'], '/') . '/' . ltrim($endpoint, '/');
        $apiKey = $config['api_key'];
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5); 
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        
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
        
        // Standard UniFi response normalization
        if (isset($data['data']) && is_array($data['data'])) {
            return $data;
        }
        
        // If it's a direct array (list or object), wrap it as data
        if (is_array($data)) {
            return ['data' => $data];
        }

        return ['data' => [], 'error' => 'Invalid JSON structure', 'original' => $data];
    } catch (Throwable $e) {
        return ['data' => [], 'error' => $e->getMessage()];
    }
}

/**
 * Returns the site ID to be used for Traditional API paths (/api/s/...)
 * For local gateways (UDM/UDR), the UUID site ID from Integration API 
 * MUST be replaced with 'default' for traditional paths.
 */
function get_trad_site_id($siteId = null) {
    global $config;
    if ($siteId === null) {
        $siteId = $_SESSION['site_id'] ?? $config['site'] ?? 'default';
    }
    
    // If it looks like a UUID (with dashes) or MongoDB ObjectId (24 hex chars),
    // it needs to be 'default' for Traditional API on most local controllers.
    if (preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i', $siteId)) {
        return 'default';
    }
    if (preg_match('/^[a-f0-9]{24}$/i', $siteId)) {
        return 'default';
    }
    
    return $siteId;
}

function fetch_api_raw($endpoint)
{
    global $config;
    
    if (!function_exists('curl_init')) {
        return false;
    }

    try {
        $url    = rtrim($config['controller_url'], '/') . '/' . ltrim($endpoint, '/');
        $apiKey = $config['api_key'];
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5); 
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "X-API-KEY: $apiKey"
        ]);
        
        $output = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($output === false || $http_code >= 400) {
            return false;
        }

        return $output;
    } catch (Throwable $e) {
        return false;
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
 * Loguje zdarzenie logowania do bazy SQLite (lub pliku data/login_history.json jako fallback)
 */
function log_login_event($username) {
    global $db;

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

    $location  = get_ip_location($ip);
    $logged_at = date('Y-m-d H:i:s');

    if (!isset($db)) {
        // JSON fallback
        $dataFile = __DIR__ . '/data/login_history.json';
        $history  = [];
        if (file_exists($dataFile)) {
            $history = json_decode(file_get_contents($dataFile), true) ?: [];
        }
        $newEntry = [
            'timestamp' => time(),
            'username'  => $username,
            'ip'        => $ip,
            'location'  => $location,
            'os'        => $os,
            'browser'   => $browser,
            'ua'        => $ua,
        ];
        array_unshift($history, $newEntry);
        $history = array_slice($history, 0, 100);
        if (!is_dir(__DIR__ . '/data')) @mkdir(__DIR__ . '/data', 0777, true);
        file_put_contents($dataFile, json_encode($history, JSON_PRETTY_PRINT));
    } else {
        $stmt = $db->prepare("
            INSERT INTO login_history (username, ip, location, os, browser, user_agent, logged_at)
            VALUES (:username, :ip, :location, :os, :browser, :user_agent, :logged_at)
        ");
        $stmt->execute([
            ':username'   => $username,
            ':ip'         => $ip,
            ':location'   => $location,
            ':os'         => $os,
            ':browser'    => $browser,
            ':user_agent' => $ua,
            ':logged_at'  => $logged_at,
        ]);
    }

    // Always write to the plain-text access log as well
    $txtLog = __DIR__ . '/logs/access.log';
    if (!is_dir(__DIR__ . '/logs')) @mkdir(__DIR__ . '/logs', 0777, true);
    $logLine = "[$logged_at] LOGIN: $username from $ip ($location) - $ua\n";
    file_put_contents($txtLog, $logLine, FILE_APPEND);
}

function get_unifi_blocked_ips() {
    global $config;
    
    // Performance: Cache in session for 60 seconds
    if (!empty($_SESSION['blocked_ips_data']) && !empty($_SESSION['blocked_ips_time'])) {
        if (time() - $_SESSION['blocked_ips_time'] < 120) {
            return $_SESSION['blocked_ips_data'];
        }
    }

    $siteId = get_trad_site_id($_SESSION['site_id'] ?? $config['site'] ?? 'default');

    // Fetch latest blocked IPS events
    $resp = fetch_api("/proxy/network/api/s/$siteId/rest/alarm?limit=100");
    $raw_events = $resp['data'] ?? [];
    
    $blocked = [];
    $seen_ips = [];
    
    foreach ($raw_events as $e) {
        if (!isset($e['src_ip']) || ($e['inner_alert_action'] ?? '') !== 'blocked') continue;
        
        // Only unique IPs for this list
        if (in_array($e['src_ip'], $seen_ips)) continue;
        $seen_ips[] = $e['src_ip'];
        
        $country = $e['srcipCountry'] ?? $e['country_code'] ?? '??';
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
        
        if (count($blocked) >= 34) break;
    }
    
    $_SESSION['blocked_ips_data'] = $blocked;
    $_SESSION['blocked_ips_time'] = time();
    return $blocked;
}


/**
 * Lookup GeoIP information for multiple IP addresses in one batch call
 * Extremely efficient and stays within ip-api.com limits
 */
function lookup_geoip_batch($ips) {
    if (empty($ips)) return [];
    
    $cache_file = __DIR__ . '/data/geoip_cache.json';
    $cache = file_exists($cache_file) ? (json_decode(file_get_contents($cache_file), true) ?: []) : [];
    $results = [];
    $to_lookup = [];
    
    // 1. Separate cached from unknown
    foreach ($ips as $ip) {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            $results[$ip] = ['country' => 'Local', 'country_code' => 'local', 'city' => '', 'org' => 'LAN'];
            continue;
        }
        
        if (isset($cache[$ip]) && (time() - ($cache[$ip]['ts'] ?? 0)) < 86400) {
            $results[$ip] = $cache[$ip];
        } else {
            $to_lookup[] = $ip;
        }
    }
    
    // 2. Perform batch lookup for unknowns (max 100 per request as per ip-api limits)
    if (!empty($to_lookup)) {
        $chunks = array_chunk(array_unique($to_lookup), 100);
        foreach ($chunks as $chunk) {
            $url = "http://ip-api.com/batch?fields=status,message,query,country,countryCode,city,org,isp";
            $post_data = json_encode($chunk);
            
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $post_data,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_TIMEOUT => 5,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_SSL_VERIFYPEER => false
            ]);
            
            $response = curl_exec($ch);
            curl_close($ch);
            
            if ($response) {
                $data = json_decode($response, true);
                if (is_array($data)) {
                    foreach ($data as $item) {
                        $ip = $item['query'] ?? '';
                        if (($item['status'] ?? '') === 'success') {
                            $res = [
                                'country' => $item['country'] ?? '??',
                                'country_code' => strtolower($item['countryCode'] ?? '??'),
                                'city' => $item['city'] ?? '',
                                'org' => $item['org'] ?? $item['isp'] ?? '',
                                'ts' => time()
                            ];
                            $cache[$ip] = $res;
                            $results[$ip] = $res;
                        } else {
                             $results[$ip] = ['country' => '??', 'country_code' => '??', 'ts' => time()];
                        }
                    }
                }
            }
        }
        
        // Save cache back (prune old)
        if (count($cache) > 2000) {
            uasort($cache, fn($a, $b) => ($b['ts'] ?? 0) <=> ($a['ts'] ?? 0));
            $cache = array_slice($cache, 0, 2000, true);
        }
        file_put_contents($cache_file, json_encode($cache));
    }
    
    return $results;
}

/**
 * Legacy wrapper for single IP lookup
 */
function lookup_geoip($ip) {
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
        return ['country' => 'Local', 'country_code' => 'local', 'city' => '', 'org' => 'LAN'];
    }
    $batch = lookup_geoip_batch([$ip]);
    return $batch[$ip] ?? ['country' => '??', 'country_code' => '??', 'city' => '', 'org' => ''];
}

/**
 * Get only country code for a given IP
 */
function get_country_code_for_ip($ip) {
    $geo = lookup_geoip($ip);
    return $geo['country_code'] ?? 'un';
}

/**
 * Helper for fast file-based caching
 */
function minidash_cache_get($key, $ttl = 60) {
    $file = __DIR__ . "/data/cache_" . md5($key) . ".json";
    if (file_exists($file) && (time() - filemtime($file)) < $ttl) {
        return json_decode(file_get_contents($file), true);
    }
    return null;
}

function minidash_cache_set($key, $data) {
    if (!is_dir(__DIR__ . "/data")) @mkdir(__DIR__ . "/data", 0777, true);
    file_put_contents(__DIR__ . "/data/cache_" . md5($key) . ".json", json_encode($data));
}

function get_unifi_security_events() {
    global $config;
    
    // Performance: Fast File Cache for 120s
    $cached = minidash_cache_get('security_events', 120);
    if ($cached !== null) return $cached;

    $site = get_trad_site_id($_SESSION['site_id'] ?? $config['site'] ?? 'default');

    // Fetch latest 50 IPS events
    $resp = fetch_api("/proxy/network/api/s/$site/rest/alarm?limit=50");
    $raw_events = $resp['data'] ?? [];
    
    // 1. Collect all source IPs for batch GeoIP
    $src_ips = array_filter(array_column($raw_events, 'src_ip'));
    $geo_data = lookup_geoip_batch($src_ips);
    
    $events = [];
    foreach ($raw_events as $e) {
        $time = isset($e['time']) ? date('H:i:s', $e['time'] / 1000) : date('H:i:s');
        $src_ip = $e['src_ip'] ?? '';
        
        $severity = 'medium';
        if (isset($e['inner_alert_action'])) {
            if ($e['inner_alert_action'] === 'blocked') $severity = 'critical';
            else if ($e['inner_alert_action'] === 'alert') $severity = 'high';
        }
        
        $events[] = [
            'time' => $time,
            'timestamp' => isset($e['time']) ? intval($e['time'] / 1000) : time(),
            'type' => strtolower($e['inner_alert_category'] ?? 'intrusion'),
            'severity' => $severity,
            'source' => 'IPS Engine',
            'src_ip' => $src_ip ?: 'Unknown',
            'dst_ip' => $e['dst_ip'] ?? 'Local',
            'country_code' => $geo_data[$src_ip]['country_code'] ?? 'un',
            'signature' => $e['inner_alert_signature'] ?? 'Unknown Threat',
            'description' => 'Wykryto ' . ($e['inner_alert_signature'] ?? 'zagrożenie') . ' z IP ' . ($src_ip ?: 'nieznane'),
            'action' => (($e['inner_alert_action'] ?? '') === 'blocked' ? 'Zablokowano' : 'Wykryto')
        ];
    }
    
    minidash_cache_set('security_events', $events);
    return $events;
}

function get_unifi_security_settings() {
    global $config;
    
    // Performance: Fast File Cache for 300s (5 min)
    $cached = minidash_cache_get('security_settings', 300);
    if ($cached !== null) return $cached;

    $site = $_SESSION['site_id'] ?? $config['site'] ?? 'default';
    $tradSite = get_trad_site_id($site);
    
    // 1. Fetch IPS/IDS Settings
    $ips_resp = fetch_api("/proxy/network/api/s/$tradSite/rest/setting/ips");
    
    $site_to_use = $site;
    if (($ips_resp['meta']['rc'] ?? '') === 'error') {
        $sites_resp = fetch_api("/proxy/network/api/self/sites");
        $site_to_use = $sites_resp['data'][0]['name'] ?? 'default';
        $trad_site_to_use = get_trad_site_id($site_to_use);
        $ips_resp = fetch_api("/proxy/network/api/s/$trad_site_to_use/rest/setting/ips");
    } else {
        $real_site_id = $ips_resp['data'][0]['site_id'] ?? '';
        if ($real_site_id) $site_to_use = $real_site_id;
    }

    $site_to_use_trad = get_trad_site_id($site_to_use);

    // 2. Optimized Firewall Rules count with Path Memory
    $fw_path = minidash_cache_get('api_path_firewall', 3600);
    $firewall_rules_resp = ['data' => []];
    if (!$fw_path) {
        $paths = ["/proxy/network/api/s/$site_to_use_trad/rest/firewallrule", "/proxy/network/api/v2/firewall/rules", "/proxy/network/api/s/$site_to_use_trad/stat/firewall/rules"];
        foreach ($paths as $p) {
            $test = fetch_api($p);
            if (!empty($test['data'])) {
                $fw_path = $p;
                minidash_cache_set('api_path_firewall', $p);
                $firewall_rules_resp = $test;
                break;
            }
        }
    } else {
        $firewall_rules_resp = fetch_api($fw_path);
    }
    
    // 3. Fast Threat Stats (Last hour)
    $threats_resp = fetch_api("/proxy/network/api/s/$site_to_use_trad/rest/alarm?period=3600");
    $threats_count = count($threats_resp['data'] ?? []);
    
    // 3a. VPN Check — use networkconf (rest/vpn and rest/vpnserver are empty on UDR)
    $vpn_active = false;
    $vpn_list = [];
    $net_resp = fetch_api("/proxy/network/api/s/$site_to_use_trad/rest/networkconf");
    foreach (($net_resp['data'] ?? []) as $net) {
        $purpose = $net['purpose'] ?? '';
        if ($purpose === 'remote-user-vpn' || $purpose === 'site-vpn') {
            $vpn_active = true;
            $vpn_list[] = [
                'name' => $net['name'] ?? 'VPN',
                'type' => $net['vpn_type'] ?? $purpose,
                'subnet' => $net['ip_subnet'] ?? '',
                'enabled' => $net['enabled'] ?? true,
            ];
        }
    }
    
    // 4. Rule List Construction
    $rule_list = [];
    foreach ($firewall_rules_resp['data'] ?? [] as $r) {
        $rule_list[] = [
            'id' => $r['_id'] ?? $r['id'] ?? 'N/A',
            'name' => $r['name'] ?? 'Firewall Rule',
            'category' => $r['ruleset'] ?? 'Network',
            'priority' => ($r['action'] ?? '') === 'drop' ? 'HIGH' : 'MEDIUM',
            'action' => strtoupper($r['action'] ?? 'ALLOW'),
            'status' => ($r['enabled'] ?? true) ? 'Aktywna' : 'Nieaktywna'
        ];
    }
    
    // Traffic rules enrichment
    $traffic_rules_resp = fetch_api("/proxy/network/api/s/$site_to_use_trad/rest/trafficrule");
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

    // Check for Region Blocking — extract from IPS blocked events by country
    $geo_countries = [];
    $geo_rules = [];
    $blocked_by_country = [];

    // Build country stats from IPS events (blocked traffic)
    $ips_events_resp = fetch_api("/proxy/network/api/s/$site_to_use_trad/rest/alarm?limit=500");
    foreach (($ips_events_resp['data'] ?? []) as $e) {
        $cc = strtolower($e['srcipCountry'] ?? $e['src_country'] ?? '');
        $action = $e['inner_alert_action'] ?? '';
        if ($action === 'blocked' && $cc && strlen($cc) === 2) {
            if (!isset($blocked_by_country[$cc])) $blocked_by_country[$cc] = 0;
            $blocked_by_country[$cc]++;
            if (!in_array($cc, $geo_countries)) $geo_countries[] = $cc;
        }
    }
    arsort($blocked_by_country);

    // Build geo_rules from aggregated data
    if (!empty($blocked_by_country)) {
        $geo_rules[] = [
            'countries' => array_keys($blocked_by_country),
            'counts' => $blocked_by_country,
            'direction' => 'in',
            'action' => 'BLOCK',
            'name' => 'IPS Blocked Sources (z ostatnich zdarzen)',
        ];
    }

    $geoblocking_enabled = !empty($blocked_by_country);

    $ips_config = $ips_resp['data'][0] ?? [];
    $settings = [
        'ips_enabled' => isset($ips_config['ips_mode']) && $ips_config['ips_mode'] !== 'disabled',
        'ad_blocking_enabled' => isset($ips_config['ad_blocking_enabled']) && $ips_config['ad_blocking_enabled'],
        'honeypot_enabled' => isset($ips_config['honeypot_enabled']) && $ips_config['honeypot_enabled'],
        'threat_detection_enabled' => (isset($ips_config['ips_mode']) && $ips_config['ips_mode'] !== 'disabled') || (isset($ips_config['ad_blocking_enabled']) && $ips_config['ad_blocking_enabled']),
        'total_rules_count' => count($rule_list),
        'rule_list' => $rule_list,
        'threats_count' => $threats_count,
        'geoblocking_enabled' => $geoblocking_enabled || !empty($ips_config['geoblock_enabled']) || !empty($ips_config['country_block_enabled']),
        'blocked_countries' => $geo_countries,
        'geo_rules' => $geo_rules,
        'monitoring_active' => true,
        'vpn_secure' => $vpn_active,
        'vpn_list' => $vpn_list
    ];
    
    minidash_cache_set('security_settings', $settings);
    return $settings;
}

// Pobierz sieci z API UniFi (cache w sesji na 5 min)
function get_vlans_from_api() {
    global $config;
    if (isset($_SESSION['network_vlans']) && isset($_SESSION['network_vlans_time']) && (time() - $_SESSION['network_vlans_time'] < 30) && !empty($_SESSION['network_subnets'])) {
        return $_SESSION['network_vlans'];
    }

    $site = $_SESSION['site_id'] ?? $config['site'] ?? 'default';
    $tradSite = get_trad_site_id($site);
    $resp = fetch_api("/proxy/network/api/s/{$tradSite}/rest/networkconf");
    $networks = $resp['data'] ?? [];

    $vlans = [];
    $subnets = []; // subnet => vlan_id mapping

    foreach ($networks as $net) {
        $vlan_id = (int)($net['vlan'] ?? $net['vlan_id'] ?? 0);
        $name = $net['name'] ?? 'Network ' . $vlan_id;
        $purpose = $net['purpose'] ?? '';
        $subnet = $net['ip_subnet'] ?? '';

        // For corporate/VLAN networks
        if ($vlan_id > 0 || $purpose === 'corporate' || $purpose === 'vlan-only') {
            $vlans[$vlan_id] = $name;
            if ($subnet) {
                $subnets[$subnet] = $vlan_id;
            }
        }

        // VPN networks (remote-user-vpn, site-vpn)
        if ($purpose === 'remote-user-vpn' || $purpose === 'site-vpn' || strpos($name, 'VPN') !== false || strpos($name, 'vpn') !== false) {
            if ($vlan_id === 0) $vlan_id = crc32($net['_id'] ?? $name) & 0xFFFF; // Generate pseudo-ID
            $vlans[$vlan_id] = $name;
            if ($subnet) {
                $subnets[$subnet] = $vlan_id;
            }
        }
    }

    // Default network (VLAN 1 / untagged)
    if (!isset($vlans[1])) {
        foreach ($networks as $net) {
            if (($net['purpose'] ?? '') === 'corporate' && empty($net['vlan'])) {
                $vlans[1] = $net['name'] ?? 'Default';
                if (!empty($net['ip_subnet'])) $subnets[$net['ip_subnet']] = 1;
                break;
            }
        }
    }
    if (!isset($vlans[1])) $vlans[1] = 'Main';
    if (!isset($vlans[0])) $vlans[0] = 'VPN';

    $_SESSION['network_vlans'] = $vlans;
    $_SESSION['network_subnets'] = $subnets;
    $_SESSION['network_vlans_time'] = time();

    return $vlans;
}

// Pobierz nazwę VLAN
function get_vlans() {
    return get_vlans_from_api();
}

// Wykrywanie VLAN na podstawie IP — dynamicznie z subnet map
function detect_vlan_id($ip, $current_vlan = null) {
    if ($current_vlan !== null && $current_vlan > 0) return (int)$current_vlan;

    if (empty($ip) || $ip === 'N/A' || $ip === 'Offline') return 0;

    // Use cached subnet map from API
    $subnets = $_SESSION['network_subnets'] ?? [];
    foreach ($subnets as $subnet => $vlan_id) {
        if (ip_in_subnet($ip, $subnet)) {
            return $vlan_id;
        }
    }

    // Fallback: unknown 10.x = VPN
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
    $site = $_SESSION['site_id'] ?? $config['site'] ?? 'default';
    $tradSite = get_trad_site_id($site);
    
    // Fetch network config
    // Note: detailed network config is at /rest/networkconf
    $resp = fetch_api("/proxy/network/api/s/$tradSite/rest/networkconf");
    
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
    $bootstrap = fetch_api("/proxy/protect/api/bootstrap");
    
    $cameras = [];
    $nvr = [];
    
    if (empty($bootstrap['data']) && !isset($bootstrap['cameras'])) {
        $cameras_resp = fetch_api("/proxy/protect/api/cameras");
        $cameras = $cameras_resp['data'] ?? $cameras_resp['original'] ?? $cameras_resp ?: [];
        if (isset($cameras['data']) && is_array($cameras['data'])) $cameras = $cameras['data'];
    } else {
        $cameras = $bootstrap['cameras'] ?? $bootstrap['data']['cameras'] ?? $bootstrap['original']['cameras'] ?? [];
    }

    $nvr = $bootstrap['nvr'] ?? $bootstrap['data']['nvr'] ?? $bootstrap['original']['nvr'] ?? [];

    $processed_cameras = [];
    foreach ($cameras as $c) {
        if (!is_array($c)) continue;
        $state = strtoupper($c['state'] ?? $c['status'] ?? '');
        $status = (in_array($state, ['CONNECTED', 'ONLINE', 'CONNECTED_PROTECT'])) ? 'online' : 'offline';
        
        $processed_cameras[] = [
            'id' => $c['id'] ?? 'unknown',
            'name' => $c['name'] ?? $c['hostname'] ?? 'Kamera',
            'status' => $status,
            'recording' => $c['isRecording'] ?? $c['recording'] ?? false,
            'motion' => $c['isMotionDetected'] ?? false,
            'model' => $c['model'] ?? 'G4/G5',
            'mac' => $c['mac'] ?? '',
            'ip' => $c['host'] ?? $c['connectionHost'] ?? '',
            'uptime' => isset($c['upSince']) ? (time() - ($c['upSince']/1000)) : 0,
            'thumbnail' => "api_protect_snapshot.php?id=" . ($c['id'] ?? '') . "&width=640&t=" . time()
        ];
    }

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

/**
 * Sprawdza czy Protect jest dostępny w konsoli (bootstrap zwraca dane)
 */
function is_protect_available() {
    $bootstrap = fetch_api("/proxy/protect/api/bootstrap");
    if (!empty($bootstrap['data']) || !empty($bootstrap['nvr']) || !empty($bootstrap['cameras'])) {
        return true;
    }
    return false;
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
                'rx_rate' => $c['rxRateBps'] ?? $c['rx_bytes-r'] ?? $c['rx_rate'] ?? 0,
                'tx_rate' => $c['txRateBps'] ?? $c['tx_bytes-r'] ?? $c['tx_rate'] ?? 0,
                'rx_bytes' => $c['rx_bytes'] ?? 0,
                'tx_bytes' => $c['tx_bytes'] ?? 0,
                'uptime' => $c['uptime'] ?? 0
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
            'tx_rate' => $client_data[$norm]['tx_rate'] ?? 0,
            'rx_bytes' => $client_data[$norm]['rx_bytes'] ?? 0,
            'tx_bytes' => $client_data[$norm]['tx_bytes'] ?? 0,
            'uptime' => $client_data[$norm]['uptime'] ?? 0
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
            'ip' => $info['ip'],
            'vlan' => $vlan,
            'rx_rate' => $info['rx_rate'],
            'tx_rate' => $info['tx_rate'],
            'rx_bytes' => $info['rx_bytes'],
            'tx_bytes' => $info['tx_bytes'],
            'uptime' => $info['uptime']
        ];
    }
    return $grouped;
}

function saveDevices(array $devices)
{
    global $db;
    if (!isset($db)) {
        // JSON fallback
        $devicesFile = __DIR__ . '/data/devices.json';
        file_put_contents($devicesFile, json_encode($devices, JSON_PRETTY_PRINT));
        return;
    }

    // Upsert every device in the new set
    $upsert = $db->prepare("
        INSERT INTO device_monitors (mac, name, vlan, added_at)
        VALUES (:mac, :name, :vlan, :added_at)
        ON CONFLICT(mac) DO UPDATE SET
            name     = excluded.name,
            vlan     = excluded.vlan,
            added_at = excluded.added_at
    ");

    foreach ($devices as $mac => $info) {
        $upsert->execute([
            ':mac'      => $mac,
            ':name'     => $info['name']     ?? $mac,
            ':vlan'     => $info['vlan']     ?? null,
            ':added_at' => $info['added_at'] ?? date('Y-m-d H:i:s'),
        ]);
    }

    // Delete devices that were removed from the set
    if (!empty($devices)) {
        $placeholders = implode(',', array_fill(0, count($devices), '?'));
        $macs = array_keys($devices);
        $db->prepare("DELETE FROM device_monitors WHERE mac NOT IN ($placeholders)")
           ->execute($macs);
    } else {
        $db->exec("DELETE FROM device_monitors");
    }
}

/**
 * Usuwa urządzenie całkowicie z monitoringu ORAZ całej historii w bazie
 */
function deleteDeviceCompletely($mac) {
    global $db;
    $mac = normalize_mac($mac);
    if (!$mac) return false;

    if (isset($db)) {
        $tables = [
            'device_monitors',
            'client_history',
            'device_status_history',
            'stalker_sessions',
            'stalker_roaming',
            'stalker_watchlist'
        ];
        foreach ($tables as $table) {
            $stmt = $db->prepare("DELETE FROM $table WHERE mac = ?");
            $stmt->execute([$mac]);
        }
    }

    // JSON fallback cleanups
    $files = ['devices.json', 'history.json'];
    foreach ($files as $f) {
        $path = __DIR__ . '/data/' . $f;
        if (file_exists($path)) {
            $data = json_decode(file_get_contents($path), true);
            if (is_array($data) && isset($data[$mac])) {
                unset($data[$mac]);
                file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT));
            }
        }
    }
    
    return true;
}

function loadDeviceHistory($mac)
{
    global $db;
    $norm = normalize_mac($mac);

    if (!isset($db)) {
        // JSON fallback
        $historyFile = __DIR__ . '/data/history.json';
        if (!file_exists($historyFile)) return [];
        $history = json_decode(file_get_contents($historyFile), true) ?? [];
        return $history[$norm] ?? [];
    }

    $stmt = $db->prepare("
        SELECT status, duration, timestamp
        FROM device_status_history
        WHERE mac = :mac
        ORDER BY timestamp ASC
    ");
    $stmt->execute([':mac' => $norm]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}


function format_bps($bps) {
    if ($bps >= 1000000000000) return number_format($bps / 1000000000000, 2) . ' Tbps';
    if ($bps >= 1000000000) return number_format($bps / 1000000000, 2) . ' Gbps';
    if ($bps >= 1000000) return number_format($bps / 1000000, 1) . ' Mbps';
    if ($bps >= 1000) return number_format($bps / 1000, 1) . ' Kbps';
    return round($bps) . ' bps';
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
    global $db;
    $norm      = normalize_mac($mac);
    $timestamp = date('Y-m-d H:i:s');

    if (!isset($db)) {
        // JSON fallback
        $historyFile = __DIR__ . '/data/history.json';
        $dir = dirname($historyFile);
        if (!file_exists($dir)) mkdir($dir, 0777, true);

        $allHistory = file_exists($historyFile) ? json_decode(file_get_contents($historyFile), true) : [];
        if (!isset($allHistory[$norm])) {
            $allHistory[$norm] = [];
        }
        $history = &$allHistory[$norm];

        // Do nothing if status unchanged
        if (!empty($history)) {
            $last = end($history);
            if ($last['status'] === $status) return;

            // Update duration on the previous entry
            $last_key = array_key_last($history);
            $history[$last_key]['duration'] = strtotime($timestamp) - strtotime($history[$last_key]['timestamp']);
        }

        // Send alert
        $devices    = loadDevices();
        $deviceName = $devices[$norm]['name'] ?? $mac;
        $statusText = ($status === 'on') ? "🟢 ONLINE" : "🔴 OFFLINE";
        sendAlert("Zmiana statusu: $deviceName", "Urządzenie **$deviceName** ($mac) jest teraz **$statusText**.");

        $history[] = ['status' => $status, 'timestamp' => $timestamp];
        if (count($history) > 50) {
            $history = array_slice($history, -50);
        }
        file_put_contents($historyFile, json_encode($allHistory, JSON_PRETTY_PRINT));
        return;
    }

    // ── SQLite path ────────────────────────────────────────────────────────────

    // Fetch the last entry for this device
    $lastStmt = $db->prepare("
        SELECT id, status, timestamp
        FROM device_status_history
        WHERE mac = :mac
        ORDER BY timestamp DESC
        LIMIT 1
    ");
    $lastStmt->execute([':mac' => $norm]);
    $last = $lastStmt->fetch(PDO::FETCH_ASSOC);

    // Do nothing if status unchanged
    if ($last && $last['status'] === $status) {
        return;
    }

    // Update duration of the previous entry
    if ($last) {
        $duration = strtotime($timestamp) - strtotime($last['timestamp']);
        $upd = $db->prepare("UPDATE device_status_history SET duration = :duration WHERE id = :id");
        $upd->execute([':duration' => $duration, ':id' => $last['id']]);
    }

    // Send alert on status change (only if device had previous status — skip first-time discovery)
    if ($last) {
        $devices    = loadDevices();
        $deviceName = $devices[$norm]['name'] ?? $mac;
        $statusText = ($status === 'on') ? "ONLINE" : "OFFLINE";
        sendAlert("Zmiana statusu: $deviceName", "Urządzenie **$deviceName** ($mac) jest teraz **$statusText**.");
    }

    // Insert new status entry
    $ins = $db->prepare("
        INSERT INTO device_status_history (mac, status, duration, timestamp)
        VALUES (:mac, :status, NULL, :timestamp)
    ");
    $ins->execute([':mac' => $norm, ':status' => $status, ':timestamp' => $timestamp]);

    // Trim to 50 most recent entries per device
    $db->prepare("
        DELETE FROM device_status_history
        WHERE mac = :mac
          AND id NOT IN (
              SELECT id FROM device_status_history
              WHERE mac = :mac2
              ORDER BY timestamp DESC
              LIMIT 50
          )
    ")->execute([':mac' => $norm, ':mac2' => $norm]);
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
    
    // Performance: Cache system events for 15s to keep sidebar snappy
    $cache_key = 'system_notifications_' . ($only_new ? 'new' : 'all');
    $cached = minidash_cache_get($cache_key, 15);
    if ($cached !== null) return $cached;

    $historyFile = __DIR__ . '/data/history.json';
    $events = [];
    $clear_time = 0;
    if ($only_new && !empty($config['last_notif_clear_time'])) {
        $clear_time = strtotime($config['last_notif_clear_time']);
    }

    // 1. Get Monitoring Events (Local History)
    if (file_exists($historyFile)) {
        $allHistory = json_decode(file_get_contents($historyFile), true) ?? [];
        $devices = loadDevices();
        
        foreach ($allHistory as $mac => $history) {
            if (!is_array($history)) continue;
            $deviceName = $devices[$mac]['name'] ?? $mac;
            foreach ($history as $entry) {
                if ($clear_time && strtotime($entry['timestamp']) <= $clear_time) continue;
                $events[] = array_merge($entry, [
                    'mac' => $mac,
                    'deviceName' => $deviceName,
                    'eventType' => 'monitor_status'
                ]);
            }
        }
    }

    // 2. Get MiniDash Alerts from SQLite events table
    global $db;
    if (isset($db)) {
        try {
            $stmt = $db->query("SELECT type, severity, message, details_json, created_at FROM events WHERE type = 'alert' ORDER BY created_at DESC LIMIT 30");
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $ts = $row['created_at'];
                if ($clear_time && strtotime($ts) <= $clear_time) continue;
                $details = json_decode($row['details_json'] ?? '{}', true);
                $events[] = [
                    'timestamp' => $ts,
                    'status' => 'alert',
                    'mac' => '',
                    'deviceName' => $row['message'] ?? 'Alert',
                    'eventType' => 'minidash_alert',
                    'raw_msg' => $details['message'] ?? $row['message'] ?? '',
                ];
            }
        } catch (Exception $e) {}
    }

    // 3. Get UniFi System Events (New Devices, Connects, Roaming)
    // We only fetch if site is configured
    $site = $_SESSION['site_id'] ?? $config['site'] ?? 'default';
    $tradSite = get_trad_site_id($site);
    $unifi_events = fetch_api("/proxy/network/api/s/$tradSite/stat/event?limit=30");
    
    if (!empty($unifi_events['data'])) {
        foreach ($unifi_events['data'] as $ue) {
            $key = $ue['key'] ?? '';
            // Interesting events: Connected, Disconnected, Roamed, New Device
            $interesting = [
                'EVT_WU_Connected', 'EVT_WC_Connected', 'EVT_LU_Connected', 'EVT_LC_Connected', 
                'EVT_WU_Disconnected', 'EVT_LU_Disconnected', 'EVT_WG_Connected',
                'EVT_WC_Discovered', 'EVT_WD_Discovered', 'EVT_WU_Roam', 'EVT_WU_RoamRadio'
            ];
            
            if (in_array($key, $interesting) || strpos($key, 'Connected') !== false || strpos($key, 'Discovered') !== false) {
                $status = (strpos($key, 'Connected') !== false || strpos($key, 'Discovered') !== false) ? 'on' : 'off';
                $events[] = [
                    'timestamp' => date('Y-m-d H:i:s', $ue['time'] / 1000),
                    'status' => $status,
                    'mac' => $ue['user'] ?? $ue['mac'] ?? '',
                    'deviceName' => $ue['msg'] ?? 'Urządzenie UniFi',
                    'eventType' => 'unifi_system',
                    'key' => $key,
                    'raw_msg' => $ue['msg'] ?? ''
                ];
            }
        }
    }
    
    // Sort by timestamp descending
    usort($events, function($a, $b) {
        return strtotime($b['timestamp']) - strtotime($a['timestamp']);
    });
    
    $result = array_slice($events, 0, $limit);
    minidash_cache_set($cache_key, $result);
    return $result;
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
                            <p class="text-[12px] font-black text-slate-500 uppercase tracking-widest">Zmień zdjęcie profilowe</p>
                        </div>
                    </div>

                    <!-- Right: Form Fields -->
                    <div class="md:col-span-7 grid grid-cols-2 gap-x-4 gap-y-6">
                        <div class="col-span-1">
                            <label class="block text-[11px] font-black text-slate-500 uppercase tracking-widest mb-2 px-1">Login / Użytkownik</label>
                            <input type="text" name="username" value="<?= htmlspecialchars($config['admin_username']) ?>" required
                                   class="w-full px-5 py-3.5 bg-slate-900/50 border border-white/10 rounded-2xl focus:outline-none focus:ring-2 focus:ring-blue-500/30 text-xs font-bold transition-all text-slate-200">
                        </div>
                        <div class="col-span-1">
                            <label class="block text-[11px] font-black text-slate-500 uppercase tracking-widest mb-2 px-1">Nowe Hasło</label>
                            <input type="password" name="password" placeholder="••••••••"
                                   class="w-full px-5 py-3.5 bg-slate-900/50 border border-white/10 rounded-2xl focus:outline-none focus:ring-2 focus:ring-blue-500/30 text-xs font-bold transition-all text-slate-200">
                        </div>
                        <div class="col-span-1">
                            <label class="block text-[11px] font-black text-slate-500 uppercase tracking-widest mb-2 px-1">Imię i Nazwisko</label>
                            <input type="text" name="full_name" value="<?= htmlspecialchars($config['admin_full_name'] ?? '') ?>"
                                   class="w-full px-5 py-3.5 bg-slate-900/50 border border-white/10 rounded-2xl focus:outline-none focus:ring-2 focus:ring-blue-500/30 text-xs font-bold transition-all text-slate-200">
                        </div>
                        <div class="col-span-1">
                            <label class="block text-[11px] font-black text-slate-500 uppercase tracking-widest mb-2 px-1">Adres Email</label>
                            <input type="email" name="email" value="<?= htmlspecialchars($config['admin_email']) ?>"
                                   class="w-full px-5 py-3.5 bg-slate-900/50 border border-white/10 rounded-2xl focus:outline-none focus:ring-2 focus:ring-blue-500/30 text-xs font-bold transition-all text-slate-200">
                        </div>
                    </div>
                </div>

                <!-- Regional Settings Section -->
                <div class="mt-8 pt-8 border-t border-white/5">
                    <?php $console = get_console_settings(); ?>
                    <div class="flex items-center gap-3 mb-6">
                        <div class="w-8 h-8 rounded-xl bg-blue-500/10 text-blue-400 flex items-center justify-center">
                            <i data-lucide="globe" class="w-4 h-4"></i>
                        </div>
                        <h3 class="text-xs font-black text-slate-500 uppercase tracking-[0.2em]">Ustawienia Regionalne</h3>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                        <div>
                            <label class="block text-[11px] font-black text-slate-500 uppercase tracking-widest mb-2 px-1">Język Interfejsu</label>
                            <select name="language" class="w-full px-5 py-3 bg-slate-900/50 border border-white/10 rounded-2xl focus:outline-none focus:ring-2 focus:ring-blue-500/30 text-xs font-bold text-slate-200 appearance-none">
                                <option value="auto">Automatyczny (<?= strtoupper($console['language'] ?? 'EN') ?>)</option>
                                <option value="pl" <?= ($config['language'] ?? '') === 'pl' ? 'selected' : '' ?>>Polski (PL)</option>
                                <option value="en" <?= ($config['language'] ?? '') === 'en' ? 'selected' : '' ?>>English (EN)</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-[11px] font-black text-slate-500 uppercase tracking-widest mb-2 px-1">Strefa Czasowa</label>
                            <select name="timezone" class="w-full px-5 py-3 bg-slate-900/50 border border-white/10 rounded-2xl focus:outline-none focus:ring-2 focus:ring-blue-500/30 text-xs font-bold text-slate-200 appearance-none">
                                <option value="auto">Z konsoli (<?= $console['timezone'] ?? 'UTC' ?>)</option>
                                <option value="Europe/Warsaw" <?= ($config['timezone'] ?? '') === 'Europe/Warsaw' ? 'selected' : '' ?>>Warszawa (GMT+1)</option>
                                <option value="UTC" <?= ($config['timezone'] ?? '') === 'UTC' ? 'selected' : '' ?>>UTC (GMT+0)</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-[11px] font-black text-slate-500 uppercase tracking-widest mb-2 px-1">Format Czasu / Daty</label>
                            <div class="flex gap-2">
                                <select name="time_format" class="flex-1 px-4 py-3 bg-slate-900/50 border border-white/10 rounded-2xl focus:outline-none focus:ring-2 focus:ring-blue-500/30 text-xs font-bold text-slate-200 appearance-none">
                                    <option value="auto">Auto (<?= $console['time_format'] ?>)</option>
                                    <option value="24h" <?= ($config['time_format'] ?? '') === '24h' ? 'selected' : '' ?>>24h</option>
                                    <option value="12h" <?= ($config['time_format'] ?? '') === '12h' ? 'selected' : '' ?>>12h</option>
                                </select>
                                <select name="date_format" class="flex-1 px-4 py-3 bg-slate-900/50 border border-white/10 rounded-2xl focus:outline-none focus:ring-2 focus:ring-blue-500/30 text-xs font-bold text-slate-200 appearance-none">
                                    <option value="auto">Auto (<?= $console['date_format'] ?>)</option>
                                    <option value="d.m.Y" <?= ($config['date_format'] ?? '') === 'd.m.Y' ? 'selected' : '' ?>>DD.MM.YYYY</option>
                                    <option value="m/d/Y" <?= ($config['date_format'] ?? '') === 'm/d/Y' ? 'selected' : '' ?>>MM/DD/YYYY</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Security (2FA) Section -->
                <div class="mt-8 pt-8 border-t border-white/5">
                    <div class="mb-4 flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <div class="w-8 h-8 rounded-xl bg-orange-500/10 text-orange-400 flex items-center justify-center">
                                <i data-lucide="shield-check" class="w-4 h-4"></i>
                            </div>
                            <h3 class="text-xs font-black text-slate-500 uppercase tracking-[0.2em]">Bezpieczeństwo (2FA)</h3>
                        </div>
                        <span class="text-[12px] font-black text-white/20 uppercase tracking-[0.2em] bg-white/5 px-2 py-1 rounded">Wkrótce</span>
                    </div>
                    <div class="p-4 bg-white/[0.01] border border-white/5 rounded-2xl flex items-center justify-between opacity-50 relative group cursor-not-allowed overflow-hidden">
                        <div class="absolute inset-0 bg-orange-500/5 opacity-0 group-hover:opacity-100 transition-opacity"></div>
                        <div class="text-[12px] text-slate-500 font-bold uppercase tracking-wider relative z-10">Logowanie dwuetapowe</div>
                        <div class="w-10 h-5 bg-slate-800 rounded-full relative z-10">
                            <div class="absolute left-1 top-1 w-3 h-3 bg-slate-600 rounded-full"></div>
                        </div>
                    </div>
                </div>

                <!-- Login History Section -->
                <div class="mt-8 pt-8 border-t border-white/5">
                    <div class="mb-4 flex items-center justify-between">
                         <div class="flex items-center gap-3">
                            <div class="w-8 h-8 rounded-xl bg-slate-500/10 text-slate-400 flex items-center justify-center">
                                <i data-lucide="history" class="w-4 h-4"></i>
                            </div>
                            <h3 class="text-xs font-black text-slate-500 uppercase tracking-[0.2em]">Historia Logowań</h3>
                        </div>
                         <button type="button" class="text-[12px] text-blue-400 font-bold uppercase tracking-widest hover:text-white transition">
                            Zobacz całą historię
                        </button>
                    </div>
                    <div class="bg-slate-900/50 border border-white/10 rounded-2xl overflow-hidden">
                        <div class="divide-y divide-white/5">
                             <!-- Active Session -->
                             <?php
                                $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
                                $browser = 'Unknown Browser'; $os = 'Unknown OS';
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
                                $historyData = [];
                                if (isset($db)) {
                                    try {
                                        $stmt = $db->query("SELECT ip, location, os, browser, logged_at FROM login_history ORDER BY logged_at DESC LIMIT 20");
                                        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                                        foreach ($rows as $r) {
                                            $historyData[] = ['timestamp' => strtotime($r['logged_at']), 'ip' => $r['ip'], 'location' => $r['location'], 'os' => $r['os'], 'browser' => $r['browser']];
                                        }
                                    } catch (Exception $e) {}
                                }
                                if (empty($historyData)) {
                                    $historyFile = __DIR__ . '/data/login_history.json';
                                    if (file_exists($historyFile)) { $loaded = json_decode(file_get_contents($historyFile), true); if (is_array($loaded)) $historyData = $loaded; }
                                }
                             ?>
                             <div class="p-4 flex items-center justify-between hover:bg-white/[0.02] transition">
                                <div class="flex items-center gap-4">
                                    <div class="w-10 h-10 rounded-xl bg-emerald-500/10 text-emerald-500 flex items-center justify-center shadow-lg shadow-emerald-500/10">
                                        <i data-lucide="laptop" class="w-5 h-5"></i>
                                    </div>
                                    <div>
                                        <div class="text-xs font-bold text-white">Obecna Sesja (<?= $os ?>)</div>
                                        <div class="text-[12px] text-slate-500 font-mono mt-0.5">IP: <?= $client_ip ?> • <?= $browser ?></div>
                                        <div class="text-[11px] text-blue-400 font-bold mt-0.5 flex items-center gap-1.5">
                                            <i data-lucide="map-pin" class="w-2.5 h-2.5"></i>
                                            <?= $current_loc ?>
                                        </div>
                                    </div>
                                </div>
                                <span class="px-2 py-1 rounded bg-emerald-500/10 text-emerald-400 text-[12px] font-bold uppercase tracking-wider animate-pulse ring-1 ring-emerald-500/20">Aktywna</span>
                            </div>

                            <!-- Past History (Limited to 3 for brevity) -->
                            <?php
                            $hist_count = 0;
                            if (!empty($historyData)):
                                foreach ($historyData as $idx => $entry):
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
                                        <div class="text-[12px] text-slate-500 font-mono mt-0.5"><?= date('d.m.Y H:i', $entry['timestamp']) ?> • IP: <?= $entry['ip'] ?></div>
                                        <div class="text-[11px] text-slate-500 mt-0.5 flex items-center gap-1.5">
                                            <i data-lucide="map-pin" class="w-2.5 h-2.5"></i>
                                            <?= $entry['location'] ?? 'Unknown' ?>
                                        </div>
                                    </div>
                                </div>
                                <span class="text-[11px] text-slate-600 font-black uppercase tracking-widest">Logowanie</span>
                            </div>
                            <?php endforeach; endif; ?>
                        </div>
                    </div>
                </div>
            </form>

            <div class="p-6 bg-slate-950/50 border-t border-white/5 flex justify-end shrink-0">
                <button type="submit" form="personalForm" class="px-12 py-4 bg-blue-600 hover:bg-blue-500 text-white font-black rounded-2xl transition shadow-xl shadow-blue-600/20 text-[11px] uppercase tracking-[0.2em] flex items-center justify-center gap-2">
                     Zapisz Dane
                </button>
            </div>
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
                    showToast('Profil zaktualizowany', 'success');
                    setTimeout(() => location.reload(), 1200);
                } else {
                    showToast('Błąd: ' + result.message, 'error');
                }
            } catch (err) {
                console.error(err);
                showToast('Wystąpił nieoczekiwany błąd zapisu', 'error');
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

function render_nav($title = "MiniDash", $stats = []) {
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
                <a href="index.php" title="MiniDash" class="flex items-center gap-4 group">
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
                    <?php if ($config['modules']['monitoring_enabled'] ?? true): ?>
                    <a href="monitored.php" class="p-2.5 rounded-xl transition-all <?= $current_page == 'monitored.php' ? 'bg-emerald-600/10 text-emerald-400' : 'text-slate-500 hover:text-slate-300 hover:bg-white/5' ?>" title="Zasoby">
                        <i data-lucide="activity" class="w-5 h-5"></i>
                    </a>
                    <?php endif; ?>
                    <?php if ($config['protect']['enabled'] ?? false): ?>
                    <a href="protect.php" class="p-2.5 rounded-xl transition-all <?= $current_page == 'protect.php' ? 'bg-purple-600/10 text-purple-400' : 'text-slate-500 hover:text-slate-300 hover:bg-white/5' ?>" title="Protect">
                        <i data-lucide="video" class="w-5 h-5"></i>
                    </a>
                    <?php endif; ?>
                    <a href="security.php" class="p-2.5 rounded-xl transition-all <?= $current_page == 'security.php' ? 'bg-rose-600/10 text-rose-400' : 'text-slate-500 hover:text-slate-300 hover:bg-white/5' ?>" title="Security">
                        <i data-lucide="shield" class="w-5 h-5"></i>
                    </a>
                    <a href="logs.php" class="p-2.5 rounded-xl transition-all <?= $current_page == 'logs.php' ? 'bg-amber-600/10 text-amber-400' : 'text-slate-500 hover:text-slate-300 hover:bg-white/5' ?>" title="Logs">
                        <i data-lucide="file-text" class="w-5 h-5"></i>
                    </a>
                    <a href="stalker.php" class="nav-icon p-2 rounded-xl hover:bg-white/5 transition" title="Wi-Fi Stalker">
                        <i data-lucide="radar" class="w-6 h-6 text-slate-400 hover:text-purple-400 transition"></i>
                    </a>
                </div>
            </div>

            <div class="flex items-center gap-4">
                <!-- System Monitor (Task Manager Style) -->
                <div class="hidden xl:flex items-center gap-6 px-1">
                    <div onclick="openProcessModal()" class="flex flex-col gap-1.5 cursor-pointer hover:opacity-80 transition-opacity" title="Zarządca Procesów">
                        <div class="flex items-center gap-2">
                            <span class="text-[11px] font-black text-slate-500 uppercase tracking-tighter w-6">CPU</span>
                            <div class="w-16 h-1.5 bg-slate-800 rounded-full overflow-hidden">
                                <div class="h-full bg-blue-500 rounded-full" style="width: <?= $cpu ?>%"></div>
                            </div>
                            <span class="text-[11px] font-mono font-bold text-blue-400 w-8 text-right"><?= round($cpu) ?>%</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="text-[11px] font-black text-slate-500 uppercase tracking-tighter w-6">RAM</span>
                            <div class="w-16 h-1.5 bg-slate-800 rounded-full overflow-hidden">
                                <div class="h-full bg-blue-400 rounded-full" style="width: <?= $ram ?>%"></div>
                            </div>
                            <span class="text-[11px] font-mono font-bold text-blue-300 w-8 text-right"><?= round($ram) ?>%</span>
                        </div>
                    </div>
                    <div class="h-6 w-[1px] bg-white/10"></div>
                    <div onclick="openWanModal()" class="flex flex-col gap-0.5 cursor-pointer hover:opacity-80 transition-opacity" title="Łącze WAN">
                        <div class="flex items-center gap-1.5 text-amber-400">
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
                                <div class="text-[12px] font-bold text-slate-500 uppercase tracking-widest" id="mobile-nav-date"><?= date('d/m/Y') ?></div>
                                <div class="text-lg font-black text-white tracking-widest" id="mobile-nav-time"><?= date('H:i') ?></div>
                            </div>
                            
                            <!-- Mobile Nav Grid -->
                            <div class="grid grid-cols-4 gap-2">
                                 <a href="index.php" class="aspect-square rounded-xl flex flex-col items-center justify-center gap-1 border border-white/5 <?= $current_page == 'index.php' ? 'bg-blue-600/20 text-blue-400 ring-1 ring-blue-500/50' : 'bg-slate-800/50 text-slate-400 hover:bg-slate-700 hover:text-white' ?>" title="Dashboard">
                                    <i data-lucide="layout-dashboard" class="w-5 h-5"></i>
                                 </a>
                                 <a href="monitored.php" class="aspect-square rounded-xl flex flex-col items-center justify-center gap-1 border border-white/5 <?= $current_page == 'monitored.php' ? 'bg-emerald-600/20 text-emerald-400' : 'bg-slate-800/50 text-slate-400 hover:bg-slate-700 hover:text-white' ?>" title="Resources">
                                    <i data-lucide="activity" class="w-5 h-5"></i>
                                 </a>
                                 <?php if ($config['protect']['enabled'] ?? false): ?>
                                  <a href="protect.php" class="aspect-square rounded-xl flex flex-col items-center justify-center gap-1 border border-white/5 <?= $current_page == 'protect.php' ? 'bg-purple-600/20 text-purple-400 ring-1 ring-purple-500/50' : 'bg-slate-800/50 text-slate-400 hover:bg-slate-700 hover:text-white' ?>" title="Protect">
                                    <i data-lucide="video" class="w-5 h-5"></i>
                                 </a>
                                 <?php endif; ?>
                                  <a href="security.php" class="aspect-square rounded-xl flex flex-col items-center justify-center gap-1 border border-white/5 <?= $current_page == 'security.php' ? 'bg-rose-600/20 text-rose-400 ring-1 ring-rose-500/50' : 'bg-slate-800/50 text-slate-400 hover:bg-slate-700 hover:text-white' ?>" title="Security">
                                    <i data-lucide="shield" class="w-5 h-5"></i>
                                 </a>
                                 <a href="logs.php" class="aspect-square rounded-xl flex flex-col items-center justify-center gap-1 border border-white/5 <?= $current_page == 'logs.php' ? 'bg-amber-600/20 text-amber-400 ring-1 ring-amber-500/50' : 'bg-slate-800/50 text-slate-400 hover:bg-slate-700 hover:text-white' ?>" title="Logs">
                                    <i data-lucide="file-text" class="w-5 h-5"></i>
                                 </a>
                                 <a href="stalker.php" class="aspect-square rounded-xl flex flex-col items-center justify-center gap-1 border border-white/5 <?= $current_page == 'stalker.php' ? 'bg-purple-600/20 text-purple-400 ring-1 ring-purple-500/50' : 'bg-slate-800/50 text-slate-400 hover:bg-slate-700 hover:text-white' ?>" title="Wi-Fi Stalker">
                                    <i data-lucide="radar" class="w-5 h-5"></i>
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
                                    <span class="text-[12px] text-slate-500 truncate"><?= htmlspecialchars($config['admin_email']) ?></span>
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
                    <p class="text-[12px] text-slate-500 font-bold uppercase tracking-tighter">Ostatnie zdarzenia systemowe</p>
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
                    $is_system = ($ev['eventType'] ?? '') === 'unifi_system';
                    $status = $ev['status'] ?? 'off';
                    $is_up = ($status === 'on');
                    $color = $is_up ? 'emerald' : 'red';
                    $icon = $is_up ? 'check-circle' : 'alert-circle';
                    
                    // Specific visuals for event types
                    if ($is_system) {
                        $key = $ev['key'] ?? '';
                        if (strpos($key, 'Roam') !== false) { $icon = 'repeat'; $color = 'blue'; }
                        elseif (strpos($key, 'Disconnected') !== false) { $icon = 'log-out'; $color = 'amber'; }
                        elseif (strpos($key, 'Discovered') !== false) { $icon = 'plus-circle'; $color = 'purple'; }
                        elseif (strpos($key, 'Connected') !== false) { $icon = 'link'; $color = 'emerald'; }
                    }
                    // MiniDash alerts (speed, roaming, status changes)
                    if (($ev['eventType'] ?? '') === 'minidash_alert') {
                        $icon = 'bell-ring'; $color = 'amber'; $is_up = true;
                    }

                    $time = strtotime($ev['timestamp']);
                    $diff = time() - $time;
                    
                    if ($diff < 60) $time_str = "teraz";
                    elseif ($diff < 3600) $time_str = floor($diff/60) . " m";
                    elseif ($diff < 86400) $time_str = floor($diff/3600) . " g";
                    else $time_str = date('d.m', $time);

                    $title_label = $is_up ? 'Online' : 'Offline';
                    if ($is_system) {
                        $key = $ev['key'] ?? '';
                        if (strpos($key, 'Roam') !== false) $title_label = 'Roaming';
                        elseif (strpos($key, 'Disconnected') !== false) $title_label = 'Rozłączono';
                        elseif (strpos($key, 'Discovered') !== false) $title_label = 'Nowy Klient';
                        elseif (strpos($key, 'Connected') !== false) $title_label = 'Podłączono';
                        else $title_label = 'System';
                    }

                    $display_name = $is_system ? ($ev['raw_msg'] ? explode(':', $ev['raw_msg'])[0] : ($ev['key'] ?? 'UniFi')) : $ev['deviceName'];
                    // Fallback for raw keys
                    if ($is_system && strpos($display_name, 'EVT_') !== false) {
                        $display_name = 'Zdarzenie Sieciowe';
                    }
                    $display_name = htmlspecialchars($display_name);
                    $display_msg = $is_system ? $ev['raw_msg'] : ($is_up ? 'Urządzenie połączyło się z siecią.' : 'Utrata połączenia z urządzeniem.');
                ?>
                    <div onclick="window.location.href='history.php?mac=<?= $ev['mac'] ?>'" class="p-4 rounded-2xl bg-white/[0.03] border border-white/5 hover:bg-white/[0.08] hover:border-blue-500/30 transition group relative overflow-hidden cursor-pointer">
                        <div class="absolute left-0 top-0 bottom-0 w-1 bg-<?= $color ?>-500"></div>
                        <div class="flex gap-3">
                            <div class="w-8 h-8 rounded-xl bg-<?= $color ?>-500/10 text-<?= $color ?>-400 flex-shrink-0 flex items-center justify-center">
                                <i data-lucide="<?= $icon ?>" class="w-4 h-4"></i>
                            </div>
                            <div class="flex-grow min-w-0">
                                <div class="flex justify-between items-start mb-0.5">
                                    <span class="text-[11px] font-black text-<?= $color ?>-500 uppercase tracking-widest bg-<?= $color ?>-500/10 px-1.5 py-0.5 rounded">
                                        <?= $title_label ?>
                                    </span>
                                    <span class="text-[12px] text-slate-500 font-mono font-bold"><?= $time_str ?></span>
                                </div>
                                <p class="text-[13px] text-white leading-tight font-bold mb-0.5 truncate"><?= htmlspecialchars($display_name) ?></p>
                                <p class="text-[12px] text-slate-400 font-medium leading-tight line-clamp-2"><?= htmlspecialchars($display_msg) ?></p>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Footer -->
        <div class="p-6 border-t border-white/5 bg-white/[0.02] grid grid-cols-2 gap-3">
            <button onclick="clearAllNotifications()" class="py-2.5 px-4 bg-slate-800 hover:bg-slate-700 text-slate-300 rounded-xl text-[12px] font-black uppercase tracking-widest transition border border-white/5">
                Wyczyść wszystko
            </button>
            <a href="events.php" class="py-2.5 px-4 bg-blue-600 hover:bg-blue-500 text-white rounded-xl text-[12px] font-black uppercase tracking-widest transition shadow-lg shadow-blue-600/20 text-center">
                Pokaż wszystko
            </a>
        </div>
    </div>

    <script>
        async function clearAllNotifications() {
            try {
                const response = await fetch('api_clear_history.php', { method: 'POST' });
                const result = await response.json();
                if (result.success) {
                    // Clear notifications from DOM without page reload
                    const panel = document.querySelector('#notif-overlay + div .flex-grow') || document.querySelector('.overflow-y-auto.custom-scrollbar');
                    if (panel) {
                        panel.innerHTML = '<div class="py-20 flex flex-col items-center justify-center text-center opacity-40"><div class="w-16 h-16 rounded-3xl bg-slate-800 flex items-center justify-center mb-4 border border-white/5"><i data-lucide="bell-off" class="w-8 h-8 text-slate-600"></i></div><p class="text-xs font-bold text-slate-500 uppercase tracking-widest">Brak zdarzen</p></div>';
                        if (typeof lucide !== 'undefined') lucide.createIcons();
                    }
                    // Remove notification badge
                    const badge = document.getElementById('notif-badge');
                    if (badge) badge.remove();
                    // Show toast
                    if (typeof showToast !== 'undefined') showToast('Powiadomienia wyczyszczone', 'success');
                }
            } catch (e) {
                if (typeof showToast !== 'undefined') showToast('Blad czyszczenia', 'error');
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

        // Toast notification system
        (function() {
            if (document.getElementById('toast-container')) return;
            const tc = document.createElement('div');
            tc.id = 'toast-container';
            tc.style.cssText = 'position:fixed;top:24px;left:24px;z-index:99999;display:flex;flex-direction:column;gap:12px;pointer-events:none;';
            document.body.appendChild(tc);
        })();
        function showToast(message, type) {
            type = type || 'success';
            const container = document.getElementById('toast-container');
            const toast = document.createElement('div');
            const colors = {
                success: { bg:'rgba(16,185,129,0.15)', border:'rgba(16,185,129,0.3)', text:'#34d399', icon:'check-circle' },
                error:   { bg:'rgba(239,68,68,0.15)',  border:'rgba(239,68,68,0.3)',  text:'#f87171', icon:'x-circle' },
                warning: { bg:'rgba(245,158,11,0.15)', border:'rgba(245,158,11,0.3)', text:'#fbbf24', icon:'alert-triangle' }
            };
            const c = colors[type] || colors.success;
            toast.style.cssText = 'pointer-events:auto;background:'+c.bg+';border:1px solid '+c.border+';color:'+c.text+';padding:14px 20px;border-radius:16px;display:flex;align-items:center;gap:10px;font-weight:600;font-size:14px;backdrop-filter:blur(12px);transform:translateX(-120%);opacity:0;transition:transform 0.4s cubic-bezier(0.34,1.56,0.64,1),opacity 0.3s ease;';
            toast.innerHTML = '<i data-lucide="'+c.icon+'" style="width:20px;height:20px;flex-shrink:0;"></i><span>'+message+'</span>';
            container.appendChild(toast);
            if (typeof lucide !== 'undefined') lucide.createIcons({nodes:[toast]});
            requestAnimationFrame(function() { toast.style.transform='translateX(0)'; toast.style.opacity='1'; });
            setTimeout(function() {
                toast.style.transform='translateX(-120%)'; toast.style.opacity='0';
                setTimeout(function() { toast.remove(); }, 400);
            }, 4000);
        }

        function openNotifSettings() {
            document.getElementById('notifSettingsModal').classList.add('active');
            document.body.style.overflow = 'hidden';
            lucide.createIcons();
            // Init collapsible toggle for notification sections
            document.querySelectorAll('#notifSettingsModal .space-y-6').forEach(section => {
                const cb = section.querySelector(':scope > .flex input[type="checkbox"]');
                const fields = section.querySelector(':scope > .notif-fields');
                if (!cb || !fields) return;
                cb.addEventListener('change', () => {
                    fields.classList.toggle('collapsed', !cb.checked);
                });
            });
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
                    showToast('Błąd: ' + result.message, 'error');
                    btn.innerHTML = originalText;
                    btn.disabled = false;
                    lucide.createIcons();
                }
            } catch (e) {
                showToast('Błąd sieci', 'error');
                btn.innerHTML = originalText;
                btn.disabled = false;
                lucide.createIcons();
            }
        }

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

        function openWanSessionsModal() {
            const modal = document.getElementById('wanSessionsModal');
            if (modal) {
                modal.classList.add('active');
                document.body.style.overflow = 'hidden';
                loadWanSessions();
                lucide.createIcons();
            }
        }

        function closeWanSessionsModal() {
            const modal = document.getElementById('wanSessionsModal');
            if (modal) {
                modal.classList.remove('active');
                document.body.style.overflow = '';
            }
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
                    <!-- OS Version + Model -->
                    <div class="p-5 bg-slate-900/40 rounded-3xl border border-white/5 flex items-center justify-between">
                        <div class="flex items-center gap-4">
                            <div class="w-12 h-12 rounded-2xl bg-blue-500/10 text-blue-400 flex items-center justify-center">
                                <i data-lucide="layers" class="w-6 h-6"></i>
                            </div>
                            <div>
                                <span class="text-[12px] text-slate-500 uppercase tracking-widest font-black">Wersja UniFi OS</span>
                                <div class="text-lg font-bold text-white"><?= htmlspecialchars($sys['version']) ?></div>
                            </div>
                        </div>
                        <div class="text-right">
                             <div class="text-[12px] text-slate-500 uppercase font-black">Model</div>
                             <div class="text-xs font-mono text-slate-400"><?= htmlspecialchars($sys['model']) ?></div>
                             <div class="text-[11px] text-slate-600 mt-1">Kanal: <?= htmlspecialchars($sys['update_channel'] ?? 'release') ?></div>
                        </div>
                    </div>

                    <!-- Uptime + CPU + RAM + Disk -->
                    <div class="p-5 bg-slate-900/40 rounded-3xl border border-white/5 grid grid-cols-2 gap-3">
                        <div class="flex items-center gap-2">
                            <div class="w-8 h-8 rounded-lg bg-emerald-500/10 text-emerald-400 flex items-center justify-center">
                                <i data-lucide="clock" class="w-4 h-4"></i>
                            </div>
                            <div>
                                <span class="text-[12px] text-slate-500 uppercase font-black">Uptime</span>
                                <div class="text-xs font-bold text-emerald-400"><?= $sys['uptime_pretty'] ?? 'N/A' ?></div>
                            </div>
                        </div>
                        <div class="flex items-center gap-2">
                            <div class="w-8 h-8 rounded-lg bg-blue-500/10 text-blue-400 flex items-center justify-center">
                                <i data-lucide="cpu" class="w-4 h-4"></i>
                            </div>
                            <div>
                                <span class="text-[12px] text-slate-500 uppercase font-black">CPU</span>
                                <div class="text-xs font-bold text-<?= ($sys['cpu_usage'] ?? 0) > 80 ? 'red' : 'slate' ?>-300"><?= $sys['cpu_usage'] ?? '0' ?>%</div>
                            </div>
                        </div>
                        <div class="flex items-center gap-2">
                            <div class="w-8 h-8 rounded-lg bg-purple-500/10 text-purple-400 flex items-center justify-center">
                                <i data-lucide="memory-stick" class="w-4 h-4"></i>
                            </div>
                            <div>
                                <span class="text-[12px] text-slate-500 uppercase font-black">RAM</span>
                                <div class="text-xs font-bold text-<?= ($sys['ram_usage'] ?? 0) > 85 ? 'red' : 'slate' ?>-300"><?= $sys['ram_usage'] ?? '0' ?>%</div>
                            </div>
                        </div>
                        <div class="flex items-center gap-2">
                            <div class="w-8 h-8 rounded-lg bg-amber-500/10 text-amber-400 flex items-center justify-center">
                                <i data-lucide="hard-drive" class="w-4 h-4"></i>
                            </div>
                            <div>
                                <span class="text-[12px] text-slate-500 uppercase font-black">Dysk</span>
                                <?php
                                    $disk_pct = ($sys['disk_total'] > 0) ? round(($sys['disk_used'] / $sys['disk_total']) * 100) : 0;
                                ?>
                                <div class="text-xs font-bold text-<?= $disk_pct > 85 ? 'red' : 'slate' ?>-300"><?= $disk_pct ?>%</div>
                            </div>
                        </div>
                    </div>

                    <!-- System Status -->
                    <div class="p-5 bg-slate-900/40 rounded-3xl border border-white/5 flex items-center justify-between sm:col-span-2">
                         <div class="flex items-center gap-4">
                            <div class="w-12 h-12 rounded-2xl bg-<?= $sys['up_to_date'] ? 'emerald' : 'amber' ?>-500/10 text-<?= $sys['up_to_date'] ? 'emerald' : 'amber' ?>-400 flex items-center justify-center">
                                <i data-lucide="<?= $sys['up_to_date'] ? 'check-circle' : 'alert-triangle' ?>" class="w-6 h-6"></i>
                            </div>
                            <div>
                                <span class="text-[12px] text-slate-500 uppercase tracking-widest font-black">Status Systemu</span>
                                <div class="text-lg font-bold text-<?= $sys['up_to_date'] ? 'emerald' : 'amber' ?>-400">
                                    <?= $sys['up_to_date'] ? 'Aktualny' : 'Dostepna aktualizacja' ?>
                                </div>
                                <?php if (!$sys['up_to_date'] && !empty($sys['update_ver'])): ?>
                                    <div class="text-[11px] text-amber-500/70 font-mono mt-0.5">Nowa wersja: <?= htmlspecialchars($sys['update_ver']) ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <i data-lucide="<?= $sys['up_to_date'] ? 'check-circle' : 'arrow-up-circle' ?>" class="w-6 h-6 text-<?= $sys['up_to_date'] ? 'emerald' : 'amber' ?>-500/40"></i>
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
                                    <p class="text-[12px] text-slate-500 font-mono uppercase">
                                        v<?= htmlspecialchars($app['version']) ?> • <?= $app['status'] ?>
                                        <?php if (!empty($app['channel'])): ?> • <?= htmlspecialchars($app['channel']) ?><?php endif; ?>
                                    </p>
                                </div>
                            </div>
                            <div class="flex items-center gap-3">
                                <?php if ($app['update']): ?>
                                    <div class="flex flex-col items-end gap-1">
                                        <div class="flex items-center gap-2 px-3 py-1 bg-amber-500 text-white text-[12px] font-black rounded-lg uppercase shadow-lg shadow-amber-500/20">
                                            <i data-lucide="arrow-up-circle" class="w-3 h-3"></i> Aktualizacja
                                        </div>
                                        <?php if (!empty($app['update_version'])): ?>
                                            <span class="text-[11px] font-mono text-amber-400/60">→ <?= htmlspecialchars($app['update_version']) ?></span>
                                        <?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <span class="px-3 py-1 bg-<?= $app['color'] ?>-500/10 text-<?= $app['color'] ?>-400 text-[12px] font-black rounded-lg uppercase border border-<?= $app['color'] ?>-500/20 tracking-tighter">Aktualny</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        
                        <?php if (count($sys['apps']) <= 1): ?>
                        <!-- No additional apps detected -->
                        <div class="p-4 text-center text-xs text-slate-600">Nie wykryto dodatkowych aplikacji UniFi</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal: Notification Settings -->
    <div id="notifSettingsModal" class="modal-overlay" onclick="closeNotifSettings()">
        <div class="modal-container max-w-5xl" onclick="event.stopPropagation()">
            <form onsubmit="event.preventDefault(); saveNotifSettings(this);" class="flex flex-col flex-1 min-h-0 overflow-hidden">
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

                <div class="modal-body overflow-y-auto custom-scrollbar p-8 space-y-10 flex-1 min-h-0">
                    <!-- Email SMTP -->
                    <div class="space-y-6">
                        <div class="flex items-center justify-between border-b border-white/5 pb-4">
                            <div class="flex items-center gap-3">
                                <div class="p-2.5 bg-blue-500/10 text-blue-400 rounded-xl"><i data-lucide="mail" class="w-5 h-5"></i></div>
                                <div>
                                    <h4 class="font-bold text-slate-200">Email (SMTP / Gmail)</h4>
                                    <span class="text-[12px] text-slate-500 uppercase tracking-widest font-black">Standardowe Alerty Email</span>
                                </div>
                            </div>
                            <label class="relative inline-flex items-center cursor-pointer">
                                <input type="checkbox" name="email_enabled" class="sr-only peer" <?= ($config['email_notifications']['enabled'] ?? false) ? 'checked' : '' ?>>
                                <div class="w-11 h-6 bg-slate-800 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:start-[2px] after:bg-slate-400 after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600 after:border-none"></div>
                            </label>
                        </div>
                        <div class="notif-fields <?= !($config['email_notifications']['enabled'] ?? false) ? 'collapsed' : '' ?>">
                            <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
                                <div class="md:col-span-3">
                                    <label class="block text-[12px] font-black text-slate-500 uppercase tracking-widest mb-2">Host SMTP</label>
                                    <input type="text" name="email_host" value="<?= htmlspecialchars($config['email_notifications']['smtp_host'] ?? '') ?>" class="w-full bg-slate-900/50 border border-white/10 rounded-xl px-4 py-3 text-sm text-white focus:outline-none focus:border-blue-500/50" placeholder="e.g. smtp.gmail.com">
                                </div>
                                <div>
                                    <label class="block text-[12px] font-black text-slate-500 uppercase tracking-widest mb-2">Port</label>
                                    <input type="number" name="email_port" value="<?= htmlspecialchars($config['email_notifications']['smtp_port'] ?? 587) ?>" class="w-full bg-slate-900/50 border border-white/10 rounded-xl px-4 py-3 text-sm text-white focus:outline-none focus:border-blue-500/50">
                                </div>
                                <div class="md:col-span-2">
                                    <label class="block text-[12px] font-black text-slate-500 uppercase tracking-widest mb-2">Użytkownik</label>
                                    <input type="text" name="email_user" value="<?= htmlspecialchars($config['email_notifications']['smtp_username'] ?? '') ?>" class="w-full bg-slate-900/50 border border-white/10 rounded-xl px-4 py-3 text-sm text-white focus:outline-none focus:border-blue-500/50">
                                </div>
                                <div class="md:col-span-2">
                                    <label class="block text-[12px] font-black text-slate-500 uppercase tracking-widest mb-2">Hasło / App Password</label>
                                    <input type="password" name="email_pass" value="<?= htmlspecialchars($config['email_notifications']['smtp_password'] ?? '') ?>" class="w-full bg-slate-900/50 border border-white/10 rounded-xl px-4 py-3 text-sm text-white focus:outline-none focus:border-blue-500/50">
                                </div>
                                <div class="md:col-span-2">
                                    <label class="block text-[12px] font-black text-slate-500 uppercase tracking-widest mb-2">Email nadawcy</label>
                                    <input type="text" name="email_from" value="<?= htmlspecialchars($config['email_notifications']['from_email'] ?? '') ?>" class="w-full bg-slate-900/50 border border-white/10 rounded-xl px-4 py-3 text-sm text-white focus:outline-none focus:border-blue-500/50">
                                </div>
                                <div class="md:col-span-2">
                                    <label class="block text-[12px] font-black text-slate-500 uppercase tracking-widest mb-2">Odbiorca Alertów</label>
                                    <input type="text" name="email_to" value="<?= htmlspecialchars($config['email_notifications']['to_email'] ?? '') ?>" class="w-full bg-slate-900/50 border border-white/10 rounded-xl px-4 py-3 text-sm text-white focus:outline-none focus:border-blue-500/50">
                                </div>
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
                                    <span class="text-[12px] text-slate-500 uppercase tracking-widest font-black">Mobilny Komunikator</span>
                                </div>
                            </div>
                            <label class="relative inline-flex items-center cursor-pointer">
                                <input type="checkbox" name="tg_enabled" class="sr-only peer" <?= ($config['telegram_notifications']['enabled'] ?? false) ? 'checked' : '' ?>>
                                <div class="w-11 h-6 bg-slate-800 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:start-[2px] after:bg-slate-400 after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-sky-500 after:border-none"></div>
                            </label>
                        </div>
                        <div class="notif-fields <?= !($config['telegram_notifications']['enabled'] ?? false) ? 'collapsed' : '' ?>">
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                                <div class="md:col-span-2">
                                    <label class="block text-[12px] font-black text-slate-500 uppercase tracking-widest mb-2">Bot Token</label>
                                    <input type="text" name="tg_token" value="<?= htmlspecialchars($config['telegram_notifications']['bot_token'] ?? '') ?>" class="w-full bg-slate-900/50 border border-white/10 rounded-xl px-4 py-3 text-sm text-white focus:outline-none focus:border-blue-500/50" placeholder="123456789:ABCDEF...">
                                </div>
                                <div>
                                    <label class="block text-[12px] font-black text-slate-500 uppercase tracking-widest mb-2">Chat ID</label>
                                    <input type="text" name="tg_chatid" value="<?= htmlspecialchars($config['telegram_notifications']['chat_id'] ?? '') ?>" class="w-full bg-slate-900/50 border border-white/10 rounded-xl px-4 py-3 text-sm text-white focus:outline-none focus:border-blue-500/50">
                                </div>
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
                                        <span class="text-[12px] text-slate-500 uppercase tracking-widest font-black">Komunikaty API</span>
                                    </div>
                                </div>
                                <label class="relative inline-flex items-center cursor-pointer">
                                    <input type="checkbox" name="wa_enabled" class="sr-only peer" <?= ($config['whatsapp_notifications']['enabled'] ?? false) ? 'checked' : '' ?>>
                                    <div class="w-11 h-6 bg-slate-800 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:start-[2px] after:bg-slate-400 after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-emerald-500 after:border-none"></div>
                                </label>
                            </div>
                            <div class="notif-fields <?= !($config['whatsapp_notifications']['enabled'] ?? false) ? 'collapsed' : '' ?>">
                                <div class="space-y-4">
                                    <div>
                                        <label class="block text-[12px] font-black text-slate-500 uppercase tracking-widest mb-2">API Gateway URL</label>
                                        <input type="text" name="wa_url" value="<?= htmlspecialchars($config['whatsapp_notifications']['api_url'] ?? '') ?>" class="w-full bg-slate-900/50 border border-white/10 rounded-xl px-4 py-3 text-sm text-white focus:outline-none focus:border-emerald-500/50" placeholder="https://api.gateway.com">
                                    </div>
                                    <div>
                                        <label class="block text-[12px] font-black text-slate-500 uppercase tracking-widest mb-2">Numer docelowy</label>
                                        <input type="text" name="wa_phone" value="<?= htmlspecialchars($config['whatsapp_notifications']['phone_number'] ?? '') ?>" class="w-full bg-slate-900/50 border border-white/10 rounded-xl px-4 py-3 text-sm text-white focus:outline-none focus:border-emerald-500/50" placeholder="+48 123 456 789">
                                    </div>
                                    <div>
                                        <label class="block text-[12px] font-black text-slate-500 uppercase tracking-widest mb-2">API Key (Opcjonalnie)</label>
                                        <input type="password" name="wa_key" value="<?= htmlspecialchars($config['whatsapp_notifications']['api_key'] ?? '') ?>" class="w-full bg-slate-900/50 border border-white/10 rounded-xl px-4 py-3 text-sm text-white focus:outline-none focus:border-emerald-500/50" placeholder="••••••••">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Slack -->
                        <div class="space-y-6">
                            <div class="flex items-center justify-between border-b border-white/5 pb-4">
                                <div class="flex items-center gap-3">
                                    <div class="p-2.5 bg-purple-500/10 text-purple-400 rounded-xl"><i data-lucide="hash" class="w-5 h-5"></i></div>
                                    <div>
                                        <h4 class="font-bold text-slate-200">Slack Webhook</h4>
                                        <span class="text-[12px] text-slate-500 uppercase tracking-widest font-black">Kanały Slack</span>
                                    </div>
                                </div>
                                <label class="relative inline-flex items-center cursor-pointer">
                                    <input type="checkbox" name="slack_enabled" class="sr-only peer" <?= ($config['slack_notifications']['enabled'] ?? false) ? 'checked' : '' ?>>
                                    <div class="w-11 h-6 bg-slate-800 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:start-[2px] after:bg-slate-400 after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-purple-600 after:border-none"></div>
                                </label>
                            </div>
                            <div class="notif-fields <?= !($config['slack_notifications']['enabled'] ?? false) ? 'collapsed' : '' ?>">
                                <div>
                                    <label class="block text-[12px] font-black text-slate-500 uppercase tracking-widest mb-2">Webhook URL</label>
                                    <input type="text" name="slack_url" value="<?= htmlspecialchars($config['slack_notifications']['webhook_url'] ?? '') ?>" class="w-full bg-slate-900/50 border border-white/10 rounded-xl px-4 py-3 text-sm text-white focus:outline-none focus:border-purple-500/50" placeholder="https://hooks.slack.com/services/...">
                                </div>
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
                                        <span class="text-[12px] text-slate-500 uppercase tracking-widest font-black">Bramka GSM</span>
                                    </div>
                                </div>
                                <label class="relative inline-flex items-center cursor-pointer">
                                    <input type="checkbox" name="sms_enabled" class="sr-only peer" <?= ($config['sms_notifications']['enabled'] ?? false) ? 'checked' : '' ?>>
                                    <div class="w-11 h-6 bg-slate-800 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:start-[2px] after:bg-slate-400 after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-rose-500 after:border-none"></div>
                                </label>
                            </div>
                            <div class="notif-fields <?= !($config['sms_notifications']['enabled'] ?? false) ? 'collapsed' : '' ?>">
                                <div class="space-y-4">
                                    <div>
                                        <label class="block text-[12px] font-black text-slate-500 uppercase tracking-widest mb-2">SMS API URL</label>
                                        <input type="text" name="sms_url" value="<?= htmlspecialchars($config['sms_notifications']['api_url'] ?? '') ?>" class="w-full bg-slate-900/50 border border-white/10 rounded-xl px-4 py-3 text-sm text-white focus:outline-none focus:border-rose-500/50" placeholder="https://sms.gateway.com">
                                    </div>
                                    <div>
                                        <label class="block text-[12px] font-black text-slate-500 uppercase tracking-widest mb-2">Numer docelowy</label>
                                        <input type="text" name="sms_phone" value="<?= htmlspecialchars($config['sms_notifications']['to_number'] ?? '') ?>" class="w-full bg-slate-900/50 border border-white/10 rounded-xl px-4 py-3 text-sm text-white focus:outline-none focus:border-rose-500/50" placeholder="+48 123 456 789">
                                    </div>
                                    <div>
                                        <label class="block text-[12px] font-black text-slate-500 uppercase tracking-widest mb-2">API Key / Token</label>
                                        <input type="password" name="sms_key" value="<?= htmlspecialchars($config['sms_notifications']['api_key'] ?? '') ?>" class="w-full bg-slate-900/50 border border-white/10 rounded-xl px-4 py-3 text-sm text-white focus:outline-none focus:border-rose-500/50" placeholder="••••••••">
                                    </div>
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
                                        <span class="text-[12px] text-slate-500 uppercase tracking-widest font-black">Mobile App</span>
                                    </div>
                                </div>
                                <label class="relative inline-flex items-center cursor-pointer">
                                    <input type="checkbox" name="ntfy_enabled" class="sr-only peer" <?= ($config['ntfy_notifications']['enabled'] ?? false) ? 'checked' : '' ?>>
                                    <div class="w-11 h-6 bg-slate-800 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:start-[2px] after:bg-slate-400 after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-orange-600 after:border-none"></div>
                                </label>
                            </div>
                            <div class="notif-fields <?= !($config['ntfy_notifications']['enabled'] ?? false) ? 'collapsed' : '' ?>">
                                <div class="space-y-4">
                                    <div>
                                        <label class="block text-[12px] font-black text-slate-500 uppercase tracking-widest mb-2">Topic (Unikalny kanał)</label>
                                        <input type="text" name="ntfy_topic" value="<?= htmlspecialchars($config['ntfy_notifications']['topic'] ?? '') ?>" class="w-full bg-slate-900/50 border border-white/10 rounded-xl px-4 py-3 text-sm text-white focus:outline-none focus:border-orange-500/50" placeholder="np. minidash_alerty">
                                    </div>
                                    <div>
                                        <label class="block text-[12px] font-black text-slate-500 uppercase tracking-widest mb-2">Serwer Push</label>
                                        <input type="text" name="ntfy_server" value="<?= htmlspecialchars($config['ntfy_notifications']['server'] ?? 'https://ntfy.sh') ?>" class="w-full bg-slate-900/50 border border-white/10 rounded-xl px-4 py-3 text-sm text-white focus:outline-none focus:border-orange-500/50">
                                    </div>
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
                                    <span class="text-[12px] text-slate-500 uppercase tracking-widest font-black">Automatyczne Alerty</span>
                                </div>
                            </div>
                        </div>
                        <div class="space-y-4">
                            <!-- Existing: Speed Alert -->
                            <div class="p-6 bg-slate-900/40 rounded-3xl border border-white/5 space-y-6">
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center gap-4">
                                        <div class="p-3 bg-blue-500/10 text-blue-400 rounded-2xl"><i data-lucide="gauge" class="w-6 h-6"></i></div>
                                        <div>
                                            <p class="text-sm font-bold text-slate-200">Alert o nagłym wzroście transferu</p>
                                            <p class="text-[12px] text-slate-500 uppercase tracking-widest">Monitoruj Monitowane Urządzenia</p>
                                        </div>
                                    </div>
                                    <label class="relative inline-flex items-center cursor-pointer">
                                        <input type="checkbox" name="speed_alert_enabled" class="sr-only peer" <?= ($config['triggers']['speed_alert_enabled'] ?? false) ? 'checked' : '' ?>>
                                        <div class="w-11 h-6 bg-slate-800 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:start-[2px] after:bg-slate-400 after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600 after:border-none"></div>
                                    </label>
                                </div>
                                <div class="flex items-center gap-6 pl-12">
                                    <div class="w-full">
                                        <label class="block text-[12px] font-black text-slate-500 uppercase tracking-widest mb-3">Próg prędkości (Mbps)</label>
                                        <div class="flex items-center gap-4">
                                            <input type="range" name="speed_threshold_mbps" min="2" max="1000" step="1" value="<?= htmlspecialchars($config['triggers']['speed_threshold_mbps'] ?? 100) ?>" class="flex-grow accent-blue-500" oninput="this.nextElementSibling.value = this.value + ' Mbps'">
                                            <output class="text-xs font-mono text-blue-400 bg-blue-500/10 px-3 py-2 rounded-lg border border-blue-500/20 min-w-[100px] text-center"><?= htmlspecialchars($config['triggers']['speed_threshold_mbps'] ?? 100) ?> Mbps</output>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Trigger: New Device -->
                            <div class="p-6 bg-slate-900/40 rounded-3xl border border-white/5 space-y-6">
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center gap-4">
                                        <div class="p-3 bg-emerald-500/10 text-emerald-500 rounded-2xl"><i data-lucide="shield-plus" class="w-6 h-6"></i></div>
                                        <div>
                                            <p class="text-sm font-bold text-slate-200">Wykryto nowe urzadzenie</p>
                                            <p class="text-[12px] text-slate-500 uppercase tracking-widest">Alert przy nieznanym MAC w sieci</p>
                                        </div>
                                    </div>
                                    <label class="relative inline-flex items-center cursor-pointer">
                                        <input type="checkbox" name="new_device_alert_enabled" class="sr-only peer" <?= ($config['triggers']['new_device_alert_enabled'] ?? false) ? 'checked' : '' ?>>
                                        <div class="w-11 h-6 bg-slate-800 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:start-[2px] after:bg-slate-400 after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-emerald-600 after:border-none"></div>
                                    </label>
                                </div>
                            </div>

                            <!-- Trigger: IPS Alert -->
                            <div class="p-6 bg-slate-900/40 rounded-3xl border border-white/5 space-y-6">
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center gap-4">
                                        <div class="p-3 bg-rose-500/10 text-rose-500 rounded-2xl"><i data-lucide="shield-alert" class="w-6 h-6"></i></div>
                                        <div>
                                            <p class="text-sm font-bold text-slate-200">Alert Bezpieczenstwa (IPS/IDS)</p>
                                            <p class="text-[12px] text-slate-500 uppercase tracking-widest">Powiadomienie o zablokowanym ataku</p>
                                        </div>
                                    </div>
                                    <label class="relative inline-flex items-center cursor-pointer">
                                        <input type="checkbox" name="ips_alert_enabled" class="sr-only peer" <?= ($config['triggers']['ips_alert_enabled'] ?? false) ? 'checked' : '' ?>>
                                        <div class="w-11 h-6 bg-slate-800 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:start-[2px] after:bg-slate-400 after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-rose-600 after:border-none"></div>
                                    </label>
                                </div>
                            </div>

                            <!-- Trigger: High Latency -->
                            <div class="p-6 bg-slate-900/40 rounded-3xl border border-white/5 space-y-6">
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center gap-4">
                                        <div class="p-3 bg-amber-500/10 text-amber-500 rounded-2xl"><i data-lucide="activity" class="w-6 h-6"></i></div>
                                        <div>
                                            <p class="text-sm font-bold text-slate-200">Nagly wzrost opoznien (Ping)</p>
                                            <p class="text-[12px] text-slate-500 uppercase tracking-widest">Monitorowanie stabilnosci lacza WAN</p>
                                        </div>
                                    </div>
                                    <label class="relative inline-flex items-center cursor-pointer">
                                        <input type="checkbox" name="latency_alert_enabled" class="sr-only peer" <?= ($config['triggers']['latency_alert_enabled'] ?? false) ? 'checked' : '' ?>>
                                        <div class="w-11 h-6 bg-slate-800 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:start-[2px] after:bg-slate-400 after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-amber-600 after:border-none"></div>
                                    </label>
                                </div>
                                <div class="flex items-center gap-6 pl-12">
                                    <div class="w-full">
                                        <label class="block text-[12px] font-black text-slate-500 uppercase tracking-widest mb-3">Prog opoznienia (ms)</label>
                                        <div class="flex items-center gap-4">
                                            <input type="range" name="latency_threshold_ms" min="10" max="500" step="5" value="<?= htmlspecialchars($config['triggers']['latency_threshold_ms'] ?? 100) ?>" class="flex-grow accent-amber-500" oninput="this.nextElementSibling.value = this.value + ' ms'">
                                            <output class="text-xs font-mono text-amber-400 bg-amber-500/10 px-3 py-2 rounded-lg border border-amber-500/20 min-w-[80px] text-center"><?= htmlspecialchars($config['triggers']['latency_threshold_ms'] ?? 100) ?> ms</output>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                            <!-- Trigger: VPN Connection -->
                            <div class="p-6 bg-slate-900/40 rounded-3xl border border-white/5 space-y-6">
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center gap-4">
                                        <div class="p-3 bg-purple-500/10 text-purple-500 rounded-2xl"><i data-lucide="shield" class="w-6 h-6"></i></div>
                                        <div>
                                            <p class="text-sm font-bold text-slate-200">Polaczenie VPN</p>
                                            <p class="text-[12px] text-slate-500 uppercase tracking-widest">Alert przy polaczeniu/rozlaczeniu VPN</p>
                                        </div>
                                    </div>
                                    <label class="relative inline-flex items-center cursor-pointer">
                                        <input type="checkbox" name="vpn_alert_enabled" class="sr-only peer" <?= ($config['triggers']['vpn_alert_enabled'] ?? false) ? 'checked' : '' ?>>
                                        <div class="w-11 h-6 bg-slate-800 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:start-[2px] after:bg-slate-400 after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-purple-600 after:border-none"></div>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="modal-footer shrink-0 p-6 bg-slate-950/50 border-t border-white/5 flex justify-end gap-3 rounded-b-3xl">
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
                    <div class="flex items-center gap-2 px-3 py-1.5 bg-blue-500/10 text-blue-400 rounded-xl border border-blue-500/20">
                        <div class="w-2 h-2 rounded-full bg-blue-500 animate-pulse"></div>
                        <span class="text-[12px] font-black uppercase tracking-widest">API Status</span>
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
                        <tr class="bg-slate-900 text-[12px] font-black text-slate-500 uppercase tracking-[0.2em] border-b border-white/5">
                            <th class="px-8 py-4 cursor-pointer hover:text-blue-400 transition" onclick="sortProcessTable(0)">Usluga <i data-lucide="chevrons-up-down" class="w-3 h-3 inline-block ml-1 opacity-50"></i></th>
                            <th class="px-6 py-4 cursor-pointer hover:text-blue-400 transition" onclick="sortProcessTable(1)">Status <i data-lucide="chevrons-up-down" class="w-3 h-3 inline-block ml-1 opacity-50"></i></th>
                            <th class="px-6 py-4 text-right cursor-pointer hover:text-blue-400 transition" onclick="sortProcessTable(2)">CPU <i data-lucide="chevrons-up-down" class="w-3 h-3 inline-block ml-1 opacity-50"></i></th>
                            <th class="px-6 py-4 text-right cursor-pointer hover:text-blue-400 transition" onclick="sortProcessTable(3)">Info <i data-lucide="chevrons-up-down" class="w-3 h-3 inline-block ml-1 opacity-50"></i></th>
                            <th class="px-6 py-4 text-right cursor-pointer hover:text-blue-400 transition" onclick="sortProcessTable(4)">Uwagi <i data-lucide="chevrons-up-down" class="w-3 h-3 inline-block ml-1 opacity-50"></i></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/[0.02]">
                        <?php 
                        // Build real service list from API data
                        $sys = get_system_info();
                        $site_id_context = $_SESSION['site_id'] ?? $config['site'] ?? 'default';
$tradSite = get_trad_site_id($site_id_context);
                        $gw_resp = fetch_api("/proxy/network/api/s/{$tradSite}/stat/device");
                        $gw = null;
                        foreach (($gw_resp['data'] ?? []) as $_d) {
                            if (in_array($_d['type'] ?? '', ['ugw', 'udm', 'uxg'])) { $gw = $_d; break; }
                        }
                        $gw_cpu = $gw['system-stats']['cpu'] ?? 0;
                        $gw_mem = $gw['system-stats']['mem'] ?? 0;
                        $gw_mem_total = $gw['sys_stats']['mem_total'] ?? 0;
                        $gw_mem_used = $gw['sys_stats']['mem_used'] ?? 0;
                        $gw_uptime = $gw['uptime'] ?? 0;
                        $gw_load = $gw['sys_stats']['loadavg_1'] ?? $gw['system-stats']['loadavg_1'] ?? '0';

                        $ips_enabled = get_ips_status();
                        $dhcp_leases = $gw['active_dhcp_lease_count'] ?? 0;
                        $device_count = count($gw_resp['data'] ?? []);

                        // Build services with real data where available, estimates where not
                        $processes = [];

                        // Apps from get_system_info
                        foreach ($sys['apps'] as $app) {
                            $processes[] = [
                                'name' => $app['name'],
                                'status' => $app['status'],
                                'cpu' => '-',
                                'mem' => 'v' . $app['version'],
                                'pid' => $app['update'] ? 'UPDATE' : '',
                                'color' => $app['color'],
                            ];
                        }

                        // IPS
                        $processes[] = [
                            'name' => 'Intrusion Prevention (IPS)',
                            'status' => $ips_enabled ? 'Protecting' : 'Disabled',
                            'cpu' => '-',
                            'mem' => '-',
                            'pid' => '',
                            'color' => $ips_enabled ? 'rose' : 'slate',
                        ];

                        // DHCP
                        $processes[] = [
                            'name' => 'DHCP Server',
                            'status' => 'Running',
                            'cpu' => '-',
                            'mem' => $dhcp_leases . ' leases',
                            'pid' => '',
                            'color' => 'blue',
                        ];

                        // DNS
                        $processes[] = [
                            'name' => 'DNS Forwarder',
                            'status' => 'Running',
                            'cpu' => '-',
                            'mem' => '-',
                            'pid' => '',
                            'color' => 'blue',
                        ];

                        // Gateway stats summary
                        $processes[] = [
                            'name' => 'UniFi OS (' . ($sys['model'] ?? 'Gateway') . ')',
                            'status' => 'Uptime: ' . formatDuration($gw_uptime),
                            'cpu' => round($gw_cpu, 1) . '%',
                            'mem' => round($gw_mem, 1) . '%',
                            'pid' => 'Load: ' . round((float)$gw_load, 2),
                            'color' => 'emerald',
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
                                <span class="px-2.5 py-1 rounded-lg bg-white/5 text-[12px] font-black text-slate-400 uppercase tracking-tighter border border-white/5">
                                    <?= $p['status'] ?>
                                </span>
                            </td>
                            <td class="px-6 py-5 text-right font-mono text-xs text-white"><?= $p['cpu'] ?></td>
                            <td class="px-6 py-5 text-right font-mono text-xs text-slate-400"><?= $p['mem'] ?></td>
                            <td class="px-6 py-5 text-right font-mono text-[12px] text-slate-600 font-bold"><?= $p['pid'] ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            </div>
            <div class="modal-footer p-6 border-t border-white/5 flex justify-between items-center bg-slate-900/30">
                <p class="text-[12px] text-slate-500 font-bold uppercase tracking-widest">Łącznie aktywne: <span class="text-blue-400"><?= count($processes) ?> procesów</span></p>
                <button type="button" onclick="closeProcessModal()" class="px-8 py-2.5 bg-white/5 hover:bg-white/10 text-slate-400 hover:text-white rounded-xl text-[12px] font-black uppercase tracking-widest transition border border-white/10">
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
                <!-- WAN Live Transfer Chart -->
                <div class="bg-slate-900/50 border border-white/5 rounded-2xl p-6">
                    <div class="flex items-center justify-between mb-4">
                        <div class="flex items-center gap-3">
                            <div class="w-8 h-8 rounded-xl bg-emerald-500/10 flex items-center justify-center text-emerald-400">
                                <i data-lucide="activity" class="w-4 h-4"></i>
                            </div>
                            <div>
                                <h3 class="text-sm font-bold text-white">Transfer WAN — Live</h3>
                                <p class="text-[12px] text-slate-500 font-bold uppercase tracking-widest">Przepustowość łącza w czasie rzeczywistym</p>
                            </div>
                        </div>
                        <div class="flex items-center gap-4">
                            <div class="flex items-center gap-2">
                                <div class="w-2.5 h-2.5 rounded-full bg-emerald-500"></div>
                                <span class="text-[12px] text-slate-400 font-bold">Download</span>
                            </div>
                            <div class="flex items-center gap-2">
                                <div class="w-2.5 h-2.5 rounded-full bg-amber-500"></div>
                                <span class="text-[12px] text-slate-400 font-bold">Upload</span>
                            </div>
                        </div>
                    </div>
                    <div class="h-[200px] w-full">
                        <canvas id="wanModalChart"></canvas>
                    </div>
                </div>

                <!-- Info Grid: Support Multi-WAN -->
                <div class="grid grid-cols-1 md:grid-cols-<?= min(count($_SESSION['wan_details']['wans'] ?? []), 4) + 1 ?> gap-6">
                    <div class="bg-slate-900/50 p-5 rounded-2xl border border-white/5 flex flex-col justify-center">
                        <p class="text-[12px] text-slate-500 font-black uppercase tracking-widest mb-1">Model Bramy</p>
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
                        <p class="text-[12px] text-<?= $is_online ? 'emerald' : 'rose' ?>-500 font-black uppercase tracking-widest mb-1"><?= htmlspecialchars($w['name']) ?></p>
                        <p class="text-lg font-black text-white font-mono"><?= htmlspecialchars($w['ip']) ?></p>
                        <div class="mt-2 flex items-center justify-between">
                            <span class="text-[11px] font-bold text-slate-500 uppercase tracking-tighter">Status: <?= $w['status'] ?></span>
                            <div class="flex gap-3 text-[11px] font-mono text-slate-400">
                                <span class="flex items-center gap-1"><i data-lucide="download" class="w-2.5 h-2.5"></i> <?= formatBps($w['rx'] ?? 0) ?></span>
                                <span class="flex items-center gap-1"><i data-lucide="upload" class="w-2.5 h-2.5"></i> <?= formatBps($w['tx'] ?? 0) ?></span>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- Active Clients Traffic -->
                <div class="space-y-4">
                    <div class="flex items-center justify-between">
                        <h3 class="text-xs font-black text-slate-500 uppercase tracking-[0.2em]">Aktywny ruch klientow</h3>
                        <button onclick="loadWanFlows()" class="text-[11px] text-slate-600 hover:text-slate-400 transition uppercase tracking-wider">
                            <i data-lucide="refresh-cw" class="w-3 h-3 inline mr-1"></i>Odswiez
                        </button>
                    </div>
                    <div class="bg-slate-900/50 rounded-2xl border border-white/5 overflow-hidden">
                        <table class="w-full text-left border-collapse">
                            <thead>
                                <tr class="bg-slate-950/30 text-[11px] font-black text-slate-500 uppercase tracking-widest border-b border-white/5">
                                    <th class="px-6 py-4">Klient</th>
                                    <th class="px-6 py-4">IP Lokalne</th>
                                    <th class="px-6 py-4">Rodzaj / Usługa</th>
                                    <th class="px-6 py-4">IP Zewnętrzne</th>
                                    <th class="px-6 py-4 text-right">Download</th>
                                    <th class="px-6 py-4 text-right">Upload</th>
                                </tr>
                            </thead>
                            <tbody id="wan-flows-body" class="divide-y divide-white/[0.02]">
                                <tr><td colspan="4" class="px-6 py-8 text-center text-slate-500 text-xs">Ladowanie...</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                <script>
                function formatBpsJs(bps) {
                    if (bps >= 1000000000000) return (bps/1000000000000).toFixed(2) + ' Tbps';
                    if (bps >= 1000000000) return (bps/1000000000).toFixed(2) + ' Gbps';
                    if (bps >= 1000000) return (bps/1000000).toFixed(1) + ' Mbps';
                    if (bps >= 1000) return (bps/1000).toFixed(1) + ' Kbps';
                    return Math.round(bps) + ' bps';
                }
                function loadWanFlows() {
                    fetch('api_wan_flows.php')
                        .then(r => r.json())
                        .then(data => {
                            const tbody = document.getElementById('wan-flows-body');
                            const flows = data.data || [];
                            if (flows.length === 0) {
                                tbody.innerHTML = '<tr><td colspan="6" class="px-6 py-8 text-center text-slate-500 text-xs">Brak aktywnego ruchu</td></tr>';
                                return;
                            }
                            tbody.innerHTML = flows.map(f => {
                                const icon = f.is_wired ? 'monitor' : 'wifi';
                                const netColor = f.is_wired ? 'text-blue-400' : 'text-purple-400';
                                const countryCode = f.country_code && f.country_code !== '??' ? f.country_code : 'un';
                                
                                return `<tr class="hover:bg-white/[0.02] transition-colors">
                                    <td class="px-6 py-3">
                                        <div class="flex items-center gap-3">
                                            <i data-lucide="${icon}" class="w-4 h-4 text-slate-500 shrink-0"></i>
                                            <span class="text-sm font-bold text-white/90 truncate">${f.name}</span>
                                        </div>
                                    </td>
                                    <td class="px-6 py-3">
                                        <div class="text-xs font-mono text-slate-400 font-bold">${f.ip || '—'}</div>
                                    </td>
                                    <td class="px-6 py-3">
                                        <span class="text-[12px] font-black uppercase text-slate-500 tracking-tighter bg-white/5 px-2 py-0.5 rounded border border-white/5">
                                            ${f.type || 'General'}
                                        </span>
                                    </td>
                                    <td class="px-6 py-3">
                                        <div class="flex items-center gap-2">
                                            <img src="img/flags/${countryCode}.png" class="w-4 h-auto rounded-sm opacity-80" onerror="this.src='img/flags/un.png'">
                                            <span class="text-xs font-mono font-bold text-blue-300">${f.external_ip || '<span class="text-slate-700">automatyczny</span>'}</span>
                                        </div>
                                    </td>
                                    <td class="px-6 py-3 text-right text-xs font-bold text-emerald-400">${formatBpsJs(f.rx_bps)}</td>
                                    <td class="px-6 py-3 text-right text-xs font-bold text-amber-400">${formatBpsJs(f.tx_bps)}</td>
                                </tr>`;
                            }).join('');
                            if (typeof lucide !== 'undefined') lucide.createIcons();
                        })
                        .catch(() => {
                            document.getElementById('wan-flows-body').innerHTML = '<tr><td colspan="4" class="px-6 py-8 text-center text-red-400 text-xs">Blad ladowania</td></tr>';
                        });
                }
                // WAN Modal Chart
                let wanModalChart = null;
                let wanModalChartInterval = null;

                function initWanModalChart() {
                    const canvas = document.getElementById('wanModalChart');
                    if (!canvas || wanModalChart) return;

                    const ctx = canvas.getContext('2d');
                    const gradientRx = ctx.createLinearGradient(0, 0, 0, 180);
                    gradientRx.addColorStop(0, 'rgba(16, 185, 129, 0.35)');
                    gradientRx.addColorStop(1, 'rgba(16, 185, 129, 0)');

                    const gradientTx = ctx.createLinearGradient(0, 0, 0, 180);
                    gradientTx.addColorStop(0, 'rgba(245, 158, 11, 0.25)');
                    gradientTx.addColorStop(1, 'rgba(245, 158, 11, 0)');

                    wanModalChart = new Chart(ctx, {
                        type: 'line',
                        data: {
                            labels: [],
                            datasets: [
                                {
                                    label: 'RX (Download)',
                                    data: [],
                                    borderColor: '#10b981',
                                    backgroundColor: gradientRx,
                                    fill: true,
                                    tension: 0.4,
                                    borderWidth: 2,
                                    pointRadius: 0,
                                    pointHoverRadius: 4,
                                    pointHoverBackgroundColor: '#10b981'
                                },
                                {
                                    label: 'TX (Upload)',
                                    data: [],
                                    borderColor: '#f59e0b',
                                    backgroundColor: gradientTx,
                                    fill: true,
                                    tension: 0.4,
                                    borderWidth: 2,
                                    pointRadius: 0,
                                    pointHoverRadius: 4,
                                    pointHoverBackgroundColor: '#f59e0b'
                                }
                            ]
                        },
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            interaction: {
                                mode: 'index',
                                intersect: false
                            },
                            plugins: {
                                legend: { display: false },
                                tooltip: {
                                    backgroundColor: 'rgba(15,23,42,0.95)',
                                    titleColor: '#e2e8f0',
                                    bodyColor: '#94a3b8',
                                    borderColor: 'rgba(255,255,255,0.1)',
                                    borderWidth: 1,
                                    cornerRadius: 12,
                                    padding: 12,
                                    callbacks: {
                                        label: function(context) {
                                            return context.dataset.label + ': ' + formatBpsJs(context.parsed.y);
                                        }
                                    }
                                }
                            },
                            scales: {
                                x: {
                                    grid: { display: false },
                                    ticks: { display: true, color: '#475569', font: { size: 9, weight: 'bold' }, maxTicksLimit: 8 }
                                },
                                y: {
                                    beginAtZero: true,
                                    grid: { color: 'rgba(255,255,255,0.04)' },
                                    ticks: {
                                        color: '#475569',
                                        font: { size: 9, weight: 'bold' },
                                        callback: function(value) {
                                            if (value >= 1000000000) return (value / 1000000000).toFixed(1) + ' Gb';
                                            if (value >= 1000000) return (value / 1000000).toFixed(1) + ' Mb';
                                            if (value >= 1000) return (value / 1000).toFixed(0) + ' Kb';
                                            return value + ' b';
                                        }
                                    }
                                }
                            }
                        }
                    });
                }

                function updateWanModalChart() {
                    if (!wanModalChart) return;
                    fetch('update_wan.php')
                        .then(r => r.json())
                        .then(data => {
                            const last30 = data.slice(-30);
                            wanModalChart.data.labels = last30.map(d => {
                                const date = new Date(d.timestamp * 1000);
                                return date.getHours() + ':' + String(date.getMinutes()).padStart(2, '0') + ':' + String(date.getSeconds()).padStart(2, '0');
                            });
                            wanModalChart.data.datasets[0].data = last30.map(d => d.rx);
                            wanModalChart.data.datasets[1].data = last30.map(d => d.tx);
                            wanModalChart.update('none');
                        })
                        .catch(e => console.warn('WAN modal chart error:', e));
                }

                // Auto-load when modal opens
                document.addEventListener('DOMContentLoaded', () => {
                    const observer = new MutationObserver(() => {
                        const modal = document.getElementById('wanModal');
                        if (modal && modal.classList.contains('active')) {
                            loadWanFlows();
                            initWanModalChart();
                            updateWanModalChart();
                            // Auto-refresh chart every 5s while modal is open
                            if (!wanModalChartInterval) {
                                wanModalChartInterval = setInterval(() => {
                                    const m = document.getElementById('wanModal');
                                    if (m && m.classList.contains('active')) {
                                        updateWanModalChart();
                                    } else {
                                        clearInterval(wanModalChartInterval);
                                        wanModalChartInterval = null;
                                    }
                                }, 5000);
                            }
                        } else {
                            if (wanModalChartInterval) {
                                clearInterval(wanModalChartInterval);
                                wanModalChartInterval = null;
                            }
                        }
                    });
                    const modal = document.getElementById('wanModal');
                    if (modal) observer.observe(modal, { attributes: true, attributeFilter: ['class'] });
                });
                </script>
            </div>
            <div class="modal-footer p-6 border-t border-white/5 bg-slate-900/30 flex justify-end">
                <button type="button" onclick="closeWanModal()" class="px-8 py-2.5 bg-blue-600 hover:bg-blue-500 text-white rounded-xl text-[12px] font-black uppercase tracking-widest transition shadow-lg shadow-blue-600/20">
                    Zamknij Szczegóły
                </button>
            </div>
        </div>
    </div>

    <!-- Modal: WAN Sessions (External Connections) -->
    <div id="wanSessionsModal" class="modal-overlay" onclick="closeWanSessionsModal()">
        <div class="modal-container max-w-5xl" onclick="event.stopPropagation()">
            <div class="modal-header">
                <div>
                    <h2 class="text-xl font-bold flex items-center gap-2 text-white">
                        <i data-lucide="zap" class="w-6 h-6 text-blue-400"></i>
                        Aktywne Sesje WAN
                    </h2>
                    <p class="text-slate-500 text-xs mt-1">Podgląd połączeń przychodzących i wychodzących w czasie rzeczywistym</p>
                </div>
                <div class="flex items-center gap-4">
                    <div id="wan-sessions-stats" class="hidden md:flex items-center gap-6 mr-6">
                        <div class="text-right">
                            <div class="text-[11px] text-slate-500 uppercase font-black tracking-widest">Inbound</div>
                            <div id="wan-stats-inbound" class="text-sm font-bold text-blue-400">0</div>
                        </div>
                        <div class="text-right">
                            <div class="text-[11px] text-slate-500 uppercase font-black tracking-widest">Outbound</div>
                            <div id="wan-stats-outbound" class="text-sm font-bold text-amber-400">0</div>
                        </div>
                    </div>
                    <button type="button" onclick="closeWanSessionsModal()" class="p-2 hover:bg-white/5 rounded-xl transition text-slate-500 hover:text-white">
                        <i data-lucide="x" class="w-6 h-6"></i>
                    </button>
                </div>
            </div>
            <div class="modal-body p-0">
                <div class="p-4 bg-slate-900/30 border-b border-white/5 flex items-center justify-between">
                    <div class="flex items-center gap-4">
                        <h3 class="text-xs font-black text-slate-500 uppercase tracking-widest">Top Kraje</h3>
                        <div id="wan-top-countries" class="flex items-center gap-3">
                            <!-- Country bubbles here -->
                        </div>
                    </div>
                    <button onclick="loadWanSessions()" class="p-2 hover:bg-white/5 rounded-lg text-slate-500 transition" title="Odśwież">
                        <i data-lucide="refresh-cw" class="w-4 h-4"></i>
                    </button>
                </div>
                <div class="max-h-[600px] overflow-y-auto custom-scrollbar">
                    <table class="w-full text-left border-collapse">
                        <thead class="sticky top-0 bg-slate-950/95 backdrop-blur-md z-10">
                            <tr class="text-[12px] font-black text-slate-500 uppercase tracking-widest border-b border-white/5">
                                <th class="px-6 py-4">Lokalizacja / Kraj</th>
                                <th class="px-6 py-4">PRZEPŁYW (ŹRÓDŁO → CEL)</th>
                                <th class="px-6 py-4">Kierunek</th>
                                <th class="px-6 py-4">Protokół / Typ</th>
                                <th class="px-6 py-4 text-right">Status</th>
                            </tr>
                        </thead>
                        <tbody id="wan-sessions-body" class="divide-y divide-white/[0.02]">
                            <tr><td colspan="5" class="px-6 py-12 text-center text-slate-500">Inicjalizacja podglądu...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer p-6 border-t border-white/5 bg-slate-900/30 flex justify-between items-center">
                <p id="wan-sessions-total" class="text-[12px] text-slate-500 font-bold uppercase tracking-widest">Wczytano 0 sesji</p>
                <button type="button" onclick="closeWanSessionsModal()" class="px-8 py-2.5 bg-blue-600 hover:bg-blue-500 text-white rounded-xl text-[12px] font-black uppercase tracking-widest transition shadow-lg shadow-blue-600/20">
                    Zamknij Podgląd
                </button>
            </div>
        </div>
    </div>

    <script>
    function loadWanSessions() {
        const tbody = document.getElementById('wan-sessions-body');
        const statsRow = document.getElementById('wan-sessions-stats');
        const countryRow = document.getElementById('wan-top-countries');
        
        fetch('api_wan_sessions.php')
            .then(r => r.json())
            .then(data => {
                if (!data.success) throw new Error('API Error');
                
                document.getElementById('wan-stats-inbound').innerText = data.stats.inbound;
                document.getElementById('wan-stats-outbound').innerText = data.stats.outbound;
                document.getElementById('wan-sessions-total').innerText = `Aktywne sesje: ${data.stats.total} (Zablokowane: ${data.stats.blocked})`;
                if (statsRow) statsRow.classList.remove('hidden');

                if (countryRow) {
                    countryRow.innerHTML = data.countries.map(c => `
                        <div class="flex items-center gap-1.5 px-2 py-1 bg-white/5 rounded-lg border border-white/5" title="${c.country}">
                            <img src="img/flags/${c.code}.png" class="w-3.5 h-auto rounded-sm opacity-80">
                            <span class="text-[12px] font-bold text-slate-400">${c.count}</span>
                        </div>
                    `).join('');
                }

                if (data.sessions.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="5" class="px-6 py-12 text-center text-slate-500">Brak aktywnych sesji zewnętrznych</td></tr>';
                } else {
                    tbody.innerHTML = data.sessions.map(s => {
                        const dirIcon = s.direction === 'inbound' ? 'arrow-left' : 'arrow-right';
                        const dirColor = s.direction === 'inbound' ? 'text-blue-400' : 'text-amber-400';
                        const actionColor = s.is_blocked ? 'bg-red-500/10 text-red-400 border-red-500/20' : 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20';
                        const countryCode = s.country_code === '??' ? 'un' : s.country_code;

                        // Connection Flow UI
                        let sourceUI, destUI;
                        const extIPUI = `
                            <div class="flex flex-col">
                                <span class="text-xs font-mono font-bold text-blue-300">${s.external_ip}</span>
                                <span class="text-[11px] text-slate-500 uppercase font-black truncate max-w-[150px]">${s.org || 'Provider'}</span>
                            </div>
                        `;
                        const localUI = `
                            <div class="flex flex-col">
                                <span class="text-xs font-bold text-white/90">${s.client_name}</span>
                                <span class="text-[11px] text-slate-600 font-mono">${s.local_ip}</span>
                            </div>
                        `;

                        if (s.direction === 'inbound') {
                            sourceUI = extIPUI;
                            destUI = localUI;
                        } else {
                            sourceUI = localUI;
                            destUI = extIPUI;
                        }
                        
                        return `
                        <tr class="hover:bg-white/[0.02] transition-colors border-t border-white/5">
                            <td class="px-6 py-4">
                                <div class="flex items-center gap-3">
                                    <img src="img/flags/${countryCode}.png" class="w-5 h-auto rounded shadow-sm" onerror="this.src='img/flags/un.png'">
                                    <div class="min-w-0">
                                        <div class="text-[12px] font-black text-slate-400 uppercase tracking-widest truncate">${s.country || 'Unknown'}</div>
                                        <div class="text-[11px] text-slate-500 truncate">${s.city || 'Internet'}</div>
                                    </div>
                                </div>
                            </td>
                            <td class="px-6 py-4">
                                <div class="flex items-center gap-4">
                                    ${sourceUI}
                                    <div class="flex flex-col items-center opacity-40">
                                        <i data-lucide="${dirIcon}" class="w-4 h-4 text-slate-400"></i>
                                        <span class="text-[7px] font-black text-slate-500 uppercase">WAN</span>
                                    </div>
                                    ${destUI}
                                </div>
                            </td>
                            <td class="px-6 py-4">
                                <div class="flex items-center gap-2 ${dirColor}">
                                    <span class="text-[12px] font-black uppercase tracking-tighter">${s.direction}</span>
                                </div>
                            </td>
                            <td class="px-6 py-4">
                                <div class="text-xs font-bold text-slate-400">${s.protocol}</div>
                                <div class="text-[11px] text-slate-500 truncate">${s.category}</div>
                            </td>
                            <td class="px-6 py-4 text-right">
                                <span class="px-2 py-0.5 rounded-lg text-[12px] font-black uppercase tracking-tighter border ${actionColor}">
                                    ${s.action}
                                </span>
                            </td>
                        </tr>
                        `;
                    }).join('');
                }
                if (typeof lucide !== 'undefined') lucide.createIcons();
            })
            .catch(err => {
                console.error(err);
                tbody.innerHTML = '<tr><td colspan="5" class="px-6 py-12 text-center text-red-400">Błąd podczas pobierania danych sesji</td></tr>';
            });
    }
    </script>

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
                            <p class="text-[12px] font-black text-slate-500 uppercase tracking-widest">Zmień zdjęcie profilowe</p>
                        </div>
                    </div>

                    <!-- Right: Form Fields -->
                    <div class="md:col-span-7 grid grid-cols-2 gap-x-4 gap-y-6">
                        <div class="col-span-1">
                            <label class="block text-[11px] font-black text-slate-500 uppercase tracking-widest mb-2 px-1">Login / Użytkownik</label>
                            <input type="text" name="username" value="<?= htmlspecialchars($config['admin_username']) ?>" required
                                   class="w-full px-5 py-3.5 bg-slate-900/50 border border-white/10 rounded-2xl focus:outline-none focus:ring-2 focus:ring-blue-500/30 text-xs font-bold transition-all text-slate-200">
                        </div>
                        <div class="col-span-1">
                            <label class="block text-[11px] font-black text-slate-500 uppercase tracking-widest mb-2 px-1">Nowe Hasło</label>
                            <input type="password" name="password" placeholder="••••••••"
                                   class="w-full px-5 py-3.5 bg-slate-900/50 border border-white/10 rounded-2xl focus:outline-none focus:ring-2 focus:ring-blue-500/30 text-xs font-bold transition-all text-slate-200">
                        </div>
                        <div class="col-span-1">
                            <label class="block text-[11px] font-black text-slate-500 uppercase tracking-widest mb-2 px-1">Imię i Nazwisko</label>
                            <input type="text" name="full_name" value="<?= htmlspecialchars($config['admin_full_name'] ?? '') ?>"
                                   class="w-full px-5 py-3.5 bg-slate-900/50 border border-white/10 rounded-2xl focus:outline-none focus:ring-2 focus:ring-blue-500/30 text-xs font-bold transition-all text-slate-200">
                        </div>
                        <div class="col-span-1">
                            <label class="block text-[11px] font-black text-slate-500 uppercase tracking-widest mb-2 px-1">Adres Email</label>
                            <input type="email" name="email" value="<?= htmlspecialchars($config['admin_email']) ?>"
                                   class="w-full px-5 py-3.5 bg-slate-900/50 border border-white/10 rounded-2xl focus:outline-none focus:ring-2 focus:ring-blue-500/30 text-xs font-bold transition-all text-slate-200">
                        </div>
                    </div>
                </div>

                <!-- Regional Settings Section -->
                <div class="mt-8 pt-6 border-t border-white/5">
                    <div class="flex items-center gap-3 mb-6">
                        <div class="w-8 h-8 rounded-xl bg-blue-500/10 text-blue-400 flex items-center justify-center">
                            <i data-lucide="globe" class="w-4 h-4"></i>
                        </div>
                        <h3 class="text-xs font-black text-slate-500 uppercase tracking-[0.2em]">Ustawienia Regionalne</h3>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                        <div>
                            <label class="block text-[11px] font-black text-slate-500 uppercase tracking-widest mb-2 px-1">Język Interfejsu</label>
                            <select name="language" class="w-full px-5 py-3 bg-slate-900/50 border border-white/10 rounded-2xi focus:outline-none focus:ring-2 focus:ring-blue-500/30 text-xs font-bold text-slate-200 appearance-none">
                                <option value="auto">Automatyczny (z konsoli)</option>
                                <option value="pl" <?= ($config['language'] ?? '') === 'pl' ? 'selected' : '' ?>>Polski (PL)</option>
                                <option value="en" <?= ($config['language'] ?? '') === 'en' ? 'selected' : '' ?>>English (EN)</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-[11px] font-black text-slate-500 uppercase tracking-widest mb-2 px-1">Strefa Czasowa</label>
                            <select name="timezone" class="w-full px-5 py-3 bg-slate-900/50 border border-white/10 rounded-2xi focus:outline-none focus:ring-2 focus:ring-blue-500/30 text-xs font-bold text-slate-200 appearance-none">
                                <option value="auto">Zgodnie z konsolą</option>
                                <option value="Europe/Warsaw">Warszawa (GMT+1)</option>
                                <option value="UTC">UTC (GMT+0)</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-[11px] font-black text-slate-500 uppercase tracking-widest mb-2 px-1">Format Czasu / Daty</label>
                            <div class="flex gap-2">
                                <select name="time_format" class="flex-1 px-4 py-3 bg-slate-900/50 border border-white/10 rounded-2xi focus:outline-none focus:ring-2 focus:ring-blue-500/30 text-xs font-bold text-slate-200 appearance-none">
                                    <option value="24h">24h</option>
                                    <option value="12h">12h</option>
                                </select>
                                <select name="date_format" class="flex-1 px-4 py-3 bg-slate-900/50 border border-white/10 rounded-2xi focus:outline-none focus:ring-2 focus:ring-blue-500/30 text-xs font-bold text-slate-200 appearance-none">
                                    <option value="d.m.Y">DD.MM.YY</option>
                                    <option value="m/d/Y">MM/DD/YY</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Security Section (2FA Placeholder) -->
                <div class="mt-8 pt-6 border-t border-white/5 opacity-40">
                    <div class="flex items-center justify-between mb-4">
                        <div class="flex items-center gap-3">
                            <div class="w-8 h-8 rounded-xl bg-orange-500/10 text-orange-400 flex items-center justify-center">
                                <i data-lucide="shield-check" class="w-4 h-4"></i>
                            </div>
                            <h3 class="text-xs font-black text-slate-550 uppercase tracking-[0.2em]">Bezpieczeństwo (2FA)</h3>
                        </div>
                        <span class="text-[12px] font-black text-white/20 uppercase tracking-[0.2em] bg-white/5 px-2 py-1 rounded">Wkrótce</span>
                    </div>
                    <div class="p-4 bg-white/[0.01] border border-white/5 rounded-2xl flex items-center justify-between">
                        <div class="text-[12px] text-slate-500 font-bold uppercase tracking-wider">Logowanie Dwuetapowe</div>
                        <div class="w-10 h-5 bg-slate-800 rounded-full relative opacity-50">
                            <div class="absolute left-1 top-1 w-3 h-3 bg-slate-600 rounded-full"></div>
                        </div>
                    </div>
                </div>

                <!-- Login History Section -->
                <div class="mt-8 pt-6 border-t border-white/5">
                    <div class="mb-4 flex items-center justify-between">
                        <h3 class="text-sm font-bold text-white uppercase tracking-wider">Historia Logowań</h3>
                         <button type="button" class="text-[12px] text-blue-400 font-bold uppercase tracking-widest hover:text-white transition">
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

                                // Read from SQLite first, fallback to JSON
                                $historyData = [];
                                if (isset($db)) {
                                    try {
                                        $stmt = $db->query("SELECT ip, location, os, browser, logged_at FROM login_history ORDER BY logged_at DESC LIMIT 20");
                                        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                                        foreach ($rows as $r) {
                                            $historyData[] = [
                                                'timestamp' => strtotime($r['logged_at']),
                                                'ip' => $r['ip'],
                                                'location' => $r['location'],
                                                'os' => $r['os'],
                                                'browser' => $r['browser'],
                                            ];
                                        }
                                    } catch (Exception $e) {}
                                }
                                if (empty($historyData)) {
                                    $historyFile = __DIR__ . '/data/login_history.json';
                                    if (file_exists($historyFile)) {
                                        $loaded = json_decode(file_get_contents($historyFile), true);
                                        if (is_array($loaded)) $historyData = $loaded;
                                    }
                                }
                             ?>
                             <div class="p-4 flex items-center justify-between hover:bg-white/[0.02] transition">
                                <div class="flex items-center gap-4">
                                    <div class="w-10 h-10 rounded-xl bg-emerald-500/10 text-emerald-500 flex items-center justify-center shadow-lg shadow-emerald-500/10">
                                        <i data-lucide="laptop" class="w-5 h-5"></i>
                                    </div>
                                    <div>
                                        <div class="text-xs font-bold text-white">Obecna Sesja (<?= $os ?>)</div>
                                        <div class="text-[12px] text-slate-500 font-mono mt-0.5">IP: <?= $client_ip ?> • <?= $browser ?></div>
                                        <div class="text-[11px] text-blue-400 font-bold mt-0.5 flex items-center gap-1.5">
                                            <i data-lucide="map-pin" class="w-2.5 h-2.5"></i>
                                            <?= $current_loc ?>
                                        </div>
                                    </div>
                                </div>
                                <span class="px-2 py-1 rounded bg-emerald-500/10 text-emerald-400 text-[12px] font-bold uppercase tracking-wider animate-pulse ring-1 ring-emerald-500/20">Aktywna</span>
                            </div>

                            <!-- Past History -->
                            <?php
                            $hist_count = 0;
                            if (!empty($historyData)):
                                foreach ($historyData as $idx => $entry):
                                    if ($idx === 0 && ($entry['ip'] ?? '') === $client_ip) continue;
                                    if ($hist_count >= 5) break;
                                    $hist_count++;
                            ?>
                             <div class="p-4 flex items-center justify-between hover:bg-white/[0.02] transition border-t border-white/[0.02]">
                                <div class="flex items-center gap-4">
                                    <div class="w-10 h-10 rounded-xl bg-slate-800 text-slate-500 flex items-center justify-center">
                                        <i data-lucide="history" class="w-5 h-5"></i>
                                    </div>
                                    <div>
                                        <div class="text-xs font-bold text-slate-300"><?= $entry['os'] ?? 'OS' ?> • <?= $entry['browser'] ?? 'Browser' ?></div>
                                        <div class="text-[12px] text-slate-500 font-mono mt-0.5"><?= date('d.m.Y H:i', $entry['timestamp']) ?> • IP: <?= $entry['ip'] ?></div>
                                        <div class="text-[11px] text-slate-500 mt-0.5 flex items-center gap-1.5">
                                            <i data-lucide="map-pin" class="w-2.5 h-2.5"></i>
                                            <?= $entry['location'] ?? 'Unknown' ?>
                                        </div>
                                    </div>
                                </div>
                                <span class="text-[11px] text-slate-600 font-black uppercase tracking-widest">Logowanie</span>
                            </div>
                            <?php 
                                endforeach;
                            endif;

                            if ($hist_count === 0): ?>
                             <div class="p-6 flex items-center justify-center text-slate-600 text-[11px] uppercase font-black tracking-[0.2em] italic">
                                Brak starszych aktywności
                             </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- 2FA Section -->
                <div class="mt-6 mb-4 border border-white/10 rounded-2xl p-5 bg-gradient-to-br from-slate-900 to-slate-950 relative group overflow-hidden">
                     <div class="absolute inset-0 bg-blue-500/5 opacity-0 group-hover:opacity-100 transition-opacity"></div>
                     <div class="flex items-center justify-between relative z-10">
                        <div class="flex items-center gap-4">
                            <div class="w-12 h-12 rounded-xl bg-blue-600/10 text-blue-500 flex items-center justify-center border border-blue-600/20 group-hover:scale-110 transition-transform shadow-lg shadow-blue-900/20">
                                 <i data-lucide="shield-check" class="w-6 h-6"></i>
                            </div>
                            <div>
                                <h3 class="text-sm font-bold text-white">Podwójne Uwierzytelnianie (2FA)</h3>
                                <p class="text-[12px] font-black text-blue-400/80 uppercase tracking-widest mt-1">Coming Soon...</p>
                            </div>
                        </div>
                        <div class="opacity-50 pointer-events-none">
                            <div class="w-11 h-6 bg-slate-800 rounded-full relative border border-white/10">
                                 <div class="absolute left-0.5 top-0.5 w-5 h-5 bg-slate-600 rounded-full"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </form>

            <div class="p-6 bg-slate-950/50 border-t border-white/5 flex justify-end shrink-0">
                <button type="submit" form="personalForm" class="px-12 py-4 bg-blue-600 hover:bg-blue-500 text-white font-black rounded-2xl transition shadow-xl shadow-blue-600/20 text-[11px] uppercase tracking-[0.2em] flex items-center justify-center gap-2">
                     Zapisz Dane
                </button>
            </div>
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
                    showToast('Profil zaktualizowany', 'success');
                    setTimeout(() => location.reload(), 1200);
                } else {
                    showToast('Błąd: ' + result.message, 'error');
                }
            } catch (err) {
                console.error(err);
                showToast('Wystąpił nieoczekiwany błąd zapisu', 'error');
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



