<?php
/** Created by Łukasz Misiura (c) 2025 | dev.lm-ads.com **/
require_once 'config.php';
require_once 'functions.php';

$output = [];

echo "Logging in...\n";
// Login logic is in functions.php/fetch_api usually handles it or we need to trigger it.
// fetch_api handles session setup.

echo "Fetching device stats...\n";
$dev_resp = fetch_api("/proxy/network/api/s/default/stat/device");
$output['devices'] = $dev_resp;

echo "Fetching sysinfo...\n";
$sys_resp = fetch_api("/proxy/network/api/s/default/stat/sysinfo");
$output['sysinfo'] = $sys_resp;

echo "Fetching health...\n";
$health_resp = fetch_api("/proxy/network/api/s/default/stat/health");
$output['health'] = $health_resp;

file_put_contents('data/api_dump.json', json_encode($output, JSON_PRETTY_PRINT));
echo "Dumped to data/api_dump.json\n";




