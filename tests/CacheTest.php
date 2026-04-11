<?php
/**
 * MiniDash — Cache Tests
 * Tests: minidash_cache_get, minidash_cache_set
 */

require_once __DIR__ . '/bootstrap.php';

class CacheTest
{
    private int $passed = 0;
    private int $failed = 0;

    public function run(): void
    {
        echo "\n=== CacheTest ===\n";
        $this->test_cache_set_get();
        $this->test_cache_miss();
        $this->test_cache_complex_data();
        $this->cleanup();
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

    private function test_cache_set_get(): void
    {
        echo "\n-- cache set/get --\n";
        minidash_cache_set('_test_key', ['hello' => 'world']);
        $result = minidash_cache_get('_test_key', 60);
        $this->assert('cache hit', 'world', $result['hello'] ?? null);
    }

    private function test_cache_miss(): void
    {
        echo "\n-- cache miss --\n";
        $result = minidash_cache_get('_nonexistent_key_xyz', 60);
        $this->assert('cache miss returns null', null, $result);
    }

    private function test_cache_complex_data(): void
    {
        echo "\n-- complex data --\n";
        $data = [
            'clients' => [
                ['name' => 'Device1', 'ip' => '10.0.0.1'],
                ['name' => 'Device2', 'ip' => '10.0.0.2'],
            ],
            'count' => 2,
            'nested' => ['a' => ['b' => 'c']],
        ];
        minidash_cache_set('_test_complex', $data);
        $result = minidash_cache_get('_test_complex', 60);
        $this->assert('complex data count', 2, $result['count'] ?? null);
        $this->assert('complex nested', 'c', $result['nested']['a']['b'] ?? null);
        $this->assert('complex array', 'Device1', $result['clients'][0]['name'] ?? null);
    }

    private function cleanup(): void
    {
        $root = dirname(__DIR__);
        @unlink($root . '/data/cache_' . md5('_test_key') . '.json');
        @unlink($root . '/data/cache_' . md5('_test_complex') . '.json');
    }
}

$t = new CacheTest();
$t->run();
