<?php
/** Created by Łukasz Misiura (c) 2025 | dev.lm-ads.com **/
define('MINIDASH_VERSION', '2.3.1');
error_reporting(E_ALL & ~E_NOTICE);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/logs/php_errors.log');

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
        $_ENV[$key] = $value;
        putenv("$key=$value");
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
        'latency_threshold_ms' => 100,
        'vpn_alert_enabled' => false
    ],
    'protect' => [
        'enabled' => null, // null = auto-detect
        'vlan_id' => 40,
        'camera_grid' => []
    ],
    'language' => 'pl',
    'debug' => filter_var($_ENV['DEBUG'] ?? 'false', FILTER_VALIDATE_BOOLEAN)
];

// ── i18n ──────────────────────────────────────────────
$_lang = [];

function load_language(string $lang = 'pl'): void {
    global $_lang;
    $file = __DIR__ . "/lang/{$lang}.json";
    if (!file_exists($file)) $file = __DIR__ . '/lang/pl.json';
    $_lang = json_decode(file_get_contents($file), true) ?? [];
}

function __(string $key, array $params = []): string {
    global $_lang;
    $parts = explode('.', $key);
    $val = $_lang;
    foreach ($parts as $p) {
        if (!is_array($val) || !isset($val[$p])) return $key;
        $val = $val[$p];
    }
    if (!is_string($val)) return $key;
    foreach ($params as $k => $v) {
        $val = str_replace('{' . $k . '}', $v, $val);
    }
    return $val;
}

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

// Load language: cookie > config > console > fallback pl
$_active_lang = $_COOKIE['minidash_lang'] ?? $config['language'] ?? 'pl';
if ($_active_lang === 'auto') {
    // Try console language, fallback pl
    $_active_lang = 'pl'; // will be overridden by console if available
}
load_language($_active_lang);

// Dynamiczna inicjalizacja tematów ntfy jeśli brakuje
if (empty($config['ntfy_notifications']['topic']) && !empty($config['api_key'])) {
    $config['ntfy_notifications']['topic'] = 'minidash_alerts_' . substr(md5($config['api_key'] ?? 'default'), 0, 8);
}

