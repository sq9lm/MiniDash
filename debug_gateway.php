<?php
/** Created by Łukasz Misiura (c) 2025 | dev.lm-ads.com **/
require_once 'config.php';
require_once 'functions.php';

$siteId = $config['site'];
$infr_resp = fetch_api("/proxy/network/integration/v1/sites/$siteId/devices");
$infr_devices = $infr_resp['data'] ?? [];

echo "Found " . count($infr_devices) . " devices.\n\n";

foreach ($infr_devices as $dev) {
    echo "Device: " . ($dev['name'] ?? 'Unnamed') . " (Model: " . ($dev['model'] ?? 'N/A') . ", Type: " . ($dev['type'] ?? 'N/A') . ")\n";
    echo "Keys: " . implode(", ", array_keys($dev)) . "\n";
    
    // Check for potential stats locations
    if (isset($dev['system-stats'])) {
        echo "  system-stats: " . json_encode($dev['system-stats']) . "\n";
    }
    if (isset($dev['wan1'])) {
        echo "  wan1: " . json_encode($dev['wan1']) . "\n";
    }
    if (isset($dev['wan2'])) {
        echo "  wan2: " . json_encode($dev['wan2']) . "\n";
    }
    if (isset($dev['uplink'])) {
        echo "  uplink: " . json_encode($dev['uplink']) . "\n";
    }
    if (isset($dev['cpu'])) { echo "  cpu: " . $dev['cpu'] . "\n"; }
    if (isset($dev['mem'])) { echo "  mem: " . $dev['mem'] . "\n"; }

    // Check for Gateway indicators
    $is_gw = false;
    $model = strtoupper($dev['model'] ?? '');
    if (in_array($model, ['UDR', 'UDM', 'UXG', 'USG']) || strpos($model, 'DREAM') !== false || ($dev['type'] ?? '') === 'udm' || ($dev['type'] ?? '') === 'gateway') {
        $is_gw = true;
    }
    echo "  Is detected as Gateway? " . ($is_gw ? "YES" : "NO") . "\n";
    echo "------------------------------------------\n";
}




