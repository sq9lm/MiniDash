<?php
/** Created by Łukasz Misiura (c) 2026 | www.lm-ads.com **/
require_once 'config.php';
require_once 'functions.php';

$site = 'default';
$endpoints = [
    'firewall_rules' => "/proxy/network/api/s/$site/rest/firewallrule",
    'firewall_groups' => "/proxy/network/api/s/$site/rest/firewallgroup",
    'country_block' => "/proxy/network/api/s/$site/rest/setting/countryblock",
    'ips_settings' => "/proxy/network/api/s/$site/rest/setting/ips",
    'traffic_rules' => "/proxy/network/api/s/$site/rest/trafficrule",
    'vpn_remote' => "/proxy/network/api/s/$site/rest/vpn",
    'vpn_status' => "/proxy/network/api/s/$site/stat/vpn",
    'networks' => "/proxy/network/api/s/$site/rest/networkconf",
    'all_settings' => "/proxy/network/api/s/$site/rest/setting"
];

$results = [];
foreach ($endpoints as $name => $url) {
    $results[$name] = fetch_api($url);
}

file_put_contents(__DIR__ . '/data/discovery_full.txt', print_r($results, true));
echo "Discovery complete.\n";
?>




