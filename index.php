<?php
/** Created by Łukasz Misiura (c) 2025 | dev.lm-ads.com **/
require_once 'config.php';
require_once 'db.php';
require_once 'functions.php';

// Sprawdzenie czy użytkownik jest zalogowany
if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    header('Location: login.php');
    exit;
}

$siteId = $_SESSION['site_id'] ?? $config['site'] ?? 'default';
$devices_config = loadDevices();

try {
    // 1. Fetch Clients
    $clients_resp = fetch_api("/proxy/network/integration/v1/sites/$siteId/clients?limit=1000");

        // Auto-detect correct site ID if the configured one fails
        if (empty($clients_resp['data']) && $siteId !== 'default') {
             $fallback_resp = fetch_api("/proxy/network/integration/v1/sites/default/clients?limit=1000");
             if (!empty($fallback_resp['data'])) {
                 $clients_resp = $fallback_resp;
                 $siteId = 'default';
             }
        }

        // 2. Fetch from Traditional API (Rich data: SSID, signals, rates)
        $tradSite = get_trad_site_id($siteId);
        $trad_resp = fetch_api("/proxy/network/api/s/$tradSite/stat/sta");
        $trad_clients = $trad_resp['data'] ?? [];
        $clients = $clients_resp['data'] ?? [];
    
    // Merge data
    if (!empty($trad_clients)) {
        $trad_map = [];
        foreach ($trad_clients as $tc) {
            $t_mac = normalize_mac($tc['mac'] ?? '');
            $trad_map[$t_mac] = $tc;
        }

        // Enrich existing clients with traditional data
        $existing_macs = [];
        foreach ($clients as &$c) {
            $c_mac = normalize_mac($c['macAddress'] ?? $c['mac'] ?? '');
            $existing_macs[] = $c_mac;
            if (isset($trad_map[$c_mac])) {
                $tc = $trad_map[$c_mac];
                $c['essid'] = $tc['essid'] ?? $c['essid'] ?? '';
                $c['rx_rate'] = (float)($tc['rx_rate'] ?? (($tc['rx_bytes-r'] ?? 0) * 8));
                $c['tx_rate'] = (float)($tc['tx_rate'] ?? (($tc['tx_bytes-r'] ?? 0) * 8));
                $c['rx_bytes'] = (float)($tc['rx_bytes'] ?? 0);
                $c['tx_bytes'] = (float)($tc['tx_bytes'] ?? 0);
                $c['signal'] = $tc['signal'] ?? $tc['rssi'] ?? 0;
                $c['is_wired'] = $c['is_wired'] ?? ($tc['is_wired'] ?? ($tc['type'] == 'wired' || empty($tc['essid'])));
                $c['vlan'] = $c['vlan'] ?? $tc['vlan'] ?? 0;
            }
        }

        // Add clients from traditional API not in Integration API (e.g. VPN clients)
        foreach ($trad_clients as $tc) {
            $t_mac = normalize_mac($tc['mac'] ?? '');
            if ($t_mac && !in_array($t_mac, $existing_macs)) {
                $clients[] = [
                    'mac' => $t_mac,
                    'macAddress' => $tc['mac'] ?? '',
                    'name' => $tc['name'] ?? $tc['hostname'] ?? $t_mac,
                    'ipAddress' => $tc['ip'] ?? $tc['last_ip'] ?? '',
                    'essid' => $tc['essid'] ?? '',
                    'rx_rate' => (float)($tc['rx_rate'] ?? (($tc['rx_bytes-r'] ?? 0) * 8)),
                    'tx_rate' => (float)($tc['tx_rate'] ?? (($tc['tx_bytes-r'] ?? 0) * 8)),
                    'rx_bytes' => (float)($tc['rx_bytes'] ?? 0),
                    'tx_bytes' => (float)($tc['tx_bytes'] ?? 0),
                    'signal' => $tc['signal'] ?? 0,
                    'is_wired' => $tc['is_wired'] ?? false,
                    'vlan' => $tc['vlan'] ?? 0,
                    'uptime' => $tc['uptime'] ?? 0,
                ];
            }
        }
    }
    
    // 3. Fetch Infrastructure Devices with traditional API for better stats
    $tradSite = $tradSite ?? get_trad_site_id($siteId);
    $trad_dev_resp = fetch_api("/proxy/network/api/s/$tradSite/stat/device");
    $trad_devices = $trad_dev_resp['data'] ?? [];
    $trad_dev_map = [];
    foreach ($trad_devices as $td) {
        $td_mac = normalize_mac($td['mac'] ?? '');
        $trad_dev_map[$td_mac] = $td;
    }

    $infr_resp = fetch_api("/proxy/network/integration/v1/sites/$siteId/devices");
    $infr_devices = $infr_resp['data'] ?? [];

    
    // Ensure we also populate subnets for detect_vlan_id
    get_vlans(); 

    $gateway = null;
    $cpu = 0; $ram = 0; $wan_rx = 0; $wan_tx = 0; $wans = []; $latency = 0;

    foreach ($infr_devices as $d) {
        $mac = normalize_mac($d['macAddress'] ?? $d['mac'] ?? '');
        $trad = $trad_dev_map[$mac] ?? [];
        $model = $d['model'] ?? '';
        
        // Much more aggressive gateway detection
        $is_gateway = in_array($model, ['UDR', 'UDM', 'UXG', 'USG', 'UCG', 'UX', 'UXG-LITE', 'UXG-MAX', 'UDMPRO', 'UDMSE', 'UDM-SE', 'UDM-PRO-MAX']) 
                    || isset($d['wan1']) 
                    || ($trad['type'] ?? '') === 'ugw' 
                    || ($trad['type'] ?? '') === 'u-wan'
                    || isset($trad['wan1']);

        if ($is_gateway) {
            $gateway = $d;
            $cpu = $trad['system-stats']['cpu'] ?? $d['cpu'] ?? 0;
            $ram = $trad['system-stats']['mem'] ?? $d['ram'] ?? 0;
            $latency = $trad['stat']['gw']['latency'] ?? $trad['latency'] ?? 0;

            // WAN Processing
            $wan_keys = ['wan1', 'wan2'];
            foreach ($wan_keys as $wk) {
                if (!empty($trad[$wk]) && ($trad[$wk]['up'] ?? false)) {
                    $rx = (float)($trad[$wk]['rx_bytes-r'] ?? 0) * 8;
                    $tx = (float)($trad[$wk]['tx_bytes-r'] ?? 0) * 8;
                    $wan_rx += $rx; $wan_tx += $tx;
                    $wans[] = ['index' => (int)str_replace('wan', '', $wk), 'name' => strtoupper($wk), 'status' => 'ONLINE', 'ip' => $trad[$wk]['ip'] ?? 'N/A', 'rx' => $rx, 'tx' => $tx];
                }
            }
            
            // Backup for aggregate WAN stats
            if (empty($wans)) {
                $rx = (float)($trad['stat']['gw']['wan_rx_bytes-r'] ?? $trad['rx_bytes-r'] ?? 0) * 8;
                $tx = (float)($trad['stat']['gw']['wan_tx_bytes-r'] ?? $trad['tx_bytes-r'] ?? 0) * 8;
                if ($rx > 0 || $tx > 0) {
                    $wan_rx = $rx; $wan_tx = $tx;
                    $wans[] = ['index' => 1, 'name' => 'WAN (Auto)', 'status' => 'ONLINE', 'ip' => $trad['wan1']['ip'] ?? $d['ip'] ?? 'N/A', 'rx' => $rx, 'tx' => $tx];
                }
            }
        }
    }

    $wan_status = 'OFFLINE';
    $wan_ip = 'Nieznane';
    if (!empty($wans)) {
        $wan_status = 'ONLINE';
        foreach ($wans as $w) {
            if ($w['ip'] && $w['ip'] !== 'N/A') { $wan_ip = $w['ip']; break; }
        }
    }
    
    // Cache navbar stats and WAN details in session for other pages
    $_SESSION['navbar_stats'] = [
        'cpu' => $cpu,
        'ram' => $ram,
        'down' => $wan_rx,
        'up' => $wan_tx
    ];
    
    $_SESSION['wan_details'] = [
        'wan_ip' => $wan_ip,
        'wans' => $wans, // Store full list
        'gateway_model' => $gateway['model'] ?? $gateway['name'] ?? 'UniFi Gateway',
        'wan_status' => $wan_status
    ];
    
    // 3. Fetch Historical Stats (for Total counters)
    $user_resp = fetch_api("/proxy/network/api/s/$siteId/stat/user");
    $hist_clients = [];
    if (!empty($user_resp['data'])) {
        foreach ($user_resp['data'] as $hc) {
            $hist_clients[normalize_mac($hc['mac'])] = $hc;
        }
    }

    $vlan_stats = [];
    $vlan_clients = []; // Clients grouped by VLAN name for detail modal
    foreach ($clients as &$client) {
        $c_mac = normalize_mac($client['macAddress'] ?? $client['mac'] ?? '');
        
        // Enrich from history if present
        if (isset($hist_clients[$c_mac])) {
            $hc = $hist_clients[$c_mac];
            $client['rx_bytes'] = max((float)($client['rx_bytes'] ?? 0), (float)($hc['rx_bytes'] ?? 0));
            $client['tx_bytes'] = max((float)($client['tx_bytes'] ?? 0), (float)($hc['tx_bytes'] ?? 0));
            if (!isset($client['uptime']) || !$client['uptime']) {
                 $client['uptime'] = $hc['uptime'] ?? 0;
            }
        }
        $vlan_id = $client['vlan'] ?? $client['network_id'] ?? null;
        $ip = $client['ipAddress'] ?? $client['ip'] ?? '';

        // Use centralized detection
        $vlan_id = detect_vlan_id($ip, $vlan_id);
        $client['vlan'] = $vlan_id;

        // Detect VPN status — check VLAN, network name, or connection type
        $net_name = strtolower($client['essid'] ?? $client['network'] ?? $client['last_connection_network_name'] ?? '');
        $conn_type = strtolower($client['type'] ?? '');
        $client['is_vpn'] = ($vlan_id === 0 || $vlan_id === 69 || $vlan_id === 70
            || strpos($net_name, 'vpn') !== false || strpos($net_name, 'ovpn') !== false || strpos($net_name, 'wireguard') !== false || strpos($net_name, 'wgadmin') !== false
            || $conn_type === 'vpn'
            || !empty($client['is_vpn']));

        $vlan_name = get_vlan_name($vlan_id);
        $c_rx = $client['rx_rate'] ?? $client['rx_bytes-r'] ?? 0;
        $c_tx = $client['tx_rate'] ?? $client['tx_bytes-r'] ?? 0;
        $c_rx_total = $client['rx_bytes'] ?? 0;
        $c_tx_total = $client['tx_bytes'] ?? 0;

        if (!isset($vlan_stats[$vlan_name])) {
            $vlan_stats[$vlan_name] = ['count' => 0, 'id' => (int)$vlan_id, 'rx' => 0, 'tx' => 0, 'total_rx' => 0, 'total_tx' => 0];
        }
        $vlan_stats[$vlan_name]['count']++;
        $vlan_stats[$vlan_name]['rx'] += $c_rx;
        $vlan_stats[$vlan_name]['tx'] += $c_tx;
        $vlan_stats[$vlan_name]['total_rx'] += $c_rx_total;
        $vlan_stats[$vlan_name]['total_tx'] += $c_tx_total;

        $vlan_clients[$vlan_name][] = [
            'name' => $client['name'] ?? $client['hostname'] ?? $client['macAddress'] ?? $client['mac'] ?? '?',
            'ip' => $ip,
            'mac' => $client['macAddress'] ?? $client['mac'] ?? '',
            'rx' => $c_rx,
            'tx' => $c_tx,
            'rx_total' => $c_rx_total,
            'tx_total' => $c_tx_total,
            'is_wired' => $client['is_wired'] ?? false,
        ];
    }
    // Find Top Consumers (Bandwidth Hogs)
    $top_downloader = null;
    $top_uploader = null;
    $max_rx_rate = 0;
    $max_tx_rate = 0;

    $total_clients_rx = 0;
    $total_clients_tx = 0;

    foreach ($clients as $c) {
        $c_rx = (float)($c['rx_rate'] ?? 0);
        $c_tx = (float)($c['tx_rate'] ?? 0);
        
        $total_clients_rx += $c_rx;
        $total_clients_tx += $c_tx;
        
        if ($c_rx > $max_rx_rate) {
            $max_rx_rate = $c_rx;
            $top_downloader = $c;
        }
        if ($c_tx > $max_tx_rate) {
            $max_tx_rate = $c_tx;
            $top_uploader = $c;
        }
    }

    // Speed Alert Trigger
    if (($config['triggers']['speed_alert_enabled'] ?? false) && $top_downloader) {
        $threshold_bps = ($config['triggers']['speed_threshold_mbps'] ?? 100) * 1000 * 1000;
        if ($max_rx_rate > $threshold_bps) {
            $last_alert = $_SESSION['last_speed_alert'] ?? 0;
            if (time() - $last_alert > 300) { // 5 min cooldown
                $devName = $top_downloader['name'] ?? $top_downloader['hostname'] ?? 'Nieznany';
                $formattedSpeed = formatBps($max_rx_rate);
                sendAlert("Nagły wzrost transferu: $devName", "Urządzenie **$devName** generuje duży ruch: **$formattedSpeed** (Próg: " . $config['triggers']['speed_threshold_mbps'] . " Mbps)");
                $_SESSION['last_speed_alert'] = time();
            }
        }
    }

    // Known Devices

    // Wi-Fi SSID Stats
    $wifi_stats = [];
    foreach ($clients as $c) {
        if (!empty($c['essid'])) {
            $essid = $c['essid'];
            if (!isset($wifi_stats[$essid])) $wifi_stats[$essid] = 0;
            $wifi_stats[$essid]++;
        }
    }
    arsort($wifi_stats);

} catch (Exception $e) {
    if (!empty($config['debug'])) $error_msg = $e->getMessage();
}

