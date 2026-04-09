<?php
/** Created by Łukasz Misiura (c) 2026 | www.lm-ads.com **/
require_once 'config.php';
require_once 'functions.php';

$site = $config['site'] ?? 'default';

echo "--- IPS SETTINGS ---\n";
$ips = fetch_api("/proxy/network/api/s/$site/rest/setting/ips");
print_r($ips);

echo "\n--- COUNTRY BLOCK SETTINGS ---\n";
$geo = fetch_api("/proxy/network/api/s/$site/rest/setting/countryblock");
print_r($geo);

echo "\n--- ALL SETTINGS ---\n";
$settings = fetch_api("/proxy/network/api/s/$site/rest/setting");
if (isset($settings['data'])) {
    foreach ($settings['data'] as $s) {
        if (isset($s['key'])) {
            echo "- " . $s['key'] . "\n";
        }
    }
}
?>




