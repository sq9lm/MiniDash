<?php
/** Created by Łukasz Misiura (c) 2026 | www.lm-ads.com **/
require_once 'config.php';
require_once 'functions.php';

$siteId = $config['site'];

function test_endpoint($path) {
    try {
        echo "--- Testing: $path ---\n";
        $data = fetch_api($path);
        $count = isset($data['data']) ? count($data['data']) : 'N/A';
        echo "Status: Success | Count: $count\n";
        if ($count > 0 && $count !== 'N/A') {
            echo "First item keys: " . implode(', ', array_keys($data['data'][0])) . "\n";
        }
        return $data;
    } catch (Exception $e) {
        echo "Status: Error | " . $e->getMessage() . "\n";
        return null;
    }
}

echo "Current Site: $siteId\n\n";

// Integration API (current)
test_endpoint("/proxy/network/integration/v1/sites/$siteId/clients");
test_endpoint("/proxy/network/integration/v1/sites/$siteId/devices");

// Traditional API (proxy)
test_endpoint("/proxy/network/api/s/$siteId/stat/sta");
test_endpoint("/proxy/network/api/s/$siteId/stat/device");

// Try 'default' site just in case
if ($siteId !== 'default') {
    echo "\n--- Testing with 'default' site ---\n";
    test_endpoint("/proxy/network/api/s/default/stat/sta");
    test_endpoint("/proxy/network/api/s/default/stat/device");
}




