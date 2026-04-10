<?php
/** Created by Łukasz Misiura (c) 2025 | dev.lm-ads.com **/
define('MINIDASH_VERSION', '2.1.0');
error_reporting(E_ALL & ~E_NOTICE);
ini_set('display_errors', 1);

// Load .env file
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (str_starts_with(trim($line), '#')) continue;
        if (strpos($line, '=') === false) continue;
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if (!array_key_exists($key, $_ENV)) {
            $_ENV[$key] = $value;
            putenv("$key=$value");
        }
    }
}

require_once __DIR__ . '/crypto.php';

// Konfiguracja UniFi - wartości z .env, fallback na puste
$config = [
    'controller_url' => $_ENV['UNIFI_CONTROLLER_URL'] ?? 'https://192.168.1.1',
    'api_key' => $_ENV['UNIFI_API_KEY'] ?? '',
    'site' => $_ENV['UNIFI_SITE'] ?? 'default',
    'admin_username' => $_ENV['ADMIN_USERNAME'] ?? 'admin',
    'admin_password' => $_ENV['ADMIN_PASSWORD'] ?? '',
    'admin_full_name' => $_ENV['ADMIN_FULL_NAME'] ?? '',
    'admin_email' => $_ENV['ADMIN_EMAIL'] ?? '',
    'admin_avatar' => '', // Empty means use default icon
    'email_notifications' => [
        'enabled' => false,
        'smtp_host' => 'smtp.gmail.com',
        'smtp_port' => 587,
        'smtp_username' => '',
        'smtp_password' => '',
        'from_email' => '',
        'to_email' => ''
    ],
    'telegram_notifications' => [
        'enabled' => false,
        'bot_token' => '',
        'chat_id' => ''
    ],
    'whatsapp_notifications' => [
        'enabled' => false,
        'api_url' => '',
        'api_key' => '',
        'phone_number' => ''
    ],
    'slack_notifications' => [
        'enabled' => false,
        'webhook_url' => ''
    ],
    'sms_notifications' => [
        'enabled' => false,
        'api_url' => '',
        'api_key' => '',
        'to_number' => ''
    ],
    'ntfy_notifications' => [
        'enabled' => false,
        'topic' => '',
        'server' => 'https://ntfy.sh'
    ],
    'discord_notifications' => [
        'enabled' => false,
        'webhook_url' => '',
        'username' => 'MiniDash'
    ],
    'n8n_notifications' => [
        'enabled' => false,
        'webhook_url' => ''
    ],
    'triggers' => [
        'speed_alert_enabled' => false,
        'speed_threshold_mbps' => 100,
        'new_device_alert_enabled' => false,
        'ips_alert_enabled' => false,
        'latency_alert_enabled' => false,
        'latency_threshold_ms' => 100
    ],
    'protect' => [
        'enabled' => null, // null = auto-detect
        'vlan_id' => 40,
        'camera_grid' => []
    ],
    'debug' => filter_var($_ENV['DEBUG'] ?? 'false', FILTER_VALIDATE_BOOLEAN)
];

/**
 * Zczytuje dane z config.json i nakłada na domyślne $config
 */
function load_app_config($defaults) {
    $configFile = __DIR__ . '/data/config.json';
    if (file_exists($configFile)) {
        $dynamic = json_decode(file_get_contents($configFile), true);
        if (is_array($dynamic)) {
            $merged = array_replace_recursive($defaults, $dynamic);
            decrypt_config($merged);
            return $merged;
        }
    }
    return $defaults;
}

$config = load_app_config($config);

// Dynamiczna inicjalizacja tematów ntfy jeśli brakuje
if (empty($config['ntfy_notifications']['topic']) && !empty($config['api_key'])) {
    $config['ntfy_notifications']['topic'] = 'minidash_alerts_' . substr(md5($config['api_key'] ?? 'default'), 0, 8);
}

// Global notification function
function sendAlert($subject, $message) {
    global $config;
    
    if ($config['email_notifications']['enabled']) {
        sendEmailNotification($subject, $message);
    }
    
    if ($config['telegram_notifications']['enabled']) {
        sendTelegramNotification($message);
    }

    if ($config['whatsapp_notifications'] && $config['whatsapp_notifications']['enabled']) {
        sendWhatsAppNotification($message);
    }

    if ($config['slack_notifications'] && $config['slack_notifications']['enabled']) {
        sendSlackNotification($message);
    }

    if ($config['sms_notifications'] && $config['sms_notifications']['enabled']) {
        sendSmsNotification($message);
    }

    if ($config['ntfy_notifications'] && $config['ntfy_notifications']['enabled']) {
        sendPushNotification($subject, $message);
    }

    if ($config['discord_notifications'] && $config['discord_notifications']['enabled']) {
        sendDiscordNotification($message);
    }

    if ($config['n8n_notifications'] && $config['n8n_notifications']['enabled']) {
        sendN8nNotification($message);
    }

    // Log alert to SQLite events table for in-app notification panel
    global $db;
    if (isset($db)) {
        try {
            $stmt = $db->prepare("INSERT INTO events (type, severity, message, details_json) VALUES (?, ?, ?, ?)");
            $stmt->execute(['alert', 'WARNING', $subject, json_encode(['message' => $message])]);
        } catch (Exception $e) {
            // Silently fail — don't break alert sending
        }
    }
}

