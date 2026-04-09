<?php
/** Created by Łukasz Misiura (c) 2025 | dev.lm-ads.com **/
require_once 'config.php';
require_once 'db.php';
require_once 'functions.php';
require_once 'includes/navbar_stats.php';

// Sprawdzenie czy użytkownik jest zalogowany
if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    header('Location: login.php');
    exit;
}
session_write_close();
// Release session lock so other pages can load while we fetch slow API data

$siteId = $config['site'];
$devices_config = loadDevices();

$navbar_stats = get_navbar_stats();
$grouped_monitored = [];
$clients_resp = ['data' => []];
$trad_resp = ['data' => []];

try {
    // 1. Pobieranie danych klientów (żeby wiedzieć co jest online)
    $clients_resp = fetch_api("/proxy/network/integration/v1/sites/$siteId/clients?limit=1000");
    
    if (empty($clients_resp['data']) && $siteId !== 'default') {
         $fallback_resp = fetch_api("/proxy/network/integration/v1/sites/default/clients?limit=1000");
         if (!empty($fallback_resp['data'])) {
             $clients_resp = $fallback_resp;
             $siteId = 'default';
         }
    }
    
    $clients = $clients_resp['data'] ?? [];
    
    // 2. Fetch from Traditional API (Rich data for traffic/uptime)
    $trad_resp = fetch_api("/proxy/network/api/s/default/stat/sta");
    $trad_clients = [];
    if (!empty($trad_resp['data'])) {
        foreach ($trad_resp['data'] as $tc) {
            $trad_clients[normalize_mac($tc['mac'])] = $tc;
        }
    }

    // Wstępna obróbka klientów i wzbogacenie o dane tradycyjne
    foreach ($clients as &$client) {
        $c_mac = normalize_mac($client['macAddress'] ?? $client['mac'] ?? '');
        $vlan_id = $client['vlan'] ?? $client['network_id'] ?? null;
        $ip = $client['ipAddress'] ?? $client['ip'] ?? '';
        $vlan_id = detect_vlan_id($ip, $vlan_id);
        
        $client['mac'] = $c_mac;
        $client['vlan'] = $vlan_id;
        $client['is_vpn'] = ($vlan_id === 0);
        
        // Enrich with traffic if online
        if (isset($trad_clients[$c_mac])) {
            $tc = $trad_clients[$c_mac];
            $client['rx_rate'] = $tc['rx_rate'] ?? 0;
            $client['tx_rate'] = $tc['tx_rate'] ?? 0;
            $client['rx_bytes'] = $tc['rx_bytes'] ?? 0;
            $client['tx_bytes'] = $tc['tx_bytes'] ?? 0;
            $client['uptime'] = $tc['uptime'] ?? 0;
        }
    }

    // Grupowanie monitorowanych urządzeń
    $grouped_monitored = group_devices_by_vlan($devices_config, $clients);

} catch (Throwable $e) {
    if (!empty($config['debug'])) {
        $error_msg = $e->getMessage();
        echo "<div style='background: red; color: white; padding: 20px; font-family: sans-serif; position: fixed; top: 70px; left: 20px; right: 20px; z-index: 99999; border-radius: 10px;'><strong>Fatal PHP Error:</strong> $error_msg <br><small>in " . $e->getFile() . " on line " . $e->getLine() . "</small></div>";
    }
}

if (!empty($clients_resp['error'])) {
    echo "<div style='background: orange; color: black; padding: 20px; font-family: sans-serif; position: fixed; top: 150px; left: 20px; right: 20px; z-index: 99998; border-radius: 10px;'><strong>API Error (Modern):</strong> " . htmlspecialchars($clients_resp['error']) . "</div>";
}
if (!empty($trad_resp['error'])) {
    echo "<div style='background: #ffd700; color: black; padding: 20px; font-family: sans-serif; position: fixed; top: 230px; left: 20px; right: 20px; z-index: 99997; border-radius: 10px;'><strong>API Error (Traditional):</strong> " . htmlspecialchars($trad_resp['error']) . "</div>";
}


