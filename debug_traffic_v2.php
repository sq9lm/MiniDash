<?php
/** Created by Łukasz Misiura (c) 2025 | dev.lm-ads.com **/
require_once 'config.php';
require_once 'functions.php';

$site = 'default';
$endpoints = [
    'v2_traffic' => "/proxy/network/api/v2/trafficrule",
    'v2_firewall' => "/proxy/network/api/v2/firewall/rules",
    'v2_routing' => "/proxy/network/api/v2/routing/rules",
    'rest_traffic' => "/proxy/network/api/s/$site/rest/trafficrule",
    'rest_firewall' => "/proxy/network/api/s/$site/rest/firewallrule",
    'stat_geo' => "/proxy/network/api/s/$site/stat/geo"
];

$results = [];
foreach ($endpoints as $name => $url) {
    echo "Testing $name ($url)...\n";
    $results[$name] = fetch_api($url);
}

file_put_contents(__DIR__ . '/data/debug_traffic_v2.txt', print_r($results, true));
echo "Traffic v2 discovery complete.\n";
?>




