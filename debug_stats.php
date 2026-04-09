<?php
/** Created by Łukasz Misiura (c) 2026 | www.lm-ads.com **/
require_once 'config.php';
require_once 'functions.php';

// Allow this script to run longer
ini_set('max_execution_time', 60);

echo "DEBUG: Finding Gateway...\n";

// Get Device List
$resp = fetch_api("/proxy/network/integration/v1/sites/{$config['site']}/devices");
// Check for empty data and fallback if needed (simplified logic from index)
if (empty($resp['data'])) {
     $resp = fetch_api("/proxy/network/integration/v1/sites/default/devices");
     $config['site'] = 'default';
}

$devices = $resp['data'] ?? [];

$gateway = null;
foreach ($devices as $d) {
    if (in_array(strtoupper($d['model'] ?? ''), ['UDR', 'UDM', 'UXG', 'USG'])) {
        $gateway = $d;
        break;
    }
}

if (!$gateway) {
    echo "No Gateway found in " . count($devices) . " devices.\n";
    exit;
}

echo "Gateway Found: " . ($gateway['model'] ?? 'Unknown') . " (" . ($gateway['id'] ?? 'No ID') . ")\n";

// Dump Gateway Keys (First Level)
echo "Gateway Keys: " . implode(", ", array_keys($gateway)) . "\n";
if (isset($gateway['system-stats'])) {
    echo "system-stats found: " . json_encode($gateway['system-stats']) . "\n";
} else {
    echo "system-stats NOT found.\n";
}

// Try Statistics Endpoint
$url = "/proxy/network/integration/v1/sites/{$config['site']}/devices/{$gateway['id']}/statistics/latest";
echo "Fetching Stats: $url ...\n";
$stats_resp = fetch_api($url);
$stats = $stats_resp['data'] ?? $stats_resp;

if (empty($stats)) {
    echo "Stats response empty or invalid.\n";
    echo "Raw Stats Response: " . substr(json_encode($stats_resp), 0, 200) . "...\n";
} else {
    echo "Stats Keys: " . implode(", ", array_keys($stats)) . "\n";
    if (isset($stats['system-stats'])) {
        echo "Stats->system-stats: " . json_encode($stats['system-stats']) . "\n";
    }
    if (isset($stats['cpuUtilizationPct'])) {
        echo "Stats->cpuUtilizationPct: " . $stats['cpuUtilizationPct'] . "\n";
    }
}




