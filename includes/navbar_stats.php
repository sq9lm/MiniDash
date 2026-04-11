<?php
/** Created by Łukasz Misiura (c) 2025 | dev.lm-ads.com **/
function get_navbar_stats() {
    // Return cached session data if available and fresh (< 60s)
    if (isset($_SESSION['navbar_stats']) && isset($_SESSION['navbar_stats_time']) && (time() - $_SESSION['navbar_stats_time'] < 60)) {
        return $_SESSION['navbar_stats'];
    }

    // Fetch from API if no cached data
    global $config;
    $cpu = 0; $ram = 0; $down = 0; $up = 0;

    try {
        $tradSite = function_exists('get_trad_site_id') ? get_trad_site_id($config['site'] ?? 'default') : 'default';
        $dev_resp = fetch_api("/proxy/network/api/s/$tradSite/stat/device");
        foreach (($dev_resp['data'] ?? []) as $d) {
            if (in_array($d['type'] ?? '', ['ugw', 'udm', 'uxg'])) {
                $cpu = $d['system-stats']['cpu'] ?? 0;
                $ram = $d['system-stats']['mem'] ?? 0;
                $down = $d['wan1']['rx_rate'] ?? (($d['wan1']['rx_bytes-r'] ?? 0) * 8);
                $up = $d['wan1']['tx_rate'] ?? (($d['wan1']['tx_bytes-r'] ?? 0) * 8);
                break;
            }
        }
    } catch (Exception $e) {}

    $stats = ['cpu' => $cpu, 'ram' => $ram, 'down' => $down, 'up' => $up];
    $_SESSION['navbar_stats'] = $stats;
    $_SESSION['navbar_stats_time'] = time();
    return $stats;
}
