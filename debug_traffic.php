<?php
/** Created by Łukasz Misiura (c) 2025 | dev.lm-ads.com **/
require_once 'config.php';
require_once 'functions.php';

$site = 'default';
$resp = fetch_api("/proxy/network/api/s/$site/rest/trafficrule");
file_put_contents(__DIR__ . '/data/debug_traffic_rules.txt', print_r($resp, true));
echo "Traffic rules logged.\n";
?>