// formatBps is now in functions.php
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MiniDash</title>
    <link rel="icon" type="image/svg+xml" href="img/favicon.svg">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="assets/css/fonts.css">
    <link rel="stylesheet" href="dashboard.css">
    <script src="assets/js/lucide.min.js"></script>
    <script src="assets/js/chart.min.js"></script>
</head>
<body class="custom-scrollbar">
    <?php render_nav("MiniDash", [
        'cpu' => $cpu,
        'ram' => $ram,
        'down' => $wan_rx,
        'up' => $wan_tx
    ]); ?>
    
    <div class="max-w-7xl mx-auto p-4 md:p-8">
        <!-- Dashboard Content -->

        <!-- Top Stats Grid (5x2) -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4 mb-8">
            <!-- Row 1 -->
            <!-- 1. Clients -->
            <div onclick="openClientsModal()" class="glass-card p-5 stat-glow-blue cursor-pointer group">
                <div class="flex justify-between items-center mb-4">
                    <div class="p-2.5 bg-blue-500/10 rounded-xl text-blue-400 group-hover:bg-blue-500/20 transition-colors">
                        <i data-lucide="users" class="w-5 h-5"></i>
                    </div>
                    <span class="text-xs font-black text-slate-500 uppercase tracking-widest">Aktywni</span>
                </div>
                <div class="text-3xl font-black tracking-tighter text-white"><?= count($clients) ?></div>
                <div class="text-slate-400 text-sm mt-1 font-medium italic">Użytkownicy sieci</div>
            </div>

            <!-- 2. WiFi SSIDs -->
            <div id="wifi-card" class="glass-card p-5 stat-glow-indigo group overflow-hidden cursor-pointer hover:bg-white/[0.02] transition-colors relative">
                <div class="absolute top-0 right-0 p-4 opacity-5 group-hover:opacity-10 transition-opacity">
                    <i data-lucide="wifi" class="w-16 h-16"></i>
                </div>
                <div class="flex justify-between items-center mb-3">
                    <div class="p-3 bg-indigo-500/10 rounded-xl text-indigo-400">
                        <i data-lucide="wifi" class="w-6 h-6"></i>
                    </div>
                    <span class="text-xs font-black text-slate-500 uppercase tracking-[0.2em]">Sieci WiFi</span>
                </div>
                <div class="space-y-1.5 relative z-10">
                    <?php 
                    $limit = 2;
                    $i = 0;
                    foreach ($wifi_stats as $ssid => $count): 
                        if ($i >= $limit) break;
                    ?>
                        <div class="flex justify-between items-center text-xs">
                            <span class="font-bold text-slate-200 truncate mr-2"><?= htmlspecialchars($ssid) ?></span>
                            <span class="font-mono text-indigo-400 bg-indigo-500/10 px-2 py-0.5 rounded border border-indigo-500/20"><?= $count ?></span>
                        </div>
                    <?php 
                        $i++;
                    endforeach; 
                    ?>
                    
                    <div class="pt-3">
                        <button onclick="openWifiModal(); event.stopPropagation();" class="w-full py-2 bg-indigo-500/10 hover:bg-indigo-500/20 text-indigo-300 text-xs font-black uppercase tracking-widest rounded-lg border border-indigo-500/20 transition-all flex items-center justify-center gap-2">
                            <span>Zarządzaj</span>
                            <i data-lucide="chevron-right" class="w-3 h-3"></i>
                        </button>
                    </div>
                </div>
            </div>

            <!-- 3. Infrastructure -->
            <div id="infr-card" class="glass-card p-6 stat-glow-purple cursor-pointer group hover:scale-[1.02] transition-all">
                <div class="flex justify-between items-center mb-5">
                    <div class="p-3 bg-purple-500/10 rounded-xl text-purple-400 group-hover:bg-purple-500/20 transition-colors">
                        <i data-lucide="network" class="w-6 h-6"></i>
                    </div>
                    <span class="text-xs font-black text-slate-500 uppercase tracking-[0.2em]">Sprzęt</span>
                </div>
                <div class="text-4xl font-black tracking-tighter text-white"><?= count($infr_devices) ?></div>
                <div class="text-slate-500 text-xs mt-1 font-black uppercase tracking-widest italic tracking-[0.1em]">Urządzenia UniFi</div>
            </div>

            <!-- 4. WAN Ingress (Download) -->
            <div onclick="openWanFlowsModal()" class="glass-card p-6 stat-glow-amber cursor-pointer group hover:scale-[1.02] transition-all">
                <div class="flex justify-between items-center mb-5">
                    <div class="p-3 bg-amber-500/10 rounded-xl text-amber-400 group-hover:bg-amber-400/20 transition-colors">
                        <i data-lucide="arrow-down-to-line" class="w-6 h-6"></i>
                    </div>
                    <div class="text-xs font-black text-amber-500/80 uppercase tracking-tighter tracking-[0.1em]">Ingress</div>
                </div>
                <div class="text-3xl font-black tracking-tighter text-white"><?= formatBps($wan_rx) ?></div>
                <div class="text-slate-500 text-xs uppercase font-black tracking-[0.2em] mt-1">Internet → Router</div>
            </div>

            <!-- 5. WAN Egress (Upload) -->
            <div onclick="openWanFlowsModal()" class="glass-card p-6 stat-glow-emerald cursor-pointer group hover:scale-[1.02] transition-all">
                <div class="flex justify-between items-center mb-5">
                    <div class="p-3 bg-emerald-500/10 rounded-xl text-emerald-400 group-hover:bg-emerald-400/20 transition-colors">
                        <i data-lucide="arrow-up-from-line" class="w-6 h-6"></i>
                    </div>
                    <div class="text-xs font-black text-emerald-400/80 uppercase tracking-tighter tracking-[0.1em]">Egress</div>
                </div>
                <div class="text-3xl font-black tracking-tighter text-white"><?= formatBps($wan_tx) ?></div>
                <div class="text-slate-500 text-xs uppercase font-black tracking-[0.2em] mt-1">Router → Internet</div>
            </div>

            <!-- 6. Stalker Widget -->
            <div onclick="window.location='stalker.php'" class="glass-card rounded-3xl p-6 cursor-pointer hover:bg-white/[0.04] transition group">
                <div class="flex items-center justify-between mb-4">
                    <div class="w-10 h-10 rounded-xl bg-purple-500/10 flex items-center justify-center border border-purple-500/20 group-hover:scale-110 transition-transform">
                        <i data-lucide="radar" class="w-5 h-5 text-purple-400"></i>
                    </div>
                    <span class="text-[12px] font-bold text-purple-400 uppercase tracking-widest">Wi-Fi Stalker</span>
                </div>
                <div class="text-3xl font-black text-white tracking-tight" id="stalker-widget-count">-</div>
                <div class="text-xs text-slate-500 mt-1">Sesji WiFi</div>
                <div class="text-[12px] text-slate-600 mt-2 truncate" id="stalker-widget-last">Ladowanie...</div>
            </div>

            <!-- 7. WAN Status (Dynamic Loop) -->
            <?php if (empty($wans)): ?>
            <div class="glass-card p-5 stat-glow-red cursor-pointer" onclick="openWanModal()">
                <div class="flex justify-between items-center mb-4">
                    <div class="p-2.5 bg-red-500/10 text-red-400 rounded-xl">
                        <i data-lucide="globe" class="w-5 h-5"></i>
                    </div>
                    <span class="px-2 py-0.5 rounded text-[12px] font-black uppercase tracking-widest bg-red-500/20 text-red-400">
                        OFFLINE
                    </span>
                </div>
                <div class="text-lg font-black tracking-tighter truncate text-slate-200">Brak połączenia</div>
                <div class="text-slate-500 text-[12px] mt-1 font-bold uppercase tracking-widest">WAN 1</div>
            </div>
            <?php else: ?>
                <?php foreach ($wans as $index => $w): 
                    $is_wan1 = ($w['index'] === 1 || $w['name'] === 'WAN 1');
                    $is_online = ($w['status'] === 'ONLINE');
                    $color = $is_online ? ($is_wan1 ? 'blue' : 'emerald') : 'red';
                ?>
                <div class="glass-card p-5 stat-glow-<?= $color ?> cursor-pointer group hover:bg-white/[0.02] transition-all relative overflow-hidden" onclick="openWanModal()">
                    <?php if ($is_wan1): ?>
                        <div class="absolute -right-8 -top-8 w-16 h-16 bg-blue-500/5 rounded-full blur-2xl"></div>
                    <?php endif; ?>
                    
                    <div class="flex justify-between items-center mb-4">
                        <div class="p-2.5 bg-<?= $color ?>-500/10 text-<?= $color ?>-400 rounded-xl relative z-10">
                            <i data-lucide="globe" class="w-5 h-5"></i>
                        </div>
                        <div class="flex flex-col items-end gap-1">
                            <span class="px-2 py-0.5 rounded text-[12px] font-black uppercase tracking-widest bg-<?= $color ?>-500/20 text-<?= $color ?>-400 relative z-10">
                                <?= $w['status'] ?>
                            </span>
                            <?php if ($is_wan1): ?>
                                <span class="text-[7px] font-black text-blue-500/50 uppercase tracking-[0.2em]">Primary</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="text-2xl font-black tracking-tighter truncate text-white relative z-10" title="<?= htmlspecialchars($w['vendor'] ?? '') ?>">
                        <?= $w['name'] ?>
                    </div>
                    <div class="text-slate-500 text-[11px] mt-1 font-mono font-bold uppercase tracking-widest truncate relative z-10"><?= $w['ip'] ?></div>
                    
                    <?php if (count($wans) > 1 || $is_wan1): ?>
                    <div class="mt-3 flex items-center justify-between text-[12px] font-bold uppercase tracking-tighter border-t border-white/5 pt-2">
                        <span class="<?= $is_online ? 'text-slate-400' : 'text-slate-600' ?>"><?= formatBps($w['rx']) ?> ↓</span>
                        <div class="h-1 w-1 rounded-full bg-slate-700"></div>
                        <span class="<?= $is_online ? 'text-slate-400' : 'text-slate-600' ?>"><?= formatBps($w['tx']) ?> ↑</span>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>



            <!-- 9. Incoming Connections -->
            <div onclick="openWanSessionsModal()" class="glass-card p-6 stat-glow-blue relative overflow-hidden group cursor-pointer hover:scale-[1.02] transition-all">
                <div class="flex justify-between items-center mb-5">
                    <div class="p-3 bg-blue-500/10 rounded-xl text-blue-400">
                        <i data-lucide="arrow-down-to-line" class="w-6 h-6"></i>
                    </div>
                    <div class="text-[12px] font-mono text-blue-400 font-black uppercase tracking-widest">SUMA IN</div>
                </div>
                <div class="text-[18px] font-black tracking-tight text-white" id="local-rx-val"><?= formatBps($total_clients_rx) ?></div>
                <div class="text-slate-500 text-[12px] mt-1 font-black uppercase tracking-[0.15em]">Ruch Lokalny (IN)</div>
                
                <?php if ($top_downloader && $max_rx_rate > 50000): ?>
                <div class="mt-3 pt-3 border-t border-white/5 flex items-center justify-between">
                    <div class="flex items-center gap-2 min-w-0">
                        <div class="w-1.5 h-1.5 rounded-full bg-blue-500 pulse"></div>
                        <span class="text-[12px] text-slate-400 font-bold truncate">
                            <?= htmlspecialchars($top_downloader['name'] ?? $top_downloader['hostname'] ?? 'Nieznany') ?>
                        </span>
                    </div>
                    <span class="text-[12px] font-mono text-blue-400 font-black px-1.5 py-0.5 bg-blue-500/10 rounded">
                        <?= formatBps($max_rx_rate) ?>
                    </span>
                </div>
                <?php endif; ?>
            </div>

            <!-- 10. Outgoing Connections -->
            <div onclick="openWanSessionsModal()" class="glass-card p-6 stat-glow-amber relative overflow-hidden group cursor-pointer hover:scale-[1.02] transition-all">
                <div class="flex justify-between items-center mb-5">
                    <div class="p-3 bg-amber-500/10 rounded-xl text-amber-400">
                        <i data-lucide="arrow-up-from-line" class="w-6 h-6"></i>
                    </div>
                    <div class="text-[12px] font-mono text-amber-400 font-black uppercase tracking-widest">SUMA OUT</div>
                </div>
                <div class="text-[18px] font-black tracking-tight text-white" id="local-tx-val"><?= formatBps($total_clients_tx) ?></div>
                <div class="text-slate-500 text-[12px] mt-1 font-black uppercase tracking-[0.15em]">Ruch Lokalny (OUT)</div>

                <?php if ($top_uploader && $max_tx_rate > 50000): ?>
                <div class="mt-3 pt-3 border-t border-white/5 flex items-center justify-between">
                    <div class="flex items-center gap-2 min-w-0">
                        <div class="w-1.5 h-1.5 rounded-full bg-amber-500 pulse"></div>
                        <span class="text-[12px] text-slate-400 font-bold truncate">
                            <?= htmlspecialchars($top_uploader['name'] ?? $top_uploader['hostname'] ?? 'Nieznany') ?>
                        </span>
                    </div>
                    <span class="text-[12px] font-mono text-amber-400 font-black px-1.5 py-0.5 bg-amber-500/10 rounded">
                        <?= formatBps($max_tx_rate) ?>
                    </span>
                </div>
            <?php endif; ?>
            </div>
            
            <!-- 8. Packet Loss -->
            <div class="glass-card p-6 stat-glow-amber">
                <div class="flex justify-between items-center mb-5">
                    <div class="p-3 bg-amber-500/10 rounded-xl text-amber-400">
                        <i data-lucide="activity" class="w-6 h-6"></i>
                    </div>
                    <div class="text-[12px] font-mono text-amber-400 font-black uppercase tracking-widest" id="packet-loss-val-top">0.0%</div>
                </div>
                <div class="text-3xl font-black tracking-tighter text-white" id="packet-loss-val-main">0.0 <span class="text-sm text-slate-500 font-bold uppercase tracking-widest">%</span></div>
                <div class="text-slate-500 text-[12px] mt-1 font-black uppercase tracking-[0.2em]">Straty Pakietów</div>
            </div>

            <!-- 7. Latency -->
            <div class="glass-card p-5 stat-glow-blue cursor-pointer transition hover:scale-[1.02] active:scale-95 group relative overflow-hidden" onclick="openPingModal()">
                <div class="flex justify-between items-center mb-4">
                    <div class="p-2.5 bg-blue-500/10 rounded-xl text-blue-400 group-hover:bg-blue-500/20 transition-colors">
                        <i data-lucide="timer" class="w-5 h-5"></i>
                    </div>
                    <span class="text-[12px] font-black text-slate-500 uppercase tracking-widest">Ping (ms)</span>
                </div>
                
                <div class="space-y-2 relative z-10">
                    <div class="flex justify-between items-center bg-white/[0.02] p-2 rounded-lg border border-white/5">
                        <div class="flex items-center gap-2">
                            <span class="w-1.5 h-1.5 rounded-full bg-blue-400 pulse"></span>
                            <span class="text-[11px] font-bold text-slate-400 uppercase tracking-widest">Cloudflare</span>
                        </div>
                        <span id="ping-val-1.1.1.1" class="text-sm font-black text-white font-mono">--</span>
                    </div>
                    <div class="flex justify-between items-center bg-white/[0.02] p-2 rounded-lg border border-white/5">
                        <div class="flex items-center gap-2">
                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 pulse"></span>
                            <span class="text-[11px] font-bold text-slate-400 uppercase tracking-widest">Google</span>
                        </div>
                        <span id="ping-val-8.8.8.8" class="text-sm font-black text-white font-mono">--</span>
                    </div>
                    <div class="flex justify-between items-center bg-white/[0.02] p-2 rounded-lg border border-white/5">
                        <div class="flex items-center gap-2">
                            <span class="w-1.5 h-1.5 rounded-full bg-purple-400 pulse"></span>
                            <span class="text-[11px] font-bold text-slate-400 uppercase tracking-widest">WP.pl</span>
                        </div>
                        <span id="ping-val-wp.pl" class="text-sm font-black text-white font-mono">--</span>
                    </div>
                </div>
                
                <div class="mt-3 text-[10px] text-slate-600 font-bold uppercase tracking-widest text-center">Opóźnienie Sieciowe</div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8 mb-12">
            <!-- WAN Status & Live Chart -->
            <div class="lg:col-span-2 glass-card p-8">
                <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-6 mb-8">
                    <div>
                        <div class="flex items-center gap-3 mb-1">
                            <h2 class="text-2xl font-black tracking-tight">Łącze WAN</h2>
                            <span class="px-2 py-0.5 <?= $wan_status === 'ONLINE' ? 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20' : 'bg-red-500/10 text-red-400 border-red-500/20' ?> text-[12px] font-bold rounded-md border">
                                <?= $wan_status ?>
                            </span>
                        </div>
                        <p class="text-slate-500 text-sm flex items-center gap-2">
                             <i data-lucide="globe" class="w-3.5 h-3.5"></i>
                             Publiczne IP: <span class="font-mono text-slate-300"><?= htmlspecialchars($wan_ip) ?></span>
                        </p>
                    </div>
                    <div class="flex gap-8">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-xl bg-emerald-500/10 flex items-center justify-center text-emerald-400">
                                <i data-lucide="download" class="w-5 h-5"></i>
                            </div>
                            <div>
                                <div class="text-[12px] text-slate-500 font-bold uppercase tracking-widest">Down</div>
                                <div class="text-lg font-bold font-mono" id="wan-rx-val"><?= formatBps($wan_rx) ?></div>
                            </div>
                        </div>
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-xl bg-amber-500/10 flex items-center justify-center text-amber-400">
                                <i data-lucide="upload" class="w-5 h-5"></i>
                            </div>
                            <div>
                                <div class="text-[12px] text-slate-500 font-bold uppercase tracking-widest">Up</div>
                                <div class="text-lg font-bold font-mono" id="wan-tx-val"><?= formatBps($wan_tx) ?></div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="h-[280px] w-full">
                    <canvas id="wanLiveChart"></canvas>
                </div>
            </div>

            <!-- VLAN List -->
            <div class="glass-card p-8 flex flex-col">
                <div class="flex items-center gap-3 mb-8">
                    <div class="w-10 h-10 rounded-xl bg-blue-500/10 flex items-center justify-center text-blue-400">
                         <i data-lucide="layers" class="w-5 h-5"></i>
                    </div>
                    <h2 class="text-xl font-bold tracking-tight">Segmentacja VLAN</h2>
                </div>
                <div class="space-y-8 flex-grow">
                    <?php foreach ($vlan_stats as $name => $stat): ?>
                        <div class="group cursor-pointer" onclick="openVlanDetail('<?= htmlspecialchars($name, ENT_QUOTES) ?>')">
                            <div class="flex justify-between items-center mb-3">
                                <span class="text-[13px] font-black text-slate-200 group-hover:text-blue-400 transition-colors uppercase tracking-wider"><?= htmlspecialchars($name) ?></span>
                                <div class="flex items-center gap-5">
                                    <div class="flex items-center gap-6 text-[13px] font-mono mr-2">
                                        <div class="flex flex-col items-end">
                                            <span class="text-emerald-400 font-bold"><?= formatBps(($stat['rx'] ?? 0) * 8) ?> ↓</span>
                                            <span class="text-slate-600 text-[10px] font-black uppercase tracking-tighter"><?= format_bytes($stat['total_rx'] ?? 0) ?></span>
                                        </div>
                                        <div class="flex flex-col items-end">
                                            <span class="text-amber-400 font-bold"><?= formatBps(($stat['tx'] ?? 0) * 8) ?> ↑</span>
                                            <span class="text-slate-600 text-[10px] font-black uppercase tracking-tighter"><?= format_bytes($stat['total_tx'] ?? 0) ?></span>
                                        </div>
                                    </div>
                                    <span class="text-sm font-mono text-slate-400 bg-slate-800/80 px-3 py-1.5 rounded-xl border border-white/5 flex flex-col items-center min-w-[50px]">
                                        <span class="font-black text-white"><?= $stat['count'] ?></span>
                                        <span class="text-[11px] font-bold uppercase opacity-50 tracking-widest">devs</span>
                                    </span>
                                </div>
                            </div>
                            <div class="vlan-bar-container bg-slate-800/50 h-2.5 rounded-full overflow-hidden">
                                <div class="vlan-bar-fill h-full bg-gradient-to-r from-blue-600 to-indigo-400 rounded-full"
                                     style="width: <?= ($stat['count'] / max(count($clients), 1)) * 100 ?>%"></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="mt-8 pt-6 border-t border-white/5 text-xs text-slate-500 leading-relaxed italic">
                    Automatyczna detekcja podsieci w czasie rzeczywistym.
                </div>
            </div>
        </div>

    </div>


    <!-- Modal: Ping/Latency Details -->
    <div id="pingModal" class="modal-overlay" onclick="closePingModal(event)">
        <div class="modal-container max-w-5xl" onclick="event.stopPropagation()">
            <div class="modal-header flex items-center justify-between">
                <div>
                    <h2 class="text-xl font-bold flex items-center gap-2">
                        <i data-lucide="activity" class="w-6 h-6 text-blue-400"></i>
                        Szczegóły Opóźnień (Ping)
                    </h2>
                    <p class="text-slate-500 text-xs mt-1">Monitorowanie czasu reakcji do zdefiniowanych hostów</p>
                </div>
                
                <div class="flex items-center gap-4">
                     <div class="flex items-center gap-2">
                        <input type="text" id="custom-ping-ip" placeholder="IP / Hostname" class="bg-slate-900/50 border border-white/10 rounded-lg px-3 py-1.5 text-xs text-slate-300 focus:outline-none focus:ring-2 focus:ring-blue-500/50 w-32 placeholder:text-slate-600" onkeydown="if(event.key==='Enter') addPingHost()">
                        <button onclick="addPingHost()" class="p-1.5 bg-blue-500/10 hover:bg-blue-500/20 text-blue-400 rounded-lg transition border border-blue-500/20">
                            <i data-lucide="plus" class="w-4 h-4"></i>
                        </button>
                     </div>
                     <div class="h-8 w-[1px] bg-white/10 mx-2"></div>
                    <button onclick="closePingModal()" class="p-2 hover:bg-white/5 rounded-full transition text-slate-400 hover:text-white">
                        <i data-lucide="x" class="w-6 h-6"></i>
                    </button>
                </div>
            </div>
            <div class="modal-body p-6 space-y-6">
                <!-- Chart Section -->
                <div class="bg-slate-900/50 border border-white/5 rounded-2xl p-4 h-64 relative">
                    <canvas id="pingChart"></canvas>
                </div>

                <!-- Hosts Grid -->
                <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-4" id="ping-hosts-grid">
                    <!-- Dynamic Content -->
                    <div class="p-4 rounded-xl bg-slate-800/50 border border-white/5 flex items-center justify-between animate-pulse">
                        <div class="h-4 w-24 bg-slate-700 rounded"></div>
                        <div class="h-4 w-12 bg-slate-700 rounded"></div>
                    </div>
                </div>
            </div>
            <div class="modal-footer p-6 border-t border-white/5 flex justify-end">
                <button onclick="closePingModal()" class="px-6 py-2.5 bg-slate-800 hover:bg-slate-700 text-slate-300 rounded-xl text-xs font-bold uppercase tracking-widest transition">
                    Zamknij
                </button>
            </div>
        </div>
    </div>

    <!-- Modal: Active Clients -->
    <div id="clientsModal" class="modal-overlay" onclick="closeClientsModal(event)">
        <div class="modal-container h-[95vh] max-h-[98vh]" onclick="event.stopPropagation()" style="width: 70%; max-width: 2200px;">
            <div class="modal-header">
                <div>
                    <h2 class="text-2xl font-black flex items-center gap-3 text-white">
                        <i data-lucide="users" class="w-7 h-7 text-blue-400"></i>
                        Aktywni Klienci Sieci
                    </h2>
                    <p class="text-slate-500 text-xs mt-1 font-bold uppercase tracking-widest">Szczegółowa lista urządzeń i statystyki połączeń</p>
                </div>
                <div class="flex items-center gap-6 mr-4">
                    <div class="flex items-center gap-3">
                         <div class="relative">
                            <i data-lucide="search" class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-500"></i>
                            <input type="text" id="filter-search" oninput="handleSearchInput(this)" placeholder="Szukaj..." class="pl-10 bg-slate-900/50 border border-white/10 rounded-xl py-2 text-sm text-slate-300 focus:outline-none focus:ring-2 focus:ring-blue-500/50 w-48 placeholder:text-slate-600 transition-all">
                         </div>
                         <div class="h-6 w-[1px] bg-white/10 mx-1"></div>
                         <span class="text-[12px] font-black text-slate-500 uppercase tracking-widest hidden sm:inline">Filtry:</span>
                         <select id="filter-type" onchange="filterClients()" class="bg-slate-900/50 border border-white/10 rounded-xl px-4 py-2 text-xs font-bold text-slate-300 focus:outline-none focus:ring-2 focus:ring-blue-500/50 appearance-none cursor-pointer hover:bg-slate-800 transition-colors">
                             <option value="all">Wszystkie typy</option>
                             <option value="wifi">Wi-Fi</option>
                             <option value="wired">Przewodowe</option>
                             <option value="vpn">VPN</option>
                         </select>
                         <select id="filter-vlan" onchange="filterClients()" class="bg-slate-900/50 border border-white/10 rounded-xl px-4 py-2 text-xs font-bold text-slate-300 focus:outline-none focus:ring-2 focus:ring-blue-500/50 appearance-none cursor-pointer hover:bg-slate-800 transition-colors">
                             <option value="all">Wszystkie Sieci</option>
                             <?php 
                                $all_vlans = get_vlans();
                                ksort($all_vlans);
                                foreach($all_vlans as $id => $name): 
                             ?>
                                <option value="<?= $id ?>"><?= htmlspecialchars($name) ?></option>
                             <?php endforeach; ?>
                         </select>
                    </div>
                </div>
                <button onclick="closeClientsModal()" class="p-2.5 hover:bg-white/5 rounded-xl transition text-slate-400 hover:text-white border border-white/5">
                    <i data-lucide="x" class="w-6 h-6"></i>
                </button>
            </div>
            <div class="modal-body custom-scrollbar !p-0">
                <table class="client-table w-full" id="clients-table">
                    <thead>
                        <tr class="bg-white/[0.02]">
                            <th onclick="sortTable(0)" class="cursor-pointer hover:text-blue-400 transition-colors py-5 px-6">Urządzenie <i data-lucide="chevrons-up-down" class="inline w-3 h-3 ml-1 opacity-50"></i></th>
                            <th onclick="sortTable(1)" class="cursor-pointer hover:text-blue-400 transition-colors py-5 px-4">Typ / Szybkość <i data-lucide="chevrons-up-down" class="inline w-3 h-3 ml-1 opacity-50"></i></th>
                            <th onclick="sortTable(2)" class="cursor-pointer hover:text-blue-400 transition-colors py-5 px-4">Sieć / VLAN <i data-lucide="chevrons-up-down" class="inline w-3 h-3 ml-1 opacity-50"></i></th>
                            <th onclick="sortTable(3)" class="cursor-pointer hover:text-blue-400 transition-colors py-5 px-4">IP / MAC <i data-lucide="chevrons-up-down" class="inline w-3 h-3 ml-1 opacity-50"></i></th>
                            <th onclick="sortTable(4)" class="cursor-pointer hover:text-blue-400 transition-colors py-5 px-4 whitespace-nowrap">Uptime <i data-lucide="chevrons-up-down" class="inline w-3 h-3 ml-1 opacity-50"></i></th>
                            <th class="text-right py-5 px-6">Akcja</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/5">
                        <?php foreach ($clients as $client): 
                            $mac = normalize_mac($client['macAddress'] ?? $client['mac'] ?? '');
                            $client_name = $client['name'] ?? $client['hostname'] ?? 'Nieznany';
                            $ip = $client['ipAddress'] ?? $client['ip'] ?? 'N/A';
                            $vlan = $client['vlan'] ?? 0;
                            
                            if (empty($mac) && ($client['is_vpn'] ?? false)) {
                                $seed = $client_name . $ip;
                                $hash = md5($seed);
                                $mac = sprintf("02:56:50:%s:%s:%s", substr($hash,0,2), substr($hash,2,2), substr($hash,4,2));
                                $mac = strtoupper($mac);
                            }

                            $is_monitored = isset($devices_config[$mac]);
                            $is_wired = $client['is_wired'] ?? false;
                            $essid = $client['essid'] ?? '';
                            $is_vpn = $client['is_vpn'] ?? false;
                            $signal = $client['signal'] ?? 0;
                            
                            $type_label = $is_vpn ? 'vpn' : ($is_wired ? 'wired' : 'wifi');
                            
                            $rx = ($client['rx_rate'] ?? $client['rx_bytes-r'] ?? 0) * 8;
                            $tx = ($client['tx_rate'] ?? $client['tx_bytes-r'] ?? 0) * 8;

                            $speed_str = ($rx > 0 || $tx > 0) ? formatBps($rx) . " / " . formatBps($tx) : "Brak ruchu";
                        ?>
                        <tr class="client-row hover:bg-white/[0.01] transition-colors group" data-type="<?= $type_label ?>" data-vlan="<?= $vlan ?>" data-ssid="<?= htmlspecialchars($essid) ?>">
                            <td class="py-5 px-6">
                                <div class="flex items-center gap-4">
                                    <div class="w-12 h-12 rounded-2xl bg-slate-800/80 flex items-center justify-center text-slate-400 border border-white/5 relative group-hover:scale-110 transition-transform">
                                        <i data-lucide="<?= $is_vpn ? 'shield' : ($is_wired ? 'monitor' : 'wifi') ?>" class="w-6 h-6"></i>
                                        <?php if (!$is_wired && $signal < 0): ?>
                                            <div class="absolute -bottom-1 -right-1 w-5 h-5 rounded-full bg-slate-950 border border-white/10 flex items-center justify-center">
                                                <div class="w-2 h-2 rounded-full <?= $signal > -65 ? 'bg-emerald-500' : ($signal > -75 ? 'bg-amber-500' : 'bg-red-500') ?> shadow-[0_0_8px_rgba(0,0,0,0.5)]"></div>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div>
                                        <div class="font-bold text-sm text-white leading-tight"><?= htmlspecialchars($client_name) ?></div>
                                        <div class="flex items-center gap-2 mt-1">
                                            <?php if ($is_monitored): ?>
                                                <span class="text-[11px] text-blue-400 uppercase font-black tracking-widest bg-blue-500/10 px-2 py-0.5 rounded-lg border border-blue-500/20">MONITOR</span>
                                            <?php endif; ?>
                                            <?php if ($is_vpn): ?>
                                                <span class="text-[11px] text-purple-400 uppercase font-black tracking-widest bg-purple-500/10 px-2 py-0.5 rounded-lg border border-purple-500/20">VPN Session</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </td>
                            <td class="py-5 px-4">
                                <div class="flex flex-col">
                                    <div class="flex items-center gap-2">
                                        <?php 
                                        $vpn_label_display = 'Standard VPN';
                                        if ($is_vpn) {
                                            if (!isset($global_vpn_networks)) { $global_vpn_networks = get_vpn_networks(); }
                                            foreach ($global_vpn_networks as $vnet) {
                                                if (ip_in_subnet($ip, $vnet['subnet'])) {
                                                    $vpn_label_display = $vnet['type'];
                                                    if ($vpn_label_display === 'vpn') $vpn_label_display = $vnet['name'];
                                                    break;
                                                }
                                            }
                                        }

                                        if ($is_wired): ?>
                                            <span class="text-xs font-black uppercase tracking-widest text-blue-400/80">Wired Connection</span>
                                        <?php elseif ($is_vpn): ?>
                                            <span class="text-xs font-black uppercase tracking-widest text-purple-400"><?= htmlspecialchars($vpn_label_display) ?></span>
                                        <?php else: ?>
                                            <span class="text-xs font-black uppercase tracking-widest text-amber-500">Wireless • <?= htmlspecialchars($essid) ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="text-[11px] font-mono text-slate-500 mt-1.5 flex items-center gap-2">
                                        <i data-lucide="activity" class="w-3.5 h-3.5 text-blue-500/50"></i>
                                        <span class="font-bold"><?= $speed_str ?></span>
                                    </div>
                                    <div class="text-[11px] font-mono text-slate-600 mt-1 flex items-center gap-2">
                                        <i data-lucide="database" class="w-3 h-3 text-slate-700"></i>
                                        <span><?= format_bytes($client['rx_bytes'] ?? 0) ?> / <?= format_bytes($client['tx_bytes'] ?? 0) ?></span>
                                    </div>
                                </div>
                            </td>
                            <td class="py-5 px-4">
                                <div class="flex flex-col gap-2">
                                    <div class="flex items-center gap-2">
                                        <span class="px-2 py-1 bg-slate-800/80 rounded-lg text-[12px] text-slate-300 font-black border border-white/10">VLAN <?= $vlan ?></span>
                                        <span class="text-xs font-bold text-slate-500"><?= get_vlan_name($vlan) ?></span>
                                    </div>
                                </div>
                            </td>
                            <td class="py-5 px-4">
                                <div class="flex flex-col gap-1">
                                    <div class="text-sm font-mono text-slate-200 font-bold flex items-center gap-2">
                                        <span class="w-2 h-2 rounded-full bg-emerald-500 shadow-[0_0_8px_rgba(16,185,129,0.4)]"></span>
                                        <?= htmlspecialchars($ip) ?>
                                    </div>
                                    <div class="text-[11px] font-mono text-slate-600 uppercase tracking-tighter"><?= htmlspecialchars($mac) ?></div>
                                </div>
                            </td>
                            <td class="py-5 px-4">
                                <div class="text-xs font-mono text-slate-400 font-bold" data-uptime="<?= $client['uptime'] ?? 0 ?>">
                                    <?= isset($client['uptime']) ? formatDuration($client['uptime']) : '0s' ?>
                                </div>
                            </td>
                            <td class="py-5 px-6 text-right">
                                <div class="flex items-center justify-end gap-3">
                                    <button onclick="toggleMonitor('<?= $mac ?>', '<?= addslashes($client_name) ?>', '<?= $vlan ?>', this)" 
                                            class="p-2.5 <?= $is_monitored ? 'text-blue-400 bg-blue-500/10 hover:bg-blue-600/20' : 'text-slate-500 bg-slate-800/50 hover:bg-slate-700' ?> rounded-xl transition border border-white/5" 
                                            title="<?= $is_monitored ? 'Wyłącz powiadomienia' : 'Monitoruj i powiadamiaj' ?>">
                                        <i data-lucide="<?= $is_monitored ? 'bell' : 'bell-off' ?>" class="w-4.5 h-4.5"></i>
                                    </button>
                                    <?php if ($is_monitored): ?>
                                    <a href="history.php?mac=<?= urlencode($mac) ?>" 
                                       class="p-2.5 bg-slate-800/50 hover:bg-slate-700 text-slate-400 hover:text-white rounded-xl transition border border-white/5" title="Historia Zdarzeń">
                                        <i data-lucide="scroll-text" class="w-4.5 h-4.5"></i>
                                    </a>
                                    <?php endif; ?>
                                    <button onclick='openClientDetail(<?= json_encode($client) ?>)' 
                                            class="p-2.5 bg-blue-500/10 hover:bg-blue-600/20 text-blue-400 rounded-xl transition border border-blue-500/20" title="Szczegóły">
                                        <i data-lucide="maximize-2" class="w-4.5 h-4.5"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <!-- End Modal: Active Clients -->

    <!-- Modal: WiFi Networks Details -->
    <div id="wifiModal" class="modal-overlay" onclick="closeWifiModal(event)">
        <div class="modal-container max-w-2xl p-0 overflow-hidden" onclick="event.stopPropagation()">
            <div class="p-6 border-b border-white/10 flex justify-between items-center">
                <div class="flex items-center gap-4">
                    <div class="p-4 bg-indigo-500/10 rounded-2xl text-indigo-400">
                        <i data-lucide="wifi" class="w-8 h-8"></i>
                    </div>
                    <div>
                        <h2 class="text-2xl font-black text-white">Rozgłaszane sieci WiFi</h2>
                        <p class="text-xs text-slate-500 uppercase tracking-[0.2em] font-bold font-mono">Wireless Networks Overview</p>
                    </div>
                </div>
                <button onclick="closeWifiModal()" class="p-3 text-slate-500 hover:text-white transition bg-white/5 rounded-2xl border border-white/5">
                    <i data-lucide="x" class="w-6 h-6"></i>
                </button>
            </div>
            <div class="p-6">
                <div class="space-y-3">
                    <?php foreach ($wifi_stats as $ssid => $count): ?>
                        <div onclick="filterByWifi('<?= htmlspecialchars(addslashes($ssid)) ?>')" class="glass-card p-6 min-h-[100px] hover:bg-white/5 transition flex justify-between items-center border border-white/5 group cursor-pointer rounded-2xl">
                            <div class="flex items-center gap-6">
                                <div class="w-14 h-14 rounded-2xl bg-indigo-500/5 text-indigo-400 flex items-center justify-center group-hover:scale-110 transition-transform shadow-inner border border-white/5">
                                    <i data-lucide="radio" class="w-7 h-7"></i>
                                </div>
                                <div>
                                    <div class="text-lg font-black text-white mb-1"><?= htmlspecialchars($ssid) ?></div>
                                    <div class="text-[12px] text-slate-500 font-black uppercase tracking-[0.2em]">Active Clients Connected</div>
                                </div>
                            </div>
                            <div class="flex items-center gap-5">
                                <div class="text-4xl font-black text-indigo-400 tracking-tighter"><?= $count ?></div>
                                <div class="w-10 h-10 rounded-xl bg-white/5 flex items-center justify-center text-slate-500 group-hover:text-white transition-colors border border-white/5">
                                    <i data-lucide="chevron-right" class="w-5 h-5"></i>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="p-6 border-t border-white/10 text-center">
                <p class="text-[12px] text-slate-600 uppercase font-black tracking-widest italic">Dane odświeżane automatycznie z kontrolera UniFi</p>
            </div>
        </div>
    </div>

    <!-- Modal: Client Info Details -->
    <div id="clientInfoModal" class="modal-overlay" onclick="closeClientInfoModal(event)">
        <div class="modal-container max-w-lg p-0 overflow-hidden" id="client-info-content" onclick="event.stopPropagation()">
            <!-- Content will be injected by JS -->
        </div>
    </div>

    <!-- Modal: UniFi Infrastructure Devices -->
    <div id="infrModal" class="modal-overlay" onclick="closeInfrModal(event)">
        <div class="modal-container max-w-5xl p-0 overflow-hidden" onclick="event.stopPropagation()">
            <div class="p-6 border-b border-white/10 flex justify-between items-center">
                <div class="flex items-center gap-4">
                    <div class="p-3 bg-purple-500/10 rounded-xl text-purple-400">
                        <i data-lucide="server" class="w-6 h-6"></i>
                    </div>
                    <div>
                        <h2 class="text-xl font-black text-white">Urządzenia UniFi</h2>
                        <p class="text-[12px] text-slate-500 uppercase tracking-widest font-bold font-mono">Infrastructure Overview</p>
                    </div>
                </div>
                <button onclick="closeInfrModal()" class="p-2 text-slate-500 hover:text-white transition bg-white/5 rounded-xl border border-white/5">
                    <i data-lucide="x" class="w-5 h-5"></i>
                </button>
            </div>
            <div class="max-h-[70vh] overflow-y-auto p-6 custom-scrollbar">
                <div class="space-y-12">
                    <?php 
                    // 0. Build Map for Uplink Names
                    $dev_mac_to_name = [];
                    foreach ($trad_devices as $td) {
                        $dev_mac_to_name[$td['mac']] = $td['name'] ?? $td['model'] ?? $td['mac'];
                    }

                    // Grouping infrastructure
                    $grouped_infr = [
                        'Network' => [],
                        'Protect' => [],
                        'Access' => [],
                        'Talk' => []
                    ];
                    
                    foreach ($trad_devices as $d) {
                        $type = $d['type'] ?? '';
                        if (in_array($type, ['uap', 'usw', 'ugw', 'udm', 'uxg'])) {
                            $grouped_infr['Network'][] = $d;
                        } else {
                            // Basic heuristic for other apps if any reveal themselves in this API
                            $grouped_infr['Network'][] = $d; 
                        }
                    }
                    
                    foreach ($grouped_infr as $app => $devs): 
                        if (empty($devs)) continue;
                    ?>
                        <div class="space-y-4">
                             <div class="flex items-center gap-4">
                                <span class="text-[12px] font-black text-slate-500 uppercase tracking-[0.3em]"><?= $app ?></span>
                                <div class="h-[1px] flex-grow bg-white/10"></div>
                            </div>
                            <div class="overflow-x-auto">
                                <table class="w-full text-left">
                                     <thead>
                                        <tr class="text-[12px] font-black uppercase text-slate-500 tracking-[0.2em] border-b border-white/5">
                                            <th class="py-5 px-4 text-center">Status</th>
                                            <th class="py-5 px-4">Urządzenie</th>
                                            <th class="py-5 px-4">Adres IP</th>
                                            <th class="py-5 px-4">Metoda Połączenia (Parent)</th>
                                            <th class="py-5 px-4 text-center">Klienci</th>
                                            <th class="py-5 px-4 text-right">Uptime</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-white/5">
                                        <?php foreach ($devs as $d): 
                                            $state = $d['state'] ?? 0; // 0=disconnected, 1=connected
                                            $status_color = ($state == 1) ? 'bg-emerald-500 shadow-[0_0_8px_rgba(16,185,129,0.5)]' : (($state == 0) ? 'bg-red-500' : 'bg-amber-500');
                                            $status_text = ($state == 1) ? 'Connected' : (($state == 0) ? 'Disconnected' : 'Pending');
                                            
                                            // Uplink resolving
                                            $uplink_mac = $d['uplink']['uplink_mac'] ?? '';
                                            $uplink_name = '-';
                                            if (!empty($uplink_mac) && isset($dev_mac_to_name[$uplink_mac])) {
                                                $uplink_name = $dev_mac_to_name[$uplink_mac];
                                            } elseif (!empty($d['uplink_device_name'])) {
                                                 $uplink_name = $d['uplink_device_name'];
                                            } elseif (empty($uplink_mac) && ($d['type'] == 'ugw' || $d['type'] == 'udm')) {
                                                $uplink_name = 'WAN / Internet';
                                            } elseif (empty($uplink_mac)) {
                                                $uplink_name = 'Standalone / Root';
                                            }
                                        ?>
                                             <tr class="hover:bg-white/[0.02] transition-colors group cursor-pointer" onclick="toggleAPClients(this, '<?= htmlspecialchars($d['mac'] ?? '') ?>', '<?= htmlspecialchars($d['device_id'] ?? $d['_id'] ?? '') ?>')">
                                                <td class="py-6 px-4">
                                                    <div class="flex items-center justify-center">
                                                        <div class="w-3.5 h-3.5 rounded-full <?= $status_color ?> shadow-[0_0_12px_rgba(0,0,0,0.3)]"></div>
                                                    </div>
                                                </td>
                                                <td class="py-6 px-4">
                                                    <div class="flex items-center gap-4">
                                                        <div class="w-10 h-10 rounded-xl bg-slate-900 flex items-center justify-center text-slate-400 group-hover:text-purple-400 transition-all shadow-inner border border-white/5 group-hover:scale-110">
                                                            <i data-lucide="<?= ($d['type'] == 'uap') ? 'wifi' : (($d['type'] == 'usw') ? 'layers' : 'shield') ?>" class="w-4 h-4"></i>
                                                        </div>
                                                        <div class="flex flex-col">
                                                            <?php 
                                                                $displayName = $d['name'] ?? $d['hostname'] ?? $d['model'] ?? 'Unknown';
                                                                $subName = ($displayName === $d['model']) ? ($d['mac'] ?? '') : ($d['model'] ?? '');
                                                            ?>
                                                            <span class="text-[14px] font-black text-white leading-none mb-0.5"><?= htmlspecialchars($displayName) ?></span>
                                                            <span class="text-[10px] font-mono text-slate-500 font-black uppercase tracking-widest"><?= htmlspecialchars($subName) ?></span>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td class="py-6 px-4">
                                                    <span class="text-lg font-mono text-slate-300 font-bold"><?= $d['ip'] ?? 'N/A' ?></span>
                                                </td>
                                                <td class="py-6 px-4">
                                                    <div class="flex flex-col gap-1">
                                                        <span class="text-base text-slate-200 font-black max-w-[200px] truncate" title="<?= htmlspecialchars($uplink_name) ?>">
                                                            <?= htmlspecialchars($uplink_name) ?>
                                                        </span>
                                                        <?php if (!empty($d['uplink']['uplink_remote_port'])): ?>
                                                            <span class="text-xs text-slate-600 font-black uppercase tracking-widest">Port <?= $d['uplink']['uplink_remote_port'] ?></span>
                                                        <?php endif; ?>
                                                    </div>
                                                </td>
                                                <td class="py-6 px-4 text-center">
                                                    <span class="text-2xl font-black text-purple-400 tracking-tighter"><?= $d['num_sta'] ?? 0 ?></span>
                                                </td>
                                                <td class="py-6 px-4 text-right">
                                                    <span class="text-[12px] font-mono text-white whitespace-nowrap"><?= formatDuration($d['uptime'] ?? 0) ?></span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="p-6 bg-slate-900/80 border-t border-white/5">
                <button onclick="closeInfrModal()" class="w-full py-3 bg-slate-800 hover:bg-slate-700 text-slate-300 rounded-xl font-bold text-xs transition border border-white/5">
                    Zamknij panel sprzętu
                </button>
            </div>
        </div>
    </div>

    <script>
        // Init Lucide Icons
        lucide.createIcons();

        // WAN Chart Logic - wrapped in try-catch to prevent blocking other scripts
        let wanChart = null;
        try {
            const chartCanvas = document.getElementById('wanLiveChart');
            if (chartCanvas) {
                const ctx = chartCanvas.getContext('2d');
                const gradientRx = ctx.createLinearGradient(0, 0, 0, 250);
                gradientRx.addColorStop(0, 'rgba(16, 185, 129, 0.3)');
                gradientRx.addColorStop(1, 'rgba(16, 185, 129, 0)');

                const gradientTx = ctx.createLinearGradient(0, 0, 0, 250);
                gradientTx.addColorStop(0, 'rgba(245, 158, 11, 0.2)');
                gradientTx.addColorStop(1, 'rgba(245, 158, 11, 0)');

                wanChart = new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: [],
                        datasets: [
                            {
                                label: 'RX (Download)',
                                data: [],
                                borderColor: '#10b981',
                                backgroundColor: gradientRx,
                                fill: true,
                                tension: 0.4,
                                borderWidth: 2,
                                pointRadius: 0
                            },
                            {
                                label: 'TX (Upload)',
                                data: [],
                                borderColor: '#f59e0b',
                                backgroundColor: gradientTx,
                                fill: true,
                                tension: 0.4,
                                borderWidth: 2,
                                pointRadius: 0
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: { display: false }
                        },
                        scales: {
                            x: {
                                grid: { display: false },
                                ticks: { display: true, color: '#64748b', font: { size: 10 } }
                            },
                            y: {
                                beginAtZero: true,
                                grid: { color: 'rgba(255,255,255,0.05)' },
                                ticks: {
                                    color: '#64748b',
                                    font: { size: 10 },
                                    callback: function(value) {
                                        return (value / 1000000).toFixed(1) + ' Mb';
                                    }
                                }
                            }
                        }
                    }
                });
            }
        } catch (e) {
            console.warn('WAN Chart initialization error:', e);
        }

        // Function to update summary pings in tile
        window.updateDashboardPings = function updateDashboardPings() {
            const targets = [
                { name: 'Cloudflare', host: '1.1.1.1' },
                { name: 'Google', host: '8.8.8.8' },
                { name: 'WP.pl', host: 'wp.pl' }
            ];
            
            fetch('api_ping.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ hosts: targets })
            })
            .then(r => r.json())
            .then(json => {
                if (json.data) {
                    json.data.forEach(res => {
                        const el = document.getElementById(`ping-val-${res.host}`);
                        if (el) {
                            el.innerText = res.status === 'online' ? res.latency + ' ms' : 'OFF';
                            el.className = `text-sm font-black font-mono ${res.status === 'online' ? (res.latency < 50 ? 'text-emerald-400' : (res.latency < 100 ? 'text-amber-400' : 'text-red-400')) : 'text-slate-600'}`;
                        }
                    });
                }
            })
            .catch(e => console.warn('Dashboard ping update error:', e));
        };

        // Function to update stats
        window.updateStats = function updateStats() {
            updateDashboardPings(); // Refresh pings too
            if (!wanChart) return;
            // Call the PHP script directly to force a refresh and get latest data
            fetch('update_wan.php')
                .then(r => r.json())
                .then(data => {
                    const last20 = data.slice(-20);
                    wanChart.data.labels = last20.map(d => {
                        const date = new Date(d.timestamp * 1000);
                        return date.getHours() + ':' + String(date.getMinutes()).padStart(2, '0') + ':' + String(date.getSeconds()).padStart(2, '0');
                    });
                    wanChart.data.datasets[0].data = last20.map(d => d.rx);
                    wanChart.data.datasets[1].data = last20.map(d => d.tx);
                    wanChart.update('none');

                    if (last20.length > 0) {
                        const last = last20[last20.length - 1];
                        const rxEl = document.getElementById('wan-rx-val');
                        const txEl = document.getElementById('wan-tx-val');
                        if (rxEl) rxEl.innerText = formatBps(last.rx);
                        if (txEl) txEl.innerText = formatBps(last.tx);
                    }
                    
                    // Animate refresh button icon if exists
                    const btn = document.querySelector('button[title="Odśwież dane"] i');
                    if(btn) {
                        btn.classList.add('animate-spin');
                        setTimeout(() => btn.classList.remove('animate-spin'), 1000);
                    }
                })
                .catch(e => console.warn('Update stats error:', e));
        };

        window.refreshClientsList = async function() {
            try {
                const response = await fetch('index.php');
                const text = await response.text();
                const parser = new DOMParser();
                const doc = parser.parseFromString(text, 'text/html');
                const newBody = doc.querySelector('#clients-table tbody');
                const oldBody = document.querySelector('#clients-table tbody');
                if (newBody && oldBody) {
                    oldBody.innerHTML = newBody.innerHTML;
                    lucide.createIcons();
                    // Re-apply filters if any
                    if (typeof filterClients === 'function') filterClients();
                }
            } catch (e) {
                console.error('Refresh clients failed:', e);
            }
        };
        
        // Alias for navbar button
        window.refreshDashboard = window.updateStats;

        function formatBps(bps) {
            if (bps >= 1000000000000) return (bps / 1000000000000).toFixed(2) + ' Tbps';
            if (bps >= 1000000000) return (bps / 1000000000).toFixed(2) + ' Gbps';
            if (bps >= 1000000) return (bps / 1000000).toFixed(1) + ' Mbps';
            if (bps >= 1000) return (bps / 1000).toFixed(1) + ' Kbps';
            return Math.round(bps) + ' bps';
        }

        function formatBytes(bytes, decimals = 2) {
            if (!+bytes) return '0 B';
            const k = 1024;
            const dm = decimals < 0 ? 0 : decimals;
            const sizes = ['B', 'KB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return `${parseFloat((bytes / Math.pow(k, i)).toFixed(dm))} ${sizes[i]}`;
        }

        // Poll every 30 seconds (same as update_wan.php frequency likely)
        setInterval(updateStats, 30000);
        updateStats();

        function openClientsModal() {
            document.getElementById('clientsModal').classList.add('active');
            document.body.style.overflow = 'hidden';
            lucide.createIcons(); // Refresh icons inside modal
        }

        function closeClientsModal(e) {
            document.getElementById('clientsModal').classList.remove('active');
            document.body.style.overflow = '';
        }

        function handleSearchInput(input) {
            if (input.getAttribute('data-exact-match') === 'true') {
                 input.setAttribute('data-exact-match', 'false');
            }
            filterClients();
        }

        function filterClients() {
            const searchInput = document.getElementById('filter-search');
            const searchValue = searchInput.value.toLowerCase();
            const isExactMatch = searchInput.getAttribute('data-exact-match') === 'true';
            
            const typeValue = document.getElementById('filter-type').value;
            const vlanValue = document.getElementById('filter-vlan').value;
            const rows = document.querySelectorAll('.client-row');

            rows.forEach(row => {
                const rowType = row.getAttribute('data-type');
                const rowVlan = row.getAttribute('data-vlan');
                const rowSsid = (row.getAttribute('data-ssid') || '').toLowerCase();
                const rowText = row.innerText.toLowerCase();

                const typeMatch = (typeValue === 'all' || rowType === typeValue);
                const vlanMatch = (vlanValue === 'all' || rowVlan === vlanValue);
                
                let searchMatch = false;
                if (searchValue === '') {
                    searchMatch = true;
                } else if (isExactMatch) {
                    searchMatch = (rowSsid === searchValue);
                } else {
                    searchMatch = rowText.includes(searchValue);
                }

                row.style.display = (typeMatch && vlanMatch && searchMatch) ? '' : 'none';
            });
        }

        function filterByWifi(ssid) {
            closeWifiModal();
            openClientsModal();
            
            document.getElementById('filter-type').value = 'wifi';
            
            const searchInput = document.getElementById('filter-search');
            searchInput.value = ssid;
            searchInput.setAttribute('data-exact-match', 'true');
            
            filterClients();
        }

        function sortTable(n) {
            const table = document.getElementById("clients-table");
            const tbody = table.querySelector("tbody");
            const rows = Array.from(tbody.rows);
            const isAsc = table.getAttribute('data-sort-dir') === 'asc' && table.getAttribute('data-sort-col') == n;
            const dir = isAsc ? -1 : 1;
            
            rows.sort((a, b) => {
                let x = a.cells[n].textContent.toLowerCase().trim();
                let y = b.cells[n].textContent.toLowerCase().trim();
                
                // IP Address special handling
                if (n === 3) {
                    x = x.split('\n')[0].trim().split('.').map(num => num.padStart(3, '0')).join('.');
                    y = y.split('\n')[0].trim().split('.').map(num => num.padStart(3, '0')).join('.');
                }
                
                // Uptime column special handling (n=4)
                if (n === 4) {
                    x = parseInt(a.cells[n].querySelector('[data-uptime]')?.getAttribute('data-uptime') || 0);
                    y = parseInt(b.cells[n].querySelector('[data-uptime]')?.getAttribute('data-uptime') || 0);
                    return (x - y) * dir;
                }
                
                if (x < y) return -1 * dir;
                if (x > y) return 1 * dir;
                return 0;
            });
            
            rows.forEach(row => tbody.appendChild(row));
            table.setAttribute('data-sort-dir', isAsc ? 'desc' : 'asc');
            table.setAttribute('data-sort-col', n);
        }

        function openClientDetail(client) {
            const modal = document.getElementById('clientInfoModal');
            const content = document.getElementById('client-info-content');
            
            // Map UniFi keys to common format
            const name = client.name || client.hostname || 'Nieznane';
            const mac = client.mac || client.macAddress || 'no-mac';
            const ip = client.ip || client.ipAddress || 'DHCP';
            const uptimeNum = client.uptime || 0;
            const rx = client.rx_bytes || 0;
            const tx = client.tx_bytes || 0;
            // Rates from UniFi are in Bytes per second, multiply by 8 for bps
            const rxRate = (client.rx_bytes_r || client['rx_bytes-r'] || client.rx_rate || 0) * 8;
            const txRate = (client.tx_bytes_r || client['tx_bytes-r'] || client.tx_rate || 0) * 8;
            const signal = client.rssi || client.signal || null;
            const isWired = client.is_wired || false;

            content.innerHTML = `
                <div class="p-8 bg-gradient-to-br from-slate-800 to-slate-900 border-b border-white/5">
                    <div class="flex justify-between items-start mb-6">
                        <div class="flex items-center gap-5">
                            <div class="w-14 h-14 rounded-2xl bg-blue-500/10 text-blue-400 flex items-center justify-center shadow-lg border border-blue-500/20">
                                <i data-lucide="${isWired ? 'monitor' : 'wifi'}" class="w-7 h-7"></i>
                            </div>
                            <div>
                                <h2 class="text-xl font-black text-white leading-tight">${name}</h2>
                                <div class="flex items-center gap-2 mt-1">
                                    <span class="text-[12px] font-mono text-slate-500 uppercase tracking-wider">${mac}</span>
                                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span>
                                    <span class="text-[11px] font-black uppercase text-slate-500 tracking-widest">Online</span>
                                </div>
                            </div>
                        </div>
                        <button onclick="closeClientInfoModal()" class="p-2 text-slate-500 hover:text-white transition bg-white/5 rounded-xl border border-white/5">
                            <i data-lucide="x" class="w-5 h-5"></i>
                        </button>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div class="bg-slate-900/40 p-4 rounded-2xl border border-white/5">
                            <span class="text-xs font-black text-slate-600 uppercase tracking-widest">Adres IP</span>
                            <div class="text-base font-mono text-slate-200 mt-1">${ip}</div>
                        </div>
                        <div class="bg-slate-900/40 p-4 rounded-2xl border border-white/5">
                            <span class="text-xs font-black text-slate-600 uppercase tracking-widest">Uptime</span>
                            <div class="text-base font-mono text-slate-200 mt-1">${formatUptime(uptimeNum)}</div>
                        </div>
                    </div>
                </div>

                <div class="p-8 bg-slate-900/50">
                    <div class="space-y-6">
                        <div class="grid grid-cols-2 gap-6">
                            <div>
                                <span class="text-xs font-black text-slate-600 uppercase tracking-widest block mb-1">Transfer Live</span>
                                <div class="flex items-center gap-3">
                                    <div class="p-1.5 bg-emerald-500/10 text-emerald-400 rounded-lg"><i data-lucide="arrow-down" class="w-3.5 h-3.5"></i></div>
                                    <span class="text-lg font-mono text-slate-200">${formatBps(rxRate)}</span>
                                </div>
                                <div class="flex items-center gap-3 mt-2">
                                    <div class="p-1.5 bg-amber-500/10 text-amber-400 rounded-lg"><i data-lucide="arrow-up" class="w-3.5 h-3.5"></i></div>
                                    <span class="text-lg font-mono text-slate-200">${formatBps(txRate)}</span>
                                </div>
                            </div>
                            <div>
                                <!-- Simple Total Display -->
                                <span class="text-xs font-black text-slate-600 uppercase tracking-widest block mb-2">Suma Danych (Total)</span>
                                <div class="space-y-2">
                                    <div class="flex justify-between items-center bg-slate-800/20 p-2 rounded-lg border border-white/5">
                                        <span class="text-xs text-slate-500 uppercase font-bold text-[11px]">Pobrane</span>
                                        <span class="text-sm font-mono text-slate-300">${formatBytes(rx)}</span>
                                    </div>
                                    <div class="flex justify-between items-center bg-slate-800/20 p-2 rounded-lg border border-white/5">
                                        <span class="text-xs text-slate-500 uppercase font-bold text-[11px]">Wysłane</span>
                                        <span class="text-sm font-mono text-slate-300">${formatBytes(tx)}</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- 3-Column historical stats -->
                        <div class="space-y-3">
                            <span class="text-xs font-black text-slate-600 uppercase tracking-widest block">Statystyki Zużycia Danych</span>
                            <div class="grid grid-cols-3 gap-3">
                                <div class="bg-slate-800/30 p-3 rounded-xl border border-white/5 text-center flex flex-col justify-center">
                                    <span class="block text-[12px] text-slate-500 font-bold uppercase tracking-wider mb-1">24 Godziny</span>
                                    <span id="c-stat-24h" class="block text-sm font-mono text-slate-400">
                                        <div class="flex justify-center"><div class="w-3 h-3 border-2 border-slate-500 border-t-transparent rounded-full animate-spin"></div></div>
                                    </span>
                                </div>
                                <div class="bg-slate-800/30 p-3 rounded-xl border border-white/5 text-center flex flex-col justify-center">
                                    <span class="block text-[12px] text-slate-500 font-bold uppercase tracking-wider mb-1">7 Dni</span>
                                    <span id="c-stat-7d" class="block text-sm font-mono text-slate-400">
                                        <div class="flex justify-center"><div class="w-3 h-3 border-2 border-slate-500 border-t-transparent rounded-full animate-spin"></div></div>
                                    </span>
                                </div>
                                <div class="bg-slate-800/30 p-3 rounded-xl border border-blue-500/10 text-center flex flex-col justify-center relative overflow-hidden">
                                     <div class="absolute inset-0 bg-blue-500/5"></div>
                                     <span class="block text-[12px] text-blue-400 font-bold uppercase tracking-wider mb-1 relative">Całkowite</span>
                                     <span class="block text-sm font-mono text-blue-100 relative font-bold">${formatBytes(parseFloat(rx) + parseFloat(tx))}</span>
                                </div>
                            </div>
                        </div>
                        
                        ${signal ? `
                        <div class="p-4 bg-slate-800/20 rounded-2xl border border-white/5">
                            <span class="text-xs font-black text-slate-600 uppercase tracking-widest block mb-2">Siła Sygnału WiFi</span>
                            <div class="flex items-center gap-4">
                                <div class="flex-grow h-1.5 bg-slate-700 rounded-full overflow-hidden">
                                    <div class="h-full bg-emerald-500" style="width: ${Math.min(100, Math.max(0, (signal + 100) * 1.5))}%"></div>
                                </div>
                                <span class="text-sm font-mono font-bold text-emerald-400">${signal} dBm</span>
                            </div>
                        </div>
                        ` : ''}
                    </div>
                    
                    <div class="mt-8 flex gap-3">
                        <a href="history.php?mac=${encodeURIComponent(mac)}" class="flex-1 flex items-center justify-center gap-2 py-3.5 bg-blue-600 hover:bg-blue-500 text-white rounded-xl font-bold transition shadow-xl shadow-blue-600/20 text-sm">
                            <i data-lucide="scroll-text" class="w-4 h-4"></i>
                            Zobacz pełną historię
                        </a>
                        <button onclick="deleteDeviceHistory('${mac}')" class="px-4 py-3.5 bg-red-500/10 hover:bg-red-500/20 text-red-500 rounded-xl font-bold transition border border-red-500/20" title="Usuń z historii">
                            <i data-lucide="trash-2" class="w-5 h-5"></i>
                        </button>
                        <button onclick="closeClientInfoModal()" class="px-6 py-3.5 bg-slate-800 hover:bg-slate-700 text-slate-300 rounded-xl font-bold transition text-sm">
                            Zamknij
                        </button>
                    </div>
                </div>
            `;
            
            modal.classList.add('active');
            lucide.createIcons();

            // Fetch historical stats
            fetch(`api_client_stats.php?mac=${encodeURIComponent(mac)}`)
                .then(r => r.json())
                .then(data => {
                    if (data.stats_24h) {
                        document.getElementById('c-stat-24h').innerText = formatBytes(data.stats_24h.total);
                    }
                    if (data.stats_7d) {
                        document.getElementById('c-stat-7d').innerText = formatBytes(data.stats_7d.total);
                    }
                })
                .catch(e => {
                    console.error('Error fetching stats:', e);
                    document.getElementById('c-stat-24h').innerText = '-';
                    document.getElementById('c-stat-7d').innerText = '-';
                });
        }

        function closeClientInfoModal(e) {
            if (e && e.target !== e.currentTarget) return;
            const m = document.getElementById('clientInfoModal');
            if (m) m.classList.remove('active');
        }

        async function deleteDeviceHistory(mac) {
            showConfirm('Czy na pewno chcesz usunąć to urządzenie z historii i monitoringu? Ta operacja jest nieodwracalna.', async () => {
                try {
                    const response = await fetch('api_toggle_monitor.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ mac, action: 'delete' })
                    });

                    const result = await response.json();
                    if (result.success) {
                        window.location.reload();
                    } else {
                        showToast(result.message || 'Nieznany błąd', 'error');
                    }
                } catch (e) {
                    console.error('Error:', e);
                    showToast('Wystąpił błąd podczas usuwania.', 'error');
                }
            });
        }

        function formatUptime(seconds) {
            if (!seconds) return "0min";
            const mo = Math.floor(seconds / 2592000);
            const d = Math.floor((seconds % 2592000) / 86400);
            const h = Math.floor((seconds % 86400) / 3600);
            const m = Math.floor((seconds % 3600) / 60);

            let parts = [];
            if (mo > 0) parts.push(`${mo}m`);
            if (d > 0) parts.push(`${d}d`);
            if (h > 0) parts.push(`${h}h`);
            if (m > 0 || parts.length === 0) parts.push(`${m}min`);
            
            return parts.join(" ");
        }

        async function toggleMonitor(mac, name, vlan, btn) {
            const isMonitored = btn.querySelector('svg')?.classList.contains('lucide-bell') || btn.querySelector('i')?.getAttribute('data-lucide') === 'bell';
            const action = isMonitored ? 'delete' : 'add';
            
            try {
                const response = await fetch('api_toggle_monitor.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ mac, name, vlan, action })
                });
                
                const text = await response.text();
                let result;
                try {
                    result = JSON.parse(text);
                } catch (e) {
                    console.error('Invalid JSON response:', text);
                    showToast('Błąd API: Nieprawidłowa odpowiedź serwera', 'error');
                    return;
                }

                if (result.success) {
                    // Show success toast
                    showToast(action === 'add' ? 'Dodano do obserwowanych' : 'Usunięto z obserwowanych', 'success');
                    
                    // Refresh current page if on monitored.php to show results immediately
                    if (window.location.pathname.includes('monitored.php')) {
                        window.location.reload();
                        return;
                    }

                    const histBtn = btn.parentNode.querySelector('.action-history-btn') || btn.parentNode.querySelector('a[href^="history.php"]');

                    if (action === 'add') {
                        btn.className = "p-2 text-blue-400 bg-blue-500/10 hover:bg-blue-600/20 rounded-lg transition border border-white/5";
                        btn.innerHTML = '<i data-lucide="bell" class="w-4 h-4"></i>';
                        btn.title = 'Wyłącz powiadomienia';
                        
                        if (histBtn) {
                             histBtn.classList.remove('hidden');
                        } else {
                             const newBtn = document.createElement('a');
                             newBtn.href = `history.php?mac=${encodeURIComponent(mac)}`;
                             newBtn.className = 'p-2 bg-slate-800 hover:bg-slate-700 text-slate-400 hover:text-white rounded-lg transition border border-white/5 action-history-btn';
                             newBtn.title = 'Historia Zdarzeń';
                             newBtn.innerHTML = '<i data-lucide="scroll-text" class="w-4 h-4"></i>';
                             btn.after(newBtn);
                        }
                    } else {
                        btn.className = "p-2 text-slate-500 bg-slate-800 hover:bg-slate-700 rounded-lg transition border border-white/5";
                        btn.innerHTML = '<i data-lucide="bell-off" class="w-4 h-4"></i>';
                        btn.title = 'Monitoruj i powiadamiaj';
                        
                        if (histBtn) histBtn.classList.add('hidden');
                    }
                    lucide.createIcons();
                } else {
                    showToast(result.message || 'Nieznany błąd', 'error');
                }
            } catch (err) {
                console.error(err);
                showToast('Błąd połączenia z API', 'error');
            }
        }

        function openWifiModal() {
            console.log('openWifiModal called');
            const modal = document.getElementById('wifiModal');
            console.log('wifiModal element:', modal);
            if (modal) {
                modal.classList.add('active');
                lucide.createIcons();
            } else {
                console.error('wifiModal not found!');
            }
        }

        function closeWifiModal(e) {
            if (e && e.target !== e.currentTarget) return;
            const m = document.getElementById('wifiModal');
            if (m) m.classList.remove('active');
        }

        function openInfrModal() {
            console.log('openInfrModal called');
            const modal = document.getElementById('infrModal');
            console.log('infrModal element:', modal);
            if (modal) {
                modal.classList.add('active');
                lucide.createIcons();
            } else {
                console.error('infrModal not found!');
            }
        }

        function closeInfrModal(e) {
            if (e && e.target !== e.currentTarget) return;
            const m = document.getElementById('infrModal');
            if (m) m.classList.remove('active');
        }

        // Close on Esc
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                closeClientsModal();
                closeClientInfoModal();
                closeInfrModal();
                closeWifiModal();
                closePingModal();
            }
        });

        // Ping Modal Logic
        let pingChart = null;
        let pingInterval = null;

        function openPingModal() {
            const modal = document.getElementById('pingModal');
            if (modal) {
                modal.classList.add('active');
                document.body.style.overflow = 'hidden';
                lucide.createIcons();
                initPingChart();
                startPingPolling();
            }
        }

        function closePingModal(e) {
            if (e && e.target !== e.currentTarget) return; // Allow clicking outside
            const modal = document.getElementById('pingModal');
            if (modal) {
                modal.classList.remove('active');
                document.body.style.overflow = '';
                stopPingPolling();
            }
        }

        function initPingChart() {
            const ctx = document.getElementById('pingChart').getContext('2d');
            if (pingChart) pingChart.destroy();
            
            pingChart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: [],
                    datasets: []
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: false,
                    plugins: {
                        legend: {
                            labels: { color: '#94a3b8', font: { size: 10, weight: 'bold' } }
                        }
                    },
                    scales: {
                        x: { display: false },
                        y: {
                            beginAtZero: true,
                            grid: { color: 'rgba(255,255,255,0.05)' },
                            ticks: { color: '#64748b', callback: v => v + ' ms' }
                        }
                    }
                }
            });
            
            // Also update real WAN Packet Loss and Health
            fetch('api_wan_health.php')
            .then(r => r.json())
            .then(json => {
                if (json.success && json.data) {
                    const loss = json.data.packet_loss || 0;
                    const lossEl = document.getElementById('packet-loss-val-main');
                    const lossTopEl = document.getElementById('packet-loss-val-top');
                    if (lossEl) lossEl.innerHTML = `${loss.toFixed(1)} <span class="text-sm text-slate-500 font-bold uppercase tracking-widest">%</span>`;
                    if (lossTopEl) lossTopEl.innerText = `${loss.toFixed(1)}%`;
                }
            });
        }

        function startPingPolling() {
            if (pingInterval) clearTimeout(pingInterval);
            fetchPingData(); 
        }

        function stopPingPolling() {
            if (pingInterval) clearTimeout(pingInterval);
            pingInterval = null;
        }

        // Ping Logic
        let pingHosts = [
            {name: 'Gateway', host: '10.0.0.1'},
            {name: 'Google DNS', host: '8.8.8.8'},
            {name: 'Cloudflare', host: '1.1.1.1'},
            {name: 'Onet.pl', host: 'onet.pl'},
            {name: 'Wirtualna Polska', host: 'wp.pl'}
        ];

        function addPingHost() {
            const input = document.getElementById('custom-ping-ip');
            const val = input.value.trim();
            if (!val) return;
            
            if (pingHosts.find(h => h.host === val)) {
                alert('Ten host jest już na liście.');
                return;
            }
            
            pingHosts.push({ name: val, host: val });
            input.value = '';
            
            stopPingPolling();
            startPingPolling(); 
        }

        function removePingHost(host) {
            pingHosts = pingHosts.filter(h => h.host !== host);
            stopPingPolling();
            startPingPolling();
        }

        async function fetchPingData() {
            const modal = document.getElementById('pingModal');
            if (!modal || !modal.classList.contains('active')) return;

            try {
                const res = await fetch('api_ping.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ hosts: pingHosts })
                });
                const json = await res.json();
                const data = json.data || [];
                
                updatePingChart(data);
                updatePingGrid(data);
            } catch (e) {
                console.error("Ping fetch error:", e);
            } finally {
                // Schedule next poll only if modal is still active
                if (modal.classList.contains('active')) {
                    pingInterval = setTimeout(fetchPingData, 2000);
                }
            }
        }

        function updatePingGrid(data) {
            const grid = document.getElementById('ping-hosts-grid');
            if (!grid) return;
            
            grid.innerHTML = data.map(h => {
                const isOnline = h.status === 'online';
                const color = isOnline ? (h.latency < 50 ? 'emerald' : (h.latency < 100 ? 'amber' : 'red')) : 'red';
                const isRemovable = !['8.8.8.8','1.1.1.1','onet.pl','10.0.0.1'].includes(h.host);

                return `
                <div class="p-4 rounded-xl bg-slate-900/60 border border-white/5 flex flex-col gap-1 transition hover:bg-white/5 group relative">
                    ${isRemovable ? `<button onclick="removePingHost('${h.host}')" class="absolute top-2 right-2 p-1 text-slate-600 hover:text-red-400 opacity-0 group-hover:opacity-100 transition"><i data-lucide="trash-2" class="w-3 h-3"></i></button>` : ''}
                    <div class="flex justify-between items-start">
                        <span class="text-[12px] uppercase font-bold text-slate-500 tracking-wider truncate pr-4">${h.name}</span>
                        <div class="w-2 h-2 rounded-full bg-${isOnline ? 'emerald-500' : 'red-500'} ${isOnline ? 'animate-pulse' : ''} shrink-0"></div>
                    </div>
                    <div class="flex items-baseline gap-1">
                        <span class="text-2xl font-black text-white tracking-tighter">${h.latency}</span>
                        <span class="text-xs font-bold text-slate-500">ms</span>
                    </div>
                    <div class="text-[11px] font-mono text-slate-600 truncate">${h.host}</div>
                </div>
                `;
            }).join('');
            lucide.createIcons();
        }

        function updatePingChart(data) {
            if (!pingChart) return;
            
            const now = new Date();
            const timeLabel = now.getHours() + ':' + String(now.getMinutes()).padStart(2,'0') + ':' + String(now.getSeconds()).padStart(2,'0');

            // Keep only last 20 points
            if (pingChart.data.labels.length > 20) {
                pingChart.data.labels.shift();
            }
            pingChart.data.labels.push(timeLabel);

            data.forEach((h, index) => {
                // Find or create dataset
                let dataset = pingChart.data.datasets.find(d => d.label === h.name);
                if (!dataset) {
                    // Define colors
                    const colors = ['#10b981', '#3b82f6', '#f59e0b', '#ec4899', '#8b5cf6'];
                    const color = colors[index % colors.length];
                    
                    dataset = {
                        label: h.name,
                        data: [],
                        borderColor: color,
                        backgroundColor: color + '20', // transparent fill
                        borderWidth: 2,
                        tension: 0.3,
                        pointRadius: 0
                    };
                    pingChart.data.datasets.push(dataset);
                }

                if (dataset.data.length > 20) dataset.data.shift();
                dataset.data.push(h.latency);
            });

            pingChart.update('none'); // Update without animation for smoothness
        }

        // Bind click events to cards using addEventListener (more reliable than inline onclick)
        document.addEventListener('DOMContentLoaded', function() {
            const wifiCard = document.getElementById('wifi-card');
            const infrCard = document.getElementById('infr-card');

            if (wifiCard) {
                wifiCard.addEventListener('click', function(e) {
                    console.log('WiFi card clicked');
                    openWifiModal();
                });
            }

            if (infrCard) {
                infrCard.addEventListener('click', function(e) {
                    console.log('Infr card clicked');
                    openInfrModal();
                });
            }
        });

        // AP Client Drilldown: Toggle WiFi clients connected to an AP
        async function toggleAPClients(row, apMac, deviceId) {
            const existingDetail = row.nextElementSibling;
            if (existingDetail && existingDetail.classList.contains('ap-detail-row')) {
                existingDetail.remove();
                return;
            }
            document.querySelectorAll('.ap-detail-row').forEach(r => r.remove());

            const detailRow = document.createElement('tr');
            detailRow.className = 'ap-detail-row';
            detailRow.innerHTML = '<td colspan="6" class="p-0"><div class="bg-slate-800/30 border-t border-b border-white/5 px-8 py-4"><div class="text-xs text-slate-500">Ladowanie klientow...</div></div></td>';
            row.after(detailRow);

            try {
                // Use existing client data from PHP (already loaded on page)
                const devMacNorm = apMac.toLowerCase().replace(/[^a-f0-9]/g, '');
                // All clients (wired + wireless) from traditional API
                const allClients = <?= json_encode(array_values(array_map(function($tc) {
                    return [
                        'name' => $tc['name'] ?? $tc['hostname'] ?? $tc['mac'] ?? '—',
                        'mac' => $tc['mac'] ?? '',
                        'ap_mac' => $tc['ap_mac'] ?? '',
                        'sw_mac' => $tc['sw_mac'] ?? '',
                        'sw_port' => $tc['sw_port'] ?? '',
                        'gw_mac' => $tc['gw_mac'] ?? '',
                        'essid' => $tc['essid'] ?? '',
                        'network' => $tc['network'] ?? '',
                        'signal' => $tc['signal'] ?? 0,
                        'ip' => $tc['ip'] ?? '',
                        'rx_rate' => $tc['rx_rate'] ?? 0,
                        'tx_rate' => $tc['tx_rate'] ?? 0,
                        'is_wired' => $tc['is_wired'] ?? false,
                        'wired_rate' => $tc['wired_rate_mbps'] ?? 0,
                    ];
                }, $trad_clients))) ?>;
                // Match: WiFi clients by ap_mac, wired by sw_mac, gateway by gw_mac
                const clients = allClients.filter(c => {
                    const apMac = (c.ap_mac || '').toLowerCase().replace(/[^a-f0-9]/g, '');
                    const swMac = (c.sw_mac || '').toLowerCase().replace(/[^a-f0-9]/g, '');
                    const gwMac = (c.gw_mac || '').toLowerCase().replace(/[^a-f0-9]/g, '');
                    return apMac === devMacNorm || swMac === devMacNorm || gwMac === devMacNorm;
                });

                if (clients.length === 0) {
                    detailRow.innerHTML = '<td colspan="6" class="p-0"><div class="bg-slate-800/30 border-t border-b border-white/5 px-8 py-4"><div class="text-xs text-slate-500">Brak klientow na tym urzadzeniu</div></div></td>';
                    return;
                }

                let html = '<td colspan="6" class="p-0"><div class="bg-slate-800/30 border-t border-b border-white/5 px-6 py-3">';
                html += '<table class="w-full"><thead><tr class="text-[12px] text-slate-500 uppercase"><th class="text-left py-1 px-2">Klient</th><th class="text-left py-1 px-2">Siec</th><th class="text-left py-1 px-2">Sygnal</th><th class="text-left py-1 px-2">Predkosc</th><th class="text-left py-1 px-2">IP</th></tr></thead><tbody>';
                clients.forEach(c => {
                    const isWired = c.is_wired;
                    const signal = c.signal || 0;
                    const rssiClass = isWired ? 'text-blue-400' : (signal > -50 ? 'text-emerald-400' : (signal > -70 ? 'text-amber-400' : 'text-red-400'));
                    const name = c.name || c.mac || '—';
                    const net = c.essid || c.network || '—';
                    const speedInfo = isWired
                        ? (c.wired_rate ? c.wired_rate + ' Mbps' : '—')
                        : ((c.rx_rate ? (c.rx_rate/1000).toFixed(0) : '0') + '/' + (c.tx_rate ? (c.tx_rate/1000).toFixed(0) : '0') + ' Mbps');
                    const signalInfo = isWired
                        ? '<span class="text-blue-400">Wired' + (c.sw_port ? ' P' + c.sw_port : '') + '</span>'
                        : (signal ? '<span class="' + rssiClass + '">' + signal + 'dBm</span>' : '—');
                    const ip = c.ip || '—';
                    html += '<tr class="border-t border-white/5"><td class="py-2 px-2 text-xs text-white">' + name + '</td><td class="py-2 px-2 text-[12px] text-purple-400">' + net + '</td><td class="py-2 px-2 text-xs font-mono">' + signalInfo + '</td><td class="py-2 px-2 text-[12px] text-slate-400">' + speedInfo + '</td><td class="py-2 px-2 text-[12px] text-slate-500 font-mono">' + ip + '</td></tr>';
                });
                html += '</tbody></table></div></td>';
                detailRow.innerHTML = html;
            } catch(e) {
                detailRow.innerHTML = '<td colspan="6" class="p-0"><div class="bg-slate-800/30 border-t border-b border-white/5 px-8 py-4"><div class="text-xs text-red-400">Blad ladowania: ' + e.message + '</div></div></td>';
            }
        }

        // Stalker Widget: Load active sessions count
        fetch('api_stalker.php?action=sessions&time=24h&band=&search=')
            .then(r => {
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.json();
            })
            .then(data => {
                const count = data.data ? data.data.length : 0;
                document.getElementById('stalker-widget-count').textContent = count;
                document.getElementById('stalker-widget-last').textContent = count > 0 ? 'Aktywnych polaczen' : 'Brak sesji WiFi';
            })
            .catch(e => {
                console.log('Stalker widget error:', e.message);
                document.getElementById('stalker-widget-count').textContent = '-';
                document.getElementById('stalker-widget-last').textContent = '';
            });

        // VLAN Detail Modal
        const vlanData = <?= json_encode($vlan_clients) ?>;
        const vlanStats = <?= json_encode($vlan_stats) ?>;

        function openVlanDetail(vlanName) {
            const clients = vlanData[vlanName] || [];
            const stats = vlanStats[vlanName] || {count:0, rx:0, tx:0};
            const modal = document.getElementById('vlanDetailModal');
            document.getElementById('vlan-detail-title').textContent = vlanName;
            document.getElementById('vlan-detail-count').textContent = clients.length + ' urzadzen';
            document.getElementById('vlan-detail-rx').textContent = formatBps((stats.rx || 0) * 8);
            document.getElementById('vlan-detail-tx').textContent = formatBps((stats.tx || 0) * 8);

            const tbody = document.getElementById('vlan-detail-body');
            if (clients.length === 0) {
                tbody.innerHTML = '<tr><td colspan="5" class="text-center py-6 text-slate-500 text-xs">Brak klientow</td></tr>';
            } else {
                // Sort by total traffic descending
                clients.sort((a,b) => (b.rx + b.tx) - (a.rx + a.tx));
                tbody.innerHTML = clients.map(c => {
                    const icon = c.is_wired ? 'monitor' : 'wifi';
                    return `<tr class="hover:bg-white/[0.02] transition-colors border-t border-white/5 group">
                        <td class="py-3 px-4">
                            <div class="flex items-center gap-2">
                                <i data-lucide="${icon}" class="w-3.5 h-3.5 text-slate-500 shrink-0"></i>
                                <span class="text-sm font-bold text-white truncate max-w-[150px] group-hover:text-blue-400 transition-colors">${c.name}</span>
                            </div>
                        </td>
                        <td class="py-3 px-4">
                            <div class="text-xs font-mono text-slate-300">${c.ip || '—'}</div>
                            <div class="text-[11px] font-mono text-slate-600 uppercase tracking-tighter">${c.mac || '—'}</div>
                        </td>
                        <td class="py-3 px-4 text-right">
                            <div class="text-xs font-bold text-emerald-400">${formatBps(c.rx * 8)}</div>
                            <div class="text-[12px] text-slate-500 font-mono">${formatBytes(c.rx_total)}</div>
                        </td>
                        <td class="py-3 px-4 text-right">
                            <div class="text-xs font-bold text-amber-400">${formatBps(c.tx * 8)}</div>
                            <div class="text-[12px] text-slate-500 font-mono">${formatBytes(c.tx_total)}</div>
                        </td>
                    </tr>`;
                }).join('');
            }
            modal.classList.remove('hidden');
            modal.classList.add('flex');
            if (typeof lucide !== 'undefined') lucide.createIcons();
        }
        function closeVlanDetail() {
            document.getElementById('vlanDetailModal').classList.add('hidden');
            document.getElementById('vlanDetailModal').classList.remove('flex');
        }
    </script>

    <!-- VLAN Detail Modal -->
    <div id="vlanDetailModal" class="fixed inset-0 bg-black/70 backdrop-blur-sm z-50 hidden items-center justify-center" onclick="if(event.target===this)closeVlanDetail()">
        <div class="bg-slate-900/95 backdrop-blur-xl border border-white/10 rounded-3xl w-full max-w-3xl max-h-[80vh] overflow-hidden shadow-2xl">
            <div class="p-8 pb-4 flex items-center justify-between">
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 rounded-2xl bg-blue-500/10 text-blue-400 flex items-center justify-center">
                        <i data-lucide="layers" class="w-6 h-6"></i>
                    </div>
                    <div>
                        <h2 class="text-xl font-bold text-white" id="vlan-detail-title">VLAN</h2>
                        <p class="text-xs text-slate-500" id="vlan-detail-count">0 urzadzen</p>
                    </div>
                </div>
                <div class="flex items-center gap-6 mr-8">
                    <div class="text-right">
                        <div class="text-[11px] text-slate-500 uppercase font-bold">Download</div>
                        <div class="text-sm font-bold text-emerald-400" id="vlan-detail-rx">0</div>
                    </div>
                    <div class="text-right">
                        <div class="text-[11px] text-slate-500 uppercase font-bold">Upload</div>
                        <div class="text-sm font-bold text-amber-400" id="vlan-detail-tx">0</div>
                    </div>
                </div>
                <button onclick="closeVlanDetail()" class="text-slate-500 hover:text-white transition">
                    <i data-lucide="x" class="w-6 h-6"></i>
                </button>
            </div>
            <div class="px-8 pb-8 overflow-y-auto max-h-[60vh] custom-scrollbar">
                <table class="w-full">
                    <thead>
                        <tr class="text-[11px] text-slate-500 uppercase tracking-wider font-bold border-b border-white/5">
                            <th class="text-left py-3 px-4">Klient</th>
                            <th class="text-left py-3 px-4">IP / MAC</th>
                            <th class="text-right py-3 px-4">Download (Live / Suma)</th>
                            <th class="text-right py-3 px-4">Upload (Live / Suma)</th>
                        </tr>
                    </thead>
                    <tbody id="vlan-detail-body"></tbody>
                </table>
            </div>
        </div>
    </div>
    <!-- WAN Flows Modal (Top clients by traffic) -->
    <div id="wanFlowsModal" class="fixed inset-0 bg-black/70 backdrop-blur-sm z-50 hidden items-center justify-center" onclick="if(event.target===this)closeWanFlowsModal()">
        <div class="bg-slate-900/95 backdrop-blur-xl border border-white/10 rounded-3xl w-full max-w-3xl max-h-[80vh] overflow-hidden shadow-2xl">
            <div class="p-6 flex items-center justify-between border-b border-white/5">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-blue-500/10 flex items-center justify-center text-blue-400">
                        <i data-lucide="activity" class="w-5 h-5"></i>
                    </div>
                    <div>
                        <h2 class="text-lg font-bold text-white">Aktywny ruch klientow</h2>
                        <p class="text-xs text-slate-500">Top 20 klientow wg transferu</p>
                    </div>
                </div>
                <button onclick="closeWanFlowsModal()" class="text-slate-500 hover:text-white transition">
                    <i data-lucide="x" class="w-6 h-6"></i>
                </button>
            </div>
            <div class="overflow-y-auto max-h-[65vh] p-4">
                <table class="w-full">
                    <thead>
                        <tr class="text-[9px] text-slate-500 uppercase tracking-wider font-bold border-b border-white/5">
                            <th class="text-left py-2 px-3">Klient</th>
                            <th class="text-left py-2 px-3">IP / Siec</th>
                            <th class="text-right py-2 px-3">Download</th>
                            <th class="text-right py-2 px-3">Upload</th>
                        </tr>
                    </thead>
                    <tbody id="wan-flows-modal-body">
                        <tr><td colspan="4" class="text-center py-8 text-slate-500 text-xs">Ladowanie...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
    function openWanFlowsModal() {
        const modal = document.getElementById('wanFlowsModal');
        modal.classList.remove('hidden');
        modal.classList.add('flex');
        loadWanFlowsModal();
        if (typeof lucide !== 'undefined') lucide.createIcons();
    }
    function closeWanFlowsModal() {
        document.getElementById('wanFlowsModal').classList.add('hidden');
        document.getElementById('wanFlowsModal').classList.remove('flex');
    }
    function loadWanFlowsModal() {
        fetch('api_wan_flows.php')
            .then(r => r.json())
            .then(data => {
                const flows = data.data || [];
                const tbody = document.getElementById('wan-flows-modal-body');
                if (flows.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="4" class="text-center py-8 text-slate-500 text-xs">Brak aktywnego ruchu</td></tr>';
                    return;
                }
                tbody.innerHTML = flows.map(f => {
                    const icon = f.is_wired ? 'monitor' : 'wifi';
                    return `<tr class="hover:bg-white/[0.02] transition-colors border-t border-white/5">
                        <td class="py-3 px-3"><div class="flex items-center gap-2"><i data-lucide="${icon}" class="w-4 h-4 text-slate-500 shrink-0"></i><span class="text-sm font-bold text-white truncate">${f.name}</span></div></td>
                        <td class="py-3 px-3"><div class="text-xs font-mono text-slate-400">${f.ip||'-'}</div><div class="text-[9px] text-purple-400">${f.network||'-'}</div></td>
                        <td class="py-3 px-3 text-right text-xs font-bold text-emerald-400">${formatBps(f.rx_bps)}</td>
                        <td class="py-3 px-3 text-right text-xs font-bold text-amber-400">${formatBps(f.tx_bps)}</td>
                    </tr>`;
                }).join('');
                if (typeof lucide !== 'undefined') lucide.createIcons();
            })
            .catch(() => {
                document.getElementById('wan-flows-modal-body').innerHTML = '<tr><td colspan="4" class="text-center py-8 text-red-400 text-xs">Blad ladowania</td></tr>';
            });
    }
    </script>

    <?php include __DIR__ . '/includes/confirm_modal.php'; ?>
    <?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>



