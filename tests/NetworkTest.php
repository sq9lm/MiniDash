<?php
/**
 * MiniDash — Network Utility Tests
 * Tests: ip_in_subnet, CSRF tokens
 */

require_once __DIR__ . '/bootstrap.php';

class NetworkTest
{
    private int $passed = 0;
    private int $failed = 0;

    public function run(): void
    {
        echo "\n=== NetworkTest ===\n";
        $this->test_ip_in_subnet();
        $this->test_csrf_token();
        echo "\nResults: {$this->passed} passed, {$this->failed} failed\n";
    }

    private function assert(string $test, $expected, $actual): void
    {
        if ($expected === $actual) {
            echo "  PASS: {$test}\n";
            $this->passed++;
        } else {
            echo "  FAIL: {$test} — expected " . var_export($expected, true) . ", got " . var_export($actual, true) . "\n";
            $this->failed++;
        }
    }

    private function test_ip_in_subnet(): void
    {
        echo "\n-- ip_in_subnet --\n";
        // /24 subnet
        $this->assert('10.0.0.1 in 10.0.0.0/24', true, ip_in_subnet('10.0.0.1', '10.0.0.0/24'));
        $this->assert('10.0.0.254 in 10.0.0.0/24', true, ip_in_subnet('10.0.0.254', '10.0.0.0/24'));
        $this->assert('10.0.1.1 NOT in 10.0.0.0/24', false, ip_in_subnet('10.0.1.1', '10.0.0.0/24'));

        // /16 subnet
        $this->assert('192.168.1.100 in 192.168.0.0/16', true, ip_in_subnet('192.168.1.100', '192.168.0.0/16'));
        $this->assert('192.169.0.1 NOT in 192.168.0.0/16', false, ip_in_subnet('192.169.0.1', '192.168.0.0/16'));

        // /32 (single host)
        $this->assert('exact match /32', true, ip_in_subnet('10.0.0.1', '10.0.0.1/32'));
        $this->assert('no match /32', false, ip_in_subnet('10.0.0.2', '10.0.0.1/32'));

        // No mask (should default to /32)
        $this->assert('no mask defaults /32', true, ip_in_subnet('10.0.0.1', '10.0.0.1'));

        // /8
        $this->assert('10.255.255.1 in 10.0.0.0/8', true, ip_in_subnet('10.255.255.1', '10.0.0.0/8'));
        $this->assert('11.0.0.1 NOT in 10.0.0.0/8', false, ip_in_subnet('11.0.0.1', '10.0.0.0/8'));
    }

    private function test_csrf_token(): void
    {
        echo "\n-- CSRF --\n";
        $token = csrf_token();
        $this->assert('token not empty', true, !empty($token));
        $this->assert('token is string', true, is_string($token));
        $this->assert('token consistent', $token, csrf_token()); // same session = same token

        $this->assert('verify valid token', true, verify_csrf($token));
        $this->assert('verify invalid token', false, verify_csrf('invalid-token'));
        $this->assert('verify empty token', false, verify_csrf(''));
    }
}

$t = new NetworkTest();
$t->run();
