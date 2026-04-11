<?php
/**
 * MiniDash — Encryption Tests
 * Tests: encrypt_value, decrypt_value, encrypt_config, decrypt_config
 */

require_once __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__) . '/crypto.php';

class CryptoTest
{
    private int $passed = 0;
    private int $failed = 0;

    public function run(): void
    {
        echo "\n=== CryptoTest ===\n";
        $this->test_encrypt_decrypt_roundtrip();
        $this->test_empty_value();
        $this->test_already_encrypted();
        $this->test_enc_prefix();
        $this->test_config_encrypt_decrypt();
        echo "\nResults: {$this->passed} passed, {$this->failed} failed\n";
    }

    private function assert(string $test, $expected, $actual): void
    {
        if ($expected === $actual) {
            echo "  PASS: {$test}\n";
            $this->passed++;
        } else {
            echo "  FAIL: {$test} — expected '{$expected}', got '{$actual}'\n";
            $this->failed++;
        }
    }

    private function test_encrypt_decrypt_roundtrip(): void
    {
        echo "\n-- encrypt/decrypt roundtrip --\n";
        $plain = 'my-secret-api-key-12345';
        $encrypted = encrypt_value($plain);
        $decrypted = decrypt_value($encrypted);
        $this->assert('roundtrip matches', $plain, $decrypted);
        $this->assert('encrypted differs from plain', true, $encrypted !== $plain);
    }

    private function test_empty_value(): void
    {
        echo "\n-- empty values --\n";
        $this->assert('empty string unchanged', '', encrypt_value(''));
        $this->assert('decrypt empty', '', decrypt_value(''));
    }

    private function test_already_encrypted(): void
    {
        echo "\n-- already encrypted --\n";
        $plain = 'test-value';
        $enc1 = encrypt_value($plain);
        $enc2 = encrypt_value($enc1); // encrypt again
        $this->assert('double encrypt returns same', $enc1, $enc2);
        $this->assert('decrypt still works', $plain, decrypt_value($enc2));
    }

    private function test_enc_prefix(): void
    {
        echo "\n-- ENC: prefix --\n";
        $encrypted = encrypt_value('hello');
        $this->assert('has ENC: prefix', true, str_starts_with($encrypted, 'ENC:'));
        $this->assert('plain has no prefix', false, str_starts_with('hello', 'ENC:'));
    }

    private function test_config_encrypt_decrypt(): void
    {
        echo "\n-- config encrypt/decrypt --\n";
        $config = [
            'api_key' => 'test-api-key',
            'admin_password' => 'test-pass',
            'telegram_notifications' => ['bot_token' => 'bot123:ABC'],
            'slack_notifications' => ['webhook_url' => 'https://hooks.slack.com/xxx'],
        ];
        $original = $config;

        encrypt_config($config);
        $this->assert('api_key encrypted', true, str_starts_with($config['api_key'], 'ENC:'));
        $this->assert('admin_password encrypted', true, str_starts_with($config['admin_password'], 'ENC:'));
        $this->assert('telegram token encrypted', true, str_starts_with($config['telegram_notifications']['bot_token'], 'ENC:'));
        $this->assert('slack webhook encrypted', true, str_starts_with($config['slack_notifications']['webhook_url'], 'ENC:'));

        decrypt_config($config);
        $this->assert('api_key decrypted', $original['api_key'], $config['api_key']);
        $this->assert('admin_password decrypted', $original['admin_password'], $config['admin_password']);
        $this->assert('telegram token decrypted', $original['telegram_notifications']['bot_token'], $config['telegram_notifications']['bot_token']);
        $this->assert('slack webhook decrypted', $original['slack_notifications']['webhook_url'], $config['slack_notifications']['webhook_url']);
    }
}

$t = new CryptoTest();
$t->run();
