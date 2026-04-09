<?php
/** Created by Łukasz Misiura (c) 2026 | www.lm-ads.com **/
require_once 'config.php';
require_once 'db.php';
require_once 'functions.php';

// Sprawdzenie czy użytkownik jest zalogowany
if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    header('Location: login.php');
    exit;
}
session_write_close();

$mac = $_GET['mac'] ?? '';
if (empty($mac)) {
    header('Location: index.php');
    exit;
}

$devices = loadDevices();
if (!isset($devices[$mac])) {
    header('Location: index.php');
    exit;
}

$device_name = $devices[$mac]['name'];
$history = array_reverse(loadDeviceHistory($mac)); // Najnowsze na górze

// Fetch Live Stats from UniFi to show in Traffic Section
$device_stats = [];
$trad_resp = fetch_api("/proxy/network/api/s/default/stat/sta");
if (!empty($trad_resp['data'])) {
    foreach ($trad_resp['data'] as $d) {
        if (normalize_mac($d['mac']) === normalize_mac($mac)) {
            $device_stats = $d;
            break;
        }
    }
}
?>

<?php
// --- 24h Uptime Calculation ---
$uptime_window = 24 * 60 * 60;
$now = time();
$start_time = $now - $uptime_window;

// Ensure we work with chronological order (Oldest -> Newest)
$chron_hist = array_reverse($history); 

// 1. Determine Initial State at $start_time
$current_state = 'on'; // default assumption
foreach ($chron_hist as $ev) {
    if (strtotime($ev['timestamp']) < $start_time) {
        $current_state = $ev['status'];
    } else {
        break; // Reached events inside window
    }
}

// 2. Calculate Stats
$total_down = 0;
$incidents = 0;
$scan_time = $start_time;

$events_in_window = [];
foreach ($chron_hist as $ev) {
    $et = strtotime($ev['timestamp']);
    if ($et >= $start_time) {
        $events_in_window[] = ['time' => $et, 'status' => $ev['status']];
    }
}

foreach ($events_in_window as $ev) {
    $duration = $ev['time'] - $scan_time;
    if ($current_state === 'off') {
        $total_down += $duration;
    }
    
    $scan_time = $ev['time'];
    $current_state = $ev['status'];
    
    if ($ev['status'] === 'off') {
        $incidents++;
    }
}
// Add remaining time from last event to now
$remaining = $now - $scan_time;
if ($current_state === 'off') {
    $total_down += $remaining;
}

$uptime_seconds = $uptime_window - $total_down;
$uptime_pct = ($uptime_seconds / $uptime_window) * 100;

// 3. Generate Bar Data (48 bars, 30m each)
$bar_count = 48;
$bar_duration = $uptime_window / $bar_count;
$bars = [];

for ($i = 0; $i < $bar_count; $i++) {
    $b_start = $start_time + ($i * $bar_duration);
    $b_end = $b_start + $bar_duration;
    
    $has_downtime = false;
    
    // Check state exactly at b_start
    $state_at_start = 'on';
     foreach ($chron_hist as $ev) {
        if (strtotime($ev['timestamp']) < $b_start) {
            $state_at_start = $ev['status'];
        }
    }
    
    if ($state_at_start === 'off') {
        $has_downtime = true;
    } else {
        // Check for any 'off' event inside [b_start, b_end]
        foreach ($events_in_window as $ev) {
            if ($ev['time'] >= $b_start && $ev['time'] <= $b_end) {
                if ($ev['status'] === 'off') {
                    $has_downtime = true;
                    break;
                }
            }
        }
    }
    
    $bars[] = $has_downtime ? 'off' : 'on';
}
?>

<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Historia - <?= htmlspecialchars($device_name) ?></title>
    <link rel="icon" type="image/svg+xml" href="img/favicon.svg">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="dashboard.css">
    <script src="https://unpkg.com/lucide@latest"></script>
