<?php
/**
 * MiniDash — Formatter Functions Tests
 * Tests: formatDuration, formatBps, format_bps, format_bytes
 */

require_once __DIR__ . '/bootstrap.php';

class FormattersTest
{
    private int $passed = 0;
    private int $failed = 0;

    public function run(): void
    {
        echo "\n=== FormattersTest ===\n";
        $this->test_formatDuration();
        $this->test_format_bps();
        $this->test_format_bytes();
        $this->test_formatBps_alias();
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

    private function test_formatDuration(): void
    {
        echo "\n-- formatDuration --\n";
        $this->assert('0 seconds', '0s', formatDuration(0));
        $this->assert('30 seconds', '30s', formatDuration(30));
        $this->assert('59 seconds', '59s', formatDuration(59));
        $this->assert('1 minute', '1m', formatDuration(60));
        $this->assert('5 minutes', '5m', formatDuration(300));
        $this->assert('59 minutes', '59m', formatDuration(3540));
        $this->assert('1 hour 0 min', '1h 0m', formatDuration(3600));
        $this->assert('2 hours 30 min', '2h 30m', formatDuration(9000));
        $this->assert('23 hours 59 min', '23h 59m', formatDuration(86340));
        $this->assert('1 day 0 hours', '1d 0h', formatDuration(86400));
        $this->assert('3 days 5 hours', '3d 5h', formatDuration(277200));
        $this->assert('30 days', '30d 0h', formatDuration(2592000));
    }

    private function test_format_bps(): void
    {
        echo "\n-- format_bps --\n";
        $this->assert('0 bps', '0 bps', format_bps(0));
        $this->assert('500 bps', '500 bps', format_bps(500));
        $this->assert('999 bps', '999 bps', format_bps(999));
        $this->assert('1 Kbps', '1.0 Kbps', format_bps(1000));
        $this->assert('1.5 Kbps', '1.5 Kbps', format_bps(1500));
        $this->assert('999.9 Kbps', '999.9 Kbps', format_bps(999900));
        $this->assert('1 Mbps', '1.0 Mbps', format_bps(1000000));
        $this->assert('100 Mbps', '100.0 Mbps', format_bps(100000000));
        $this->assert('1 Gbps', '1.00 Gbps', format_bps(1000000000));
        $this->assert('2.5 Gbps', '2.50 Gbps', format_bps(2500000000));
        $this->assert('1 Tbps', '1.00 Tbps', format_bps(1000000000000));
    }

    private function test_format_bytes(): void
    {
        echo "\n-- format_bytes --\n";
        $this->assert('0 bytes', '0 B', format_bytes(0));
        $this->assert('500 B', '500 B', format_bytes(500));
        $this->assert('1 KB', '1 KB', format_bytes(1024));
        $this->assert('1 MB', '1 MB', format_bytes(1048576));
        $this->assert('1.5 MB', '1.5 MB', format_bytes(1572864));
        $this->assert('1 GB', '1 GB', format_bytes(1073741824));
        $this->assert('2.5 GB', '2.5 GB', format_bytes(2684354560));
        $this->assert('1 TB', '1 TB', format_bytes(1099511627776));
    }

    private function test_formatBps_alias(): void
    {
        echo "\n-- formatBps (alias) --\n";
        $this->assert('alias matches format_bps', format_bps(1500000), formatBps(1500000));
        $this->assert('alias matches format_bps 0', format_bps(0), formatBps(0));
    }
}

$t = new FormattersTest();
$t->run();
exit($t->failed ?? 0);
