<?php
/**
 * MiniDash — Test Runner
 * Run via browser: https://your-host/tests/run_all.php
 */
header('Content-Type: text/plain; charset=utf-8');

echo "╔══════════════════════════════════════╗\n";
echo "║     MiniDash Test Suite v2.1.1       ║\n";
echo "╚══════════════════════════════════════╝\n";

// Bootstrap once
require_once __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__) . '/crypto.php';

$total_pass = 0;
$total_fail = 0;

function t_assert(string $test, $expected, $actual, int &$pass, int &$fail): void {
    if ($expected === $actual) {
        echo "  PASS: {$test}\n";
        $pass++;
    } else {
        echo "  FAIL: {$test} — expected " . var_export($expected, true) . ", got " . var_export($actual, true) . "\n";
        $fail++;
    }
}

// ═══════════════════════════════════════
echo "\n=== FormattersTest ===\n";

echo "\n-- formatDuration --\n";
t_assert('0 seconds', '0s', formatDuration(0), $total_pass, $total_fail);
t_assert('30 seconds', '30s', formatDuration(30), $total_pass, $total_fail);
t_assert('59 seconds', '59s', formatDuration(59), $total_pass, $total_fail);
t_assert('1 minute', '1m', formatDuration(60), $total_pass, $total_fail);
t_assert('5 minutes', '5m', formatDuration(300), $total_pass, $total_fail);
t_assert('59 minutes', '59m', formatDuration(3540), $total_pass, $total_fail);
t_assert('1 hour 0 min', '1h 0m', formatDuration(3600), $total_pass, $total_fail);
t_assert('2h 30m', '2h 30m', formatDuration(9000), $total_pass, $total_fail);
t_assert('23h 59m', '23h 59m', formatDuration(86340), $total_pass, $total_fail);
t_assert('1 day', '1d 0h', formatDuration(86400), $total_pass, $total_fail);
t_assert('3d 5h', '3d 5h', formatDuration(277200), $total_pass, $total_fail);
t_assert('30 days', '30d 0h', formatDuration(2592000), $total_pass, $total_fail);

echo "\n-- format_bps --\n";
t_assert('0 bps', '0 bps', format_bps(0), $total_pass, $total_fail);
t_assert('500 bps', '500 bps', format_bps(500), $total_pass, $total_fail);
t_assert('1 Kbps', '1.0 Kbps', format_bps(1000), $total_pass, $total_fail);
t_assert('1.5 Kbps', '1.5 Kbps', format_bps(1500), $total_pass, $total_fail);
t_assert('1 Mbps', '1.0 Mbps', format_bps(1000000), $total_pass, $total_fail);
t_assert('100 Mbps', '100.0 Mbps', format_bps(100000000), $total_pass, $total_fail);
t_assert('1 Gbps', '1.00 Gbps', format_bps(1000000000), $total_pass, $total_fail);
t_assert('1 Tbps', '1.00 Tbps', format_bps(1000000000000), $total_pass, $total_fail);
t_assert('formatBps alias', format_bps(1500000), formatBps(1500000), $total_pass, $total_fail);

echo "\n-- format_bytes --\n";
t_assert('0 bytes', '0 B', format_bytes(0), $total_pass, $total_fail);
t_assert('1 KB', '1 KB', format_bytes(1024), $total_pass, $total_fail);
t_assert('1 MB', '1 MB', format_bytes(1048576), $total_pass, $total_fail);
t_assert('1 GB', '1 GB', format_bytes(1073741824), $total_pass, $total_fail);
t_assert('1 TB', '1 TB', format_bytes(1099511627776), $total_pass, $total_fail);

