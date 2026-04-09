<?php
/** Created by Łukasz Misiura (c) 2025 | dev.lm-ads.com **/
require_once 'config.php';
require_once 'db.php';
require_once 'functions.php';
require_once 'includes/navbar_stats.php';

if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    header('Location: login.php');
    exit;
}

// Get navbar stats for system monitor
$navbar_stats = get_navbar_stats();

// Get real camera data from UniFi Protect
$protect_data = get_unifi_protect_data();
$cameras = $protect_data['cameras'] ?: [];
$stats_data = $protect_data['stats'];
$nvr = $protect_data['nvr'];

$total_cameras = $stats_data['total'];
$online_cameras = $stats_data['online'];
$recording_cameras = $stats_data['recording'];
$motion_detected = $stats_data['motion'];

// Get Traffic Data for Camera VLAN (40) and Active Connections
$siteId = $config['site'];
$clients_resp = fetch_api("/proxy/network/integration/v1/sites/$siteId/clients?limit=1000");
if (empty($clients_resp['data']) && $siteId !== 'default') {
     $fallback_resp = fetch_api("/proxy/network/integration/v1/sites/default/clients?limit=1000");
     if (!empty($fallback_resp['data'])) {
         $clients_resp = $fallback_resp;
     }
}
$clients = $clients_resp['data'] ?? [];

$cam_vlan_rx = 0;
$cam_vlan_tx = 0;
$camera_connections = 0;

foreach ($clients as $c) {
    // Check if device is in Camera VLAN (40) or matches a known camera MAC
    $ip = $c['ipAddress'] ?? $c['ip'] ?? '';
    $vlan = $c['vlan'] ?? $c['network_id'] ?? null;
    $detected_vlan = detect_vlan_id($ip, $vlan);
    
    // Check if MAC matches a known camera from Protect
    $is_camera = false;
    foreach ($cameras as $cam) {
        if (strcasecmp($cam['mac'], $c['macAddress'] ?? $c['mac'] ?? '') === 0) {
            $is_camera = true;
            break;
        }
    }

    if ($detected_vlan === 40 || $is_camera) {
        $cam_vlan_rx += $c['rxRateBps'] ?? 0;
        $cam_vlan_tx += $c['txRateBps'] ?? 0;
        if (!empty($c['ipAddress']) || !empty($c['ip'])) {
             $camera_connections++;
        }
    }
}
// If we found no specific connections via Client API but have Protect Online count, use Protect count
if ($camera_connections == 0) $camera_connections = $online_cameras;


// NVR Storage formatting
$nvr_total = $nvr['storage']['total'] ?? 0; // Bytes? Usually returned in bytes
$nvr_used = $nvr['storage']['used'] ?? 0;
$nvr_free = $nvr_total - $nvr_used;
$nvr_utilization = $nvr['storage']['utilization'] ?? 0; // Percentage likely? Or fraction? 
// Usually utilization is boolean or float 0-100. Let's assume bytes from 'storage' object
// If stats are missing, mock roughly based on simple math if total > 0
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>UniFi Protect - Monitoring Kamer</title>
    <link rel="icon" type="image/svg+xml" href="img/favicon.svg">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="dashboard.css">
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        .cam-grid-slot {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .cam-grid-slot:hover {
            z-index: 10;
            transform: scale(1.02);
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.5), 0 8px 10px -6px rgba(0, 0, 0, 0.5);
            border-color: rgba(168, 85, 247, 0.5); /* Purple-500 */
        }
    </style>