function sendPushNotification($subject, $message) {
    global $config;
    $server = rtrim($config['ntfy_notifications']['server'] ?? 'https://ntfy.sh', '/');
    $topic = $config['ntfy_notifications']['topic'] ?? '';
    
    if (empty($topic)) return;

    $url = "$server/$topic";
    
    $cleanMessage = str_replace(['**', '🔴', '🟢'], ['', 'OFFLINE', 'ONLINE'], $message);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $cleanMessage);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Title: $subject",
        "Priority: high",
        "Tags: bell,robot"
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    @curl_exec($ch);
    curl_close($ch);
}

function sendEmailNotification($subject, $message) {
    global $config;
    $headers = "From: " . $config['email_notifications']['from_email'] . "\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    
    $htmlMessage = "<h2>MiniDash Alert</h2><p>$message</p>";
    
    @mail(
        $config['email_notifications']['to_email'],
        $subject,
        $htmlMessage,
        $headers
    );
}

function sendTelegramNotification($message) {
    global $config;
    $token = $config['telegram_notifications']['bot_token'];
    $chatId = $config['telegram_notifications']['chat_id'];
    
    if (empty($token) || empty($chatId)) return;
    
    $url = "https://api.telegram.org/bot$token/sendMessage";
    $data = [
        'chat_id' => $chatId,
        'text' => "🔔 MiniDash Alert:\n" . $message,
        'parse_mode' => 'Markdown'
    ];

    $options = [
        'http' => [
            'method'  => 'POST',
            'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => http_build_query($data)
        ]
    ];
    $context  = stream_context_create($options);
    @file_get_contents($url, false, $context);
}

function sendWhatsAppNotification($message) {
    global $config;
    $url = $config['whatsapp_notifications']['api_url'];
    $key = $config['whatsapp_notifications']['api_key'];
    $phone = $config['whatsapp_notifications']['phone_number'];
    
    if (empty($url) || empty($phone)) return;
    
    $data = json_encode([
        'phone' => $phone,
        'message' => "🔔 MiniDASH: " . $message
    ]);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Content-Type: application/json",
        "Authorization: Bearer $key"
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    @curl_exec($ch);
    curl_close($ch);
}

function sendSlackNotification($message) {
    global $config;
    $url = $config['slack_notifications']['webhook_url'];
    if (empty($url)) return;

    $data = json_encode(['text' => "🔔 *MiniDASH Alert*\n" . $message]);
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    @curl_exec($ch);
    curl_close($ch);
}

function sendSmsNotification($message) {
    global $config;
    $url = $config['sms_notifications']['api_url'];
    $key = $config['sms_notifications']['api_key'];
    $phone = $config['sms_notifications']['to_number'];

    if (empty($url) || empty($phone)) return;

    $data = [
        'to' => $phone,
        'message' => "MiniDASH Alert: " . $message,
        'api_key' => $key
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    @curl_exec($ch);
    curl_close($ch);
}

function sendDiscordNotification($message) {
    global $config;
    $url = $config['discord_notifications']['webhook_url'];
    if (empty($url)) return;

    $data = json_encode([
        'username' => $config['discord_notifications']['username'] ?? 'MiniDash',
        'embeds' => [[
            'title' => 'MiniDash Alert',
            'description' => strip_tags($message),
            'color' => 3447003,
            'timestamp' => date('c'),
            'footer' => ['text' => 'MiniDash v' . MINIDASH_VERSION]
        ]]
    ]);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    @curl_exec($ch);
    curl_close($ch);
}

function sendN8nNotification($message) {
    global $config;
    $url = $config['n8n_notifications']['webhook_url'];
    if (empty($url)) return;

    $data = json_encode([
        'source' => 'MiniDash',
        'version' => MINIDASH_VERSION,
        'message' => strip_tags($message),
        'severity' => 'alert',
        'timestamp' => date('c')
    ]);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    @curl_exec($ch);
    curl_close($ch);
}

// Inicjalizacja sesji
session_start();




