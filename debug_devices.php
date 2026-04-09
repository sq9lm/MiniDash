<?php
/** Created by Łukasz Misiura (c) 2026 | www.lm-ads.com **/
require_once 'config.php';
require_once 'functions.php';

$siteId = $config['site'];
$infr_resp = fetch_api("/proxy/network/integration/v1/sites/$siteId/devices");
$devices = $infr_resp['data'] ?? [];

echo "Found " . count($devices) . " devices.\n\n";

foreach ($devices as $dev) {
    echo "Model: " . ($dev['model'] ?? 'N/A') . " | Name: " . ($dev['name'] ?? 'N/A') . " | Type: " . ($dev['type'] ?? 'N/A') . "\n";
    if (isset($dev['system-stats'])) {
        echo " - system-stats: CPU " . ($dev['system-stats']['cpu'] ?? 'N/A') . " | Mem " . ($dev['system-stats']['mem'] ?? 'N/A') . "\n";
    }
    if (isset($dev['cpu'])) echo " - cpu direct: " . $dev['cpu'] . "\n";
    if (isset($dev['mem'])) echo " - mem direct: " . $dev['mem'] . "\n";
    if (isset($dev['wan1'])) echo " - wan1 IP: " . ($dev['wan1']['ip'] ?? 'N/A') . "\n";
    echo "\n";
}