// ═══════════════════════════════════════
echo "\n=== MacNormalizationTest ===\n";
t_assert('colon lower', 'aabbccddeeff', normalize_mac('aa:bb:cc:dd:ee:ff'), $total_pass, $total_fail);
t_assert('colon upper', 'aabbccddeeff', normalize_mac('AA:BB:CC:DD:EE:FF'), $total_pass, $total_fail);
t_assert('dash separated', 'aabbccddeeff', normalize_mac('AA-BB-CC-DD-EE-FF'), $total_pass, $total_fail);
t_assert('already normalized', 'aabbccddeeff', normalize_mac('aabbccddeeff'), $total_pass, $total_fail);
t_assert('empty', '', normalize_mac(''), $total_pass, $total_fail);
t_assert('null', '', normalize_mac(null), $total_pass, $total_fail);
t_assert('mixed separators', 'aabbccddeeff', normalize_mac('AA:BB-CC:DD-EE:FF'), $total_pass, $total_fail);

// ═══════════════════════════════════════
echo "\n=== I18nTest ===\n";

echo "\n-- PL --\n";
load_language('pl');
t_assert('PL nav.dashboard', 'Dashboard', __('nav.dashboard'), $total_pass, $total_fail);
t_assert('PL common.save', 'Zapisz', __('common.save'), $total_pass, $total_fail);
t_assert('PL common.close', 'Zamknij', __('common.close'), $total_pass, $total_fail);
t_assert('PL login.username', 'Użytkownik', __('login.username'), $total_pass, $total_fail);

echo "\n-- EN --\n";
load_language('en');
t_assert('EN nav.dashboard', 'Dashboard', __('nav.dashboard'), $total_pass, $total_fail);
t_assert('EN common.save', 'Save', __('common.save'), $total_pass, $total_fail);
t_assert('EN common.close', 'Close', __('common.close'), $total_pass, $total_fail);
t_assert('EN login.username', 'Username', __('login.username'), $total_pass, $total_fail);
t_assert('EN footer.rights', 'All rights reserved', __('footer.rights'), $total_pass, $total_fail);

echo "\n-- missing keys --\n";
t_assert('nonexistent returns key', 'foo.bar.baz', __('foo.bar.baz'), $total_pass, $total_fail);
t_assert('partial returns key', 'nav.nonexistent', __('nav.nonexistent'), $total_pass, $total_fail);

echo "\n-- params --\n";
load_language('pl');
$result = __('alerts.speed_spike_body', ['name' => 'TestDev', 'speed' => '50 Mbps', 'threshold' => '100']);
t_assert('param name', true, strpos($result, 'TestDev') !== false, $total_pass, $total_fail);
t_assert('param speed', true, strpos($result, '50 Mbps') !== false, $total_pass, $total_fail);

echo "\n-- lang completeness --\n";
$root = dirname(__DIR__);
$pl = json_decode(file_get_contents($root . '/lang/pl.json'), true);
$en = json_decode(file_get_contents($root . '/lang/en.json'), true);
t_assert('pl.json valid', true, is_array($pl), $total_pass, $total_fail);
t_assert('en.json valid', true, is_array($en), $total_pass, $total_fail);

$missing = [];
foreach ($pl as $section => $keys) {
    if (!is_array($keys)) continue;
    foreach (array_keys($keys) as $key) {
        if (!isset($en[$section][$key])) $missing[] = "{$section}.{$key}";
    }
}
if (count($missing) > 0) {
    echo "  WARN: missing EN keys: " . implode(', ', array_slice($missing, 0, 10)) . "\n";
}
t_assert('EN covers all PL keys', 0, count($missing), $total_pass, $total_fail);

// ═══════════════════════════════════════
echo "\n=== CryptoTest ===\n";
$plain = 'my-secret-api-key-12345';
$encrypted = encrypt_value($plain);
$decrypted = decrypt_value($encrypted);
t_assert('roundtrip', $plain, $decrypted, $total_pass, $total_fail);
t_assert('encrypted differs', true, $encrypted !== $plain, $total_pass, $total_fail);
t_assert('has ENC: prefix', true, str_starts_with($encrypted, 'ENC:'), $total_pass, $total_fail);
t_assert('empty unchanged', '', encrypt_value(''), $total_pass, $total_fail);
$enc2 = encrypt_value($encrypted);
t_assert('double encrypt idempotent', $encrypted, $enc2, $total_pass, $total_fail);