// Funkcja pomocnicza do uptime
function formatUptime($seconds) {
    if (!$seconds) return "0min";
    $mo = floor($seconds / 2592000);
    $d = floor(($seconds % 2592000) / 86400);
    $h = floor(($seconds % 86400) / 3600);
    $m = floor(($seconds % 3600) / 60);

    $parts = [];
    if ($mo > 0) $parts[] = "{$mo}m";
    if ($d > 0) $parts[] = "{$d}d";
    if ($h > 0) $parts[] = "{$h}h";
    if ($m > 0 || empty($parts)) $parts[] = "{$m}min";
    
    return implode(" ", $parts);
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Zasoby | MiniDash UniFi</title>
    <link rel="icon" type="image/svg+xml" href="img/favicon.svg">
    <link rel="stylesheet" href="dashboard.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/lucide@latest"></script>
</head>
<body class="custom-scrollbar">
    <?php render_nav("Zasoby Sieciowe", $navbar_stats); ?>
    
    <div class="max-w-7xl mx-auto p-4 md:p-8">
        
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-12">
            <div>
                <h2 class="text-3xl font-black tracking-tight flex items-center gap-3">
                    <span class="w-2.5 h-10 bg-emerald-500 rounded-full"></span>
                    Monitorowane Zasoby
                </h2>
                <p class="text-slate-500 mt-2 text-sm">Przegląd kluczowych urządzeń przypisanych do Twojej sieci.</p>
            </div>
            
            <div class="flex items-center gap-4 bg-slate-900/50 p-4 rounded-2xl border border-white/5">
                <div class="flex items-center gap-6 text-[10px] font-black uppercase tracking-widest">
                    <div class="flex items-center gap-2">
                        <span class="w-2.5 h-2.5 rounded-full bg-emerald-500 shadow-[0_0_10px_rgba(16,185,129,0.5)]"></span>
                        <span class="text-emerald-400">Online</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="w-2.5 h-2.5 rounded-full bg-red-500 shadow-[0_0_10px_rgba(239,68,68,0.5)]"></span>
                        <span class="text-red-400">Offline</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="space-y-16">
            <?php if (empty($grouped_monitored)): ?>
                <div class="glass-card p-12 text-center">
                    <div class="w-16 h-16 bg-slate-800 rounded-full flex items-center justify-center mx-auto mb-4 text-slate-500">
                        <i data-lucide="search-x" class="w-8 h-8"></i>
                    </div>
                    <h3 class="text-xl font-bold text-slate-300">Brak monitorowanych urządzeń</h3>
                    <p class="text-slate-500 mt-2">Dodaj urządzenia w ustawieniach, aby widzieć je tutaj.</p>
                    <a href="devices.php" class="mt-6 inline-flex items-center gap-2 px-6 py-3 bg-blue-600 hover:bg-blue-500 text-white rounded-xl font-bold transition-all transform hover:scale-105">
                        <i data-lucide="plus-circle" class="w-5 h-5"></i>
                        Zarządzaj urządzeniami
                    </a>
                </div>
            <?php else: ?>
                <?php foreach ($grouped_monitored as $vlan_name => $mon_devices): ?>
                    <div class="space-y-8">
                        <div class="flex items-center gap-4">
                            <span class="text-xs font-black text-slate-600 uppercase tracking-[0.3em] whitespace-nowrap"><?= htmlspecialchars($vlan_name) ?></span>
                            <div class="h-[1px] w-full bg-gradient-to-r from-slate-800 via-slate-700 to-transparent"></div>
                            <span class="text-[10px] font-mono text-slate-700 whitespace-nowrap"><?= count($mon_devices) ?> urz.</span>
                        </div>
                        
                        <div class="resource-grid">
                            <?php foreach ($mon_devices as $device): ?>
                                <?php $is_on = ($device['status'] === 'on'); ?>
                                <div onclick='openResourceDetail(<?= htmlspecialchars(json_encode($device), ENT_QUOTES, "UTF-8") ?>)' 
                                     class="resource-card <?= $is_on ? 'online' : 'offline' ?> group block cursor-pointer">
                                    
                                    <div class="flex justify-between items-start mb-3">
                                        <div class="w-14 h-14 rounded-2xl flex items-center justify-center transition-all duration-500 group-hover:rotate-6 <?= $is_on ? 'bg-emerald-500/10 text-emerald-400' : 'bg-red-500/10 text-red-400' ?>">
                                            <i data-lucide="server" class="w-7 h-7"></i>
                                        </div>
                                        <div class="text-[9px] font-black uppercase tracking-widest <?= $is_on ? 'text-emerald-500' : 'text-red-500' ?>">
                                            <?= $is_on ? 'Online' : 'Offline' ?>
                                        </div>
                                    </div>

                                    <div class="space-y-0.5">
                                        <h3 class="text-xl font-black text-slate-100 group-hover:text-white transition-colors truncate"><?= htmlspecialchars($device['name']) ?></h3>
                                        <div class="flex items-center gap-2 mt-1">
                                            <span class="text-xs font-mono text-slate-400 font-bold"><?= htmlspecialchars($device['ip']) ?></span>
                                            <span class="text-slate-700 text-xs">•</span>
                                            <span class="text-[11px] font-mono text-slate-600 uppercase tracking-tighter"><?= htmlspecialchars(implode(':', str_split($device['mac'], 2))) ?></span>
                                        </div>
                                    </div>
                                    
                                    <div class="mt-3 pt-2 border-t border-white/5 flex justify-between items-center opacity-0 group-hover:opacity-100 transition-opacity">
                                        <div class="flex items-center gap-1">
                                            <span class="text-[8px] text-slate-500 uppercase font-black tracking-wider">Szczegóły</span>
                                            <i data-lucide="plus" class="w-3 h-3 text-slate-500"></i>
                                        </div>
                                        <button onclick="event.stopPropagation(); deleteDeviceHistory('<?= $device['mac'] ?>')" class="p-1.5 hover:bg-red-500/20 text-slate-600 hover:text-red-500 rounded transition-colors" title="Usuń z historii">
                                            <i data-lucide="trash-2" class="w-3.5 h-3.5"></i>
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Modal: Monitored Resource Detail -->
    <div id="resourceModal" class="modal-overlay" onclick="closeResourceModal(event)">
        <div class="modal-container max-w-lg p-0 overflow-hidden shadow-2xl ring-1 ring-white/10" onclick="event.stopPropagation()">
            <div id="resource-modal-content">
                <!-- Data inserted by JS -->
            </div>
        </div>
    </div>

    <script>
        lucide.createIcons();

        function formatBps(bps) {
            bps = parseFloat(bps) || 0;
            if (bps >= 1000000) return (bps/1000000).toFixed(2) + ' Mbps';
            if (bps >= 1000) return (bps/1000).toFixed(1) + ' Kbps';
            return bps.toFixed(0) + ' bps';
        }

        function formatBytes(bytes) {
            bytes = parseFloat(bytes) || 0;
            if (bytes >= 1073741824) return (bytes/1073741824).toFixed(2) + ' GB';
            if (bytes >= 1048576) return (bytes/1048576).toFixed(1) + ' MB';
            if (bytes >= 1024) return (bytes/1024).toFixed(0) + ' KB';
            return bytes.toFixed(0) + ' B';
        }

        function openResourceDetail(device) {
            const modal = document.getElementById('resourceModal');
            const content = document.getElementById('resource-modal-content');
            const isOn = device.status === 'on';
            
            content.innerHTML = `
                <div class="p-8 bg-gradient-to-br from-slate-800 to-slate-900 border-b border-white/5">
                    <div class="flex justify-between items-start mb-6">
                        <div class="flex items-center gap-5">
                            <div class="w-14 h-14 rounded-2xl ${isOn ? 'bg-emerald-500/10 text-emerald-400' : 'bg-red-500/10 text-red-400'} flex items-center justify-center shadow-lg">
                                <i data-lucide="server" class="w-7 h-7"></i>
                            </div>
                            <div>
                                <h2 class="text-2xl font-black text-white leading-tight">${device.name}</h2>
                                <div class="flex items-center gap-2 mt-1">
                                    <span class="text-xs font-mono text-slate-500 uppercase tracking-wider">${device.mac.match(/.{1,2}/g).join(':')}</span>
                                    <span class="w-1.5 h-1.5 rounded-full ${isOn ? 'bg-emerald-500' : 'bg-red-500'}"></span>
                                    <span class="text-xs font-black uppercase text-slate-500 tracking-widest">${isOn ? 'Online' : 'Offline'}</span>
                                </div>
                            </div>
                        </div>
                        <button onclick="closeResourceModal()" class="p-2 text-slate-500 hover:text-white transition bg-white/5 rounded-xl border border-white/5">
                            <i data-lucide="x" class="w-5 h-5"></i>
                        </button>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div class="bg-slate-900/40 p-4 rounded-2xl border border-white/5">
                            <span class="text-xs font-black text-slate-600 uppercase tracking-widest">Adres IP</span>
                            <div class="text-base font-mono text-slate-200 mt-1">${device.ip || 'Nieprzypisany'}</div>
                        </div>
                        <div class="bg-slate-900/40 p-4 rounded-2xl border border-white/5">
                            <span class="text-xs font-black text-slate-600 uppercase tracking-widest">Uptime</span>
                            <div class="text-base font-mono text-slate-200 mt-1">${formatUptime(device.uptime || 0)}</div>
                        </div>
                    </div>
                </div>

                <div class="p-8 bg-slate-900/50">
                    ${isOn ? `
                        <div class="space-y-6">
                            <!-- Live Transfer -->
                            <div>
                                <span class="text-[9px] font-black text-slate-600 uppercase tracking-widest block mb-3">Transfer Live</span>
                                <div class="grid grid-cols-2 gap-4">
                                    <div class="flex items-center gap-3 p-3 bg-slate-800/30 rounded-xl border border-white/5">
                                        <div class="p-2 bg-emerald-500/10 text-emerald-400 rounded-lg"><i data-lucide="arrow-down" class="w-4 h-4"></i></div>
                                        <div class="flex flex-col">
                                            <span class="text-[9px] text-slate-500 uppercase font-bold">Pobieranie</span>
                                            <span class="text-lg font-mono text-slate-200 leading-none mt-0.5">${formatBps(device.rx_rate || 0)}</span>
                                        </div>
                                    </div>
                                    <div class="flex items-center gap-3 p-3 bg-slate-800/30 rounded-xl border border-white/5">
                                        <div class="p-2 bg-amber-500/10 text-amber-400 rounded-lg"><i data-lucide="arrow-up" class="w-4 h-4"></i></div>
                                        <div class="flex flex-col">
                                            <span class="text-[9px] text-slate-500 uppercase font-bold">Wysyłanie</span>
                                            <span class="text-lg font-mono text-slate-200 leading-none mt-0.5">${formatBps(device.tx_rate || 0)}</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Data Usage Stats -->
                            <div>
                                <span class="text-[9px] font-black text-slate-600 uppercase tracking-widest block mb-3">Statystyki Zużycia Danych</span>
                                <div class="grid grid-cols-3 gap-3 mb-3">
                                     <!-- 1D Placeholder -->
                                     <div class="bg-slate-800/30 p-3 rounded-xl border border-white/5 text-center flex flex-col justify-center">
                                         <span class="block text-[8px] text-slate-500 font-bold uppercase tracking-wider mb-1">24 Godziny</span>
                                         <span class="block text-sm font-mono text-slate-400">-</span>
                                     </div>
                                     <!-- 1W Placeholder -->
                                     <div class="bg-slate-800/30 p-3 rounded-xl border border-white/5 text-center flex flex-col justify-center">
                                         <span class="block text-[8px] text-slate-500 font-bold uppercase tracking-wider mb-1">7 Dni</span>
                                         <span class="block text-sm font-mono text-slate-400">-</span>
                                     </div>
                                     <!-- Total -->
                                     <div class="bg-slate-800/30 p-3 rounded-xl border border-blue-500/10 text-center flex flex-col justify-center relative overflow-hidden">
                                         <div class="absolute inset-0 bg-blue-500/5"></div>
                                         <span class="block text-[8px] text-blue-400 font-bold uppercase tracking-wider mb-1 relative">Całkowite</span>
                                         <span class="block text-sm font-mono text-blue-100 relative font-bold">${formatBytes((device.rx_bytes || 0) + (device.tx_bytes || 0))}</span>
                                     </div>
                                </div>
                                
                                <div class="bg-slate-800/20 p-3 rounded-xl border border-white/5 flex justify-between items-center text-xs">
                                     <div class="flex items-center gap-2">
                                         <div class="w-1.5 h-1.5 rounded-full bg-slate-600"></div>
                                         <span class="text-slate-500 uppercase font-bold text-[9px]">Pobrane (Total):</span>
                                         <span class="font-mono text-slate-300">${formatBytes(device.rx_bytes || 0)}</span>
                                     </div>
                                     <div class="h-4 w-[1px] bg-white/10"></div>
                                     <div class="flex items-center gap-2">
                                         <div class="w-1.5 h-1.5 rounded-full bg-slate-600"></div>
                                         <span class="text-slate-500 uppercase font-bold text-[9px]">Wysłane (Total):</span>
                                         <span class="font-mono text-slate-300">${formatBytes(device.tx_bytes || 0)}</span>
                                     </div>
                                </div>
                            </div>
                        </div>
                    ` : `
                        <div class="flex flex-col items-center justify-center py-8 text-center">
                            <div class="w-16 h-16 bg-red-500/10 text-red-500 rounded-full flex items-center justify-center mb-4">
                                <i data-lucide="zap-off" class="w-8 h-8"></i>
                            </div>
                            <h4 class="text-lg font-bold text-slate-300">Urządzenie jest OFFLINE</h4>
                            <p class="text-sm text-slate-500 mt-1 max-w-[250px]">Nie można pobrać danych o ruchu, gdy urządzenie nie ma połączenia z siecią.</p>
                        </div>
                    `}
                    
                    <div class="mt-8 flex gap-3">
                        <a href="history.php?mac=${encodeURIComponent(device.mac)}" class="flex-1 flex items-center justify-center gap-2 py-3.5 bg-blue-600 hover:bg-blue-500 text-white rounded-xl font-bold transition shadow-xl shadow-blue-600/20 text-sm">
                            <i data-lucide="scroll-text" class="w-4 h-4"></i>
                            Zobacz pełną historię
                        </a>
                        <button onclick="closeResourceModal()" class="px-6 py-3.5 bg-slate-800 hover:bg-slate-700 text-slate-300 rounded-xl font-bold transition text-sm">
                            Zamknij
                        </button>
                    </div>
                </div>
            `;
            
            modal.classList.add('active');
            lucide.createIcons();
        }

        function closeResourceModal() {
            document.getElementById('resourceModal').classList.remove('active');
        }

        function formatUptime(seconds) {
            if (!seconds) return '0min';
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

        // Close on Esc
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') closeResourceModal();
        });

        async function deleteDeviceHistory(mac) {
            if (!confirm('Czy na pewno chcesz usunąć to urządzenie z historii i monitoringu? Ta operacja jest nieodwracalna.')) return;

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
                    alert('Błąd: ' + (result.message || 'Nieznany błąd'));
                }
            } catch (e) {
                console.error('Error:', e);
                alert('Wystąpił błąd podczas usuwania.');
            }
        }
    </script>
    <?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>




