<?php
/** Created by Łukasz Misiura (c) 2025 | dev.lm-ads.com **/
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    http_response_code(401);
    exit;
}

$id = $_GET['id'] ?? '';
if (empty($id)) {
    http_response_code(400);
    exit;
}

$width = (int)($_GET['width'] ?? 640);

// Proxy the snapshot from UniFi Protect
$raw = fetch_api_raw("/proxy/protect/api/cameras/$id/snapshot?width=$width");

if ($raw) {
    header('Content-Type: image/jpeg');
    header('Cache-Control: no-cache, must-revalidate'); // Ensure we always get a fresh one
    echo $raw;
} else {
    // Return a dummy SVG if snapshot fails
    header('Content-Type: image/svg+xml');
    echo '<svg xmlns="http://www.w3.org/2000/svg" width="400" height="300"><rect fill="#1e293b" width="400" height="300"/><text x="50%" y="50%" font-family="Arial" font-size="24" fill="#64748b" text-anchor="middle" dominant-baseline="middle">Kamero offline</text></svg>';
}
