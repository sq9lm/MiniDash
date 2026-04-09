<?php
/** Created by Łukasz Misiura (c) 2025 | dev.lm-ads.com **/
require_once 'config.php';
require_once 'functions.php';

echo "Debugging Clients Data...\n";
$resp = fetch_api("/proxy/network/integration/v1/sites/{$config['site']}/clients");
$clients = $resp['data'] ?? [];

echo "Total Clients: " . count($clients) . "\n\n";

if (count($clients) > 0) {
    // Show first 5 clients
    foreach (array_slice($clients, 0, 10) as $i => $c) {
        $ip = $c['ipAddress'] ?? $c['ip'] ?? 'NO_IP';
        $vlan = $c['vlan'] ?? $c['network_id'] ?? 'NO_VLAN';
        $mac = $c['macAddress'] ?? $c['mac'] ?? 'NO_MAC';
        echo "[$i] MAC: $mac | IP: $ip | VLAN: $vlan\n";
    }
} else {
    echo "No clients found. Trying default site...\n";
    $resp = fetch_api("/proxy/network/integration/v1/sites/default/clients");
    $clients = $resp['data'] ?? [];
    echo "Default Site Clients: " . count($clients) . "\n";
     foreach (array_slice($clients, 0, 10) as $i => $c) {
        $ip = $c['ipAddress'] ?? $c['ip'] ?? 'NO_IP';
        $vlan = $c['vlan'] ?? $c['network_id'] ?? 'NO_VLAN';
        echo "[$i] IP: $ip | VLAN: $vlan\n";
    }
}




