<?php
/** Created by Łukasz Misiura (c) 2025 | dev.lm-ads.com **/
ob_start();
try {
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

    $siteId = $_SESSION['site_id'] ?? $config['site'] ?? 'default';

    // GeoIP cache file
    $geo_cache_file = __DIR__ . '/data/geoip_cache.json';
    $geo_cache = [];
    if (file_exists($geo_cache_file)) {
        $geo_cache = json_decode(file_get_contents($geo_cache_file), true) ?: [];
    }

    $tradSite = get_trad_site_id($siteId);
    // Fetch data
    $sta_resp = fetch_api("/proxy/network/api/s/{$tradSite}/stat/sta");
    $clients = $sta_resp['data'] ?? [];

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

    $ips_resp = fetch_api("/proxy/network/api/s/{$tradSite}/stat/ips/event?limit=50");
    $ips_events = $ips_resp['data'] ?? [];
    $events_resp = fetch_api("/proxy/network/api/s/$tradSite/stat/event?limit=100&_sort=-time");
    $net_events = $events_resp['data'] ?? [];

    $seen_dest = [];
    $candidate_ips = [];
    foreach ($ips_events as $e) {
        if ($src = $e['src_ip'] ?? '') if (filter_var($src, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) $candidate_ips[] = $src;
        if ($dst = $e['dst_ip'] ?? '') if (filter_var($dst, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) $candidate_ips[] = $dst;
    }

    $geo_data = lookup_geoip_batch(array_unique($candidate_ips), $geo_cache, $geo_cache_file);

    $sessions = [];

    // 1. Process Security/IPS Events
    foreach ($ips_events as $e) {
        $src = $e['src_ip'] ?? '';
        $dst = $e['dst_ip'] ?? '';
        $ext = ''; $local = ''; $dir = 'inbound';
        
        if (filter_var($src, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) { $ext = $src; $local = $dst; $dir = 'inbound'; }
        elseif (filter_var($dst, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) { $ext = $dst; $local = $src; $dir = 'outbound'; }
        else continue;

        $key = $ext . ':' . $local;
        if (isset($seen_dest[$key])) continue;
        $seen_dest[$key] = true;

        $geo = $geo_data[$ext] ?? ['country' => '??', 'country_code' => '??', 'city' => '', 'org' => ''];
        $client_info = $client_map[$local] ?? null;

        $sessions[] = [
            'external_ip' => $ext,
            'local_ip' => $local,
            'client_name' => $client_info['name'] ?? $local,
            'direction' => $dir,
            'protocol' => strtoupper($e['proto'] ?? 'TCP'),
            'action' => $e['inner_alert_action'] ?? 'allowed',
            'category' => $e['inner_alert_category'] ?? $e['inner_alert_signature'] ?? 'Security Event',
            'country' => $geo['country'],
            'country_code' => $geo['country_code'],
            'city' => $geo['city'] ?? '',
            'org' => $geo['org'] ?? '',
            'time' => isset($e['time']) ? round($e['time'] / 1000) : time(),
            'is_blocked' => (($e['inner_alert_action'] ?? '') === 'blocked'),
        ];
        if (count($sessions) >= 50) break;
    }

    // 2. If sessions are empty, populate with Active Flows (Top Clients)
    if (count($sessions) < 10) {
        usort($clients, function($a, $b) {
            $rateA = ($a['rx_rate'] ?? 0) + ($a['tx_rate'] ?? 0);
            $rateB = ($b['rx_rate'] ?? 0) + ($b['tx_rate'] ?? 0);
            return $rateB <=> $rateA;
        });

        foreach ($clients as $c) {
            if (count($sessions) >= 50) break;
            $rate = ($c['rx_rate'] ?? 0) + ($c['tx_rate'] ?? 0);
            if ($rate < 1000) continue; 

            $ip = $c['ip'] ?? $c['last_ip'] ?? '';
            if (!$ip || isset($seen_dest[$ip])) continue;
            $seen_dest[$ip] = true;

            $sessions[] = [
                'external_ip' => 'INTERNET',
                'local_ip' => $ip,
                'client_name' => $c['name'] ?? $c['hostname'] ?? $c['mac'] ?? 'Urządzenie',
                'direction' => ($c['tx_rate'] ?? 0) > ($c['rx_rate'] ?? 0) ? 'outbound' : 'inbound',
                'protocol' => 'TCP/UDP',
                'action' => 'active',
                'category' => 'Traffic Flow',
                'country' => 'Internet',
                'country_code' => 'un',
                'city' => 'Live Traffic',
                'org' => 'Active Flow',
                'time' => time(),
                'is_blocked' => false,
            ];
        }
    }

    usort($sessions, function($a, $b) { return ($b['time'] ?? 0) <=> ($a['time'] ?? 0); });

    $in = count(array_filter($sessions, function($s) { return $s['direction'] === 'inbound'; }));
    $out = count(array_filter($sessions, function($s) { return $s['direction'] === 'outbound'; }));
    $blk = count(array_filter($sessions, function($s) { return $s['is_blocked']; }));

    $country_stats = [];
    foreach ($sessions as $s) {
        $cc = $s['country_code'];
        if (!isset($country_stats[$cc])) $country_stats[$cc] = ['code' => $cc, 'country' => $s['country'], 'count' => 0, 'blocked' => 0];
        $country_stats[$cc]['count']++;
        if ($s['is_blocked']) $country_stats[$cc]['blocked']++;
    }
    usort($country_stats, function($a, $b) { return $b['count'] <=> $a['count']; });

    ob_clean();
    echo json_encode([
        'success' => true,
        'stats' => ['total' => count($sessions), 'inbound' => $in, 'outbound' => $out, 'blocked' => $blk],
        'countries' => array_slice($country_stats, 0, 15),
        'sessions' => $sessions,
    ]);
} catch (Exception $e) {
    ob_clean();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (Error $e) {
    ob_clean();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