// Global notification function
// Severity: 'critical' (red), 'warning' (orange), 'info' (green)
function sendAlert($subject, $message, $severity = 'info') {
    global $config;
    
    if ($config['email_notifications']['enabled']) {
        sendEmailNotification($subject, $message);
    }
    
    $full_message = $subject . "\n" . $message;

    if ($config['telegram_notifications']['enabled']) {
        sendTelegramNotification($full_message, $severity);
    }

    if ($config['whatsapp_notifications'] && $config['whatsapp_notifications']['enabled']) {
        sendWhatsAppNotification($full_message);
    }

    if ($config['slack_notifications'] && $config['slack_notifications']['enabled']) {
        sendSlackNotification($full_message);
    }

    if ($config['sms_notifications'] && $config['sms_notifications']['enabled']) {
        sendSmsNotification($full_message);
    }

    if ($config['ntfy_notifications'] && $config['ntfy_notifications']['enabled']) {
        sendPushNotification($subject, $message);
    }

    if ($config['discord_notifications'] && $config['discord_notifications']['enabled']) {
        sendDiscordNotification($full_message);
    }

    if ($config['n8n_notifications'] && $config['n8n_notifications']['enabled']) {
        sendN8nNotification($full_message);
    }

    // Log alert to SQLite events table for in-app notification panel
    global $db;
    if (isset($db)) {
        try {
            $severity_upper = strtoupper($severity);
            $stmt = $db->prepare("INSERT INTO events (type, severity, message, details_json) VALUES (?, ?, ?, ?)");
            $stmt->execute(['alert', $severity_upper, $subject, json_encode(['message' => $message, 'full' => $full_message])]);
        } catch (Exception $e) {
            error_log("MiniDash: Failed to log event: " . $e->getMessage());
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

function sendTelegramNotification($message, $severity = 'info') {
    global $config;
    $token = $config['telegram_notifications']['bot_token'];
    $chatId = $config['telegram_notifications']['chat_id'];

    if (empty($token) || empty($chatId)) return;

    $icons = ['critical' => '🔴', 'warning' => '🟠', 'info' => '🟢'];
    $icon = $icons[$severity] ?? '🔔';

    $url = "https://api.telegram.org/bot$token/sendMessage";
    $data = [
        'chat_id' => $chatId,
        'text' => "$icon MiniDash:\n" . $message,
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

// Inicjalizacja sesji z bezpiecznymi ustawieniami
$session_timeout = $config['session_timeout'] ?? 60; // minutes
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_samesite', 'Strict');
ini_set('session.use_strict_mode', 1);
ini_set('session.sid_length', 48);
ini_set('session.sid_bits_per_character', 6);
ini_set('session.cookie_lifetime', 0); // Session cookie (expires when browser closes)
ini_set('session.gc_maxlifetime', $session_timeout * 60);
if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
    ini_set('session.cookie_secure', 1);
}
session_start();

// Session timeout check
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $session_timeout * 60)) {
    session_unset();
    session_destroy();
    session_start();
}
$_SESSION['last_activity'] = time();

// Security headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');
if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

// Session fingerprint — bind session to browser + IP to prevent hijacking
$fingerprint = hash('sha256', ($_SERVER['HTTP_USER_AGENT'] ?? '') . ($_SERVER['REMOTE_ADDR'] ?? ''));
if (isset($_SESSION['fingerprint']) && $_SESSION['fingerprint'] !== $fingerprint) {
    // Session hijacking detected — destroy session
    session_unset();
    session_destroy();
    session_start();
} else {
    $_SESSION['fingerprint'] = $fingerprint;
}

// Remember Me — auto-login from persistent cookie
if (empty($_SESSION['logged_in']) && !empty($_COOKIE['remember_me'])) {
    $parts = explode(':', $_COOKIE['remember_me'], 2);
    if (count($parts) === 2) {
        [$selector, $validator] = $parts;
        $db_path_rm = __DIR__ . '/data/minidash.db';
        if (file_exists($db_path_rm)) {
            try {
                $db_rm = new PDO("sqlite:$db_path_rm");
                $db_rm->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $stmt_rm = $db_rm->prepare("SELECT * FROM remember_tokens WHERE selector = ? AND expires_at > datetime('now') LIMIT 1");
                $stmt_rm->execute([$selector]);
                $token_row = $stmt_rm->fetch(PDO::FETCH_ASSOC);

                if ($token_row && hash_equals($token_row['validator_hash'], hash('sha256', $validator))) {
                    session_regenerate_id(true);
                    $_SESSION['logged_in'] = true;
                    $_SESSION['username'] = $token_row['username'];
                    $_SESSION['last_login_time'] = time();
                    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                    $_SESSION['fingerprint'] = $fingerprint;

                    // Rotate token — delete old, issue new
                    $db_rm->prepare("DELETE FROM remember_tokens WHERE selector = ?")->execute([$selector]);
                    $new_selector = bin2hex(random_bytes(16));
                    $new_validator = bin2hex(random_bytes(32));
                    $new_expires = date('Y-m-d H:i:s', time() + 30 * 86400);
                    $db_rm->prepare("INSERT INTO remember_tokens (selector, validator_hash, username, expires_at) VALUES (?, ?, ?, ?)")
                           ->execute([$new_selector, hash('sha256', $new_validator), $token_row['username'], $new_expires]);

                    $secure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on';
                    setcookie('remember_me', $new_selector . ':' . $new_validator, [
                        'expires'  => time() + 30 * 86400,
                        'path'     => '/',
                        'secure'   => $secure,
                        'httponly'  => true,
                        'samesite' => 'Strict',
                    ]);
                } else {
                    // Invalid token — clear cookie
                    setcookie('remember_me', '', ['expires' => 1, 'path' => '/']);
                }
                $db_rm = null;
            } catch (PDOException $e) {
                // DB not ready yet — ignore
            }
        }
    }
}




