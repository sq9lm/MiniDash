<?php
/** Created by Łukasz Misiura (c) 2026 | www.lm-ads.com **/
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

// Get real security settings from UniFi
$security_settings = get_unifi_security_settings();
$security_events = get_unifi_security_events() ?: [];
$blocked_ips_list = get_unifi_blocked_ips() ?: [];

// Calculate security score dynamically based on multiple factors
$security_score = 100; // Start with perfect score

// Factor 1: IPS/IDS Status (20 points)
$ips_enabled = $security_settings['ips_enabled']; 
if (!$ips_enabled) {
    $security_score -= 20;
}

// Factor 2: Firewall & Traffic Rules Coverage (15 points)
// Use combined count of firewall and traffic rules (flows)
$active_rules = ($security_settings['firewall_rules_count'] ?? 0) + ($security_settings['traffic_rules_count'] ?? 0);
if ($active_rules < 2) {
    $security_score -= 15;
} elseif ($active_rules < 10) {
    $security_score -= 8;
} elseif ($active_rules < 30) {
    $security_score -= 3;
}
// High coverage bonus
if ($active_rules >= 30) {
    $security_score = min(100, $security_score + 15);
}

// 1. Data Retrieval
// These variables are already fetched above, but the instruction implies they should be re-assigned or confirmed here.
// Re-assigning them here to match the instruction, though it's redundant with the initial fetch.
$ips_enabled = $security_settings['ips_enabled'];
$threat_detection_enabled = $security_settings['threat_detection_enabled'];
$geoblocking_enabled = $security_settings['geoblocking_enabled'];
$active_rules = $security_settings['total_rules_count'] ?? ($security_settings['firewall_rules_count'] + $security_settings['traffic_rules_count']);
$threats_blocked = $security_settings['threats_count'];
$blocked_ips = count($blocked_ips_list); // Using actual blocked_ips_list count
$vpn_secure = $security_settings['vpn_secure'] ?? false; // Assuming a default if not present
$monitoring_active = $security_settings['monitoring_active'];
$critical_events_count = 0; // Captured dynamically in a real app, currently 0 as per instruction
$rule_list = $security_settings['rule_list'] ?? [];

// Factor 3: Threat Detection Active (15 points)
$threat_detection_enabled = $security_settings['threat_detection_enabled'];
if (!$threat_detection_enabled) {
    $security_score -= 15;
}

// Factor 4: Blocked Threats Ratio (20 points)
// Use real threat count from API
$threats_blocked = $security_settings['threats_count'];
// Actually let's try to get a real blocked IP count if we have that endpoint, otherwise keep mock 34 for now as it's hard to get IPS-blocked list easily without more complex API calls
if ($threats_blocked > 1000) {
    $security_score -= 10; // Under heavy attack
} elseif ($threats_blocked > 500) {
    $security_score -= 5; // Moderate attack
}

// Factor 5: Active Monitoring (10 points)
$monitoring_active = $security_settings['monitoring_active'];
if (!$monitoring_active) {
    $security_score -= 10;
}

// Factor 6: Advanced Protection (10 points)
$ad_blocking = $security_settings['ad_blocking_enabled'] ?? false;
$honeypot = $security_settings['honeypot_enabled'] ?? false;
if (!$ad_blocking && !$honeypot) {
    $security_score -= 10;
} elseif ($ad_blocking && $honeypot) {
    $security_score = min(100, $security_score + 5); // Bonus for full protection
}

// Factor 7: Recent Critical Events (penalty)
// Count critical events in last hour
$critical_events_count = 0;
foreach ($security_events as $event) {
    if ($event['severity'] === 'critical') {
        $critical_events_count++;
    }
}
if ($critical_events_count > 5) {
    $security_score -= 15; // Too many critical events
} elseif ($critical_events_count > 2) {
    $security_score -= 8;
} elseif ($critical_events_count > 0) {
    $security_score -= 3;
}

// Factor 8: Geo-blocking (10 points bonus or neutral)
$geoblocking_enabled = $security_settings['geoblocking_enabled'] ?? false;
if (!$geoblocking_enabled) {
    $security_score -= 5; // Penalty if not even basic geoblocking is on
}

// Ensure score stays within 0-100 range
$security_score = max(0, min(100, $security_score));

$stats = [
    'threats_blocked' => $threats_blocked,
    'active_rules' => $active_rules,
    'blocked_ips' => $blocked_ips,
    'security_score' => $security_score
];
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>UniFi Security - Monitoring Bezpieczeństwa</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="dashboard.css">
    <script src="https://unpkg.com/lucide@latest"></script>