</head>
<body class="custom-scrollbar">
    <?php render_nav("Historia: " . $device_name); ?>

    <div class="max-w-4xl mx-auto p-4 md:p-8">
        <header class="flex flex-col md:flex-row justify-between items-start md:items-center mb-8 gap-4">
            <div class="flex items-center gap-4">
                <a href="index.php" class="p-2.5 bg-slate-800 hover:bg-slate-700 text-slate-400 hover:text-white rounded-xl transition-all border border-white/5 group" title="Wróć">
                    <i data-lucide="chevron-left" class="w-6 h-6 group-hover:-translate-x-0.5 transition-transform"></i>
                </a>
                <div>
                    <h1 class="text-4xl font-black bg-clip-text text-transparent bg-gradient-to-r from-blue-400 via-indigo-400 to-purple-400 leading-tight"><?= htmlspecialchars($device_name) ?></h1>
                    <p class="text-slate-500 text-base mt-1 font-bold uppercase tracking-[0.1em]">Dziennik zdarzeń i historia dostępności</p>
                </div>
            </div>
            
            <div class="flex items-center gap-3">
                <button onclick="deleteDeviceHistory('<?= $mac ?>')" class="p-3 bg-red-500/10 hover:bg-red-500/20 text-red-500 rounded-xl border border-red-500/20 transition-all hover:scale-105 active:scale-95 group/del" title="Usuń z historii i monitoringu">
                    <i data-lucide="trash-2" class="w-5 h-5 group-hover/del:animate-bounce"></i>
                </button>
                <div class="glass-card px-5 py-3 border-white/10">
                    <div class="text-[10px] text-slate-500 uppercase tracking-[0.2em] font-bold mb-1">Adres MAC</div>
                    <div class="text-sm font-mono text-blue-400">
                        <?php 
                            $clean_mac = normalize_mac($mac);
                            echo strtoupper(implode(':', str_split($clean_mac, 2))); 
                        ?>
                    </div>
                </div>
            </div>
        </header>

        <!-- Stats Overview -->
        <?php 
        $current_status = !empty($history) ? $history[0]['status'] : 'unknown';
        $last_change = !empty($history) ? $history[0]['timestamp'] : 'Brak danych';
        ?>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
            <div class="glass-card p-6 stat-glow-<?= $current_status === 'on' ? 'emerald' : 'red' ?>">
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 rounded-2xl flex items-center justify-center <?= $current_status === 'on' ? 'bg-emerald-500/10 text-emerald-400' : 'bg-red-500/10 text-red-400' ?>">
                        <i data-lucide="<?= $current_status === 'on' ? 'check-circle' : 'alert-circle' ?>" class="w-6 h-6"></i>
                    </div>
                    <div>
                        <div class="text-[10px] text-slate-500 uppercase font-black tracking-widest mb-1">Aktualny Status</div>
                        <div class="text-2xl font-black <?= $current_status === 'on' ? 'text-emerald-400' : 'text-red-400' ?>">
                            <?= $current_status === 'on' ? 'Online' : 'Offline' ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="glass-card p-6">
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 rounded-2xl bg-blue-500/10 text-blue-400 flex items-center justify-center">
                        <i data-lucide="clock" class="w-6 h-6"></i>
                    </div>
                    <div>
                        <div class="text-[10px] text-slate-500 uppercase font-black tracking-widest mb-1">Ostatnia zmiana</div>
                        <div class="text-base font-bold text-slate-200"><?= $last_change ?></div>
                    </div>
                </div>
            </div>

            <div class="glass-card p-6">
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 rounded-2xl bg-purple-500/10 text-purple-400 flex items-center justify-center">
                        <i data-lucide="list" class="w-6 h-6"></i>
                    </div>
                    <div>
                        <div class="text-[10px] text-slate-500 uppercase font-black tracking-widest mb-1">Liczba zdarzeń</div>
                        <div class="text-2xl font-black text-slate-200"><?= count($history) ?></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Traffic Stats -->
        <div class="glass-card p-6 mb-8">
            <h2 class="text-lg font-bold flex items-center gap-2 mb-6 text-slate-200">
                <i data-lucide="bar-chart-3" class="text-blue-400 w-5 h-5"></i>
                Statystyki Transferu
            </h2>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                <!-- Live -->
                <div>
                    <span class="text-[10px] font-black text-slate-500 uppercase tracking-widest block mb-4">Transfer Live</span>
                    <div class="grid grid-cols-2 gap-4">
                        <div class="flex items-center gap-4 p-5 bg-slate-800/30 rounded-2xl border border-white/5">
                            <div class="p-3 bg-emerald-500/10 text-emerald-400 rounded-xl"><i data-lucide="arrow-down" class="w-6 h-6"></i></div>
                            <div class="flex flex-col">
                                <span class="text-[10px] text-slate-500 uppercase font-black tracking-widest">Pobieranie</span>
                                <span class="text-2xl font-black text-white font-mono mt-1"><?= format_bps($device_stats['rx_rate'] ?? 0) ?></span>
                            </div>
                        </div>
                        <div class="flex items-center gap-4 p-5 bg-slate-800/30 rounded-2xl border border-white/5">
                            <div class="p-3 bg-amber-500/10 text-amber-400 rounded-xl"><i data-lucide="arrow-up" class="w-6 h-6"></i></div>
                            <div class="flex flex-col">
                                <span class="text-[10px] text-slate-500 uppercase font-black tracking-widest">Wysyłanie</span>
                                <span class="text-2xl font-black text-white font-mono mt-1"><?= format_bps($device_stats['tx_rate'] ?? 0) ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Usage History -->
                <div>
                   <span class="text-[10px] font-black text-slate-500 uppercase tracking-widest block mb-4">Zużycie Danych</span>
                   <div class="grid grid-cols-3 gap-3 mb-4">
                         <div class="bg-slate-800/30 p-4 rounded-2xl border border-white/5 text-center flex flex-col justify-center">
                             <span class="block text-[10px] text-slate-500 font-black uppercase tracking-widest mb-1">24h</span>
                             <span class="block text-lg font-mono text-slate-400 font-bold">-</span>
                         </div>
                         <div class="bg-slate-800/30 p-4 rounded-2xl border border-white/5 text-center flex flex-col justify-center">
                             <span class="block text-[10px] text-slate-500 font-black uppercase tracking-widest mb-1">7d</span>
                             <span class="block text-lg font-mono text-slate-400 font-bold">-</span>
                         </div>
                         <div class="bg-slate-800/30 p-4 rounded-2xl border border-blue-500/10 text-center relative overflow-hidden flex flex-col justify-center">
                             <div class="absolute inset-0 bg-blue-500/5"></div>
                             <span class="block text-[10px] text-blue-400 font-black uppercase tracking-widest mb-1 relative">Total</span>
                             <span class="block text-lg font-mono font-black text-blue-100 mt-1 relative">
                                <?= format_bytes(($device_stats['rx_bytes'] ?? 0) + ($device_stats['tx_bytes'] ?? 0)) ?>
                             </span>
                         </div>
                   </div>
                   
                   <div class="bg-slate-800/20 p-3 rounded-xl border border-white/5 flex justify-between items-center text-sm">
                         <div class="flex items-center gap-2">
                             <div class="w-1.5 h-1.5 rounded-full bg-slate-600"></div>
                             <span class="text-slate-500 uppercase font-bold text-xs">DL (Total):</span>
                             <span class="font-mono text-slate-300"><?= format_bytes($device_stats['rx_bytes'] ?? 0) ?></span>
                         </div>
                         <div class="h-4 w-[1px] bg-white/10"></div>
                         <div class="flex items-center gap-2">
                             <div class="w-1.5 h-1.5 rounded-full bg-slate-600"></div>
                             <span class="text-slate-500 uppercase font-bold text-xs">UL (Total):</span>
                             <span class="font-mono text-slate-300"><?= format_bytes($device_stats['tx_bytes'] ?? 0) ?></span>
                         </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- History Timeline -->
        <div class="glass-card overflow-hidden">
            <div class="p-6 border-b border-white/5 flex justify-between items-center">
                <h2 class="text-lg font-bold flex items-center gap-2">
                    <i data-lucide="activity" class="text-blue-400 w-5 h-5"></i>
                    Zdarzenia systemowe
                </h2>
                <span class="text-[10px] text-slate-500 bg-slate-800 px-2 py-1 rounded">Ostatnie 50 wpisów</span>
            </div>
            
            <!-- Uptime Visualizer -->
            <div class="px-6 py-8 border-b border-white/5">
                <div class="flex justify-between items-end mb-4">
                     <div>
                         <div class="text-sm font-bold text-white mb-1">Ostatnie 24 godziny</div>
                         <div class="text-[10px] text-slate-500 font-bold uppercase tracking-wider">
                            <?= $incidents ?> incydentów, <?= round($total_down / 60) ?>min offline
                         </div>
                     </div>
                     <div class="text-xl font-mono font-bold <?= $uptime_pct >= 99 ? 'text-emerald-400' : ($uptime_pct >= 90 ? 'text-amber-400' : 'text-red-400') ?>">
                        <?= number_format($uptime_pct, 1) ?>%
                     </div>
                </div>
                <div class="flex gap-[3px] h-8">
                     <?php foreach ($bars as $status): ?>
                        <div class="flex-1 rounded-sm transition-all hover:opacity-80 <?= $status === 'on' ? 'bg-emerald-500' : 'bg-red-500/50' ?>" 
                             title="<?= $status === 'on' ? 'Online' : 'Offline / Incydent' ?>"></div>
                     <?php endforeach; ?>
                </div>
            </div>
            
            <div class="overflow-x-auto">
                <table class="w-full text-left">
                    <thead>
                        <tr class="bg-white/[0.02] text-[10px] font-black uppercase tracking-widest text-slate-500 border-b border-white/5">
                            <th class="px-8 py-5">Status / Akcja</th>
                            <th class="px-8 py-5">Data i Godzina</th>
                            <th class="px-8 py-5 text-right">Czas trwania</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/5">
                        <?php if (empty($history)): ?>
                            <tr>
                                <td colspan="3" class="px-6 py-20 text-center">
                                    <div class="flex flex-col items-center gap-3 text-slate-500">
                                        <i data-lucide="database-zap" class="w-12 h-12 opacity-20"></i>
                                        <div class="italic">Brak zarejestrowanych zdarzeń w bazie danych.</div>
                                    </div>
                                </td>
                            </tr>
                        <?php endif; ?>
                        
                        <?php foreach ($history as $entry): ?>
                            <tr class="hover:bg-white/[0.01] transition-colors group">
                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-3">
                                        <?php if ($entry['status'] === 'on'): ?>
                                            <div class="w-8 h-8 rounded-lg bg-emerald-500/10 text-emerald-400 flex items-center justify-center shadow-lg shadow-emerald-500/5">
                                                <i data-lucide="arrow-up-right" class="w-4 h-4"></i>
                                            </div>
                                            <div>
                                                <div class="text-sm font-bold text-emerald-400">DEVICE_UP</div>
                                                <div class="text-[10px] text-slate-500 uppercase font-medium">Połączono z siecią</div>
                                            </div>
                                        <?php else: ?>
                                            <div class="w-8 h-8 rounded-lg bg-red-500/10 text-red-400 flex items-center justify-center shadow-lg shadow-red-500/5">
                                                <i data-lucide="arrow-down-left" class="w-4 h-4"></i>
                                            </div>
                                            <div>
                                                <div class="text-sm font-bold text-red-400">DEVICE_DOWN</div>
                                                <div class="text-[10px] text-slate-500 uppercase font-medium">Utrata połączenia</div>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="px-6 py-4">
                                    <div class="text-sm font-medium text-slate-300">
                                        <?= date('d.m.Y', strtotime($entry['timestamp'])) ?>
                                    </div>
                                    <div class="text-xs text-slate-500 font-mono">
                                        <?= date('H:i:s', strtotime($entry['timestamp'])) ?>
                                    </div>
                                </td>
                                 <td class="px-8 py-5 text-right">
                                    <?php if (isset($entry['duration'])): ?>
                                        <span class="inline-flex items-center gap-2 px-3 py-1.5 rounded-xl bg-slate-800 text-xs font-black text-slate-400 border border-white/5">
                                            <i data-lucide="timer" class="w-3.5 h-3.5"></i>
                                            <?= formatDuration($entry['duration']) ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-slate-600 text-xs font-mono">W toku...</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="mt-8 flex justify-center">
            <div class="px-4 py-2 bg-slate-800/50 rounded-full border border-white/5 flex items-center gap-2">
                <span class="w-2 h-2 rounded-full bg-blue-500 animate-pulse"></span>
                <span class="text-[10px] text-slate-400 uppercase tracking-widest font-bold">Monitorowanie aktywne</span>
            </div>
        </div>

    </div>

    <script>
        lucide.createIcons();

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
                    window.location.href = 'index.php';
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




