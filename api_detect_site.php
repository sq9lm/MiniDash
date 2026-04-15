<?php
/** Auto-detect UniFi Site ID — used by Setup Wizard */
header('Content-Type: application/json');

$controller_url = trim($_POST['controller_url'] ?? '');
$api_key = trim($_POST['api_key'] ?? '');

if (empty($controller_url) || empty($api_key)) {
    echo json_encode(['error' => 'Controller URL and API Key required']);
    exit;
}

$base = rtrim($controller_url, '/');
$headers = [
    "X-API-KEY: $api_key",
    "Accept: application/json"
];
$ctx_opts = [
    'http' => ['timeout' => 5],
    'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]
];

// Try Integration API first — returns site list with IDs
$sites = [];

$ch = curl_init("$base/proxy/network/integration/v1/sites");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT => 10,
    CURLOPT_HTTPHEADER => $headers,
]);
$resp = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = curl_error($ch);
curl_close($ch);

if ($http_code === 200 && $resp) {
    $data = json_decode($resp, true);
    if (!empty($data['data']) && is_array($data['data'])) {
        foreach ($data['data'] as $site) {
            $sites[] = [
                'id' => $site['siteId'] ?? $site['id'] ?? '',
                'name' => $site['meta']['name'] ?? $site['name'] ?? 'Unknown',
            ];
        }
    }
}

// Fallback: Traditional API
if (empty($sites)) {
    $ch = curl_init("$base/proxy/network/api/self/sites");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    $resp = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http_code === 200 && $resp) {
        $data = json_decode($resp, true);
        foreach (($data['data'] ?? []) as $site) {
            $sites[] = [
                'id' => $site['_id'] ?? $site['name'] ?? 'default',
                'name' => $site['desc'] ?? $site['name'] ?? 'Default',
            ];
        }
    }
}

if (empty($sites)) {
    echo json_encode([
        'error' => $err ?: "Cannot connect to controller (HTTP $http_code)",
        'sites' => []
    ]);
} else {
    echo json_encode(['sites' => $sites]);
}
