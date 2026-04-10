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

// Fetch active clients with real-time transfer rates
$sta_resp = fetch_api('/proxy/network/api/s/default/stat/sta');
$clients = $sta_resp['data'] ?? [];

// Fetch recent active station DPI for broader context
$stadpi_resp = fetch_api("/proxy/network/api/s/default/stat/stadpi");
$stadp_data = $stadpi_resp['data'] ?? [];

// Build a map of local IP -> Details from DPI and sessions
$external_map = [];
$dpi_map = [];

// Try to get DPI stats from station-specific data
foreach ($clients as $c) {
    $mac = strtolower($c['mac'] ?? '');
    $ip = $c['ip'] ?? $c['last_ip'] ?? '';
    
    // Fallback: Use manufacturer from MAC if DPI is empty
    $vendor = $c['manufacturer'] ?? '';
    
    // Check if station has built-in DPI stats (common in newer firmware)
    if (!empty($c['dpi_stats'])) {
        // Find top app in dpi_stats
        // Note: dpi_stats structure varies, but usually contains app categories
    }
}

foreach ($stadp_data as $d) {
    if (empty($d['by_app'])) continue;
    $mac = strtolower($d['mac'] ?? '');
    usort($d['by_app'], fn($a, $b) => ($b['rx_bytes'] + $b['tx_bytes']) <=> ($a['rx_bytes'] + $a['tx_bytes']));
    $top_app = $d['by_app'][0];
    $dpi_map[$mac] = $top_app['app_display'] ?? $top_app['app'] ?? $top_app['cat'] ?? '';
}

// Fetch IPS events for external IPs (often the only source for real-time external flows)
$ips_resp = fetch_api("/proxy/network/api/s/default/stat/ips/event?limit=100");
$ips_events = $ips_resp['data'] ?? [];
foreach ($ips_events as $e) {
    $src = $e['src_ip'] ?? ''; $dst = $e['dst_ip'] ?? '';
    $ext = ''; $local = '';
    if (filter_var($src, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
        $ext = $src; $local = $dst;
    } elseif (filter_var($dst, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
        $ext = $dst; $local = $src;
    }
    if ($local && !isset($external_map[$local])) {
        $external_map[$local] = ['ext' => $ext, 'cc' => strtolower($e['country_code'] ?? 'un')];
    }
}

// Load GeoIP cache 
$geo_cache_file = __DIR__ . '/data/geoip_cache.json';
$geo_cache = [];
if (file_exists($geo_cache_file)) {
    $geo_cache = json_decode(file_get_contents($geo_cache_file), true) ?: [];
}

// Build top active clients list
$active_flows = [];
foreach ($clients as $c) {
    $rx = $c['rx_bytes-r'] ?? $c['wired-rx_bytes-r'] ?? 0;
    $tx = $c['tx_bytes-r'] ?? $c['wired-tx_bytes-r'] ?? 0;
    $total = $rx + $tx;
    $ip = $c['ip'] ?? $c['last_ip'] ?? '';
    $mac = strtolower($c['mac'] ?? '');

    if ($total > 5) { // Lower threshold to catch even small IoT pings
        $ext_data = $external_map[$ip] ?? null;
        $cc = $ext_data['cc'] ?? 'un';
        if ($cc === 'un' && $ext_data && isset($geo_cache[$ext_data['ext']])) {
            $cc = $geo_cache[$ext_data['ext']]['country_code'] ?? 'un';
        }

        // Improved type detection: DPI Application > Manufacturer > General
        $type = $dpi_map[$mac] ?? '';
        if (!$type || $type === 'General') {
            $type = $c['manufacturer'] ?? ($c['os_name'] ?? 'General');
        }

        $active_flows[] = [
            'name' => $c['name'] ?? $c['hostname'] ?? $c['mac'] ?? 'Unknown',
            'mac' => $mac,
            'ip' => $ip,
            'external_ip' => $ext_data['ext'] ?? '',
            'country_code' => $cc,
            'type' => strtoupper($type),
            'rx_bps' => round($rx * 8),
            'tx_bps' => round($tx * 8),
            'total_bps' => round($total * 8),
            'is_wired' => !empty($c['is_wired']),
            'network' => $c['network'] ?? $c['essid'] ?? '',
        ];
    }
}

// Final sort and slice
usort($active_flows, fn($a, $b) => $b['total_bps'] <=> $a['total_bps']);
$active_flows = array_slice($active_flows, 0, 20);

echo json_encode(['success' => true, 'data' => $active_flows]);
