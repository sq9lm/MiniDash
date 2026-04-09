<?php
/** Created by Łukasz Misiura (c) 2026 | www.lm-ads.com **/
// Test script to verify API connectivity with new curl implementation
require_once 'config.php';
require_once 'functions.php';

// Test 1: Load Devices
echo "Testing loadDevices()...\n";
$devices = loadDevices();
echo "Devices count: " . count($devices) . "\n";

// Test 2: Fetch API (Clients)
// Using timeout to ensure it doesn't hang
echo "\nTesting fetch_api (Clients)...\n";
$startTime = microtime(true);
$response = fetch_api("/proxy/network/integration/v1/sites/" . $config['site'] . "/clients?limit=10");
$endTime = microtime(true);

echo "Time taken: " . number_format($endTime - $startTime, 4) . "s\n";

if (isset($response['error'])) {
    echo "ERROR: " . $response['error'] . "\n";
} else {
    $count = count($response['data'] ?? []);
    echo "SUCCESS. Clients fetched: $count\n";
    if ($count > 0) {
        echo "First client MAC: " . ($response['data'][0]['macAddress'] ?? 'N/A') . "\n";
    }
}

// Test 3: Fetch API (Non-existent endpoint to test timeout/404)
echo "\nTesting fetch_api (Bad Endpoint)...\n";
$startTime = microtime(true);
$responsev2 = fetch_api("/proxy/network/api/s/default/stat/non_existent_endpoint");
$endTime = microtime(true);
echo "Time taken: " . number_format($endTime - $startTime, 4) . "s\n";
echo "Response keys: " . implode(", ", array_keys($responsev2)) . "\n";

?>




