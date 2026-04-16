<?php
/** Created by Łukasz Misiura (c) 2025 | dev.lm-ads.com **/
ini_set('display_errors', 0);
require_once 'config.php';
require_once 'db.php';
require_once 'functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$type  = $_GET['type'] ?? 'event'; // 'event' or 'alarm'
$limit = min(200, max(1, (int)($_GET['limit'] ?? 100)));

$site_id  = $config['site'] ?? 'default';
$tradSite = get_trad_site_id($site_id);

if ($type === 'alarm') {
    $resp = fetch_api("/proxy/network/api/s/$tradSite/rest/alarm?limit=$limit");
    if (empty($resp['data']) && $tradSite !== 'default') {
        $resp = fetch_api("/proxy/network/api/s/default/rest/alarm?limit=$limit");
    }
} else {
    $resp = fetch_api("/proxy/network/api/s/$tradSite/stat/event?limit=$limit&_sort=-time");
    if (empty($resp['data']) && $tradSite !== 'default') {
        $resp = fetch_api("/proxy/network/api/s/default/stat/event?limit=$limit&_sort=-time");
    }
}

$items = $resp['data'] ?? [];
$processed = [];

foreach ($items as $ev) {
    $sev = 'INFO';
    $key = $ev['key'] ?? '';

    if ((isset($ev['inner_alert_action']) && $ev['inner_alert_action'] === 'blocked') ||
        strpos($key, 'EVT_GW_Block') !== false ||
        strpos($key, 'THREAT') !== false) {
        $sev = 'CRITICAL';
    } elseif (strpos($key, 'Lost_Contact') !== false) {
        $sev = 'ERROR';
    } elseif (isset($ev['archived']) || strpos($key, 'WARN') !== false) {
        $sev = 'WARNING';
    }

    $cat = 'General';
    if (strpos($key, 'EVT_GW') !== false) $cat = 'Security/Gateway';
    elseif (strpos($key, 'EVT_AP') !== false) $cat = 'Wireless';
    elseif (strpos($key, 'EVT_SW') !== false) $cat = 'Switching';
    elseif (strpos($key, 'EVT_LU') !== false) $cat = 'Client';
    elseif (strpos($key, 'EVT_AD') !== false) $cat = 'Admin';
    elseif (isset($ev['inner_alert_action'])) $cat = 'Firewall';

    $ts = isset($ev['time']) ? $ev['time'] / 1000 : time();

    $processed[] = [
        'id'       => $ev['_id'] ?? uniqid(),
        'date'     => date('Y-m-d H:i:s', $ts),
        'ts'       => $ts,
        'severity' => $sev,
        'category' => $cat,
        'message'  => $ev['msg'] ?? $key,
        'raw'      => $ev
    ];
}

echo json_encode(['success' => true, 'data' => $processed, 'type' => $type]);
