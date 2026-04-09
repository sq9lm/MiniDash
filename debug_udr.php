<?php
/** Created by Łukasz Misiura (c) 2025 | dev.lm-ads.com **/
require_once 'config.php';
require_once 'functions.php';

$siteId = $config['site'];
echo "Debugging UDR Stats for Site: $siteId\n";

// Try Classic Default first as it seems to be the one working based on previous steps
$resp = fetch_api("/proxy/network/api/s/default/stat/device");
$data = $resp['data'] ?? [];

if (empty($data)) {
    echo "Default site empty, trying configured site...\n";
    $resp = fetch_api("/proxy/network/api/s/$siteId/stat/device");
    $data = $resp['data'] ?? [];
}

echo "Found " . count($data) . " devices.\n";

$udr = null;
foreach ($data as $d) {
    if (in_array($d['model'], ['UDR', 'UDM', 'UXG', 'USG'])) {
        $udr = $d;
        echo "Found Gateway: " . $d['model'] . " (MAC: " . $d['mac'] . ")\n";
        break;
    }
}

if ($udr) {
    file_put_contents(__DIR__ . '/data/debug_udr_full.json', json_encode($udr, JSON_PRETTY_PRINT));
    echo "Saved full UDR JSON to data/debug_udr_full.json\n";
    
    echo "Keys check:\n";
    echo "system-stats: " . (isset($udr['system-stats']) ? 'YES' : 'NO') . "\n";
    if (isset($udr['system-stats'])) {
        print_r($udr['system-stats']);
    }
} else {
    echo "No UDR/Gateway found!\n";
    // Dump first device to see structure
    if (!empty($data)) {
        file_put_contents(__DIR__ . '/data/debug_first_device.json', json_encode($data[0], JSON_PRETTY_PRINT));
        echo "Saved first device to data/debug_first_device.json\n";
    }
}




