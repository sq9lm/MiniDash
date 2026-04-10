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

$siteId = $config['site'] ?? 'default';

// GeoIP cache file to avoid hammering API
$geo_cache_file = __DIR__ . '/data/geoip_cache.json';
$geo_cache = [];
if (file_exists($geo_cache_file)) {
    $geo_cache = json_decode(file_get_contents($geo_cache_file), true) ?: [];
}

function lookup_geoip($ip, &$cache, $cache_file) {
    // Skip private/local IPs
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
        return ['country' => 'Local', 'country_code' => 'local', 'city' => '', 'org' => 'LAN'];
    }

    // Check cache (24h TTL)
    if (isset($cache[$ip]) && (time() - ($cache[$ip]['ts'] ?? 0)) < 86400) {
        return $cache[$ip];
    }

    // Use ip-api.com batch-friendly endpoint
    $url = "http://ip-api.com/json/" . urlencode($ip) . "?fields=status,country,countryCode,city,org,isp";
    
    try {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 3,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $response = curl_exec($ch);
        curl_close($ch);
        
        if ($response !== false) {
            $data = json_decode($response, true);
            if (($data['status'] ?? '') === 'success') {
                $result = [
                    'country' => $data['country'] ?? '??',
                    'country_code' => strtolower($data['countryCode'] ?? '??'),
                    'city' => $data['city'] ?? '',
                    'org' => $data['org'] ?? $data['isp'] ?? '',
                    'ts' => time()
                ];
                $cache[$ip] = $result;
                // Save cache (limit to 500 entries)
                if (count($cache) > 500) {
                    uasort($cache, fn($a, $b) => ($b['ts'] ?? 0) <=> ($a['ts'] ?? 0));
                    $cache = array_slice($cache, 0, 500, true);
                }
                file_put_contents($cache_file, json_encode($cache));
                return $result;
            }
        }
    } catch (Throwable $e) {}
    
    return ['country' => '??', 'country_code' => '??', 'city' => '', 'org' => ''];
}

// Fetch active clients with their traffic stats
$sta_resp = fetch_api('/proxy/network/api/s/default/stat/sta');
$clients = $sta_resp['data'] ?? [];

// Build a map of local IPs to client names
$client_map = [];
foreach ($clients as $c) {
    $ip = $c['ip'] ?? $c['last_ip'] ?? '';
    if ($ip) {
        $client_map[$ip] = [
            'name' => $c['name'] ?? $c['hostname'] ?? $c['mac'] ?? 'Unknown',
            'mac' => $c['mac'] ?? '',
            'is_wired' => !empty($c['is_wired']),
        ];
    }
}

// Try to get active connections from the UniFi gateway
// Method 1: Try DPI (Deep Packet Inspection) client stats — shows apps/destinations
$sessions = [];

// Method: Use active client data + IPS events for external IP visibility
// Fetch recent IPS/firewall events which show external IPs
$ips_resp = fetch_api("/proxy/network/api/s/default/stat/ips/event?limit=50");
$ips_events = $ips_resp['data'] ?? [];

// Also try to fetch recent network events showing connections
$events_resp = fetch_api("/proxy/network/api/s/$siteId/stat/event?limit=100&_sort=-time");
$net_events = $events_resp['data'] ?? [];

// Build sessions from IPS blocked + allowed events (these show real external IPs)
$seen_dest = [];
$candidate_ips = [];