$cfg = [
    'api_key' => 'test-key',
    'admin_password' => 'test-pass',
    'telegram_notifications' => ['bot_token' => 'bot123'],
];
$orig = $cfg;
encrypt_config($cfg);
t_assert('config api_key encrypted', true, str_starts_with($cfg['api_key'], 'ENC:'), $total_pass, $total_fail);
t_assert('config password encrypted', true, str_starts_with($cfg['admin_password'], 'ENC:'), $total_pass, $total_fail);
decrypt_config($cfg);
t_assert('config api_key restored', $orig['api_key'], $cfg['api_key'], $total_pass, $total_fail);
t_assert('config password restored', $orig['admin_password'], $cfg['admin_password'], $total_pass, $total_fail);

// ═══════════════════════════════════════
echo "\n=== NetworkTest ===\n";
t_assert('10.0.0.1 in /24', true, ip_in_subnet('10.0.0.1', '10.0.0.0/24'), $total_pass, $total_fail);
t_assert('10.0.1.1 NOT in /24', false, ip_in_subnet('10.0.1.1', '10.0.0.0/24'), $total_pass, $total_fail);
t_assert('192.168.1.100 in /16', true, ip_in_subnet('192.168.1.100', '192.168.0.0/16'), $total_pass, $total_fail);
t_assert('exact /32', true, ip_in_subnet('10.0.0.1', '10.0.0.1/32'), $total_pass, $total_fail);
t_assert('miss /32', false, ip_in_subnet('10.0.0.2', '10.0.0.1/32'), $total_pass, $total_fail);
t_assert('10.x in /8', true, ip_in_subnet('10.255.255.1', '10.0.0.0/8'), $total_pass, $total_fail);
t_assert('11.x NOT in /8', false, ip_in_subnet('11.0.0.1', '10.0.0.0/8'), $total_pass, $total_fail);

echo "\n-- CSRF --\n";
$token = csrf_token();
t_assert('token not empty', true, !empty($token), $total_pass, $total_fail);
t_assert('token consistent', $token, csrf_token(), $total_pass, $total_fail);
t_assert('verify valid', true, verify_csrf($token), $total_pass, $total_fail);
t_assert('verify invalid', false, verify_csrf('bad-token'), $total_pass, $total_fail);

// ═══════════════════════════════════════
echo "\n=== CacheTest ===\n";
minidash_cache_set('_test_unit', ['foo' => 'bar']);
$cached = minidash_cache_get('_test_unit', 60);
t_assert('cache hit', 'bar', $cached['foo'] ?? null, $total_pass, $total_fail);
t_assert('cache miss', null, minidash_cache_get('_no_exist_xyz', 60), $total_pass, $total_fail);
$complex = ['a' => ['b' => 'c'], 'count' => 42];
minidash_cache_set('_test_complex', $complex);
$r = minidash_cache_get('_test_complex', 60);
t_assert('complex nested', 'c', $r['a']['b'] ?? null, $total_pass, $total_fail);
t_assert('complex count', 42, $r['count'] ?? null, $total_pass, $total_fail);
// Cleanup
@unlink(dirname(__DIR__) . '/data/cache_' . md5('_test_unit') . '.json');
@unlink(dirname(__DIR__) . '/data/cache_' . md5('_test_complex') . '.json');

// ═══════════════════════════════════════
echo "\n╔══════════════════════════════════════╗\n";
$line = "  TOTAL: {$total_pass} passed, {$total_fail} failed";
echo "║{$line}" . str_repeat(' ', 38 - strlen($line)) . "║\n";
echo "╚══════════════════════════════════════╝\n";