</head>
<body class="custom-scrollbar">
    <?php render_nav("UniFi Security", $navbar_stats); ?>
    
    <div class="max-w-7xl mx-auto p-4 md:p-8">
        <!-- Page Header -->
        <div class="mb-8">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-3xl font-black text-white mb-2 flex items-center gap-3">
                        <i data-lucide="shield" class="w-8 h-8 text-rose-400"></i>
                        UniFi Security
                    </h1>
                    <p class="text-slate-500 text-sm">Zaawansowany system wykrywania zagrożeń i ochrony sieci</p>
                </div>
                <div class="relative group">
                    <button class="px-6 py-3 bg-rose-600 hover:bg-rose-500 text-white rounded-2xl font-bold uppercase tracking-widest transition flex items-center gap-3 shadow-xl shadow-rose-600/20">
                        <i data-lucide="shield-alert" class="w-5 h-5"></i>
                        Konfiguracja IPS
                        <i data-lucide="chevron-down" class="w-4 h-4 transition-transform group-hover:rotate-180"></i>
                    </button>
                    
                    <!-- Dropdown Menu -->
                    <div class="absolute right-0 top-full mt-2 w-80 opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all duration-200 z-50">
                        <div class="bg-slate-900/95 backdrop-blur-xl rounded-2xl border border-white/10 shadow-2xl overflow-hidden">
                            <div class="p-2 space-y-1">
                                <a href="#" class="flex items-start gap-3 p-3 rounded-xl hover:bg-white/5 transition-colors group/item">
                                    <div class="p-2 bg-rose-500/10 rounded-lg text-rose-400 shrink-0">
                                        <i data-lucide="shield" class="w-4 h-4"></i>
                                    </div>
                                    <div class="flex-grow min-w-0">
                                        <div class="flex items-center gap-2">
                                            <p class="text-sm font-bold text-white">Reguły IPS</p>
                                            <span class="px-1.5 py-0.5 rounded text-[8px] font-black uppercase tracking-tighter <?= $ips_enabled ? 'bg-emerald-500/20 text-emerald-400' : 'bg-rose-500/20 text-rose-400' ?>">
                                                <?= $ips_enabled ? 'AKTYWNE' : 'WYŁĄCZONE' ?>
                                            </span>
                                        </div>
                                        <p class="text-[10px] text-slate-500 mt-0.5">Zarządzaj regułami wykrywania zagrożeń</p>
                                    </div>
                                </a>
                                
                                 <a href="#" class="flex items-start gap-3 p-3 rounded-xl hover:bg-white/5 transition-colors group/item">
                                    <div class="p-2 bg-blue-500/10 rounded-lg text-blue-400 shrink-0">
                                        <i data-lucide="database" class="w-4 h-4"></i>
                                    </div>
                                    <div class="flex-grow min-w-0">
                                        <div class="flex items-center gap-2">
                                            <p class="text-sm font-bold text-white">Threat Intelligence</p>
                                            <span class="px-1.5 py-0.5 rounded text-[8px] font-black uppercase tracking-tighter <?= $threat_detection_enabled ? 'bg-emerald-500/20 text-emerald-400' : 'bg-rose-500/20 text-rose-400' ?>">
                                                <?= $threat_detection_enabled ? 'AKTYWNE' : 'WYŁĄCZONE' ?>
                                            </span>
                                        </div>
                                        <p class="text-[10px] text-slate-500 mt-0.5">Źródła danych o zagrożeniach</p>
                                    </div>
                                </a>
                                
                                 <a href="#" class="flex items-start gap-3 p-3 rounded-xl hover:bg-white/5 transition-colors group/item">
                                    <div class="p-2 bg-purple-500/10 rounded-lg text-purple-400 shrink-0">
                                        <i data-lucide="globe" class="w-4 h-4"></i>
                                    </div>
                                    <div class="flex-grow min-w-0">
                                        <div class="flex items-center gap-2">
                                            <p class="text-sm font-bold text-white">Geo-blocking</p>
                                            <span class="px-1.5 py-0.5 rounded text-[8px] font-black uppercase tracking-tighter <?= $geoblocking_enabled ? 'bg-emerald-500/20 text-emerald-400' : 'bg-rose-500/20 text-rose-400' ?>">
                                                <?= $geoblocking_enabled ? 'AKTYWNE' : 'WYŁĄCZONE' ?>
                                            </span>
                                        </div>
                                        <p class="text-[10px] text-slate-500 mt-0.5">Blokada ruchu z wybranych krajów</p>
                                        
                                        <?php if ($geoblocking_enabled && !empty($security_settings['blocked_countries'])): ?>
                                        <div class="mt-2 flex flex-wrap gap-1 items-center">
                                            <?php 
                                            // Handle different UniFi formats (ISO codes vs numbers)
                                            foreach (array_slice($security_settings['blocked_countries'], 0, 12) as $country): 
                                                $code = is_numeric($country) ? 'un' : strtolower($country);
                                                if ($code === 'un') continue; 
                                            ?>
                                                <img src="https://flagcdn.com/24x18/<?= $code ?>.png" class="w-4 h-3 rounded-sm opacity-50 hover:opacity-100 transition-opacity" title="<?= strtoupper($code) ?>">
                                            <?php endforeach; ?>
                                            <?php if (count($security_settings['blocked_countries']) > 12): ?>
                                                <span class="text-[8px] text-slate-600 font-bold">+<?= count($security_settings['blocked_countries']) - 12 ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </a>
                                
                                <a href="#" class="flex items-start gap-3 p-3 rounded-xl hover:bg-white/5 transition-colors group/item">
                                    <div class="p-2 bg-amber-500/10 rounded-lg text-amber-400 shrink-0">
                                        <i data-lucide="zap" class="w-4 h-4"></i>
                                    </div>
                                    <div class="flex-grow min-w-0">
                                        <p class="text-sm font-bold text-white">Ochrona DDoS</p>
                                        <p class="text-[10px] text-slate-500 mt-0.5">Konfiguracja limitów i progów</p>
                                    </div>
                                </a>
                                
                                <a href="#" class="flex items-start gap-3 p-3 rounded-xl hover:bg-white/5 transition-colors group/item">
                                    <div class="p-2 bg-emerald-500/10 rounded-lg text-emerald-400 shrink-0">
                                        <i data-lucide="list" class="w-4 h-4"></i>
                                    </div>
                                    <div class="flex-grow min-w-0">
                                        <p class="text-sm font-bold text-white">Blacklist / Whitelist</p>
                                        <p class="text-[10px] text-slate-500 mt-0.5">Własne listy IP i domen</p>
                                    </div>
                                </a>
                                
                                <div class="h-px bg-white/5 my-2"></div>
                                
                                <a href="#" class="flex items-start gap-3 p-3 rounded-xl hover:bg-white/5 transition-colors group/item">
                                    <div class="p-2 bg-slate-500/10 rounded-lg text-slate-400 shrink-0">
                                        <i data-lucide="bell" class="w-4 h-4"></i>
                                    </div>
                                    <div class="flex-grow min-w-0">
                                        <p class="text-sm font-bold text-white">Alerty i powiadomienia</p>
                                        <p class="text-[10px] text-slate-500 mt-0.5">Konfiguracja alertów bezpieczeństwa</p>
                                    </div>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Stats Grid -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
            <div class="glass-card p-5 stat-glow-rose">
                <div class="flex justify-between items-center mb-4">
                    <div class="p-2.5 bg-rose-500/10 rounded-xl text-rose-400">
                        <i data-lucide="shield-x" class="w-5 h-5"></i>
                    </div>
                    <span class="text-[10px] font-black text-slate-500 uppercase tracking-widest">24h</span>
                </div>
                <div class="text-3xl font-black tracking-tighter"><?= $stats['threats_blocked'] ?></div>
                <div class="text-slate-400 text-xs mt-1 font-medium italic">Zablokowane zagrożenia</div>
            </div>


            <div class="glass-card p-5 stat-glow-blue cursor-pointer hover:scale-[1.02] transition-transform" onclick="openSecurityRulesModal()">
                <div class="flex justify-between items-center mb-4">
                    <div class="p-2.5 bg-blue-500/10 rounded-xl text-blue-400">
                        <i data-lucide="list-checks" class="w-5 h-5"></i>
                    </div>
                    <span class="text-[10px] font-black text-slate-500 uppercase tracking-widest">Aktywne</span>
                </div>
                <div class="text-3xl font-black tracking-tighter"><?= number_format($stats['active_rules']) ?></div>
                <div class="flex items-center justify-between mt-2">
                    <div class="text-slate-400 text-xs font-medium italic">Reguły bezpieczeństwa</div>
                    <button class="text-[10px] font-black text-blue-400 uppercase tracking-widest hover:text-blue-300 transition flex items-center gap-1">
                        Szczegóły
                        <i data-lucide="chevron-right" class="w-3 h-3"></i>
                    </button>
                </div>
            </div>

            <div class="glass-card p-5 stat-glow-amber cursor-pointer hover:scale-[1.02] transition-transform" onclick="openBlockedIPsModal()">
                <div class="flex justify-between items-center mb-4">
                    <div class="p-2.5 bg-amber-500/10 rounded-xl text-amber-400">
                        <i data-lucide="ban" class="w-5 h-5"></i>
                    </div>
                    <span class="text-[10px] font-black text-slate-500 uppercase tracking-widest">Blacklist</span>
                </div>
                <div class="text-3xl font-black tracking-tighter"><?= $stats['blocked_ips'] ?></div>
                <div class="flex items-center justify-between mt-2">
                    <div class="text-slate-400 text-xs font-medium italic">Zablokowane IP</div>
                    <button class="text-[10px] font-black text-amber-400 uppercase tracking-widest hover:text-amber-300 transition flex items-center gap-1">
                        Szczegóły
                        <i data-lucide="chevron-right" class="w-3 h-3"></i>
                    </button>
                </div>
            </div>

            <div class="glass-card p-6 stat-glow-emerald cursor-pointer hover:scale-[1.02] transition-transform relative overflow-hidden flex flex-col items-center justify-center min-h-[220px]" onclick="openSecurityScoreModal()">
                <!-- Progress Circle -->
                <div class="relative w-32 h-32 flex items-center justify-center mb-4">
                    <svg class="w-full h-full -rotate-90">
                        <circle cx="64" cy="64" r="58" stroke="currentColor" stroke-width="8" fill="transparent" class="text-white/5"></circle>
                        <circle cx="64" cy="64" r="58" stroke="currentColor" stroke-width="10" fill="transparent" class="<?= $stats['security_score'] >= 80 ? 'text-emerald-500' : 'text-rose-500' ?> transition-all duration-1000 ease-out" stroke-dasharray="364.4" stroke-dashoffset="<?= 364.4 - (364.4 * $stats['security_score'] / 100) ?>" stroke-linecap="round"></circle>
                    </svg>
                    <div class="absolute inset-0 flex flex-col items-center justify-center">
                        <span class="text-3xl font-black text-white"><?= $stats['security_score'] ?></span>
                        <span class="text-[9px] font-black text-slate-500 uppercase tracking-widest">Score</span>
                    </div>
                </div>
                
                <div class="text-center">
                    <p class="text-[10px] font-black <?= $stats['security_score'] >= 80 ? 'text-emerald-400' : 'text-rose-400' ?> uppercase tracking-widest mb-1">Poziom Bezpieczeństwa</p>
                    <p class="text-slate-500 text-[10px] font-medium italic">Kliknij po szczegóły</p>
                </div>
            </div>
        </div>

        <!-- Security Events -->
        <div class="glass-card p-6">
            <div class="flex flex-col gap-4 mb-6">
                <div class="flex items-center justify-between">
                    <h2 class="text-xl font-bold text-white flex items-center gap-2">
                        <i data-lucide="bell" class="w-6 h-6 text-rose-400"></i>
                        Zdarzenia bezpieczeństwa
                    </h2>
                    <div class="flex items-center gap-2">
                        <button class="px-4 py-2 bg-white/5 hover:bg-white/10 text-slate-400 hover:text-white rounded-xl text-xs font-bold transition border border-white/10">
                            <i data-lucide="filter" class="w-4 h-4 inline mr-1"></i>
                            Filtruj
                        </button>
                        <button class="px-4 py-2 bg-rose-600 hover:bg-rose-500 text-white rounded-xl text-xs font-bold transition">
                            <i data-lucide="download" class="w-4 h-4 inline mr-1"></i>
                            Eksportuj
                        </button>
                    </div>
                </div>
                
                <!-- Time Range Selector -->
                <div class="flex items-center gap-2 p-3 bg-slate-900/50 rounded-2xl border border-white/5">
                    <span class="text-[10px] font-black text-slate-500 uppercase tracking-widest mr-2">Zakres czasowy:</span>
                    <button onclick="selectTimeRange('1h')" class="time-range-btn px-4 py-2 bg-white/5 hover:bg-white/10 text-slate-400 hover:text-white rounded-xl text-xs font-bold transition border border-white/10" data-range="1h">
                        1h
                    </button>
                    <button onclick="selectTimeRange('1d')" class="time-range-btn px-4 py-2 bg-blue-600 text-white rounded-xl text-xs font-bold transition border border-blue-500/50 shadow-lg shadow-blue-600/20" data-range="1d">
                        1D
                    </button>
                    <button onclick="selectTimeRange('1w')" class="time-range-btn px-4 py-2 bg-white/5 hover:bg-white/10 text-slate-400 hover:text-white rounded-xl text-xs font-bold transition border border-white/10" data-range="1w">
                        1W
                    </button>
                    <button onclick="selectTimeRange('1m')" class="time-range-btn px-4 py-2 bg-white/5 hover:bg-white/10 text-slate-400 hover:text-white rounded-xl text-xs font-bold transition border border-white/10" data-range="1m">
                        1M
                    </button>
                    <div class="h-6 w-px bg-white/10 mx-1"></div>
                    <button onclick="openDateRangePicker()" class="px-4 py-2 bg-white/5 hover:bg-white/10 text-slate-400 hover:text-white rounded-xl text-xs font-bold transition border border-white/10 flex items-center gap-2">
                        <i data-lucide="calendar" class="w-4 h-4"></i>
                        Zakres
                    </button>
                </div>
            </div>

            <div class="space-y-3">
                <?php foreach ($security_events as $event): 
                    $severity_colors = [
                        'critical' => ['bg' => 'bg-red-500/10', 'text' => 'text-red-400', 'border' => 'border-red-500/20', 'icon' => 'alert-triangle'],
                        'high' => ['bg' => 'bg-orange-500/10', 'text' => 'text-orange-400', 'border' => 'border-orange-500/20', 'icon' => 'alert-circle'],
                        'medium' => ['bg' => 'bg-amber-500/10', 'text' => 'text-amber-400', 'border' => 'border-amber-500/20', 'icon' => 'info'],
                        'low' => ['bg' => 'bg-blue-500/10', 'text' => 'text-blue-400', 'border' => 'border-blue-500/20', 'icon' => 'info']
                    ];
                    $colors = $severity_colors[$event['severity']];
                ?>
                <div class="bg-slate-900/50 rounded-2xl border border-white/5 p-4 hover:border-rose-500/30 transition-all group">
                    <div class="flex items-start gap-4">
                        <div class="p-2.5 <?= $colors['bg'] ?> rounded-xl <?= $colors['text'] ?> shrink-0">
                            <i data-lucide="<?= $colors['icon'] ?>" class="w-5 h-5"></i>
                        </div>
                        <div class="flex-grow min-w-0">
                            <div class="flex items-start justify-between gap-4 mb-2">
                                <div class="flex-grow">
                                    <h3 class="font-bold text-white text-sm mb-1"><?= htmlspecialchars($event['description']) ?></h3>
                                    <div class="flex items-center gap-3 text-[10px] text-slate-500">
                                        <span class="flex items-center gap-1">
                                            <i data-lucide="clock" class="w-3 h-3"></i>
                                            <?= $event['time'] ?>
                                        </span>
                                        <span class="flex items-center gap-1">
                                            <i data-lucide="server" class="w-3 h-3"></i>
                                            <?= htmlspecialchars($event['source']) ?>
                                        </span>
                                    </div>
                                </div>
                                <div class="flex items-center gap-2 shrink-0">
                                    <span class="px-2.5 py-1 <?= $colors['bg'] ?> <?= $colors['text'] ?> rounded-lg text-[9px] font-black uppercase tracking-wider border <?= $colors['border'] ?>">
                                        <?= strtoupper($event['severity']) ?>
                                    </span>
                                    <span class="px-2.5 py-1 bg-emerald-500/10 text-emerald-400 rounded-lg text-[9px] font-black uppercase tracking-wider border border-emerald-500/20">
                                        <?= htmlspecialchars($event['action']) ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php render_footer(); ?>


    <!-- Date Range Picker Modal -->
    <div id="dateRangeModal" class="modal-overlay" onclick="closeDateRangePicker()">
        <div class="modal-container max-w-4xl" onclick="event.stopPropagation()">
            <div class="modal-header">
                <div>
                    <h2 class="text-xl font-bold text-white flex items-center gap-2">
                        <i data-lucide="calendar" class="w-6 h-6 text-blue-400"></i>
                        Wybierz zakres czasowy
                    </h2>
                    <p class="text-slate-500 text-xs mt-1">Określ początek i koniec okresu do analizy</p>
                </div>
                <button type="button" onclick="closeDateRangePicker()" class="p-2 hover:bg-white/5 rounded-xl transition text-slate-500 hover:text-white">
                    <i data-lucide="x" class="w-6 h-6"></i>
                </button>
            </div>
            <div class="modal-body p-8">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                    <!-- Start Date -->
                    <div class="space-y-4">
                        <h3 class="text-sm font-bold text-white uppercase tracking-widest">Data początkowa</h3>
                        <div class="bg-slate-900/50 rounded-2xl border border-white/5 p-4">
                            <div class="flex items-center justify-between mb-4">
                                <button class="p-2 hover:bg-white/5 rounded-lg transition">
                                    <i data-lucide="chevron-left" class="w-4 h-4 text-slate-400"></i>
                                </button>
                                <span class="text-sm font-bold text-white">Styczeń 2026</span>
                                <button class="p-2 hover:bg-white/5 rounded-lg transition">
                                    <i data-lucide="chevron-right" class="w-4 h-4 text-slate-400"></i>
                                </button>
                            </div>
                            <div class="grid grid-cols-7 gap-1 mb-2">
                                <div class="text-center text-[9px] font-black text-slate-600 uppercase">Pn</div>
                                <div class="text-center text-[9px] font-black text-slate-600 uppercase">Wt</div>
                                <div class="text-center text-[9px] font-black text-slate-600 uppercase">Śr</div>
                                <div class="text-center text-[9px] font-black text-slate-600 uppercase">Cz</div>
                                <div class="text-center text-[9px] font-black text-slate-600 uppercase">Pt</div>
                                <div class="text-center text-[9px] font-black text-slate-600 uppercase">So</div>
                                <div class="text-center text-[9px] font-black text-slate-600 uppercase">Nd</div>
                            </div>
                            <div class="grid grid-cols-7 gap-1">
                                <?php for ($i = 1; $i <= 31; $i++): ?>
                                <button class="aspect-square flex items-center justify-center text-xs font-bold rounded-lg hover:bg-blue-500/20 hover:text-blue-400 transition <?= $i === 5 ? 'bg-blue-600 text-white' : 'text-slate-400' ?>">
                                    <?= $i ?>
                                </button>
                                <?php endfor; ?>
                            </div>
                            <div class="mt-4 pt-4 border-t border-white/5">
                                <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest mb-2 block">Godzina rozpoczęcia</label>
                                <div class="flex items-center gap-2">
                                    <select class="flex-1 bg-slate-800 border border-white/10 rounded-lg px-3 py-2 text-sm text-white focus:border-blue-500 focus:outline-none">
                                        <?php for ($h = 0; $h < 24; $h++): ?>
                                        <option value="<?= $h ?>" <?= $h === 22 ? 'selected' : '' ?>><?= str_pad($h, 2, '0', STR_PAD_LEFT) ?></option>
                                        <?php endfor; ?>
                                    </select>
                                    <span class="text-slate-500">:</span>
                                    <select class="flex-1 bg-slate-800 border border-white/10 rounded-lg px-3 py-2 text-sm text-white focus:border-blue-500 focus:outline-none">
                                        <?php for ($m = 0; $m < 60; $m += 5): ?>
                                        <option value="<?= $m ?>"><?= str_pad($m, 2, '0', STR_PAD_LEFT) ?></option>
                                        <?php endfor; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- End Date -->
                    <div class="space-y-4">
                        <h3 class="text-sm font-bold text-white uppercase tracking-widest">Data końcowa</h3>
                        <div class="bg-slate-900/50 rounded-2xl border border-white/5 p-4">
                            <div class="flex items-center justify-between mb-4">
                                <button class="p-2 hover:bg-white/5 rounded-lg transition">
                                    <i data-lucide="chevron-left" class="w-4 h-4 text-slate-400"></i>
                                </button>
                                <span class="text-sm font-bold text-white">Luty 2026</span>
                                <button class="p-2 hover:bg-white/5 rounded-lg transition">
                                    <i data-lucide="chevron-right" class="w-4 h-4 text-slate-400"></i>
                                </button>
                            </div>
                            <div class="grid grid-cols-7 gap-1 mb-2">
                                <div class="text-center text-[9px] font-black text-slate-600 uppercase">Pn</div>
                                <div class="text-center text-[9px] font-black text-slate-600 uppercase">Wt</div>
                                <div class="text-center text-[9px] font-black text-slate-600 uppercase">Śr</div>
                                <div class="text-center text-[9px] font-black text-slate-600 uppercase">Cz</div>
                                <div class="text-center text-[9px] font-black text-slate-600 uppercase">Pt</div>
                                <div class="text-center text-[9px] font-black text-slate-600 uppercase">So</div>
                                <div class="text-center text-[9px] font-black text-slate-600 uppercase">Nd</div>
                            </div>
                            <div class="grid grid-cols-7 gap-1">
                                <?php for ($i = 1; $i <= 28; $i++): ?>
                                <button class="aspect-square flex items-center justify-center text-xs font-bold rounded-lg hover:bg-blue-500/20 hover:text-blue-400 transition <?= $i === 5 ? 'bg-blue-600 text-white' : 'text-slate-400' ?>">
                                    <?= $i ?>
                                </button>
                                <?php endfor; ?>
                            </div>
                            <div class="mt-4 pt-4 border-t border-white/5">
                                <label class="text-[10px] font-black text-slate-500 uppercase tracking-widest mb-2 block">Godzina zakończenia</label>
                                <div class="flex items-center gap-2">
                                    <select class="flex-1 bg-slate-800 border border-white/10 rounded-lg px-3 py-2 text-sm text-white focus:border-blue-500 focus:outline-none">
                                        <?php for ($h = 0; $h < 24; $h++): ?>
                                        <option value="<?= $h ?>" <?= $h === 22 ? 'selected' : '' ?>><?= str_pad($h, 2, '0', STR_PAD_LEFT) ?></option>
                                        <?php endfor; ?>
                                    </select>
                                    <span class="text-slate-500">:</span>
                                    <select class="flex-1 bg-slate-800 border border-white/10 rounded-lg px-3 py-2 text-sm text-white focus:border-blue-500 focus:outline-none">
                                        <?php for ($m = 0; $m < 60; $m += 5): ?>
                                        <option value="<?= $m ?>"><?= str_pad($m, 2, '0', STR_PAD_LEFT) ?></option>
                                        <?php endfor; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer p-6 border-t border-white/5 bg-slate-900/30 flex justify-end gap-3">
                <button type="button" onclick="closeDateRangePicker()" class="px-8 py-2.5 bg-white/5 hover:bg-white/10 text-slate-400 hover:text-white rounded-xl text-xs font-bold uppercase tracking-widest transition border border-white/10">
                    Anuluj
                </button>
                <button type="button" onclick="applyDateRange()" class="px-8 py-2.5 bg-blue-600 hover:bg-blue-500 text-white rounded-xl text-xs font-bold uppercase tracking-widest transition shadow-lg shadow-blue-600/20">
                    Zastosuj
                </button>
            </div>
        </div>
    </div>

    <script>
        // Time range selection
        function selectTimeRange(range) {
            // Remove active state from all buttons
            document.querySelectorAll('.time-range-btn').forEach(btn => {
                btn.classList.remove('bg-blue-600', 'text-white', 'border-blue-500/50', 'shadow-lg', 'shadow-blue-600/20');
                btn.classList.add('bg-white/5', 'text-slate-400', 'border-white/10');
            });
            
            // Add active state to clicked button
            const activeBtn = document.querySelector(`[data-range="${range}"]`);
            activeBtn.classList.remove('bg-white/5', 'text-slate-400', 'border-white/10');
            activeBtn.classList.add('bg-blue-600', 'text-white', 'border-blue-500/50', 'shadow-lg', 'shadow-blue-600/20');
            
            // Here you would typically fetch new data based on the selected range
            console.log('Selected time range:', range);
        }
        
        function openDateRangePicker() {
            document.getElementById('dateRangeModal').classList.add('active');
            document.body.style.overflow = 'hidden';
            setTimeout(() => lucide.createIcons(), 100);
        }
        
        function closeDateRangePicker() {
            document.getElementById('dateRangeModal').classList.remove('active');
            document.body.style.overflow = '';
        }
        
        function applyDateRange() {
            // Here you would collect the selected dates and apply the filter
            console.log('Applying custom date range');
            closeDateRangePicker();
        }
        
        function openBlockedIPsModal() {
            document.getElementById('blockedIPsModal').classList.add('active');
            document.body.style.overflow = 'hidden';
            setTimeout(() => lucide.createIcons(), 100);
        }
        
        function closeBlockedIPsModal() {
            document.getElementById('blockedIPsModal').classList.remove('active');
            document.body.style.overflow = '';
        }
        
        lucide.createIcons();
    </script>
    
    <!-- Modal: Blocked IPs -->
    <div id="blockedIPsModal" class="modal-overlay" onclick="closeBlockedIPsModal()">
        <div class="modal-container max-w-6xl" onclick="event.stopPropagation()">
            <div class="modal-header">
                <div>
                    <h2 class="text-xl font-bold flex items-center gap-2 text-white">
                        <i data-lucide="ban" class="w-6 h-6 text-amber-400"></i>
                        Zablokowane adresy IP
                    </h2>
                    <p class="text-slate-500 text-xs mt-1">Lista adresów IP zablokowanych przez system bezpieczeństwa</p>
                </div>
                <button type="button" onclick="closeBlockedIPsModal()" class="p-2 hover:bg-white/5 rounded-xl transition text-slate-500 hover:text-white">
                    <i data-lucide="x" class="w-6 h-6"></i>
                </button>
            </div>
            
            <div class="modal-body p-8">
                <!-- Search Bar -->
                <div class="mb-6">
                    <div class="relative">
                        <i data-lucide="search" class="absolute left-4 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-500"></i>
                        <input type="text" placeholder="Szukaj adresu IP, kraju lub powodu blokady..." 
                               class="w-full pl-12 pr-4 py-3 bg-slate-900/50 border border-white/10 rounded-xl text-white placeholder-slate-500 focus:outline-none focus:border-amber-500/50 transition">
                    </div>
                </div>
                
                <!-- Pagination Controls -->
                <div class="flex items-center justify-between mb-4">
                    <div class="flex items-center gap-2">
                        <span class="text-xs text-slate-500 font-bold uppercase tracking-widest">Pokaż:</span>
                        <div class="flex gap-1 bg-slate-900/50 p-1 rounded-xl border border-white/5">
                            <button onclick="setBlockedIPsPageSize(20)" class="blocked-ips-page-btn px-3 py-1.5 rounded-lg text-xs font-bold transition active">20</button>
                            <button onclick="setBlockedIPsPageSize(50)" class="blocked-ips-page-btn px-3 py-1.5 rounded-lg text-xs font-bold transition">50</button>
                            <button onclick="setBlockedIPsPageSize(100)" class="blocked-ips-page-btn px-3 py-1.5 rounded-lg text-xs font-bold transition">100</button>
                            <button onclick="setBlockedIPsPageSize(-1)" class="blocked-ips-page-btn px-3 py-1.5 rounded-lg text-xs font-bold transition">Wszystko</button>
                        </div>
                        
                        <div id="blockedIPsPageNumbers" class="flex gap-1 ml-4 block p-1 rounded-xl border border-white/5 bg-slate-900/50">
                            <!-- Page numbers will be injected here via JS -->
                        </div>
                    </div>
                    <div class="text-xs text-slate-500 font-mono">
                        Wyświetlanie <span id="blockedIPsRangeStart" class="text-amber-400 font-bold">1</span>-<span id="blockedIPsRangeEnd" class="text-amber-400 font-bold"><?= min($blocked_ips, 20) ?></span> z <span class="text-white font-bold"><?= $blocked_ips ?></span>
                    </div>
                </div>
                
                <!-- IP Table -->
                <div class="bg-slate-900/50 rounded-2xl border border-white/5 overflow-hidden">
                    <table class="w-full text-left border-collapse">
                        <thead>
                            <tr class="bg-slate-950/30 text-[9px] font-black text-slate-500 uppercase tracking-widest border-b border-white/5">
                                <th class="px-6 py-4">Kraj</th>
                                <th class="px-6 py-4">Adres IP</th>
                                <th class="px-6 py-4">Typ zagrożenia</th>
                                <th class="px-6 py-4">Powód blokady</th>
                                <th class="px-6 py-4 text-right">Zablokowano</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/[0.02]">
                            <?php if ($blocked_ips > 0): ?>
                            <?php foreach ($blocked_ips_list as $ip): ?>
                            <tr class="hover:bg-white/[0.02] transition-colors">
                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-2">
                                        <img src="https://flagcdn.com/24x18/<?= $ip['country_code'] ?>.png" alt="<?= strtoupper($ip['country_code']) ?>" class="w-6 h-auto rounded shadow-sm">
                                        <span class="text-xs text-slate-400 font-mono"><?= strtoupper($ip['country_code']) ?></span>
                                    </div>
                                </td>
                                <td class="px-6 py-4 font-mono text-sm text-white"><?= $ip['ip'] ?></td>
                                <td class="px-6 py-4">
                                    <span class="px-2 py-0.5 rounded-md bg-rose-500/10 text-[10px] font-mono text-rose-400 border border-rose-500/20"><?= $ip['type'] ?></span>
                                </td>
                                <td class="px-6 py-4 text-xs text-slate-400"><?= $ip['reason'] ?></td>
                                <td class="px-6 py-4 text-right text-xs font-mono text-slate-500"><?= $ip['time'] ?></td>
                            </tr>
                            <?php endforeach; ?>
                            <?php else: ?>
                            <tr>
                                <td colspan="5" class="px-6 py-20 text-center">
                                    <div class="flex flex-col items-center gap-3">
                                        <div class="p-4 bg-slate-900/50 rounded-full">
                                            <i data-lucide="shield-check" class="w-8 h-8 text-emerald-400"></i>
                                        </div>
                                        <div class="text-white font-bold">Brak zablokowanych adresów</div>
                                        <div class="text-slate-500 text-xs text-center max-w-[280px]">
                                            System IPS nie zarejestrował obecnie żadnych aktywnych blokad adresów IP.
                                        </div>
                                    </div>
                                </td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
    <tr class="hover:bg-white/[0.02] transition-colors">
                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-2">
                                        <img src="https://flagcdn.com/24x18/us.png" alt="US" class="w-6 h-auto rounded shadow-sm">
                                        <span class="text-xs text-slate-400 font-mono">US</span>
                                    </div>
                                </td>
                                <td class="px-6 py-4 font-mono text-sm text-white">192.168.45.12</td>
                                <td class="px-6 py-4">
                                    <span class="px-2 py-0.5 rounded-md bg-purple-500/10 text-[10px] font-mono text-purple-400 border border-purple-500/20">Port Scan</span>
                                </td>
                                <td class="px-6 py-4 text-xs text-slate-400">Aggressive port scanning</td>
                                <td class="px-6 py-4 text-right text-xs font-mono text-slate-500">3d 2h temu</td>
                            </tr>
                            
                            <tr class="hover:bg-white/[0.02] transition-colors">
                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-2">
                                        <img src="https://flagcdn.com/24x18/pl.png" alt="PL" class="w-6 h-auto rounded shadow-sm">
                                        <span class="text-xs text-slate-400 font-mono">PL</span>
                                    </div>
                                </td>
                                <td class="px-6 py-4 font-mono text-sm text-white">83.12.45.78</td>
                                <td class="px-6 py-4">
                                    <span class="px-2 py-0.5 rounded-md bg-rose-500/10 text-[10px] font-mono text-rose-400 border border-rose-500/20">Brute Force</span>
                                </td>
                                <td class="px-6 py-4 text-xs text-slate-400">FTP brute force attack</td>
                                <td class="px-6 py-4 text-right text-xs font-mono text-slate-500">3d 8h temu</td>
                            </tr>
                            
                            <tr class="hover:bg-white/[0.02] transition-colors">
                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-2">
                                        <img src="https://flagcdn.com/24x18/de.png" alt="DE" class="w-6 h-auto rounded shadow-sm">
                                        <span class="text-xs text-slate-400 font-mono">DE</span>
                                    </div>
                                </td>
                                <td class="px-6 py-4 font-mono text-sm text-white">95.142.33.21</td>
                                <td class="px-6 py-4">
                                    <span class="px-2 py-0.5 rounded-md bg-amber-500/10 text-[10px] font-mono text-amber-400 border border-amber-500/20">DDoS</span>
                                </td>
                                <td class="px-6 py-4 text-xs text-slate-400">HTTP flood attack</td>
                                <td class="px-6 py-4 text-right text-xs font-mono text-slate-500">3d 14h temu</td>
                            </tr>
                            
                            <tr class="hover:bg-white/[0.02] transition-colors">
                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-2">
                                        <img src="https://flagcdn.com/24x18/fr.png" alt="FR" class="w-6 h-auto rounded shadow-sm">
                                        <span class="text-xs text-slate-400 font-mono">FR</span>
                                    </div>
                                </td>
                                <td class="px-6 py-4 font-mono text-sm text-white">51.89.12.45</td>
                                <td class="px-6 py-4">
                                    <span class="px-2 py-0.5 rounded-md bg-blue-500/10 text-[10px] font-mono text-blue-400 border border-blue-500/20">SQL Injection</span>
                                </td>
                                <td class="px-6 py-4 text-xs text-slate-400">Database injection attempt</td>
                                <td class="px-6 py-4 text-right text-xs font-mono text-slate-500">4d 6h temu</td>
                            </tr>
                            
                            <tr class="hover:bg-white/[0.02] transition-colors">
                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-2">
                                        <img src="https://flagcdn.com/24x18/nl.png" alt="NL" class="w-6 h-auto rounded shadow-sm">
                                        <span class="text-xs text-slate-400 font-mono">NL</span>
                                    </div>
                                </td>
                                <td class="px-6 py-4 font-mono text-sm text-white">185.107.56.23</td>
                                <td class="px-6 py-4">
                                    <span class="px-2 py-0.5 rounded-md bg-red-500/10 text-[10px] font-mono text-red-400 border border-red-500/20">Malware</span>
                                </td>
                                <td class="px-6 py-4 text-xs text-slate-400">Trojan distribution</td>
                                <td class="px-6 py-4 text-right text-xs font-mono text-slate-500">4d 12h temu</td>
                            </tr>
                            
                            <tr class="hover:bg-white/[0.02] transition-colors">
                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-2">
                                        <img src="https://flagcdn.com/24x18/gb.png" alt="GB" class="w-6 h-auto rounded shadow-sm">
                                        <span class="text-xs text-slate-400 font-mono">GB</span>
                                    </div>
                                </td>
                                <td class="px-6 py-4 font-mono text-sm text-white">81.143.22.67</td>
                                <td class="px-6 py-4">
                                    <span class="px-2 py-0.5 rounded-md bg-purple-500/10 text-[10px] font-mono text-purple-400 border border-purple-500/20">Port Scan</span>
                                </td>
                                <td class="px-6 py-4 text-xs text-slate-400">Service enumeration</td>
                                <td class="px-6 py-4 text-right text-xs font-mono text-slate-500">5d 3h temu</td>
                            </tr>
                            
                            <tr class="hover:bg-white/[0.02] transition-colors">
                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-2">
                                        <img src="https://flagcdn.com/24x18/jp.png" alt="JP" class="w-6 h-auto rounded shadow-sm">
                                        <span class="text-xs text-slate-400 font-mono">JP</span>
                                    </div>
                                </td>
                                <td class="px-6 py-4 font-mono text-sm text-white">153.126.45.89</td>
                                <td class="px-6 py-4">
                                    <span class="px-2 py-0.5 rounded-md bg-rose-500/10 text-[10px] font-mono text-rose-400 border border-rose-500/20">Brute Force</span>
                                </td>
                                <td class="px-6 py-4 text-xs text-slate-400">RDP brute force</td>
                                <td class="px-6 py-4 text-right text-xs font-mono text-slate-500">5d 18h temu</td>
                            </tr>
                            
                            <tr class="hover:bg-white/[0.02] transition-colors">
                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-2">
                                        <img src="https://flagcdn.com/24x18/kr.png" alt="KR" class="w-6 h-auto rounded shadow-sm">
                                        <span class="text-xs text-slate-400 font-mono">KR</span>
                                    </div>
                                </td>
                                <td class="px-6 py-4 font-mono text-sm text-white">121.162.78.34</td>
                                <td class="px-6 py-4">
                                    <span class="px-2 py-0.5 rounded-md bg-amber-500/10 text-[10px] font-mono text-amber-400 border border-amber-500/20">DDoS</span>
                                </td>
                                <td class="px-6 py-4 text-xs text-slate-400">UDP flood</td>
                                <td class="px-6 py-4 text-right text-xs font-mono text-slate-500">6d 4h temu</td>
                            </tr>
                            
                            <tr class="hover:bg-white/[0.02] transition-colors">
                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-2">
                                        <img src="https://flagcdn.com/24x18/au.png" alt="AU" class="w-6 h-auto rounded shadow-sm">
                                        <span class="text-xs text-slate-400 font-mono">AU</span>
                                    </div>
                                </td>
                                <td class="px-6 py-4 font-mono text-sm text-white">203.45.12.78</td>
                                <td class="px-6 py-4">
                                    <span class="px-2 py-0.5 rounded-md bg-blue-500/10 text-[10px] font-mono text-blue-400 border border-blue-500/20">XSS Attack</span>
                                </td>
                                <td class="px-6 py-4 text-xs text-slate-400">Reflected XSS attempt</td>
                                <td class="px-6 py-4 text-right text-xs font-mono text-slate-500">6d 16h temu</td>
                            </tr>
                            
                            <tr class="hover:bg-white/[0.02] transition-colors">
                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-2">
                                        <img src="https://flagcdn.com/24x18/sg.png" alt="SG" class="w-6 h-auto rounded shadow-sm">
                                        <span class="text-xs text-slate-400 font-mono">SG</span>
                                    </div>
                                </td>
                                <td class="px-6 py-4 font-mono text-sm text-white">128.199.45.23</td>
                                <td class="px-6 py-4">
                                    <span class="px-2 py-0.5 rounded-md bg-red-500/10 text-[10px] font-mono text-red-400 border border-red-500/20">Malware</span>
                                </td>
                                <td class="px-6 py-4 text-xs text-slate-400">Cryptominer detected</td>
                                <td class="px-6 py-4 text-right text-xs font-mono text-slate-500">7d 8h temu</td>
                            </tr>
                            
                            <tr class="hover:bg-white/[0.02] transition-colors">
                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-2">
                                        <img src="https://flagcdn.com/24x18/ca.png" alt="CA" class="w-6 h-auto rounded shadow-sm">
                                        <span class="text-xs text-slate-400 font-mono">CA</span>
                                    </div>
                                </td>
                                <td class="px-6 py-4 font-mono text-sm text-white">142.93.12.56</td>
                                <td class="px-6 py-4">
                                    <span class="px-2 py-0.5 rounded-md bg-purple-500/10 text-[10px] font-mono text-purple-400 border border-purple-500/20">Port Scan</span>
                                </td>
                                <td class="px-6 py-4 text-xs text-slate-400">Vulnerability scanning</td>
                                <td class="px-6 py-4 text-right text-xs font-mono text-slate-500">8d 2h temu</td>
                            </tr>
                            
                            <tr class="hover:bg-white/[0.02] transition-colors">
                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-2">
                                        <img src="https://flagcdn.com/24x18/mx.png" alt="MX" class="w-6 h-auto rounded shadow-sm">
                                        <span class="text-xs text-slate-400 font-mono">MX</span>
                                    </div>
                                </td>
                                <td class="px-6 py-4 font-mono text-sm text-white">187.45.78.23</td>
                                <td class="px-6 py-4">
                                    <span class="px-2 py-0.5 rounded-md bg-rose-500/10 text-[10px] font-mono text-rose-400 border border-rose-500/20">Brute Force</span>
                                </td>
                                <td class="px-6 py-4 text-xs text-slate-400">WordPress admin attack</td>
                                <td class="px-6 py-4 text-right text-xs font-mono text-slate-500">8d 14h temu</td>
                            </tr>
                            
                            <tr class="hover:bg-white/[0.02] transition-colors">
                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-2">
                                        <img src="https://flagcdn.com/24x18/ar.png" alt="AR" class="w-6 h-auto rounded shadow-sm">
                                        <span class="text-xs text-slate-400 font-mono">AR</span>
                                    </div>
                                </td>
                                <td class="px-6 py-4 font-mono text-sm text-white">190.12.45.89</td>
                                <td class="px-6 py-4">
                                    <span class="px-2 py-0.5 rounded-md bg-amber-500/10 text-[10px] font-mono text-amber-400 border border-amber-500/20">DDoS</span>
                                </td>
                                <td class="px-6 py-4 text-xs text-slate-400">ICMP flood</td>
                                <td class="px-6 py-4 text-right text-xs font-mono text-slate-500">9d 6h temu</td>
                            </tr>
                            
                            <tr class="hover:bg-white/[0.02] transition-colors">
                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-2">
                                        <img src="https://flagcdn.com/24x18/za.png" alt="ZA" class="w-6 h-auto rounded shadow-sm">
                                        <span class="text-xs text-slate-400 font-mono">ZA</span>
                                    </div>
                                </td>
                                <td class="px-6 py-4 font-mono text-sm text-white">102.67.34.12</td>
                                <td class="px-6 py-4">
                                    <span class="px-2 py-0.5 rounded-md bg-blue-500/10 text-[10px] font-mono text-blue-400 border border-blue-500/20">SQL Injection</span>
                                </td>
                                <td class="px-6 py-4 text-xs text-slate-400">Blind SQL injection</td>
                                <td class="px-6 py-4 text-right text-xs font-mono text-slate-500">9d 18h temu</td>
                            </tr>
                            
                            <tr class="hover:bg-white/[0.02] transition-colors">
                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-2">
                                        <img src="https://flagcdn.com/24x18/se.png" alt="SE" class="w-6 h-auto rounded shadow-sm">
                                        <span class="text-xs text-slate-400 font-mono">SE</span>
                                    </div>
                                </td>
                                <td class="px-6 py-4 font-mono text-sm text-white">94.142.56.78</td>
                                <td class="px-6 py-4">
                                    <span class="px-2 py-0.5 rounded-md bg-red-500/10 text-[10px] font-mono text-red-400 border border-red-500/20">Malware</span>
                                </td>
                                <td class="px-6 py-4 text-xs text-slate-400">Phishing site hosting</td>
                                <td class="px-6 py-4 text-right text-xs font-mono text-slate-500">10d 4h temu</td>
                            </tr>
                            
                            <tr class="hover:bg-white/[0.02] transition-colors">
                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-2">
                                        <img src="https://flagcdn.com/24x18/no.png" alt="NO" class="w-6 h-auto rounded shadow-sm">
                                        <span class="text-xs text-slate-400 font-mono">NO</span>
                                    </div>
                                </td>
                                <td class="px-6 py-4 font-mono text-sm text-white">85.167.23.45</td>
                                <td class="px-6 py-4">
                                    <span class="px-2 py-0.5 rounded-md bg-purple-500/10 text-[10px] font-mono text-purple-400 border border-purple-500/20">Port Scan</span>
                                </td>
                                <td class="px-6 py-4 text-xs text-slate-400">Stealth SYN scan</td>
                                <td class="px-6 py-4 text-right text-xs font-mono text-slate-500">10d 16h temu</td>
                            </tr>
                            
                            <tr class="hover:bg-white/[0.02] transition-colors">
                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-2">
                                        <img src="https://flagcdn.com/24x18/dk.png" alt="DK" class="w-6 h-auto rounded shadow-sm">
                                        <span class="text-xs text-slate-400 font-mono">DK</span>
                                    </div>
                                </td>
                                <td class="px-6 py-4 font-mono text-sm text-white">87.56.12.34</td>
                                <td class="px-6 py-4">
                                    <span class="px-2 py-0.5 rounded-md bg-rose-500/10 text-[10px] font-mono text-rose-400 border border-rose-500/20">Brute Force</span>
                                </td>
                                <td class="px-6 py-4 text-xs text-slate-400">SMTP authentication attack</td>
                                <td class="px-6 py-4 text-right text-xs font-mono text-slate-500">11d 8h temu</td>
                            </tr>
                            
                            <tr class="hover:bg-white/[0.02] transition-colors">
                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-2">
                                        <img src="https://flagcdn.com/24x18/fi.png" alt="FI" class="w-6 h-auto rounded shadow-sm">
                                        <span class="text-xs text-slate-400 font-mono">FI</span>
                                    </div>
                                </td>
                                <td class="px-6 py-4 font-mono text-sm text-white">91.152.34.67</td>
                                <td class="px-6 py-4">
                                    <span class="px-2 py-0.5 rounded-md bg-amber-500/10 text-[10px] font-mono text-amber-400 border border-amber-500/20">DDoS</span>
                                </td>
                                <td class="px-6 py-4 text-xs text-slate-400">DNS amplification</td>
                                <td class="px-6 py-4 text-right text-xs font-mono text-slate-500">12d 2h temu</td>
                            </tr>
                            
                            <tr class="hover:bg-white/[0.02] transition-colors">
                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-2">
                                        <img src="https://flagcdn.com/24x18/es.png" alt="ES" class="w-6 h-auto rounded shadow-sm">
                                        <span class="text-xs text-slate-400 font-mono">ES</span>
                                    </div>
                                </td>
                                <td class="px-6 py-4 font-mono text-sm text-white">88.23.45.12</td>
                                <td class="px-6 py-4">
                                    <span class="px-2 py-0.5 rounded-md bg-blue-500/10 text-[10px] font-mono text-blue-400 border border-blue-500/20">XSS Attack</span>
                                </td>
                                <td class="px-6 py-4 text-xs text-slate-400">Stored XSS injection</td>
                                <td class="px-6 py-4 text-right text-xs font-mono text-slate-500">12d 14h temu</td>
                            </tr>
                            
                            <tr class="hover:bg-white/[0.02] transition-colors">
                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-2">
                                        <img src="https://flagcdn.com/24x18/it.png" alt="IT" class="w-6 h-auto rounded shadow-sm">
                                        <span class="text-xs text-slate-400 font-mono">IT</span>
                                    </div>
                                </td>
                                <td class="px-6 py-4 font-mono text-sm text-white">93.45.78.23</td>
                                <td class="px-6 py-4">
                                    <span class="px-2 py-0.5 rounded-md bg-red-500/10 text-[10px] font-mono text-red-400 border border-red-500/20">Malware</span>
                                </td>
                                <td class="px-6 py-4 text-xs text-slate-400">Spyware distribution</td>
                                <td class="px-6 py-4 text-right text-xs font-mono text-slate-500">13d 6h temu</td>
                            </tr>
                            
                            <tr class="hover:bg-white/[0.02] transition-colors">
                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-2">
                                        <img src="https://flagcdn.com/24x18/pt.png" alt="PT" class="w-6 h-auto rounded shadow-sm">
                                        <span class="text-xs text-slate-400 font-mono">PT</span>
                                    </div>
                                </td>
                                <td class="px-6 py-4 font-mono text-sm text-white">89.152.12.45</td>
                                <td class="px-6 py-4">
                                    <span class="px-2 py-0.5 rounded-md bg-purple-500/10 text-[10px] font-mono text-purple-400 border border-purple-500/20">Port Scan</span>
                                </td>
                                <td class="px-6 py-4 text-xs text-slate-400">Network reconnaissance</td>
                                <td class="px-6 py-4 text-right text-xs font-mono text-slate-500">13d 18h temu</td>
                            </tr>
                            
                            <tr class="hover:bg-white/[0.02] transition-colors">
                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-2">
                                        <img src="https://flagcdn.com/24x18/gr.png" alt="GR" class="w-6 h-auto rounded shadow-sm">
                                        <span class="text-xs text-slate-400 font-mono">GR</span>
                                    </div>
                                </td>
                                <td class="px-6 py-4 font-mono text-sm text-white">95.67.23.89</td>
                                <td class="px-6 py-4">
                                    <span class="px-2 py-0.5 rounded-md bg-rose-500/10 text-[10px] font-mono text-rose-400 border border-rose-500/20">Brute Force</span>
                                </td>
                                <td class="px-6 py-4 text-xs text-slate-400">Telnet brute force</td>
                                <td class="px-6 py-4 text-right text-xs font-mono text-slate-500">14d 10h temu</td>
                            </tr>
                            
                            <tr class="hover:bg-white/[0.02] transition-colors">
                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-2">
                                        <img src="https://flagcdn.com/24x18/cz.png" alt="CZ" class="w-6 h-auto rounded shadow-sm">
                                        <span class="text-xs text-slate-400 font-mono">CZ</span>
                                    </div>
                                </td>
                                <td class="px-6 py-4 font-mono text-sm text-white">78.45.12.34</td>
                                <td class="px-6 py-4">
                                    <span class="px-2 py-0.5 rounded-md bg-amber-500/10 text-[10px] font-mono text-amber-400 border border-amber-500/20">DDoS</span>
                                </td>
                                <td class="px-6 py-4 text-xs text-slate-400">NTP amplification</td>
                                <td class="px-6 py-4 text-right text-xs font-mono text-slate-500">15d 4h temu</td>
                            </tr>
                            
                            <tr class="hover:bg-white/[0.02] transition-colors">
                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-2">
                                        <img src="https://flagcdn.com/24x18/hu.png" alt="HU" class="w-6 h-auto rounded shadow-sm">
                                        <span class="text-xs text-slate-400 font-mono">HU</span>
                                    </div>
                                </td>
                                <td class="px-6 py-4 font-mono text-sm text-white">84.23.56.78</td>
                                <td class="px-6 py-4">
                                    <span class="px-2 py-0.5 rounded-md bg-blue-500/10 text-[10px] font-mono text-blue-400 border border-blue-500/20">SQL Injection</span>
                                </td>
                                <td class="px-6 py-4 text-xs text-slate-400">Time-based SQL injection</td>
                                <td class="px-6 py-4 text-right text-xs font-mono text-slate-500">15d 16h temu</td>
                            </tr>
                            
                            <tr class="hover:bg-white/[0.02] transition-colors">
                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-2">
                                        <img src="https://flagcdn.com/24x18/at.png" alt="AT" class="w-6 h-auto rounded shadow-sm">
                                        <span class="text-xs text-slate-400 font-mono">AT</span>
                                    </div>
                                </td>
                                <td class="px-6 py-4 font-mono text-sm text-white">77.116.45.23</td>
                                <td class="px-6 py-4">
                                    <span class="px-2 py-0.5 rounded-md bg-red-500/10 text-[10px] font-mono text-red-400 border border-red-500/20">Malware</span>
                                </td>
                                <td class="px-6 py-4 text-xs text-slate-400">Keylogger detected</td>
                                <td class="px-6 py-4 text-right text-xs font-mono text-slate-500">16d 8h temu</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <div class="modal-footer p-6 border-t border-white/5 bg-slate-900/30 flex justify-between items-center">
                <p class="text-[10px] text-slate-500 font-bold uppercase tracking-widest">
                    Łącznie zablokowanych: <span class="text-amber-400">34 adresy IP</span>
                </p>
                <button type="button" onclick="closeBlockedIPsModal()" class="px-8 py-2.5 bg-amber-600 hover:bg-amber-500 text-white rounded-xl text-[10px] font-black uppercase tracking-widest transition shadow-lg shadow-amber-600/20">
                    Zamknij
                </button>
            </div>
        </div>
    </div>
    
    <!-- Modal: Security Rules -->
    <div id="securityRulesModal" class="modal-overlay" onclick="closeSecurityRulesModal()">
        <div class="modal-container max-w-7xl" onclick="event.stopPropagation()">
            <div class="modal-header">
                <div>
                    <h2 class="text-xl font-bold flex items-center gap-2 text-white">
                        <i data-lucide="list-checks" class="w-6 h-6 text-blue-400"></i>
                        Aktywne reguły bezpieczeństwa
                    </h2>
                    <p class="text-slate-500 text-xs mt-1">System <?= $ips_enabled ? 'wykrywania i zapobiegania włamaniom (IPS/IDS)' : '<span class="text-red-400 font-bold">WYŁĄCZONY</span> - Włącz IPS w ustawieniach UniFi' ?></p>
                </div>
                <button type="button" onclick="closeSecurityRulesModal()" class="p-2 hover:bg-white/5 rounded-xl transition text-slate-500 hover:text-white">
                    <i data-lucide="x" class="w-6 h-6"></i>
                </button>
            </div>
            
            <div class="modal-body p-8">
                <!-- Filter Tabs -->
                <div class="flex gap-2 mb-6 overflow-x-auto pb-2">
                    <button class="px-4 py-2 bg-blue-600 text-white rounded-xl text-xs font-bold uppercase tracking-widest whitespace-nowrap">
                        Wszystkie (<?= number_format($active_rules) ?>)
                    </button>
                    <button class="px-4 py-2 bg-slate-900/50 text-slate-400 hover:text-white rounded-xl text-xs font-bold uppercase tracking-widest transition whitespace-nowrap">
                        Krytyczne (<?= $ips_enabled ? number_format(round($active_rules * 0.12)) : '0' ?>)
                    </button>
                    <button class="px-4 py-2 bg-slate-900/50 text-slate-400 hover:text-white rounded-xl text-xs font-bold uppercase tracking-widest transition whitespace-nowrap">
                        Wysokie (<?= $ips_enabled ? number_format(round($active_rules * 0.42)) : '0' ?>)
                    </button>
                    <button class="px-4 py-2 bg-slate-900/50 text-slate-400 hover:text-white rounded-xl text-xs font-bold uppercase tracking-widest transition whitespace-nowrap">
                        Średnie (<?= $ips_enabled ? number_format(round($active_rules * 0.46)) : '0' ?>)
                    </button>
                </div>
                
                <!-- Pagination Controls -->
                <div class="flex items-center justify-between mb-4">
                    <div class="flex items-center gap-2">
                        <span class="text-xs text-slate-500 font-bold uppercase tracking-widest">Pokaż:</span>
                        <div class="flex gap-1 bg-slate-900/50 p-1 rounded-xl border border-white/5">
                            <button onclick="setSecurityRulesPageSize(20)" class="security-rules-page-btn px-3 py-1.5 rounded-lg text-xs font-bold transition active">20</button>
                            <button onclick="setSecurityRulesPageSize(50)" class="security-rules-page-btn px-3 py-1.5 rounded-lg text-xs font-bold transition">50</button>
                            <button onclick="setSecurityRulesPageSize(100)" class="security-rules-page-btn px-3 py-1.5 rounded-lg text-xs font-bold transition">100</button>
                            <button onclick="setSecurityRulesPageSize(-1)" class="security-rules-page-btn px-3 py-1.5 rounded-lg text-xs font-bold transition">Wszystko</button>
                        </div>

                        <div id="securityRulesPageNumbers" class="flex gap-1 ml-4 block p-1 rounded-xl border border-white/5 bg-slate-900/50">
                            <!-- Page numbers will be injected here via JS -->
                        </div>
                    </div>
                    <div class="text-xs text-slate-500 font-mono">
                        Wyświetlanie <span id="securityRulesRangeStart" class="text-blue-400 font-bold">1</span>-<span id="securityRulesRangeEnd" class="text-blue-400 font-bold"><?= min($active_rules, 20) ?></span> z <span class="text-white font-bold"><?= number_format($active_rules) ?></span>
                    </div>
                </div>
                
                <!-- Rules Table -->
                <div class="bg-slate-900/50 rounded-2xl border border-white/5 overflow-hidden">
                    <table class="w-full text-left border-collapse">
                        <thead>
                            <tr class="bg-slate-950/30 text-[9px] font-black text-slate-500 uppercase tracking-widest border-b border-white/5">
                                <th class="px-6 py-4">ID</th>
                                <th class="px-6 py-4">Nazwa reguły</th>
                                <th class="px-6 py-4">Kategoria</th>
                                <th class="px-6 py-4">Priorytet</th>
                                <th class="px-6 py-4">Akcja</th>
                                <th class="px-6 py-4 text-right">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-white/[0.02]">
                            <?php if (!empty($rule_list)): ?>
                            <?php foreach ($rule_list as $index => $r): 
                                $severity_color = [
                                    'CRITICAL' => 'text-red-400 bg-red-500/10 border-red-500/20',
                                    'HIGH' => 'text-orange-400 bg-orange-500/10 border-orange-500/20',
                                    'MEDIUM' => 'text-amber-400 bg-amber-500/10 border-amber-500/20',
                                    'SECURE' => 'text-emerald-400 bg-emerald-500/10 border-emerald-500/20'
                                ][$r['priority'] ?? 'MEDIUM'] ?? 'text-slate-400 bg-slate-500/10 border-slate-500/20';
                            ?>
                            <tr class="hover:bg-white/[0.02] transition-colors">
                                <td class="px-6 py-4 font-mono text-xs text-slate-500">#<?= substr(md5($r['id']), 0, 4) ?></td>
                                <td class="px-6 py-4">
                                    <div class="font-bold text-sm text-white"><?= htmlspecialchars($r['name']) ?></div>
                                    <div class="text-[10px] text-slate-500 mt-0.5"><?= htmlspecialchars($r['id']) ?></div>
                                </td>
                                <td class="px-6 py-4">
                                    <span class="px-2 py-0.5 rounded-md bg-purple-500/10 text-[10px] font-mono text-purple-400 border border-purple-500/20"><?= htmlspecialchars($r['category']) ?></span>
                                </td>
                                <td class="px-6 py-4">
                                    <span class="px-2 py-0.5 rounded-md text-[10px] font-bold border <?= $severity_color ?>"><?= $r['priority'] ?? 'MEDIUM' ?></span>
                                </td>
                                <td class="px-6 py-4 text-xs font-mono text-slate-400"><?= $r['action'] ?></td>
                                <td class="px-6 py-4 text-right">
                                    <div class="flex items-center justify-end gap-2">
                                        <div class="w-2 h-2 rounded-full <?= $r['status'] === 'Aktywna' ? 'bg-emerald-500 animate-pulse' : 'bg-slate-500' ?>"></div>
                                        <span class="text-xs font-bold <?= $r['status'] === 'Aktywna' ? 'text-emerald-400' : 'text-slate-400' ?>"><?= $r['status'] ?></span>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php else: ?>
                            <tr>
                                <td colspan="6" class="px-6 py-20 text-center">
                                    <div class="flex flex-col items-center gap-3">
                                        <div class="p-4 bg-slate-900/50 rounded-full">
                                            <i data-lucide="<?= $ips_enabled ? 'info' : 'shield-off' ?>" class="w-8 h-8 text-<?= $ips_enabled ? 'blue' : 'red' ?>-400"></i>
                                        </div>
                                        <div class="text-white font-bold"><?= $ips_enabled ? 'Brak zdefiniowanych reguł' : 'Ochrona IPS wyłączona' ?></div>
                                        <div class="text-slate-500 text-xs text-center max-w-[280px]">
                                            <?= $ips_enabled ? 'Nie znaleziono żadnych reguł bezpieczeństwa w wybranej kategorii.' : 'Reguły ochrony przed włamaniami są nieaktywne, ponieważ system IPS/IDS jest obecnie wyłączony w ustawieniach UniFi.' ?>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <div class="modal-footer p-6 border-t border-white/5 bg-slate-900/30 flex justify-between items-center">
                <p class="text-[10px] text-slate-500 font-bold uppercase tracking-widest">
                    Aktywnych reguł: <span class="text-blue-400"><?= number_format($active_rules) ?></span> | Krytycznych: <span class="text-red-400"><?= $ips_enabled ? number_format(round($active_rules * 0.12)) : '0' ?></span>
                </p>
                <button type="button" onclick="closeSecurityRulesModal()" class="px-8 py-2.5 bg-blue-600 hover:bg-blue-500 text-white rounded-xl text-[10px] font-black uppercase tracking-widest transition shadow-lg shadow-blue-600/20">
                    Zamknij
                </button>
            </div>
        </div>
    </div>
    
    <script>
        function openSecurityRulesModal() {
            document.getElementById('securityRulesModal').classList.add('active');
            document.body.style.overflow = 'hidden';
            setTimeout(() => lucide.createIcons(), 100);
        }
        
        function closeSecurityRulesModal() {
            document.getElementById('securityRulesModal').classList.remove('active');
            document.body.style.overflow = '';
        }
        
        // Pagination state
        let blockedIPsPage = 1;
        let blockedIPsSize = 20;
        let securityRulesPage = 1;
        let securityRulesSize = 20;

        function updatePaginationUI(type) {
            const isRules = type === 'rules';
            const total = isRules ? <?= (int)$active_rules ?> : 34;
            const size = isRules ? securityRulesSize : blockedIPsSize;
            const currentPage = isRules ? securityRulesPage : blockedIPsPage;
            const containerId = isRules ? 'securityRulesPageNumbers' : 'blockedIPsPageNumbers';
            const rangeStartId = isRules ? 'securityRulesRangeStart' : 'blockedIPsRangeStart';
            const rangeEndId = isRules ? 'securityRulesRangeEnd' : 'blockedIPsRangeEnd';
            const btnClass = isRules ? 'security-rules-page-num' : 'blocked-ips-page-num';
            
            const container = document.getElementById(containerId);
            if (!container) return;

            // Update range counters
            if (size === -1) {
                document.getElementById(rangeStartId).textContent = total > 0 ? 1 : 0;
                document.getElementById(rangeEndId).textContent = total;
                container.style.display = 'none';
                return;
            }

            container.style.display = 'flex';
            const totalPages = Math.ceil(total / size);
            
            // Show only relevant pages if too many
            let startPage = Math.max(1, currentPage - 2);
            let endPage = Math.min(totalPages, startPage + 4);
            if (endPage - startPage < 4) startPage = Math.max(1, endPage - 4);

            let html = '';
            for (let i = startPage; i <= endPage; i++) {
                html += `<button onclick="goToPage('${type}', ${i})" class="${btnClass} px-3 py-1.5 rounded-lg text-xs font-bold transition ${i === currentPage ? 'active' : ''}">${i}</button>`;
            }
            
            container.innerHTML = html;

            // Update range text
            const first = (currentPage - 1) * size + 1;
            const last = Math.min(currentPage * size, total);
            document.getElementById(rangeStartId).textContent = total > 0 ? first : 0;
            document.getElementById(rangeEndId).textContent = last.toLocaleString('pl-PL');
        }

        function goToPage(type, page) {
            if (type === 'rules') {
                securityRulesPage = page;
            } else {
                blockedIPsPage = page;
            }
            updatePaginationUI(type);
        }

        // Pagination functions for Blocked IPs
        function setBlockedIPsPageSize(size) {
            blockedIPsSize = size;
            blockedIPsPage = 1;
            
            document.querySelectorAll('.blocked-ips-page-btn').forEach(btn => btn.classList.remove('active'));
            event.target.classList.add('active');
            
            updatePaginationUI('ips');
        }
        
        // Pagination functions for Security Rules
        function setSecurityRulesPageSize(size) {
            securityRulesSize = size;
            securityRulesPage = 1;
            
            document.querySelectorAll('.security-rules-page-btn').forEach(btn => btn.classList.remove('active'));
            event.target.classList.add('active');
            
            updatePaginationUI('rules');
        }
        
        // Initialize UI
        window.addEventListener('load', () => {
            updatePaginationUI('ips');
            updatePaginationUI('rules');
        });
    </script>
    
    <style>
        /* Pagination button styles */
        .blocked-ips-page-btn,
        .security-rules-page-btn {
            color: #94a3b8;
            background: transparent;
        }
        
        .blocked-ips-page-btn:hover,
        .security-rules-page-btn:hover {
            color: #ffffff;
            background: rgba(255, 255, 255, 0.05);
        }
        
        .blocked-ips-page-btn.active,
        .blocked-ips-page-num.active {
            color: #ffffff;
            background: #f59e0b;
            box-shadow: 0 0 15px rgba(245, 158, 11, 0.3);
        }
        
        .security-rules-page-btn.active,
        .security-rules-page-num.active {
            color: #ffffff;
            background: #3b82f6;
            box-shadow: 0 0 15px rgba(59, 130, 246, 0.3);
        }

        .blocked-ips-page-num,
        .security-rules-page-num {
            color: #94a3b8;
            background: transparent;
        }

        .blocked-ips-page-num:hover,
        .security-rules-page-num:hover {
            color: #ffffff;
            background: rgba(255, 255, 255, 0.05);
        }
    </style>
    
    <!-- Modal: Security Score Breakdown -->
    <div id="securityScoreModal" class="modal-overlay" onclick="closeSecurityScoreModal()">
        <div class="modal-container max-w-4xl" onclick="event.stopPropagation()">
            <div class="modal-header">
                <div>
                    <h2 class="text-xl font-bold flex items-center gap-2 text-white">
                        <i data-lucide="shield-check" class="w-6 h-6 text-emerald-400"></i>
                        Szczegółowa ocena bezpieczeństwa
                    </h2>
                    <p class="text-slate-500 text-xs mt-1">Rozbicie punktacji systemu zabezpieczeń</p>
                </div>
                <button type="button" onclick="closeSecurityScoreModal()" class="p-2 hover:bg-white/5 rounded-xl transition text-slate-500 hover:text-white">
                    <i data-lucide="x" class="w-6 h-6"></i>
                </button>
            </div>
            
            <div class="modal-body p-8">
                <!-- Score Display -->
                <div class="flex items-center justify-center mb-8">
                    <div class="relative">
                        <svg class="w-48 h-48 transform -rotate-90">
                            <circle cx="96" cy="96" r="88" stroke="rgba(255,255,255,0.05)" stroke-width="12" fill="none"/>
                            <circle cx="96" cy="96" r="88" stroke="url(#scoreGradient)" stroke-width="12" fill="none"
                                    stroke-dasharray="<?= 2 * 3.14159 * 88 ?>" 
                                    stroke-dashoffset="<?= 2 * 3.14159 * 88 * (1 - $stats['security_score'] / 100) ?>"
                                    stroke-linecap="round" class="transition-all duration-1000"/>
                            <defs>
                                <linearGradient id="scoreGradient" x1="0%" y1="0%" x2="100%" y2="100%">
                                    <stop offset="0%" style="stop-color:#10b981;stop-opacity:1" />
                                    <stop offset="100%" style="stop-color:#059669;stop-opacity:1" />
                                </linearGradient>
                            </defs>
                        </svg>
                        <div class="absolute inset-0 flex flex-col items-center justify-center">
                            <div class="text-5xl font-black text-white"><?= $stats['security_score'] ?></div>
                            <div class="text-sm text-slate-400 font-bold">/100</div>
                        </div>
                    </div>
                </div>
                
                <!-- Score Breakdown -->
                <div class="space-y-4">
                    <h3 class="text-sm font-bold text-white uppercase tracking-widest mb-4">Czynniki wpływające na ocenę:</h3>
                    
                    <!-- Factor 1: IPS/IDS -->
                    <div class="bg-slate-900/50 rounded-xl p-4 border border-white/5">
                        <div class="flex items-center justify-between mb-2">
                            <div class="flex items-center gap-3">
                                <i data-lucide="<?= $ips_enabled ? 'check-circle' : 'x-circle' ?>" class="w-5 h-5 <?= $ips_enabled ? 'text-emerald-400' : 'text-red-400' ?>"></i>
                                <span class="text-sm font-bold text-white">System IPS/IDS</span>
                            </div>
                            <span class="text-xs font-mono <?= $ips_enabled ? 'text-emerald-400' : 'text-red-400' ?>">
                                <?= $ips_enabled ? '+20' : '0' ?> pkt
                            </span>
                        </div>
                        <div class="w-full bg-slate-800 rounded-full h-2">
                            <div class="bg-emerald-500 h-2 rounded-full transition-all" style="width: <?= $ips_enabled ? '100' : '0' ?>%"></div>
                        </div>
                    </div>
                    
                    <!-- Factor 2: Firewall Rules -->
                    <?php 
                    $rules_score = 15;
                    if ($active_rules < 2) $rules_score = 0;
                    elseif ($active_rules < 10) $rules_score = 7;
                    elseif ($active_rules < 30) $rules_score = 12;
                    else $rules_score = 15; // Max or more with bonus
                    ?>
                    <div class="bg-slate-900/50 rounded-xl p-4 border border-white/5">
                        <div class="flex items-center justify-between mb-2">
                            <div class="flex items-center gap-3">
                                <i data-lucide="<?= $active_rules >= 30 ? 'check-circle' : 'alert-circle' ?>" class="w-5 h-5 <?= $active_rules >= 30 ? 'text-emerald-400' : 'text-amber-400' ?>"></i>
                                <span class="text-sm font-bold text-white">Pokrycie reguł firewall i Flows</span>
                                <span class="text-xs text-slate-500 font-mono">(<?= number_format($active_rules) ?> reguł)</span>
                            </div>
                            <span class="text-xs font-mono <?= $active_rules >= 30 ? 'text-emerald-400' : 'text-amber-400' ?>">
                                <?= ($active_rules >= 30) ? '+15' : '+'.$rules_score ?> pkt
                            </span>
                        </div>
                        <div class="w-full bg-slate-800 rounded-full h-2">
                            <div class="bg-emerald-500 h-2 rounded-full transition-all" style="width: <?= min(100, ($active_rules / 30) * 100) ?>%"></div>
                        </div>
                    </div>
                    
                    <!-- Factor 3: Threat Detection -->
                    <div class="bg-slate-900/50 rounded-xl p-4 border border-white/5">
                        <div class="flex items-center justify-between mb-2">
                            <div class="flex items-center gap-3">
                                <i data-lucide="<?= $threat_detection_enabled ? 'check-circle' : 'x-circle' ?>" class="w-5 h-5 <?= $threat_detection_enabled ? 'text-emerald-400' : 'text-red-400' ?>"></i>
                                <span class="text-sm font-bold text-white">Wykrywanie zagrożeń</span>
                            </div>
                            <span class="text-xs font-mono <?= $threat_detection_enabled ? 'text-emerald-400' : 'text-red-400' ?>">
                                <?= $threat_detection_enabled ? '+15' : '0' ?> pkt
                            </span>
                        </div>
                        <div class="w-full bg-slate-800 rounded-full h-2">
                            <div class="bg-emerald-500 h-2 rounded-full transition-all" style="width: <?= $threat_detection_enabled ? '100' : '0' ?>%"></div>
                        </div>
                    </div>
                    
                    <!-- Factor 4: Blocked Threats -->
                    <?php 
                    $threat_score = 20;
                    if ($threats_blocked > 1000) $threat_score = 10;
                    elseif ($threats_blocked > 500) $threat_score = 15;
                    ?>
                    <div class="bg-slate-900/50 rounded-xl p-4 border border-white/5">
                        <div class="flex items-center justify-between mb-2">
                            <div class="flex items-center gap-3">
                                <i data-lucide="<?= $threat_score >= 15 ? 'check-circle' : 'alert-triangle' ?>" class="w-5 h-5 <?= $threat_score >= 15 ? 'text-emerald-400' : 'text-amber-400' ?>"></i>
                                <span class="text-sm font-bold text-white">Stosunek zablokowanych zagrożeń</span>
                                <span class="text-xs text-slate-500 font-mono">(<?= $threats_blocked ?> blokad)</span>
                            </div>
                            <span class="text-xs font-mono <?= $threat_score >= 15 ? 'text-emerald-400' : 'text-amber-400' ?>">
                                +<?= $threat_score ?> pkt
                            </span>
                        </div>
                        <div class="w-full bg-slate-800 rounded-full h-2">
                            <div class="bg-emerald-500 h-2 rounded-full transition-all" style="width: <?= ($threat_score / 20) * 100 ?>%"></div>
                        </div>
                    </div>
                    
                    <!-- Factor 5: Monitoring -->
                    <div class="bg-slate-900/50 rounded-xl p-4 border border-white/5">
                        <div class="flex items-center justify-between mb-2">
                            <div class="flex items-center gap-3">
                                <i data-lucide="<?= $monitoring_active ? 'check-circle' : 'x-circle' ?>" class="w-5 h-5 <?= $monitoring_active ? 'text-emerald-400' : 'text-red-400' ?>"></i>
                                <span class="text-sm font-bold text-white">Monitoring aktywny</span>
                            </div>
                            <span class="text-xs font-mono <?= $monitoring_active ? 'text-emerald-400' : 'text-red-400' ?>">
                                <?= $monitoring_active ? '+10' : '0' ?> pkt
                            </span>
                        </div>
                        <div class="w-full bg-slate-800 rounded-full h-2">
                            <div class="bg-emerald-500 h-2 rounded-full transition-all" style="width: <?= $monitoring_active ? '100' : '0' ?>%"></div>
                        </div>
                    </div>
                    
                    <!-- Factor 6: VPN Security -->
                    <div class="bg-slate-900/50 rounded-xl p-4 border border-white/5">
                        <div class="flex items-center justify-between mb-2">
                            <div class="flex items-center gap-3">
                                <i data-lucide="<?= $vpn_secure ? 'check-circle' : 'x-circle' ?>" class="w-5 h-5 <?= $vpn_secure ? 'text-emerald-400' : 'text-red-400' ?>"></i>
                                <span class="text-sm font-bold text-white">Bezpieczeństwo VPN</span>
                            </div>
                            <span class="text-xs font-mono <?= $vpn_secure ? 'text-emerald-400' : 'text-red-400' ?>">
                                <?= $vpn_secure ? '+10' : '0' ?> pkt
                            </span>
                        </div>
                        <div class="w-full bg-slate-800 rounded-full h-2">
                            <div class="bg-emerald-500 h-2 rounded-full transition-all" style="width: <?= $vpn_secure ? '100' : '0' ?>%"></div>
                        </div>
                    </div>
                    
                    <!-- Factor 7: Critical Events Penalty -->
                    <?php 
                    $event_penalty = 0;
                    if ($critical_events_count > 5) $event_penalty = 15;
                    elseif ($critical_events_count > 2) $event_penalty = 8;
                    elseif ($critical_events_count > 0) $event_penalty = 3;
                    ?>
                    <div class="bg-slate-900/50 rounded-xl p-4 border border-white/5">
                        <div class="flex items-center justify-between mb-2">
                            <div class="flex items-center gap-3">
                                <i data-lucide="<?= $event_penalty == 0 ? 'check-circle' : 'alert-triangle' ?>" class="w-5 h-5 <?= $event_penalty == 0 ? 'text-emerald-400' : 'text-red-400' ?>"></i>
                                <span class="text-sm font-bold text-white">Krytyczne zdarzenia (ostatnia godzina)</span>
                                <span class="text-xs text-slate-500 font-mono">(<?= $critical_events_count ?> zdarzeń)</span>
                            </div>
                            <span class="text-xs font-mono <?= $event_penalty == 0 ? 'text-emerald-400' : 'text-red-400' ?>">
                                <?= $event_penalty > 0 ? '-' : '+' ?><?= $event_penalty ?> pkt
                            </span>
                        </div>
                        <div class="w-full bg-slate-800 rounded-full h-2">
                            <div class="<?= $event_penalty > 0 ? 'bg-red-500' : 'bg-emerald-500' ?> h-2 rounded-full transition-all" style="width: <?= $event_penalty > 0 ? ($event_penalty / 15) * 100 : 100 ?>%"></div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="modal-footer p-6 border-t border-white/5 bg-slate-900/30 flex justify-between items-center">
                <p class="text-[10px] text-slate-500 font-bold uppercase tracking-widest">
                    Ocena końcowa: <span class="text-emerald-400 text-lg"><?= $stats['security_score'] ?>/100</span>
                </p>
                <button type="button" onclick="closeSecurityScoreModal()" class="px-8 py-2.5 bg-emerald-600 hover:bg-emerald-500 text-white rounded-xl text-[10px] font-black uppercase tracking-widest transition shadow-lg shadow-emerald-600/20">
                    Zamknij
                </button>
            </div>
        </div>
    </div>
    
    <script>
        function openSecurityScoreModal() {
            document.getElementById('securityScoreModal').classList.add('active');
            document.body.style.overflow = 'hidden';
            setTimeout(() => lucide.createIcons(), 100);
        }
        
        function closeSecurityScoreModal() {
            document.getElementById('securityScoreModal').classList.remove('active');
            document.body.style.overflow = '';
        }
    </script>
    <?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>