// First pass: collect unique external IPs
foreach ($ips_events as $e) {
    if ($src = $e['src_ip'] ?? '') {
        if (filter_var($src, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
            $candidate_ips[] = $src;
        }
    }
    if ($dst = $e['dst_ip'] ?? '') {
        if (filter_var($dst, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
            $candidate_ips[] = $dst;
        }
    }
}

// Perform batch lookup once for all unique IPs
$geo_data = lookup_geoip_batch(array_unique($candidate_ips));

foreach ($ips_events as $e) {
    $src = $e['src_ip'] ?? '';
    $dst = $e['dst_ip'] ?? '';
    $proto = strtoupper($e['proto'] ?? $e['inner_alert_severity'] ?? 'TCP');
    $action = $e['inner_alert_action'] ?? 'allowed';
    $category = $e['inner_alert_category'] ?? '';
    $signature = $e['inner_alert_signature'] ?? '';
    $time = isset($e['time']) ? round($e['time'] / 1000) : time();
    
    // Determine which IP is external
    $external_ip = '';
    $local_ip = '';
    $direction = 'inbound';
    
    if (filter_var($src, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
        $external_ip = $src;
        $local_ip = $dst;
        $direction = 'inbound';
    } elseif (filter_var($dst, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
        $external_ip = $dst;
        $local_ip = $src;
        $direction = 'outbound';
    } else {
        continue; // Both local, skip
    }
    
    // Skip duplicates
    $key = $external_ip . ':' . $local_ip;
    if (isset($seen_dest[$key])) continue;
    $seen_dest[$key] = true;
    
    // Get pre-fetched GeoIP info
    $geo = $geo_data[$external_ip] ?? ['country' => '??', 'country_code' => '??', 'city' => '', 'org' => ''];
    
    $client_info = $client_map[$local_ip] ?? null;
    
    $sessions[] = [
        'external_ip' => $external_ip,
        'local_ip' => $local_ip,
        'client_name' => $client_info['name'] ?? $local_ip,
        'direction' => $direction,
        'protocol' => $proto,
        'action' => $action,
        'category' => $category ?: ($signature ?: 'Connection'),
        'country' => $geo['country'],
        'country_code' => $geo['country_code'],
        'city' => $geo['city'] ?? '',
        'org' => $geo['org'] ?? '',
        'time' => $time,
        'is_blocked' => ($action === 'blocked'),
    ];
    
    if (count($sessions) >= 50) break;
}

// Also add active client destinations from DPI data if available
$dpi_resp = fetch_api("/proxy/network/api/s/default/stat/stadpi");
$dpi_data = $dpi_resp['data'] ?? [];

foreach ($dpi_data as $client_dpi) {
    $client_mac = $client_dpi['mac'] ?? '';
    $client_ip = '';
    // Find client IP from map
    foreach ($client_map as $cip => $cinfo) {
        if (strtolower($cinfo['mac']) === strtolower($client_mac)) {
            $client_ip = $cip;
            break;
        }
    }
    
    $by_app = $client_dpi['by_app'] ?? [];
    foreach ($by_app as $app) {
        $app_name = $app['app'] ?? $app['cat'] ?? 'Unknown App';
        $rx = $app['rx_bytes'] ?? 0;
        $tx = $app['tx_bytes'] ?? 0;
        if (($rx + $tx) < 10000) continue; // Skip tiny flows
        
        // DPI doesn't give us external IPs directly, but gives app categories
        // We skip these for now as they don't have external IP info
    }
}

// Sort by time descending
usort($sessions, fn($a, $b) => ($b['time'] ?? 0) <=> ($a['time'] ?? 0));

// Count stats
$total_inbound = count(array_filter($sessions, fn($s) => $s['direction'] === 'inbound'));
$total_outbound = count(array_filter($sessions, fn($s) => $s['direction'] === 'outbound'));
$total_blocked = count(array_filter($sessions, fn($s) => $s['is_blocked']));

// Country stats
$country_stats = [];
foreach ($sessions as $s) {
    $cc = $s['country_code'];
    if (!isset($country_stats[$cc])) {
        $country_stats[$cc] = ['code' => $cc, 'country' => $s['country'], 'count' => 0, 'blocked' => 0];
    }
    $country_stats[$cc]['count']++;
    if ($s['is_blocked']) $country_stats[$cc]['blocked']++;
}
usort($country_stats, fn($a, $b) => $b['count'] <=> $a['count']);

echo json_encode([
    'success' => true,
    'stats' => [
        'total' => count($sessions),
        'inbound' => $total_inbound,
        'outbound' => $total_outbound,
        'blocked' => $total_blocked,
    ],
    'countries' => array_slice($country_stats, 0, 15),
    'sessions' => $sessions,
]);
