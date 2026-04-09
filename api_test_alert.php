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

$channel = $_GET['channel'] ?? 'all';

$subject = "Test MiniDash Alert";
$message = "To jest testowy alert z MiniDash v" . MINIDASH_VERSION . " wysłany " . date('Y-m-d H:i:s');

$results = [];

if ($channel === 'all' || $channel === 'ntfy') {
    if ($config['ntfy_notifications']['enabled'] ?? false) {
        sendPushNotification($subject, $message);
        $results[] = 'ntfy: sent to topic ' . ($config['ntfy_notifications']['topic'] ?? '?');
    } else {
        $results[] = 'ntfy: disabled';
    }
}

if ($channel === 'all' || $channel === 'discord') {
    if ($config['discord_notifications']['enabled'] ?? false) {
        sendDiscordNotification($message);
        $results[] = 'discord: sent';
    } else {
        $results[] = 'discord: disabled';
    }
}

if ($channel === 'all' || $channel === 'telegram') {
    if ($config['telegram_notifications']['enabled'] ?? false) {
        sendTelegramNotification($message);
        $results[] = 'telegram: sent';
    } else {
        $results[] = 'telegram: disabled';
    }
}

if ($channel === 'all' || $channel === 'slack') {
    if ($config['slack_notifications']['enabled'] ?? false) {
        sendSlackNotification($message);
        $results[] = 'slack: sent';
    } else {
        $results[] = 'slack: disabled';
    }
}

echo json_encode(['success' => true, 'results' => $results, 'message' => $message]);
