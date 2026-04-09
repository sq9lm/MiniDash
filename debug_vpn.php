<?php
/** Created by Łukasz Misiura (c) 2026 | www.lm-ads.com **/
require_once 'config.php';
require_once 'functions.php';

$site = 'default';
$endpoints = [
    'remotevpn' => "/proxy/network/api/s/$site/rest/remotevpn",
    'vpnserver' => "/proxy/network/api/s/$site/rest/vpnserver",
    'vpn' => "/proxy/network/api/s/$site/rest/vpn",
    'stat_vpn' => "/proxy/network/api/s/$site/stat/vpn",
    'radius' => "/proxy/network/api/s/$site/rest/radiusprofile",
    'radius_users' => "/proxy/network/api/s/$site/rest/radiususer"
];

$results = [];
foreach ($endpoints as $name => $url) {
    $results[$name] = fetch_api($url);
}

file_put_contents(__DIR__ . '/data/debug_vpn_discovery.txt', print_r($results, true));
echo "VPN Discovery complete.\n";
?>




