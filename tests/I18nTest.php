<?php
/**
 * MiniDash — Internationalization Tests
 * Tests: __(), load_language(), lang file completeness
 */

require_once __DIR__ . '/bootstrap.php';

class I18nTest
{
    private int $passed = 0;
    private int $failed = 0;

    public function run(): void
    {
        echo "\n=== I18nTest ===\n";
        $this->test_load_language_pl();
        $this->test_load_language_en();
        $this->test_translation_function();
        $this->test_missing_key_returns_key();
        $this->test_params_substitution();
        $this->test_lang_files_completeness();
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

    private function test_load_language_pl(): void
    {
        echo "\n-- load_language PL --\n";
        load_language('pl');
        $this->assert('PL nav.dashboard', 'Dashboard', __('nav.dashboard'));
        $this->assert('PL common.save', 'Zapisz', __('common.save'));
        $this->assert('PL common.close', 'Zamknij', __('common.close'));
        $this->assert('PL login.username', 'Użytkownik', __('login.username'));
    }

    private function test_load_language_en(): void
    {
        echo "\n-- load_language EN --\n";
        load_language('en');
        $this->assert('EN nav.dashboard', 'Dashboard', __('nav.dashboard'));
        $this->assert('EN common.save', 'Save', __('common.save'));
        $this->assert('EN common.close', 'Close', __('common.close'));
        $this->assert('EN login.username', 'Username', __('login.username'));
        $this->assert('EN footer.rights', 'All rights reserved', __('footer.rights'));
    }

    private function test_translation_function(): void
    {
        echo "\n-- __() deep keys --\n";
        load_language('pl');
        $this->assert('deep key security.title', 'UniFi Security', __('security.title'));
        $this->assert('deep key stalker.title', 'Wi-Fi Stalker', __('stalker.title'));
    }

    private function test_missing_key_returns_key(): void
    {
        echo "\n-- missing keys --\n";
        load_language('pl');
        $this->assert('nonexistent key', 'foo.bar.baz', __('foo.bar.baz'));
        $this->assert('partial key', 'nav.nonexistent', __('nav.nonexistent'));
        $this->assert('empty key', '', __(''));
    }

    private function test_params_substitution(): void
    {
        echo "\n-- params --\n";
        load_language('pl');
        $result = __('alerts.speed_spike_body', ['name' => 'TestDevice', 'speed' => '50 Mbps', 'threshold' => '100']);
        $this->assert('params replaced', true, strpos($result, 'TestDevice') !== false);
        $this->assert('params speed', true, strpos($result, '50 Mbps') !== false);
    }

    private function test_lang_files_completeness(): void
    {
        echo "\n-- lang file completeness --\n";
        $root = dirname(__DIR__);
        $pl = json_decode(file_get_contents($root . '/lang/pl.json'), true);
        $en = json_decode(file_get_contents($root . '/lang/en.json'), true);

        $this->assert('pl.json is valid JSON', true, is_array($pl));
        $this->assert('en.json is valid JSON', true, is_array($en));

        // Check that every top-level section in PL exists in EN
        $missing_sections = [];
        foreach (array_keys($pl) as $section) {
            if (!isset($en[$section])) {
                $missing_sections[] = $section;
            }
        }
        $this->assert('EN has all PL sections', '[]', json_encode($missing_sections));

        // Check keys in each section
        $missing_keys = [];
        foreach ($pl as $section => $keys) {
            if (!is_array($keys)) continue;
            foreach (array_keys($keys) as $key) {
                if (!isset($en[$section][$key])) {
                    $missing_keys[] = "{$section}.{$key}";
                }
            }
        }
        $count = count($missing_keys);
        if ($count > 0) {
            echo "  WARN: {$count} keys in PL missing from EN: " . implode(', ', array_slice($missing_keys, 0, 10)) . ($count > 10 ? '...' : '') . "\n";
        }
        $this->assert('EN covers all PL keys', 0, $count);
    }
}

$t = new I18nTest();
$t->run();
