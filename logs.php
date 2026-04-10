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

$navbar_stats = get_navbar_stats();

// Get logs from UniFi Controller API
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
if (!in_array($limit, [25, 50, 100, 500])) $limit = 100;

$site_id = $config['site'] ?? 'default';
$tradSite = get_trad_site_id($site_id);

// Helper to fetch keys
function fetch_logs($site, $type, $limit, $offset) {
    // Try standard UniFi OS path
    $resp = fetch_api("/proxy/network/api/s/$site/stat/$type?_sort=-time&_start=$offset&_limit=$limit");
    if (!empty($resp['data'])) return $resp['data'];
    
    // Fallback? (If fetch_api handles the base URL, we just rely on it returning data or error)
    return [];
}

// Fetch Events
$offset = ($page - 1) * $limit;
$api_limit = $limit + 1; 
$raw_events = fetch_logs($tradSite, 'event', $api_limit, $offset);

// If empty and site is specific UUID, try 'default'
if (empty($raw_events) && $tradSite !== 'default') {
    $raw_events = fetch_logs('default', 'event', $api_limit, $offset);
}

// Fetch Alarms (Threats, Errors) - merge often useful
$raw_alarms = fetch_logs($tradSite, 'alarm', $api_limit, $offset);
if (empty($raw_alarms) && $tradSite !== 'default') {
    $raw_alarms = fetch_logs('default', 'alarm', $api_limit, $offset);
}

// Merge and Sort
$all_logs = array_merge($raw_events, $raw_alarms);

// Deduplicate based on _id
$unique_logs = [];
foreach ($all_logs as $item) {
    if (isset($item['_id'])) {
        $unique_logs[$item['_id']] = $item;
    } else {
        $unique_logs[] = $item;
    }
}

// Sort by time DESC
usort($unique_logs, function($a, $b) {
    return ($b['time'] ?? 0) - ($a['time'] ?? 0);
});

// Processing & Filtering
$level = $_GET['level'] ?? '';
$processed_logs = [];

foreach ($unique_logs as $ev) {
    // Determine Severity
    $sev = 'INFO';
    $key = $ev['key'] ?? '';
    
    // Severity mapping based on UniFi "Internal" levels
    // Blocked/Threats = Very High (Critical)
    if ((isset($ev['inner_alert_action']) && $ev['inner_alert_action'] === 'blocked') || 
        strpos($key, 'EVT_GW_Block') !== false || 
        strpos($key, 'THREAT') !== false) {
         $sev = 'CRITICAL'; 
    } 
    // Device Lost/Disconnected = High (Error)
    elseif (strpos($key, 'EVT_AP_Lost_Contact') !== false || 
            strpos($key, 'EVT_SW_Lost_Contact') !== false || 
            strpos($key, 'EVT_GW_Lost_Contact') !== false) {
        $sev = 'ERROR';
    } 
    // Alarms/Warnings = Medium (Warning)
    elseif (isset($ev['archived']) || strpos($key, 'WARN') !== false) {
         $sev = 'WARNING'; 
    }
    // General Events = Low (Info)
    elseif (strpos($key, 'EVT_LU_Connected') !== false) {
        $sev = 'INFO';
    }

    // Determine Category
    $cat = 'General';
    if (strpos($key, 'EVT_GW') !== false) $cat = 'Security/Gateway';
    elseif (strpos($key, 'EVT_AP') !== false) $cat = 'Wireless';
    elseif (strpos($key, 'EVT_SW') !== false) $cat = 'Switching';
    elseif (strpos($key, 'EVT_LU') !== false) $cat = 'Client';
    elseif (strpos($key, 'EVT_AD') !== false) $cat = 'Admin';
    elseif (isset($ev['inner_alert_action'])) $cat = 'Firewall';

    // Filter Check
    if ($level && $sev !== $level) continue;

    // Format Date
    $ts = isset($ev['time']) ? $ev['time'] / 1000 : time();
    $date_str = date('Y-m-d H:i:s', $ts);

    // Message
    $msg = $ev['msg'] ?? $key;

    $processed_logs[] = [
        'generated_id' => $ev['_id'] ?? uniqid(),
        'raw' => $ev, // Keep full object for modal
        'date' => $date_str,
        'severity' => $sev,
        'category' => $cat,
        'message' => $msg,
        // Helper fields for modal
        'source' => isset($ev['src_ip']) ? $ev['src_ip'] . (isset($ev['src_port']) ? ':'.$ev['src_port'] : '') : null,
        'dest' => isset($ev['dst_ip']) ? $ev['dst_ip'] . (isset($ev['dst_port']) ? ':'.$ev['dst_port'] : '') : null,
        'proto' => $ev['proto'] ?? null,
        'iface' => $ev['iface'] ?? null,
        'app_proto' => $ev['app_proto'] ?? null
    ];
}

