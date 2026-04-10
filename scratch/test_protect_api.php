<?php
require_once 'config.php';
require_once 'functions.php';

echo "Testing UniFi Protect API...\n";
$bootstrap = fetch_api("/proxy/protect/api/bootstrap");

echo "Bootstrap response keys: " . implode(', ', array_keys($bootstrap)) . "\n";
if (isset($bootstrap['data'])) {
    echo "Data keys: " . implode(', ', array_keys($bootstrap['data'])) . "\n";
}

$cameras = $bootstrap['cameras'] ?? $bootstrap['data']['cameras'] ?? [];
echo "Cameras count: " . count($cameras) . "\n";

if (empty($cameras)) {
    echo "Trying fallback /proxy/protect/api/cameras...\n";
    $cameras_resp = fetch_api("/proxy/protect/api/cameras");
    $cameras = $cameras_resp['data'] ?? $cameras_resp['original'] ?? [];
    echo "Fallback cameras count: " . count($cameras) . "\n";
}

print_r(array_slice($cameras, 0, 1));
