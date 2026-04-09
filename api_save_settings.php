<?php
/** Created by Łukasz Misiura (c) 2026 | www.lm-ads.com **/
require_once 'config.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid method']);
    exit;
}

$newConfig = [
    'email_notifications' => [
        'enabled' => isset($_POST['email_enabled']) && ($_POST['email_enabled'] === 'true' || $_POST['email_enabled'] === 'on'),
        'smtp_host' => $_POST['email_host'] ?? '',
        'smtp_port' => (int)($_POST['email_port'] ?? 587),
        'smtp_username' => $_POST['email_user'] ?? '',
        'smtp_password' => $_POST['email_pass'] ?? '',
        'from_email' => $_POST['email_from'] ?? '',
        'to_email' => $_POST['email_to'] ?? ''
    ],
    'telegram_notifications' => [
        'enabled' => isset($_POST['tg_enabled']) && ($_POST['tg_enabled'] === 'true' || $_POST['tg_enabled'] === 'on'),
        'bot_token' => $_POST['tg_token'] ?? '',
        'chat_id' => $_POST['tg_chatid'] ?? ''
    ],
    'whatsapp_notifications' => [
        'enabled' => isset($_POST['wa_enabled']) && ($_POST['wa_enabled'] === 'true' || $_POST['wa_enabled'] === 'on'),
        'api_url' => $_POST['wa_url'] ?? '',
        'api_key' => $_POST['wa_key'] ?? '',
        'phone_number' => $_POST['wa_phone'] ?? ''
    ],
    'slack_notifications' => [
        'enabled' => isset($_POST['slack_enabled']) && ($_POST['slack_enabled'] === 'true' || $_POST['slack_enabled'] === 'on'),
        'webhook_url' => $_POST['slack_url'] ?? ''
    ],
    'sms_notifications' => [
        'enabled' => isset($_POST['sms_enabled']) && ($_POST['sms_enabled'] === 'true' || $_POST['sms_enabled'] === 'on'),
        'api_url' => $_POST['sms_url'] ?? '',
        'api_key' => $_POST['sms_key'] ?? '',
        'to_number' => $_POST['sms_phone'] ?? ''
    ],
    'ntfy_notifications' => [
        'enabled' => isset($_POST['ntfy_enabled']) && ($_POST['ntfy_enabled'] === 'true' || $_POST['ntfy_enabled'] === 'on'),
        'topic' => $_POST['ntfy_topic'] ?? '',
        'server' => $_POST['ntfy_server'] ?? 'https://ntfy.sh'
    ],
    'triggers' => [
        'speed_alert_enabled' => isset($_POST['speed_alert_enabled']) && ($_POST['speed_alert_enabled'] === 'true' || $_POST['speed_alert_enabled'] === 'on'),
        'speed_threshold_mbps' => (int)($_POST['speed_threshold_mbps'] ?? 100)
    ]
];

$configFile = __DIR__ . '/data/config.json';
$existing = file_exists($configFile) ? json_decode(file_get_contents($configFile), true) : [];
if (!is_array($existing)) $existing = [];

$finalConfig = array_replace_recursive($existing, $newConfig);

require_once __DIR__ . '/crypto.php';
encrypt_config($finalConfig);

if (file_put_contents($configFile, json_encode($finalConfig, JSON_PRETTY_PRINT))) {
    echo json_encode(['success' => true]);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to write config file']);
}




