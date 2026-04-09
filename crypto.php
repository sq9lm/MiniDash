<?php
/** Created by Łukasz Misiura (c) 2025 | dev.lm-ads.com **/
/**
 * Credential Encryption - MiniDash v2.0.0
 * Uses sodium_crypto_secretbox (PHP 8 built-in).
 */

define('ENCRYPTION_KEY_FILE', __DIR__ . '/data/.encryption_key');
define('ENC_PREFIX', 'ENC:');

function get_encryption_key(): string {
    if (file_exists(ENCRYPTION_KEY_FILE)) {
        $key = file_get_contents(ENCRYPTION_KEY_FILE);
        if (strlen($key) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            return $key;
        }
    }
    $key = sodium_crypto_secretbox_keygen();
    $dir = dirname(ENCRYPTION_KEY_FILE);
    if (!is_dir($dir)) mkdir($dir, 0700, true);
    file_put_contents(ENCRYPTION_KEY_FILE, $key);
    chmod(ENCRYPTION_KEY_FILE, 0600);
    return $key;
}

function encrypt_value(string $plaintext): string {
    if (str_starts_with($plaintext, ENC_PREFIX)) return $plaintext;
    if (empty($plaintext)) return $plaintext;
    $key = get_encryption_key();
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $cipher = sodium_crypto_secretbox($plaintext, $nonce, $key);
    return ENC_PREFIX . base64_encode($nonce . $cipher);
}

function decrypt_value(string $encrypted): string {
    if (!str_starts_with($encrypted, ENC_PREFIX)) return $encrypted;
    if (empty($encrypted)) return $encrypted;
    $key = get_encryption_key();
    $decoded = base64_decode(substr($encrypted, strlen(ENC_PREFIX)));
    if ($decoded === false) return $encrypted;
    $nonce = substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $cipher = substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $plaintext = sodium_crypto_secretbox_open($cipher, $nonce, $key);
    if ($plaintext === false) return $encrypted;
    return $plaintext;
}

define('ENCRYPTED_FIELDS', [
    'api_key',
    'admin_password',
    'email_notifications.smtp_password',
    'telegram_notifications.bot_token',
    'whatsapp_notifications.api_key',
    'slack_notifications.webhook_url',
    'sms_notifications.api_key',
    'discord_notifications.webhook_url',
    'n8n_notifications.webhook_url',
]);

function encrypt_config(array &$config): void {
    foreach (ENCRYPTED_FIELDS as $path) {
        $parts = explode('.', $path);
        if (count($parts) === 1) {
            if (isset($config[$parts[0]]) && !empty($config[$parts[0]])) {
                $config[$parts[0]] = encrypt_value($config[$parts[0]]);
            }
        } elseif (count($parts) === 2) {
            if (isset($config[$parts[0]][$parts[1]]) && !empty($config[$parts[0]][$parts[1]])) {
                $config[$parts[0]][$parts[1]] = encrypt_value($config[$parts[0]][$parts[1]]);
            }
        }
    }
}

function decrypt_config(array &$config): void {
    foreach (ENCRYPTED_FIELDS as $path) {
        $parts = explode('.', $path);
        if (count($parts) === 1) {
            if (isset($config[$parts[0]])) {
                $config[$parts[0]] = decrypt_value($config[$parts[0]]);
            }
        } elseif (count($parts) === 2) {
            if (isset($config[$parts[0]][$parts[1]])) {
                $config[$parts[0]][$parts[1]] = decrypt_value($config[$parts[0]][$parts[1]]);
            }
        }
    }
}
