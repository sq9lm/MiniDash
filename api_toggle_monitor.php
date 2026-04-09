<?php
/** Created by Łukasz Misiura (c) 2025 | dev.lm-ads.com **/
require_once 'config.php';
require_once 'functions.php';

ob_start();
header('Content-Type: application/json');

// Sprawdzenie czy użytkownik jest zalogowany
if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    
    $action = $data['action'] ?? '';
    $mac = normalize_mac($data['mac'] ?? '');
    $name = $data['name'] ?? '';
    $vlan = $data['vlan'] ?? null;
    
    if (!$mac) {
        ob_clean();
        echo json_encode(['success' => false, 'message' => 'Missing MAC address']);
        exit;
    }

    $devices = loadDevices();

    if ($action === 'add') {
        if (!$name) {
            ob_clean();
            echo json_encode(['success' => false, 'message' => 'Missing name']);
            exit;
        }
        
        $devices[$mac] = [
            'name' => $name,
            'vlan' => $vlan,
            'added_at' => date('Y-m-d H:i:s')
        ];
        saveDevices($devices);
        ob_clean();
        echo json_encode(['success' => true, 'message' => 'Device added to monitoring']);
        exit;
    } 
    elseif ($action === 'delete') {
        if (isset($devices[$mac])) {
            unset($devices[$mac]);
            saveDevices($devices);
            
            // Also remove from history
            $historyFile = __DIR__ . '/data/history.json';
            if (file_exists($historyFile)) {
                $allHistory = json_decode(file_get_contents($historyFile), true);
                if (isset($allHistory[$mac])) {
                    unset($allHistory[$mac]);
                    file_put_contents($historyFile, json_encode($allHistory, JSON_PRETTY_PRINT));
                }
            }
            
            ob_clean();
            echo json_encode(['success' => true, 'message' => 'Device and history removed']);
            exit;
        }
    }
}

ob_clean();
echo json_encode(['success' => false, 'message' => 'Invalid request']);




