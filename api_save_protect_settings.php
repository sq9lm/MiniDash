<?php
/** Created by Łukasz Misiura (c) 2025 | dev.lm-ads.com **/
require_once __DIR__ . '/config.php';

if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    header('Content-Type: application/json');
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json');

$json = file_get_contents('php://input');
$data = json_decode($json, true);

if (!$data) {
    echo json_encode(['success' => false, 'message' => 'Invalid data']);
    exit;
}

// Load existing config
$configFile = __DIR__ . '/data/config.json';
$configData = [];
if (file_exists($configFile)) {
    $configData = json_decode(file_get_contents($configFile), true) ?: [];
}

// Update protect settings
if (!isset($configData['protect'])) {
    $configData['protect'] = [];
}

if (isset($data['vlan_id'])) {
    $configData['protect']['vlan_id'] = (int)$data['vlan_id'];
}

if (isset($data['camera_grid'])) {
    $configData['protect']['camera_grid'] = $data['camera_grid'];
}

if (file_put_contents($configFile, json_encode($configData, JSON_PRETTY_PRINT))) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to write config']);
}
