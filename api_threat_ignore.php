<?php
/** Created by Łukasz Misiura (c) 2025 | dev.lm-ads.com **/
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $stmt = $db->query("SELECT id, ip, label, reason, added_at FROM threat_ignore ORDER BY added_at DESC");
    echo json_encode(['data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $ip = trim($input['ip'] ?? '');
    $label = trim($input['label'] ?? '');
    $reason = trim($input['reason'] ?? '');

    if (empty($ip)) {
        http_response_code(400);
        echo json_encode(['error' => 'IP is required']);
        exit;
    }

    $stmt = $db->prepare("INSERT OR IGNORE INTO threat_ignore (ip, label, reason) VALUES (?, ?, ?)");
    $stmt->execute([$ip, $label, $reason]);
    echo json_encode(['success' => true, 'message' => "IP $ip added to ignore list"]);
    exit;
}

if ($method === 'DELETE') {
    $input = json_decode(file_get_contents('php://input'), true);
    $id = intval($input['id'] ?? 0);

    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'ID is required']);
        exit;
    }

    $db->prepare("DELETE FROM threat_ignore WHERE id = ?")->execute([$id]);
    echo json_encode(['success' => true]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
