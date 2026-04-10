<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions.php';

$mac = '28:3d:c2:ce:bf:38';
$end = time();
$start = $end - (24 * 3600);

$endpoint = "/proxy/network/api/s/default/stat/report/daily.user?mac=$mac&start=$start&end=$end";
$resp = fetch_api($endpoint);

header('Content-Type: application/json');
echo json_encode([
    'endpoint' => $endpoint,
    'response' => $resp
], JSON_PRETTY_PRINT);
