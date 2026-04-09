<?php
/** Created by Łukasz Misiura (c) 2025 | dev.lm-ads.com **/
require_once 'config.php';
require_once 'functions.php';

$site = 'default';
$sysinfo = fetch_api("/proxy/network/api/s/$site/stat/sysinfo");
$networks = fetch_api("/proxy/network/api/s/$site/rest/networkconf");

file_put_contents(__DIR__ . '/data/debug_ver.txt', 
    "SYSINFO:\n" . print_r($sysinfo, true) . "\n\n" .
    "NETWORKS:\n" . print_r($networks, true)
);
echo "Ver discovery complete.\n";
?>




