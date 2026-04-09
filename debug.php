<?php
/** Created by Łukasz Misiura (c) 2025 | dev.lm-ads.com **/
$config = include 'config.php';

function fetch_api_debug($endpoint) {
    global $config;
    $url = $config['controller_url'] . $endpoint;
    $headers = [
        "Accept: application/json",
        "X-API-KEY: " . $config['api_key']
    ];
    $curl = curl_init($url);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
    $response = curl_exec($curl);
    $code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    return ['status' => $code, 'data' => json_decode($response, true), 'raw' => $response];
}

header("Content-Type: text/html; charset=utf-8");
echo "<h1>🧪 UniFi API Debug</h1>";

$endpoints = [
    "/proxy/network/integration/v1/sites" => "📌 Sites (lokacje)",
    "/proxy/network/v2/api/site/{$config['site']}/clients" => "👥 Klienci",
    "/proxy/network/v2/api/site/{$config['site']}/devices" => "📡 Urządzenia"
];

foreach ($endpoints as $endpoint => $desc) {
    $result = fetch_api_debug($endpoint);
    echo "<h2>{$desc}</h2>";
    echo "<strong>Status HTTP:</strong> {$result['status']}<br>";
    if ($result['status'] === 200) {
    } else {
    }
}
?>



