<?php
/** Created by Łukasz Misiura (c) 2025 | dev.lm-ads.com **/
require_once 'config.php';

echo "Using API Key: " . substr($config['api_key'], 0, 5) . "...\n";
echo "Controller: " . $config['controller_url'] . "\n\n";

$url = $config['controller_url'] . "/proxy/network/integration/v1/sites";
$apiKey = $config['api_key'];

$cmd = "curl -k -s -X GET '$url' -H 'X-API-KEY: $apiKey' -H 'Accept: application/json'";

echo "Executing: $cmd\n\n";
$output = shell_exec($cmd);

echo "Response:\n";
echo $output . "\n\n";

$data = json_decode($output, true);
if (isset($data['data'])) {
    echo "Sites found:\n";
    foreach ($data['data'] as $site) {
        echo "- Name: " . ($site['name'] ?? 'N/A') . " | ID: " . ($site['id'] ?? $site['_id'] ?? 'N/A') . " | Desc: " . ($site['desc'] ?? 'N/A') . "\n";
    }
} else {
    echo "No 'data' key found in response.\n";
}




