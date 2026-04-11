<?php
/**
 * MiniDash — MAC Address Normalization Tests
 * Tests: normalize_mac()
 */

require_once __DIR__ . '/bootstrap.php';

class MacNormalizationTest
{
    private int $passed = 0;
    private int $failed = 0;

    public function run(): void
    {
        echo "\n=== MacNormalizationTest ===\n";
        $this->test_normalize_mac();
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

    private function test_normalize_mac(): void
    {
        echo "\n-- normalize_mac --\n";
        // Standard colon-separated
        $this->assert('colon lowercase', 'aabbccddeeff', normalize_mac('aa:bb:cc:dd:ee:ff'));
        // Uppercase with colons
        $this->assert('colon uppercase', 'aabbccddeeff', normalize_mac('AA:BB:CC:DD:EE:FF'));
        // Mixed case
        $this->assert('colon mixed', 'aabbccddeeff', normalize_mac('Aa:Bb:Cc:Dd:Ee:Ff'));
        // Dash-separated (Windows style)
        $this->assert('dash separated', 'aabbccddeeff', normalize_mac('AA-BB-CC-DD-EE-FF'));
        // Already normalized
        $this->assert('already normalized', 'aabbccddeeff', normalize_mac('aabbccddeeff'));
        // Empty
        $this->assert('empty string', '', normalize_mac(''));
        // Null
        $this->assert('null', '', normalize_mac(null));
        // False
        $this->assert('false', '', normalize_mac(false));
        // Mixed separators
        $this->assert('mixed separators', 'aabbccddeeff', normalize_mac('AA:BB-CC:DD-EE:FF'));
    }
}

$t = new MacNormalizationTest();
$t->run();