</head>
<body class="custom-scrollbar">
    <?php render_nav("UniFi Protect", $navbar_stats); ?>
    
    <div class="max-w-7xl mx-auto p-4 md:p-8">
        <!-- Page Header -->
        <div class="mb-8">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-3xl font-black text-white mb-2 flex items-center gap-3">
                        <i data-lucide="video" class="w-8 h-8 text-purple-400"></i>
                        UniFi Protect
                    </h1>
                    <p class="text-slate-500 text-sm">System monitoringu wideo i nagrywania zdarzeń</p>
                </div>
                <button onclick="openSettings()" class="px-6 py-3 bg-purple-600 hover:bg-purple-500 text-white rounded-2xl font-bold uppercase tracking-widest transition flex items-center gap-3 shadow-xl shadow-purple-600/20">
                    <i data-lucide="settings" class="w-5 h-5"></i>
                    Ustawienia Protect
                </button>
            </div>
        </div>

        <!-- Stats Grid (4 Tiles) -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
            <!-- Tile 1: Zainstalowane kamery -->
            <div onclick="openCamerasModal()" class="glass-card p-5 stat-glow-purple cursor-pointer group hover:bg-purple-500/5 transition-all">
                <div class="flex justify-between items-center mb-4">
                    <div class="p-2.5 bg-purple-500/10 rounded-xl text-purple-400 group-hover:bg-purple-500 group-hover:text-white transition-colors">
                        <i data-lucide="camera" class="w-5 h-5"></i>
                    </div>
                    <span class="text-[10px] font-black text-slate-500 uppercase tracking-widest group-hover:text-purple-300">Kamery</span>
                </div>
                <div class="text-3xl font-black tracking-tighter text-white"><?= $total_cameras ?></div>
                <div class="flex justify-between items-end mt-1">
                    <div class="text-slate-400 text-xs font-medium italic">Zainstalowane</div>
                    <i data-lucide="chevron-right" class="w-4 h-4 text-slate-600 group-hover:translate-x-1 transition-transform"></i>
                </div>
            </div>

            <!-- Tile 2: Aktywne połączenia -->
            <div onclick="openConnectionsModal()" class="glass-card p-5 stat-glow-emerald cursor-pointer group hover:bg-emerald-500/5 transition-all">
                <div class="flex justify-between items-center mb-4">
                    <div class="p-2.5 bg-emerald-500/10 rounded-xl text-emerald-400 group-hover:bg-emerald-500 group-hover:text-white transition-colors">
                        <i data-lucide="network" class="w-5 h-5"></i>
                    </div>
                    <span class="text-[10px] font-black text-slate-500 uppercase tracking-widest group-hover:text-emerald-300">Połączenia</span>
                </div>
                <div class="text-3xl font-black tracking-tighter text-white"><?= $camera_connections ?></div>
                <div class="flex justify-between items-end mt-1">
                    <div class="text-slate-400 text-xs font-medium italic">Aktywne sesje</div>
                    <i data-lucide="chevron-right" class="w-4 h-4 text-slate-600 group-hover:translate-x-1 transition-transform"></i>
                </div>
            </div>

            <!-- Tile 3: Dostępne NVR -->
            <div onclick="openNVRModal()" class="glass-card p-5 stat-glow-blue cursor-pointer group hover:bg-blue-500/5 transition-all">
                <div class="flex justify-between items-center mb-4">
                    <div class="p-2.5 bg-blue-500/10 rounded-xl text-blue-400 group-hover:bg-blue-500 group-hover:text-white transition-colors">
                        <i data-lucide="hard-drive" class="w-5 h-5"></i>
                    </div>
                    <span class="text-[10px] font-black text-slate-500 uppercase tracking-widest group-hover:text-blue-300">Magazyn</span>
                </div>
                <div class="text-3xl font-black tracking-tighter text-white">1</div>
                <div class="flex justify-between items-end mt-1">
                    <div class="text-slate-400 text-xs font-medium italic">Dostępne NVR</div>
                    <i data-lucide="chevron-right" class="w-4 h-4 text-slate-600 group-hover:translate-x-1 transition-transform"></i>
                </div>
            </div>

            <!-- Tile 4: Ruch w VLAN Kamer -->
            <div class="glass-card p-5 stat-glow-amber">
                <div class="flex justify-between items-center mb-4">
                    <div class="p-2.5 bg-amber-500/10 rounded-xl text-amber-400">
                        <i data-lucide="activity" class="w-5 h-5"></i>
                    </div>
                    <span class="text-[10px] font-black text-slate-500 uppercase tracking-widest">Ruch VLAN 40</span>
                </div>
                <div class="flex flex-col gap-1">
                    <div class="flex items-center gap-2 text-emerald-400">
                        <i data-lucide="arrow-down" class="w-3 h-3"></i>
                        <span class="text-lg font-mono font-bold"><?= format_bps($cam_vlan_rx) ?></span>
                    </div>
                    <div class="flex items-center gap-2 text-blue-400">
                        <i data-lucide="arrow-up" class="w-3 h-3"></i>
                        <span class="text-lg font-mono font-bold"><?= format_bps($cam_vlan_tx) ?></span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Live View Grid -->
        <div class="glass-card p-6">
            <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-6">
                <h2 class="text-xl font-bold text-white flex items-center gap-2">
                    <i data-lucide="grid" class="w-6 h-6 text-purple-400"></i>
                    Podgląd na żywo
                </h2>
                
                <!-- Layout Selector -->
                <div class="bg-slate-900/50 p-1 rounded-xl border border-white/5 flex items-center gap-1">
                    <button onclick="setGridSize(1)" class="layout-btn p-2 rounded-lg text-slate-400 hover:text-white hover:bg-white/10 transition" title="1 Kamera" data-size="1">
                        <i data-lucide="square" class="w-4 h-4"></i>
                    </button>
                    <button onclick="setGridSize(2)" class="layout-btn p-2 rounded-lg text-slate-400 hover:text-white hover:bg-white/10 transition" title="2 Kamery" data-size="2">
                        <i data-lucide="columns-2" class="w-4 h-4"></i>
                    </button>
                    <button onclick="setGridSize(4)" class="layout-btn p-2 rounded-lg text-slate-400 hover:text-white hover:bg-white/10 transition" title="4 Kamery" data-size="4">
                        <i data-lucide="layout-grid" class="w-4 h-4"></i>
                    </button>
                    <button onclick="setGridSize(9)" class="layout-btn p-2 rounded-lg text-slate-400 hover:text-white hover:bg-white/10 transition" title="9 Kamer" data-size="9">
                        <i data-lucide="grid-3x3" class="w-4 h-4"></i>
                    </button>
                    <button onclick="setGridSize(12)" class="layout-btn p-2 rounded-lg text-slate-400 hover:text-white hover:bg-white/10 transition" title="12 Kamer" data-size="12">
                        <i data-lucide="layout-template" class="w-4 h-4"></i>
                    </button>
                </div>
            </div>
            
            <div class="grid gap-4" id="camera-grid">
                <!-- Grid slots will be rendered here -->
            </div>
        </div>
    </div>

    <!-- Modal: Installed Cameras -->
    <div id="camerasModal" class="modal-overlay" onclick="closeModal('camerasModal')">
        <div class="modal-container w-[70%] max-w-[1200px] max-h-[90vh]" onclick="event.stopPropagation()">
            <!-- ... (Modal content unchanged) ... -->
            <div class="modal-header">
                <h2 class="text-xl font-bold text-white flex items-center gap-3">
                    <i data-lucide="camera" class="w-6 h-6 text-purple-400"></i>
                    Lista zainstalowanych kamer
                </h2>
                <button onclick="closeModal('camerasModal')" class="p-2 hover:bg-white/5 rounded-xl transition text-slate-500 hover:text-white">
                    <i data-lucide="x" class="w-6 h-6"></i>
                </button>
            </div>
            <div class="modal-body p-0">
                <div class="overflow-x-auto">
                    <table class="w-full text-left border-collapse">
                        <thead>
                            <tr class="bg-slate-950/30 text-[9px] font-black text-slate-500 uppercase tracking-widest border-b border-white/5">
                                <th class="px-6 py-4">Nazwa / Model</th>
                                <th class="px-6 py-4">Status</th>
                                <th class="px-6 py-4">Adres IP / MAC</th>
                                <th class="px-6 py-4">Rozdzielczość</th>
                                <th class="px-6 py-4 text-right">Ostatni ruch</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/[0.02]">
                            <?php foreach ($cameras as $c): ?>
                            <tr class="hover:bg-white/[0.02] transition-colors">
                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-3">
                                        <div class="w-10 h-7 bg-slate-800 rounded flex items-center justify-center overflow-hidden">
                                            <img src="<?= $c['thumbnail'] ?>" class="w-full h-full object-cover opacity-80">
                                        </div>
                                        <div>
                                            <div class="font-bold text-white text-sm"><?= htmlspecialchars($c['name']) ?></div>
                                            <div class="text-[10px] text-slate-500"><?= htmlspecialchars($c['model']) ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4">
                                    <?php if ($c['status'] === 'online'): ?>
                                        <span class="px-2 py-1 bg-emerald-500/10 text-emerald-400 rounded text-[10px] font-black uppercase tracking-wider border border-emerald-500/20">Online</span>
                                    <?php else: ?>
                                        <span class="px-2 py-1 bg-red-500/10 text-red-400 rounded text-[10px] font-black uppercase tracking-wider border border-red-500/20">Offline</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-6 py-4">
                                    <div class="font-mono text-xs text-slate-300"><?= $c['ip'] ?></div>
                                    <div class="font-mono text-[10px] text-slate-600 uppercase"><?= $c['mac'] ?></div>
                                </td>
                                <td class="px-6 py-4 text-xs text-slate-400"><?= $c['resolution'] ?> @ <?= $c['fps'] ?> FPS</td>
                                <td class="px-6 py-4 text-right text-xs text-slate-500">
                                    <?= $c['motion'] ? '<span class="text-amber-400 font-bold">Wykryto</span>' : 'Brak' ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal: Connections -->
    <div id="connectionsModal" class="modal-overlay" onclick="closeModal('connectionsModal')">
        <div class="modal-container w-[70%] max-w-[1200px] max-h-[90vh]" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h2 class="text-xl font-bold text-white flex items-center gap-3">
                    <i data-lucide="network" class="w-6 h-6 text-emerald-400"></i>
                    Aktywne połączenia kamer
                </h2>
                <button onclick="closeModal('connectionsModal')" class="p-2 hover:bg-white/5 rounded-xl transition text-slate-500 hover:text-white">
                    <i data-lucide="x" class="w-6 h-6"></i>
                </button>
            </div>
            <div class="modal-body p-8">
                <p class="text-slate-400 mb-6">Lista aktywnych sesji streamingowych i połączeń z kamerami.</p>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                     <?php foreach ($cameras as $c): 
                        if ($c['status'] !== 'online') continue;
                     ?>
                     <div class="bg-slate-900/50 p-4 rounded-xl border border-white/5 flex items-center gap-4">
                        <div class="p-3 bg-emerald-500/10 rounded-lg text-emerald-400">
                            <i data-lucide="wifi" class="w-5 h-5"></i>
                        </div>
                        <div>
                            <div class="font-bold text-white text-sm"><?= htmlspecialchars($c['name']) ?></div>
                            <div class="text-[10px] text-slate-500 font-mono"><?= $c['ip'] ?> • Stabilne</div>
                        </div>
                     </div>
                     <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal: NVR Details -->
    <div id="nvrModal" class="modal-overlay" onclick="closeModal('nvrModal')">
        <div class="modal-container max-w-2xl" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h2 class="text-xl font-bold text-white flex items-center gap-3">
                    <i data-lucide="hard-drive" class="w-6 h-6 text-blue-400"></i>
                    Szczegóły NVR
                </h2>
                <button onclick="closeModal('nvrModal')" class="p-2 hover:bg-white/5 rounded-xl transition text-slate-500 hover:text-white">
                    <i data-lucide="x" class="w-6 h-6"></i>
                </button>
            </div>
            <div class="modal-body p-8 space-y-8">
                <!-- NVR Info -->
                <div class="flex items-center gap-6">
                    <div class="w-20 h-20 bg-slate-800 rounded-2xl flex items-center justify-center p-4 border border-white/10 relative">
                        <i data-lucide="server" class="w-10 h-10 text-slate-400"></i>
                    </div>
                    <div>
                        <h3 class="text-2xl font-black text-white">UniFi Cloud Key / NVR</h3>
                        <div class="flex items-center gap-3 mt-2">
                             <span class="px-2.5 py-1 bg-emerald-500/10 text-emerald-400 rounded-lg text-[10px] font-black uppercase tracking-wider border border-emerald-500/20">ONLINE</span>
                             <span class="text-xs text-slate-500 font-mono">v<?= $nvr['version'] ?></span>
                        </div>
                    </div>
                </div>

                <!-- Storage Stats -->
                <div class="bg-slate-900/50 rounded-2xl border border-white/5 p-6">
                    <h4 class="text-sm font-bold text-white uppercase tracking-widest mb-6">Status Macierzy Dyskowej</h4>
                    
                    <!-- Progress Bar -->
                    <div class="h-4 bg-slate-800 rounded-full overflow-hidden mb-4 relative">
                        <div class="absolute inset-y-0 left-0 bg-blue-600 rounded-full" style="width: <?= min(100, ($nvr_used / ($nvr_total ?: 1)) * 100) ?>%"></div>
                    </div>
                    
                    <div class="grid grid-cols-3 gap-4 text-center">
                        <div>
                            <div class="text-[10px] uppercase font-black text-slate-500 tracking-widest mb-1">Całkowite</div>
                            <div class="text-lg font-mono font-bold text-white"><?= format_bytes($nvr_total) ?></div>
                        </div>
                        <div>
                            <div class="text-[10px] uppercase font-black text-slate-500 tracking-widest mb-1">Użyte</div>
                            <div class="text-lg font-mono font-bold text-blue-400"><?= format_bytes($nvr_used) ?></div>
                        </div>
                        <div>
                            <div class="text-[10px] uppercase font-black text-slate-500 tracking-widest mb-1">Wolne</div>
                            <div class="text-lg font-mono font-bold text-emerald-400"><?= format_bytes($nvr_free) ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Camera Selector Modal (for picking a camera for a slot) -->
    <div id="camSelectorModal" class="modal-overlay" onclick="closeModal('camSelectorModal')">
        <div class="modal-container max-w-sm" onclick="event.stopPropagation()">
            <div class="modal-header">
                <h2 class="text-lg font-bold text-white">Wybierz kamerę</h2>
                <button onclick="closeModal('camSelectorModal')" class="p-2 hover:bg-white/5 rounded-xl transition text-slate-500 hover:text-white">
                    <i data-lucide="x" class="w-5 h-5"></i>
                </button>
            </div>
            <div class="modal-body p-2 max-h-[60vh] overflow-y-auto">
                <div class="space-y-1" id="cam-selector-list">
                    <!-- Populated by JS -->
                </div>
            </div>
        </div>
    </div>


    <script>
        // Data from PHP
        const cameras = <?= json_encode($cameras) ?>;
        
        // Grid State (Slot ID -> Camera ID)
        let gridState = new Array(16).fill(null); // Max 16 slots support
        let currentGridSize = 9;

        function setGridSize(size) {
            currentGridSize = size;
            updateGridLayout();
            initGrid();
            
            // Update active button state
            document.querySelectorAll('.layout-btn').forEach(btn => {
                if(parseInt(btn.dataset.size) === size) {
                    btn.classList.add('bg-white/10', 'text-white');
                    btn.classList.remove('text-slate-400');
                } else {
                    btn.classList.remove('bg-white/10', 'text-white');
                    btn.classList.add('text-slate-400');
                }
            });
        }
        
        function updateGridLayout() {
            const gridEl = document.getElementById('camera-grid');
            gridEl.className = 'grid gap-4 transition-all duration-300'; // Reset styling
            
            if (currentGridSize === 1) {
                gridEl.classList.add('grid-cols-1');
            } else if (currentGridSize === 2) {
                gridEl.classList.add('grid-cols-1', 'md:grid-cols-2');
            } else if (currentGridSize === 4) {
                gridEl.classList.add('grid-cols-1', 'md:grid-cols-2');
            } else if (currentGridSize === 9) {
                gridEl.classList.add('grid-cols-1', 'md:grid-cols-2', 'lg:grid-cols-3');
            } else if (currentGridSize === 12) {
                gridEl.classList.add('grid-cols-1', 'md:grid-cols-2', 'lg:grid-cols-3', 'xl:grid-cols-4');
            }
        }
        
        // Initialize Grid
        function initGrid() {
            const gridEl = document.getElementById('camera-grid');
            gridEl.innerHTML = '';
            
            // Default fill: distribute cameras across the current size
            // Only fill if slot is null? Or always refill? Let's preserve state if resizing up using existing gridState
            
            // Render slots
            for (let i = 0; i < currentGridSize; i++) {
                // If slot is empty in state, try to auto-fill linearly from cameras list
                if (!gridState[i] && i < cameras.length) {
                     gridState[i] = cameras[i].id;
                }
                
                gridEl.appendChild(createSlotElement(i));
            }
            lucide.createIcons();
        }
        
        function createSlotElement(slotIdx) {
            const camId = gridState[slotIdx];
            const cam = cameras.find(c => c.id === camId);
            
            const div = document.createElement('div');
            // Aspect ratio handling
            // For single view, allow taller? Standardize on video aspect
            div.className = 'cam-grid-slot aspect-video bg-slate-950 rounded-xl overflow-hidden relative border border-white/5 group bg-slate-900/50 cursor-pointer';
            
            // If viewing 1 camera, maybe make it bigger/max-height? Default aspect-video is safe
            if (currentGridSize === 1) {
                div.classList.remove('aspect-video');
                div.classList.add('aspect-[16/9]', 'h-[60vh]', 'w-full');
                div.style.maxHeight = '75vh';
            }
            
            div.onclick = () => openCameraSelector(slotIdx);
            
            if (cam) {
                div.innerHTML = `
                    <div class="w-full h-full relative">
                        <img src="${cam.thumbnail}" class="w-full h-full object-cover opacity-80 group-hover:opacity-100 transition-opacity">
                        <div class="absolute inset-0 bg-gradient-to-t from-black/80 via-transparent to-transparent opacity-60"></div>
                        
                        <div class="absolute bottom-0 left-0 w-full p-3 flex justify-between items-end">
                            <div>
                                <div class="text-xs font-bold text-white drop-shadow-md">${cam.name}</div>
                                <div class="flex items-center gap-2 mt-0.5">
                                    <span class="w-1.5 h-1.5 rounded-full ${cam.status === 'online' ? 'bg-emerald-500' : 'bg-red-500'}"></span>
                                    <span class="text-[9px] font-mono text-slate-300 uppercase">${cam.status}</span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="absolute inset-0 flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity bg-black/40 backdrop-blur-[2px]">
                            <div class="flex flex-col items-center gap-2 text-white">
                                <i data-lucide="refresh-cw" class="w-6 h-6"></i>
                                <span class="text-[10px] font-bold uppercase tracking-widest">Zmień kamerę</span>
                            </div>
                        </div>
                    </div>
                `;
            } else {
                div.innerHTML = `
                   <div class="w-full h-full flex flex-col items-center justify-center text-slate-600 group-hover:text-slate-400 transition-colors">
                        <i data-lucide="plus" class="w-8 h-8 mb-2"></i>
                        <span class="text-xs font-bold uppercase tracking-widest">Dodaj kamerę</span>
                   </div> 
                `;
            }
            
            return div;
        }

        let currentSlotSelector = -1;

        function openCameraSelector(slotIdx) {
            currentSlotSelector = slotIdx;
            const list = document.getElementById('cam-selector-list');
            list.innerHTML = '';
            
            // Add Empty option
            const emptyBtn = document.createElement('button');
            emptyBtn.className = 'w-full text-left p-3 rounded-lg hover:bg-white/5 text-slate-400 hover:text-white text-xs font-bold flex items-center gap-3 transition';
            emptyBtn.innerHTML = '<i data-lucide="x-circle" class="w-4 h-4"></i> Puste gniazdo';
            emptyBtn.onclick = () => selectCamera(null);
            list.appendChild(emptyBtn);

            cameras.forEach(cam => {
                const btn = document.createElement('button');
                btn.className = 'w-full text-left p-3 rounded-lg hover:bg-white/5 text-slate-300 hover:text-white text-xs font-bold flex items-center gap-3 transition';
                // Mark if already used elsewhere? Optional logic.
                const isSelected = gridState[slotIdx] === cam.id;
                
                btn.innerHTML = `
                    <div class="w-8 h-5 rounded bg-slate-800 overflow-hidden shrink-0">
                        <img src="${cam.thumbnail}" class="w-full h-full object-cover">
                    </div>
                    <span class="flex-grow truncate">${cam.name}</span>
                    ${isSelected ? '<i data-lucide="check" class="w-4 h-4 text-emerald-400"></i>' : ''}
                `;
                btn.onclick = () => selectCamera(cam.id);
                list.appendChild(btn);
            });
            
            lucide.createIcons();
            document.getElementById('camSelectorModal').classList.add('active');
        }

        function selectCamera(camId) {
            if (currentSlotSelector !== -1) {
                gridState[currentSlotSelector] = camId;
                
                // Refresh just that slot
                const gridEl = document.getElementById('camera-grid');
                const newSlot = createSlotElement(currentSlotSelector);
                gridEl.replaceChild(newSlot, gridEl.children[currentSlotSelector]);
                lucide.createIcons();
            }
            closeModal('camSelectorModal');
        }

        // Modal Helpers
        function openCamerasModal() {
            document.getElementById('camerasModal').classList.add('active');
            document.body.style.overflow = 'hidden';
            lucide.createIcons();
        }
        function openConnectionsModal() {
            document.getElementById('connectionsModal').classList.add('active');
            document.body.style.overflow = 'hidden';
            lucide.createIcons();
        }
        function openNVRModal() {
            document.getElementById('nvrModal').classList.add('active');
            document.body.style.overflow = 'hidden';
            lucide.createIcons();
        }
        function closeModal(id) {
            document.getElementById(id).classList.remove('active');
            document.body.style.overflow = '';
        }

        window.addEventListener('DOMContentLoaded', () => {
             setGridSize(9); // Default
        });
        
        lucide.createIcons();
    </script>
    <?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>




