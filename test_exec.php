<?php
/** Created by Łukasz Misiura (c) 2025 | dev.lm-ads.com **/
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "Testing exec...\n";
$output = [];
$return_var = -1;
exec('whoami', $output, $return_var);

echo "Return var: $return_var\n";
echo "Output: " . implode("\n", $output) . "\n";

echo "Testing file write...\n";
if (file_put_contents('test_debug.txt', 'test')) {
    echo "File write OK\n";
} else {
    echo "File write FAILED\n";
}




