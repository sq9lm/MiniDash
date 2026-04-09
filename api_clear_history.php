<?php
/** Created by Łukasz Misiura (c) 2025 | dev.lm-ads.com **/
require_once 'config.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid method']);
    exit;
}

$historyFile = __DIR__ . '/data/history.json';

$configFile = __DIR__ . '/data/config.json';
$configData = [];
if (file_exists($configFile)) {
    $configData = json_decode(file_get_contents($configFile), true) ?? [];
}

$configData['last_notif_clear_time'] = date('Y-m-d H:i:s');

if (file_put_contents($configFile, json_encode($configData, JSON_PRETTY_PRINT))) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to update config']);
}