// Pagination on Processed Logs
$total_lines = count($processed_logs);
$has_next_page = $total_lines > $limit;
// Since we are filtering locally, the 'page' concept is tricky if we don't have ALL logs. 
// But for now we fetch $api_limit per page. If we filter heavily, we might show empty pages.
// BETTER: Fetch a large batch (e.g. 500), filter locally, then paginate locally.
// HOWEVER, keeping consistent with request:
$log_lines = array_slice($processed_logs, 0, $limit);
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Logs</title>
    <link rel="icon" type="image/svg+xml" href="img/favicon.svg">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="dashboard.css">
    <script src="https://unpkg.com/lucide@latest"></script>
</head>
<body class="custom-scrollbar">
    <?php render_nav("Logs", $navbar_stats); ?>
    
    <div class="max-w-7xl mx-auto p-4 md:p-8">
        <!-- Page Header -->
        <div class="mb-8 flex flex-col md:flex-row md:items-center justify-between gap-4">
            <div>
                <h1 class="text-3xl font-black text-white mb-2 flex items-center gap-3">
                    <i data-lucide="file-text" class="w-8 h-8 text-amber-400"></i>
                    Logi Kontrolera
                </h1>
                <p class="text-slate-500 text-sm">Zdarzenia pobrane bezpośrednio z API UniFiController</p>
            </div>
            
            <!-- Severity Filter -->
             <div class="flex items-center gap-2">
                <span class="text-xs font-bold text-slate-500 uppercase tracking-widest hidden md:block">Filtruj:</span>
                <select onchange="window.location.href='?limit=<?= $limit ?>&level='+this.value" class="bg-slate-900 border border-white/10 rounded-xl px-4 py-2 text-xs text-white font-bold uppercase tracking-wider focus:outline-none focus:border-amber-500 transition-colors cursor-pointer">
                    <option value="" <?= empty($level) ? 'selected' : '' ?>>Wszystkie Poziomy</option>
                    <option value="INFO" <?= $level === 'INFO' ? 'selected' : '' ?>>Info</option>
                    <option value="WARNING" <?= $level === 'WARNING' ? 'selected' : '' ?>>Warning</option>
                    <option value="ERROR" <?= $level === 'ERROR' ? 'selected' : '' ?>>Error</option>
                    <option value="CRITICAL" <?= $level === 'CRITICAL' ? 'selected' : '' ?>>Critical</option>
                </select>
            </div>
        </div>

        <!-- Log Table -->
        <div class="glass-card flex flex-col min-h-[70vh]">
            <div class="p-4 border-b border-white/5 flex items-center justify-between bg-slate-950/30">
                <div class="flex items-center gap-3">
                    <div class="p-2 bg-slate-800 rounded-lg text-slate-400">
                        <i data-lucide="server" class="w-4 h-4"></i>
                    </div>
                    <span class="font-mono text-sm text-slate-300">Live API Data</span>
                </div>
                <!-- Pagination Controls Top -->
                <div class="flex items-center gap-2 text-xs">
                    <a href="?limit=<?= $limit ?>&page=<?= max(1, $page-1) ?>&level=<?= htmlspecialchars($level) ?>" class="p-2 hover:bg-white/5 rounded-lg text-slate-400 hover:text-white <?= $page <= 1 ? 'opacity-50 pointer-events-none' : '' ?>">
                        <i data-lucide="chevron-left" class="w-4 h-4"></i>
                    </a>
                    <span class="text-slate-500">Strona <span class="text-white font-bold"><?= $page ?></span></span>
                    <a href="?limit=<?= $limit ?>&page=<?= $page+1 ?>&level=<?= htmlspecialchars($level) ?>" class="p-2 hover:bg-white/5 rounded-lg text-slate-400 hover:text-white <?= !$has_next_page ? 'opacity-50 pointer-events-none' : '' ?>">
                        <i data-lucide="chevron-right" class="w-4 h-4"></i>
                    </a>
                </div>
            </div>
            
            <div class="flex-grow overflow-x-auto">
                <table class="w-full text-left border-collapse">
                    <thead>
                        <tr class="bg-slate-950/50 text-[12px] font-black text-slate-500 uppercase tracking-widest border-b border-white/5">
                            <th class="px-6 py-3 w-48">Data / Czas</th>
                            <th class="px-6 py-3 w-32">Kategoria</th>
                            <th class="px-6 py-3 w-32">Poziom</th>
                            <th class="px-6 py-3">Wiadomość</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/[0.02] text-xs font-mono">
                        <?php if (empty($log_lines)): ?>
                        <tr>
                            <td colspan="4" class="px-6 py-8 text-center text-slate-500 italic">Brak zdarzeń do wyświetlenia</td>
                        </tr>
                        <?php else: ?>
                            <?php foreach ($log_lines as $l): 
                                // Encode for JS
                                $jsLog = htmlspecialchars(json_encode($l), ENT_QUOTES, 'UTF-8');
                            ?>
                            <tr onclick="openLogDetails(<?= $jsLog ?>)" class="hover:bg-white/[0.02] transition-colors group cursor-pointer border-l-2 border-transparent hover:border-amber-500">
                                <td class="px-6 py-3 text-slate-400 whitespace-nowrap"><?= $l['date'] ?></td>
                                <td class="px-6 py-3 text-slate-500"><?= $l['category'] ?></td>
                                <td class="px-6 py-3">
                                    <?php 
                                    $bg = 'bg-slate-800 text-slate-300';
                                    if($l['severity'] === 'CRITICAL') $bg = 'bg-rose-600 text-white shadow-lg shadow-rose-500/20 border border-rose-500 ring-1 ring-white/10';
                                    elseif($l['severity'] === 'ERROR') $bg = 'bg-red-500/10 text-red-400 border border-red-500/20';
                                    elseif($l['severity'] === 'WARNING') $bg = 'bg-amber-500/10 text-amber-400 border border-amber-500/20';
                                    elseif($l['severity'] === 'DEBUG') $bg = 'bg-purple-500/10 text-purple-400 border border-purple-500/20';
                                    elseif($l['severity'] === 'INFO') $bg = 'bg-blue-500/10 text-blue-400 border border-blue-500/20';
                                    ?>
                                    <span class="px-2 py-0.5 rounded text-[12px] font-bold uppercase tracking-wider <?= $bg ?> inline-block min-w-[60px] text-center">
                                        <?= $l['severity'] ?>
                                    </span>
                                </td>
                                <td class="px-6 py-3 text-slate-300 break-all group-hover:text-white transition-colors">
                                    <?= htmlspecialchars($l['message']) ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Footer Pagination -->
            <div class="p-4 border-t border-white/5 flex items-center justify-between bg-slate-950/30 text-xs text-slate-500">
                <div class="flex items-center gap-2">
                    <span>Wierszy na stronę:</span>
                    <select onchange="window.location.href='?page=1&level=<?= htmlspecialchars($level) ?>&limit='+this.value" class="bg-slate-900 border border-white/10 rounded px-2 py-1 text-white focus:outline-none focus:border-amber-500">
                        <option value="25" <?= $limit == 25 ? 'selected' : '' ?>>25</option>
                        <option value="50" <?= $limit == 50 ? 'selected' : '' ?>>50</option>
                        <option value="100" <?= $limit == 100 ? 'selected' : '' ?>>100</option>
                        <option value="500" <?= $limit == 500 ? 'selected' : '' ?>>500</option>
                    </select>
                </div>
                
                <div class="flex items-center gap-4">
                    <div class="flex items-center gap-1">
                        <a href="?limit=<?= $limit ?>&page=<?= max(1, $page-1) ?>&level=<?= htmlspecialchars($level) ?>" class="p-1.5 hover:bg-white/5 rounded-lg text-slate-400 hover:text-white <?= $page <= 1 ? 'opacity-50 pointer-events-none' : '' ?>">
                            <i data-lucide="chevron-left" class="w-4 h-4"></i>
                        </a>
                        <a href="?limit=<?= $limit ?>&page=<?= $page+1 ?>&level=<?= htmlspecialchars($level) ?>" class="p-1.5 hover:bg-white/5 rounded-lg text-slate-400 hover:text-white <?= !$has_next_page ? 'opacity-50 pointer-events-none' : '' ?>">
                            <i data-lucide="chevron-right" class="w-4 h-4"></i>
                        </a>
                    </div>
                </div>
            </div>
            
            <!-- Redundant block removed -->

            <!-- Footer Pagination -->
            <!-- Removed redundant footer -->
        </div>
    </div>

    <!-- Log Detail Modal -->
    <div id="logDetailModal" class="modal-overlay" onclick="closeModal('logDetailModal')">
        <div class="modal-container w-[600px] max-w-[95vw]" onclick="event.stopPropagation()">
            <div class="modal-header border-b border-white/5 bg-slate-900/50">
                <h2 class="text-sm font-bold text-white flex items-center gap-3" id="modal-title-date">
                    Szczegóły zdarzenia
                </h2>
                <button onclick="closeModal('logDetailModal')" class="p-2 hover:bg-white/5 rounded-xl transition text-slate-500 hover:text-white">
                    <i data-lucide="x" class="w-5 h-5"></i>
                </button>
            </div>
            <div class="modal-body p-6 space-y-6">
                <!-- Basic Info -->
                <div class="space-y-3">
                    <div class="flex justify-between items-center">
                        <span class="text-slate-500 text-xs font-bold uppercase tracking-widest">Wydarzenie</span>
                        <span id="modal-event-type" class="text-white text-sm font-medium text-right">General Log</span>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-slate-500 text-xs font-bold uppercase tracking-widest">Poziom</span>
                        <span id="modal-severity" class="text-amber-400 text-sm font-bold text-right">INFO</span>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-slate-500 text-xs font-bold uppercase tracking-widest">Kategoria</span>
                        <span id="modal-category" class="text-slate-300 text-sm text-right">System</span>
                    </div>
                </div>

                <div class="h-px bg-white/5"></div>

                <!-- Message / Details -->
                <div class="bg-slate-950/50 rounded-xl p-4 border border-white/5">
                    <h3 class="text-slate-500 text-[12px] font-bold uppercase tracking-widest mb-2">Pełna treść</h3>
                    <p id="modal-message" class="text-slate-300 font-mono text-xs break-all leading-relaxed"></p>
                </div>

                <!-- Structured Data (Simulated for generic logs) -->
                <div id="modal-structured-data" class="hidden space-y-4">
                     <!-- Populated by JS if regex matches known patterns -->
                </div>
            </div>
        </div>
    </div>

    <script>
        lucide.createIcons();
        
        function openLogDetails(log) {
            document.getElementById('modal-title-date').innerText = log.date || 'Szczegóły zdarzenia';
            document.getElementById('modal-severity').innerText = log.severity;
            document.getElementById('modal-category').innerText = log.category;
            document.getElementById('modal-message').innerText = log.message;
            
            // Basic styling for severity
            const sevEl = document.getElementById('modal-severity');
            sevEl.className = 'text-sm font-bold text-right';
            if(log.severity.includes('ERR')) sevEl.classList.add('text-red-400');
            else if(log.severity.includes('WARN')) sevEl.classList.add('text-amber-400');
            else if(log.severity.includes('DEBUG')) sevEl.classList.add('text-purple-400');
            else sevEl.classList.add('text-blue-400');

            document.getElementById('logDetailModal').classList.add('active');
        }

        function closeModal(id) {
            document.getElementById(id).classList.remove('active');
        }
    </script>
    <?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
