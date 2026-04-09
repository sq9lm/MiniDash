<?php
/** Created by Łukasz Misiura (c) 2025 | dev.lm-ads.com **/
require_once 'config.php';

echo "=== UniFi API Connectivity Debug ===\n";
echo "Controller: " . $config['controller_url'] . "\n";
echo "Site ID: " . $config['site'] . "\n";
echo "API Key provided: " . (empty($config['api_key']) ? "NO" : "YES") . "\n";
echo "\n";

function test_url($url, $method = 'GET', $data = null, $verify_ssl = false) {
    global $config;
    echo "Testing: $url ...\n";
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    
    $headers = [
        'Content-Type: application/json',
        'Accept: application/json'
    ];
    if (!empty($config['api_key'])) {
        $headers[] = 'X-API-KEY: ' . $config['api_key'];
    }
    
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($data) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }
    
    $output = curl_exec($ch);
    $info = curl_getinfo($ch);
    $error = curl_error($ch);
    curl_close($ch);
    
    echo "HTTP Code: " . $info['http_code'] . "\n";
    if ($output === false) {
        echo "cURL Error: $error\n";
    } else {
        $json = json_decode($output, true);
        if ($json) {
            echo "Response (JSON): valid JSON found.\n";
            if (isset($json['meta']['rc'])) echo "Meta RC: " . $json['meta']['rc'] . "\n";
            if (isset($json['data'])) echo "Data items: " . count($json['data']) . "\n";
        } else {
            echo "Response (Raw First 300 chars): " . substr($output, 0, 300) . "\n";
        }
    }
    echo "----------------------------------------\n";
}

// 1. Test Integration API (Clients)
test_url($config['controller_url'] . "/proxy/network/integration/v1/sites/" . $config['site'] . "/clients");

// 2. Test Integration API (Devices)
test_url($config['controller_url'] . "/proxy/network/integration/v1/sites/" . $config['site'] . "/devices");

// 3. Test Login (to check credentials/cookie path)
echo "Testing Login (Legacy)...\n";
$cookie_jar = __DIR__ . '/test_cookie.txt';
if (file_exists($cookie_jar)) unlink($cookie_jar);

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $config['controller_url'] . '/api/auth/login');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
    'username' => $config['admin_username'],
    'password' => $config['admin_password']
]));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
curl_setopt($ch, CURLOPT_COOKIEJAR, $cookie_jar);
curl_setopt($ch, CURLOPT_COOKIEFILE, $cookie_jar);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

$output = curl_exec($ch);
$info = curl_getinfo($ch);
curl_close($ch);

echo "Login HTTP Code: " . $info['http_code'] . "\n";
if ($info['http_code'] == 200) {
    echo "Login successful. Testing auth-required endpoint...\n";
    
    // Test Self (often simplified)
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $config['controller_url'] . '/proxy/network/api/self');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookie_jar);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookie_jar);
    $out = curl_exec($ch);
    echo "Self Info Length: " . strlen($out) . "\n";
    $json = json_decode($out, true);
    if ($json) echo "Self Data found.\n";
    else echo "Raw: " . substr($out, 0, 100) . "...\n";
} else {
    echo "Login failed.\n";
}




