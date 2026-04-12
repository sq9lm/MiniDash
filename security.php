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
session_write_close();

$navbar_stats = get_navbar_stats();

// Fetch settings (cached 5min) and threat events (cached 120s) in one go
$security_settings = get_unifi_security_settings();
$threat_result = fetch_threat_events('24h');
$threat_events = $threat_result['events'] ?? [];
$threat_source = $threat_result['source'] ?? 'unknown';

// Filter ignored IPs
$ignore_ips = [];
if (isset($db)) {
    try {
        $stmt = $db->query("SELECT ip FROM threat_ignore");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) $ignore_ips[] = $row['ip'];
    } catch (Exception $e) {}
}
if (!empty($ignore_ips)) {
    $threat_events = array_values(array_filter($threat_events, fn($ev) => !in_array($ev['src_ip'], $ignore_ips)));
}

$t_total = count($threat_events);
$t_blocked = count(array_filter($threat_events, fn($e) => $e['action'] === 'blocked'));
$t_alerts = $t_total - $t_blocked;
$t_high = count(array_filter($threat_events, fn($e) => $e['risk'] === 'high'));
if (empty($security_settings) || !is_array($security_settings)) {
    $security_settings = [
        'ips_enabled' => false, 'ips_mode' => 'disabled',
        'threat_detection_enabled' => false, 'geoblocking_enabled' => false,
        'threats_count' => 0, 'total_rules_count' => 0,
        'monitoring_active' => false, 'rule_list' => [],
        'ad_blocking_enabled' => false, 'honeypot_enabled' => false,
    ];
}

$ips_enabled = $security_settings['ips_enabled'] ?? false;
$ips_mode = $security_settings['ips_mode'] ?? 'disabled';
$threat_detection_enabled = $security_settings['threat_detection_enabled'] ?? false;
$geoblocking_enabled = $security_settings['geoblocking_enabled'] ?? false;
$active_rules = $security_settings['total_rules_count'] ?? 0;
$threats_blocked = $security_settings['threats_count'] ?? 0;
$vpn_secure = $security_settings['vpn_secure'] ?? false;
$monitoring_active = $security_settings['monitoring_active'] ?? false;
$ad_blocking = $security_settings['ad_blocking_enabled'] ?? false;
$honeypot = $security_settings['honeypot_enabled'] ?? false;

$ips_mode_labels = [
    'disabled'  => __('threats.mode_disabled'),
    'ids'       => __('threats.mode_ids'),
    'ips'       => __('threats.mode_ips'),
    'ipsInline' => __('threats.mode_ips_inline'),
];
$ips_mode_label = $ips_mode_labels[$ips_mode] ?? $ips_mode;

// Security score
$security_score = 100;
if (!$ips_enabled) $security_score -= 20;
if ($active_rules < 2) $security_score -= 15;
elseif ($active_rules < 10) $security_score -= 8;
if (!$threat_detection_enabled) $security_score -= 15;
if (!$monitoring_active) $security_score -= 10;
if (!$ad_blocking && !$honeypot) $security_score -= 10;
elseif ($ad_blocking && $honeypot) $security_score = min(100, $security_score + 5);
$security_score = max(0, min(100, $security_score));

$stats = [
    'threats_blocked' => $threats_blocked,
    'active_rules' => $active_rules,
    'security_score' => $security_score,
];

$rule_list = $security_settings['rule_list'] ?? [];
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= __('security.monitoring_title') ?> | MiniDash</title>
    <link rel="icon" type="image/png" href="img/favicon.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="assets/css/fonts.css">
    <link rel="stylesheet" href="dashboard.css">
    <script src="assets/js/lucide.min.js"></script>
</head>
<body class="custom-scrollbar">
    <?php render_nav("UniFi Security", $navbar_stats); ?>

    <div class="max-w-7xl mx-auto p-4 md:p-8">
        <!-- Page Header -->
        <div class="mb-8">
            <div class="flex items-center justify-between flex-wrap gap-4">
                <div>
                    <h1 class="text-3xl font-black text-white mb-2 flex items-center gap-3">
                        <i data-lucide="shield" class="w-8 h-8 text-rose-400"></i>
                        <?= __('security.title') ?>
                    </h1>
                    <div class="flex items-center gap-2 mb-2">
                        <span class="px-2 py-0.5 rounded-md text-[11px] font-black uppercase tracking-widest border transition-all <?= $ips_enabled ? 'bg-emerald-500/10 text-emerald-400 border-emerald-500/20' : 'bg-slate-800 text-slate-500 border-white/5 opacity-50' ?>">
                            <i data-lucide="shield-check" class="w-3 h-3 inline mr-1"></i> <?= htmlspecialchars($ips_mode_label) ?>
                        </span>
                        <span class="px-2 py-0.5 rounded-md text-[11px] font-black uppercase tracking-widest border transition-all <?= $honeypot ? 'bg-amber-500/10 text-amber-400 border-amber-500/20' : 'bg-slate-800 text-slate-500 border-white/5 opacity-50' ?>">
                            <i data-lucide="ghost" class="w-3 h-3 inline mr-1"></i> Honeypot: <?= $honeypot ? __('security.honeypot_active') : __('security.ips_disabled') ?>
                        </span>
                        <span class="px-2 py-0.5 rounded-md text-[11px] font-black uppercase tracking-widest border transition-all <?= $ad_blocking ? 'bg-blue-500/10 text-blue-400 border-blue-500/20' : 'bg-slate-800 text-slate-500 border-white/5 opacity-50' ?>">
                            <i data-lucide="ban" class="w-3 h-3 inline mr-1"></i> Ad-block: <?= $ad_blocking ? __('security.ad_block_active') : __('security.ips_disabled') ?>
                        </span>
                    </div>
                    <p class="text-slate-500 text-sm italic"><?= __('security.subtitle') ?></p>
                </div>
                <div class="flex items-center gap-3">
                    <button onclick="openIgnoreModal()" class="px-4 py-2 bg-amber-600/20 hover:bg-amber-600/30 text-amber-400 rounded-xl text-xs font-bold transition border border-amber-500/20">
                        <i data-lucide="shield-off" class="w-4 h-4 inline mr-1"></i> <?= __('security.ignored_ip') ?>
                    </button>
                </div>
            </div>
        </div>

        <!-- Tab Switcher -->
        <div class="flex gap-2 mb-8 p-1 bg-slate-900/50 rounded-2xl border border-white/5 w-fit">
            <button onclick="switchTab('overview')" id="tab-btn-overview" class="tab-btn px-6 py-2.5 rounded-xl text-sm font-bold transition flex items-center gap-2 bg-white/10 text-white">
                <i data-lucide="shield" class="w-4 h-4"></i> <?= __('security.tab_overview') ?>
            </button>
            <button onclick="switchTab('threats')" id="tab-btn-threats" class="tab-btn px-6 py-2.5 rounded-xl text-sm font-bold transition flex items-center gap-2 text-slate-400 hover:text-white hover:bg-white/5">
                <i data-lucide="scan-eye" class="w-4 h-4"></i> <?= __('security.tab_threats') ?>
            </button>
        </div>

        <!-- ==================== TAB: OVERVIEW ==================== -->
        <div id="tab-overview">
            <!-- Stats Grid -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 mb-8">
                <div class="glass-card p-5 stat-glow-rose">
                    <div class="flex justify-between items-center mb-4">
                        <div class="p-2.5 bg-rose-500/10 rounded-xl text-rose-400">
                            <i data-lucide="shield-x" class="w-5 h-5"></i>
                        </div>
                        <span class="text-xs font-black text-slate-500 uppercase tracking-widest">24h</span>
                    </div>
                    <div class="text-3xl font-black tracking-tighter"><?= $stats['threats_blocked'] ?></div>
                    <div class="text-slate-400 text-xs mt-1 font-medium italic"><?= __('security.threats_blocked') ?></div>
                </div>

                <div class="glass-card p-5 stat-glow-blue cursor-pointer hover:scale-[1.02] transition-transform" onclick="openSecurityRulesModal()">
                    <div class="flex justify-between items-center mb-4">
                        <div class="p-2.5 bg-blue-500/10 rounded-xl text-blue-400">
                            <i data-lucide="list-checks" class="w-5 h-5"></i>
                        </div>
                        <span class="text-xs font-black text-slate-500 uppercase tracking-widest"><?= __('common.active') ?></span>
                    </div>
                    <div class="text-3xl font-black tracking-tighter"><?= number_format($stats['active_rules']) ?></div>
                    <div class="flex items-center justify-between mt-2">
                        <div class="text-slate-400 text-xs font-medium italic"><?= __('security.security_rules') ?></div>
                        <button class="text-xs font-black text-blue-400 uppercase tracking-widest hover:text-blue-300 transition flex items-center gap-1">
                            <?= __('common.details') ?> <i data-lucide="chevron-right" class="w-3 h-3"></i>
                        </button>
                    </div>
                </div>

                <div class="glass-card p-6 stat-glow-emerald cursor-pointer hover:scale-[1.02] transition-transform relative overflow-hidden flex flex-col items-center justify-center min-h-[220px]" onclick="openSecurityScoreModal()">
                    <div class="relative w-32 h-32 flex items-center justify-center mb-4">
                        <svg class="w-full h-full -rotate-90">
                            <circle cx="64" cy="64" r="58" stroke="currentColor" stroke-width="8" fill="transparent" class="text-white/5"></circle>
                            <circle cx="64" cy="64" r="58" stroke="currentColor" stroke-width="10" fill="transparent" class="<?= $security_score >= 80 ? 'text-emerald-500' : 'text-rose-500' ?> transition-all duration-1000 ease-out" stroke-dasharray="364.4" stroke-dashoffset="<?= 364.4 - (364.4 * $security_score / 100) ?>" stroke-linecap="round"></circle>
                        </svg>
                        <div class="absolute inset-0 flex flex-col items-center justify-center">
                            <span class="text-4xl font-black text-white leading-none"><?= $security_score ?></span>
                            <span class="text-[11px] font-black text-slate-500 uppercase tracking-widest mt-1">Score</span>
                        </div>
                    </div>
                    <div class="text-center">
                        <p class="text-[12px] font-black <?= $security_score >= 80 ? 'text-emerald-400' : 'text-rose-400' ?> uppercase tracking-widest mb-1"><?= __('security.security_score') ?></p>
                        <p class="text-slate-500 text-[12px] font-medium italic"><?= __('security.click_for_details') ?></p>
                    </div>
                </div>
            </div>

            <!-- Protection Info -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                <!-- Protection Pillars -->
                <div class="glass-card p-6 overflow-hidden relative">
                    <div class="absolute -right-8 -top-8 w-32 h-32 bg-blue-500/5 blur-3xl rounded-full"></div>
                    <h3 class="text-sm font-black text-slate-500 uppercase tracking-widest mb-6"><?= __('security.protection_pillars') ?></h3>
                    <div class="space-y-4">
                        <div class="flex items-start gap-4 p-3 rounded-2xl bg-white/5 border border-white/5">
                            <div class="p-2 bg-emerald-500/10 rounded-xl text-emerald-400">
                                <i data-lucide="check-circle-2" class="w-4 h-4"></i>
                            </div>
                            <div>
                                <p class="text-xs font-bold text-white mb-0.5"><?= __('security.auto_blocking') ?></p>
                                <p class="text-[11px] text-slate-500 italic"><?= __('security.auto_blocking_desc') ?></p>
                            </div>
                        </div>
                        <div class="flex items-start gap-4 p-3 rounded-2xl bg-white/5 border border-white/5">
                            <div class="p-2 bg-blue-500/10 rounded-xl text-blue-400">
                                <i data-lucide="zap" class="w-4 h-4"></i>
                            </div>
                            <div>
                                <p class="text-xs font-bold text-white mb-0.5"><?= __('security.dpi_analysis') ?></p>
                                <p class="text-[11px] text-slate-500 italic"><?= __('security.dpi_analysis_desc') ?></p>
                            </div>
                        </div>
                        <div class="flex items-start gap-4 p-3 rounded-2xl bg-white/5 border border-white/5">
                            <div class="p-2 bg-purple-500/10 rounded-xl text-purple-400">
                                <i data-lucide="globe" class="w-4 h-4"></i>
                            </div>
                            <div>
                                <p class="text-xs font-bold text-white mb-0.5"><?= __('security.geo_blocking') ?></p>
                                <p class="text-[11px] text-slate-500 italic"><?= __('security.geo_blocking_desc') ?><?php if (!empty($security_settings['blocked_countries'])): ?> (<?= count($security_settings['blocked_countries']) ?> <?= __('security.geo_blocking_countries') ?>)<?php endif; ?></p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- IPS/Threat Intel Config -->
                <div class="glass-card p-6">
                    <h3 class="text-sm font-black text-slate-500 uppercase tracking-widest mb-6"><?= __('security.threat_intel') ?></h3>
                    <div class="grid grid-cols-2 gap-4">
                        <div class="bg-slate-800/50 border border-white/5 rounded-2xl p-5">
                            <div class="flex items-center gap-3 mb-2">
                                <i data-lucide="shield-alert" class="w-5 h-5 text-blue-400"></i>
                                <span class="text-xs font-black text-slate-500 uppercase tracking-widest"><?= __('threats.protocol') ?> IPS</span>
                            </div>
                            <p class="text-lg font-bold text-white capitalize"><?= htmlspecialchars($ips_mode) ?></p>
                        </div>
                        <div class="bg-slate-800/50 border border-white/5 rounded-2xl p-5">
                            <div class="flex items-center gap-3 mb-2">
                                <i data-lucide="ban" class="w-5 h-5 text-blue-400"></i>
                                <span class="text-xs font-black text-slate-500 uppercase tracking-widest">Ad Blocking</span>
                            </div>
                            <span class="px-2 py-0.5 rounded text-sm font-bold uppercase <?= $ad_blocking ? 'bg-emerald-500/20 text-emerald-400' : 'bg-slate-700 text-slate-400' ?>">
                                <?= $ad_blocking ? __('common.enabled') : __('common.disabled') ?>
                            </span>
                        </div>
                        <div class="bg-slate-800/50 border border-white/5 rounded-2xl p-5">
                            <div class="flex items-center gap-3 mb-2">
                                <i data-lucide="ghost" class="w-5 h-5 text-amber-400"></i>
                                <span class="text-xs font-black text-slate-500 uppercase tracking-widest">Honeypot</span>
                            </div>
                            <span class="px-2 py-0.5 rounded text-sm font-bold uppercase <?= $honeypot ? 'bg-emerald-500/20 text-emerald-400' : 'bg-slate-700 text-slate-400' ?>">
                                <?= $honeypot ? __('security.honeypot_active') : __('common.disabled') ?>
                            </span>
                        </div>
                        <div class="bg-slate-800/50 border border-white/5 rounded-2xl p-5">
                            <div class="flex items-center gap-3 mb-2">
                                <i data-lucide="globe-2" class="w-5 h-5 text-purple-400"></i>
                                <span class="text-xs font-black text-slate-500 uppercase tracking-widest">Geo-block</span>
                            </div>
                            <span class="px-2 py-0.5 rounded text-sm font-bold uppercase <?= $geoblocking_enabled ? 'bg-emerald-500/20 text-emerald-400' : 'bg-slate-700 text-slate-400' ?>">
                                <?= $geoblocking_enabled ? __('common.enabled') : __('common.disabled') ?>
                            </span>
                        </div>
                    </div>
                    <?php if (!empty($security_settings['geo_rules']) || !empty($security_settings['blocked_countries'])): ?>
                    <button onclick="openGeoBlockModal()" class="mt-4 w-full px-4 py-2 bg-purple-600/20 hover:bg-purple-600/30 text-purple-400 rounded-xl text-xs font-bold transition border border-purple-500/20">
                        <i data-lucide="globe" class="w-4 h-4 inline mr-1"></i> <?= __('security.geo_blocking') ?> — <?= __('common.details') ?>
                    </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- ==================== TAB: THREATS (lazy loaded) ==================== -->
        <div id="tab-threats" class="hidden">
            <!-- Stats Grid -->
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-8">
                <div class="glass-card p-5 stat-glow-orange">
                    <div class="flex justify-between items-center mb-4">
                        <div class="p-2.5 bg-orange-500/10 rounded-xl text-orange-400"><i data-lucide="scan-eye" class="w-5 h-5"></i></div>
                        <span class="text-xs font-black text-slate-500 uppercase tracking-widest" id="stat-range">24H</span>
                    </div>
                    <div class="text-3xl font-black tracking-tighter" id="stat-total"><?= $t_total ?></div>
                    <div class="text-slate-400 text-xs mt-1 font-medium italic"><?= __('threats.total_events') ?></div>
                </div>
                <div class="glass-card p-5 stat-glow-rose">
                    <div class="flex justify-between items-center mb-4">
                        <div class="p-2.5 bg-rose-500/10 rounded-xl text-rose-400"><i data-lucide="shield-x" class="w-5 h-5"></i></div>
                        <span class="text-xs font-black text-slate-500 uppercase tracking-widest"><?= __('threats.blocked_label') ?></span>
                    </div>
                    <div class="text-3xl font-black tracking-tighter" id="stat-blocked"><?= $t_blocked ?></div>
                    <div class="text-slate-400 text-xs mt-1 font-medium italic"><?= __('threats.blocked_desc') ?></div>
                </div>
                <div class="glass-card p-5 stat-glow-amber">
                    <div class="flex justify-between items-center mb-4">
                        <div class="p-2.5 bg-amber-500/10 rounded-xl text-amber-400"><i data-lucide="alert-triangle" class="w-5 h-5"></i></div>
                        <span class="text-xs font-black text-slate-500 uppercase tracking-widest"><?= __('threats.alerts_label') ?></span>
                    </div>
                    <div class="text-3xl font-black tracking-tighter" id="stat-alerts"><?= $t_alerts ?></div>
                    <div class="text-slate-400 text-xs mt-1 font-medium italic"><?= __('threats.alerts_desc') ?></div>
                </div>
                <div class="glass-card p-5 stat-glow-red">
                    <div class="flex justify-between items-center mb-4">
                        <div class="p-2.5 bg-red-500/10 rounded-xl text-red-400"><i data-lucide="flame" class="w-5 h-5"></i></div>
                        <span class="text-xs font-black text-slate-500 uppercase tracking-widest"><?= __('threats.critical_label') ?></span>
                    </div>
                    <div class="text-3xl font-black tracking-tighter" id="stat-high"><?= $t_high ?></div>
                    <div class="text-slate-400 text-xs mt-1 font-medium italic"><?= __('threats.high_risk') ?></div>
                </div>
            </div>

            <!-- Filters -->
            <div class="flex flex-wrap items-center gap-3 mb-6 p-3 bg-slate-900/50 rounded-2xl border border-white/5">
                <span class="text-[12px] font-black text-slate-500 uppercase tracking-widest mr-1"><?= __('threats.time_range') ?></span>
                <button onclick="setRange('1h')" class="range-btn px-4 py-2 rounded-xl text-xs font-bold transition border border-white/10 bg-white/5 text-slate-400" data-range="1h">1H</button>
                <button onclick="setRange('24h')" class="range-btn px-4 py-2 rounded-xl text-xs font-bold transition border border-blue-500/50 bg-blue-600 text-white shadow-lg shadow-blue-600/20" data-range="24h">24H</button>
                <button onclick="setRange('7d')" class="range-btn px-4 py-2 rounded-xl text-xs font-bold transition border border-white/10 bg-white/5 text-slate-400" data-range="7d">7D</button>
                <div class="h-6 w-px bg-white/10 mx-2 hidden sm:block"></div>
                <span class="text-[12px] font-black text-slate-500 uppercase tracking-widest mr-1"><?= __('threats.filter_risk') ?></span>
                <button onclick="toggleFilter('risk','high')" class="filter-btn px-3 py-1.5 rounded-lg text-[11px] font-bold transition border border-white/10 bg-white/5 text-red-400" data-filter="risk" data-value="high">High</button>
                <button onclick="toggleFilter('risk','medium')" class="filter-btn px-3 py-1.5 rounded-lg text-[11px] font-bold transition border border-white/10 bg-white/5 text-amber-400" data-filter="risk" data-value="medium">Medium</button>
                <button onclick="toggleFilter('risk','low')" class="filter-btn px-3 py-1.5 rounded-lg text-[11px] font-bold transition border border-white/10 bg-white/5 text-blue-400" data-filter="risk" data-value="low">Low</button>
                <div class="h-6 w-px bg-white/10 mx-2 hidden sm:block"></div>
                <button onclick="toggleFilter('action','blocked')" class="filter-btn px-3 py-1.5 rounded-lg text-[11px] font-bold transition border border-white/10 bg-white/5 text-rose-400" data-filter="action" data-value="blocked"><?= __('threats.action_blocked') ?></button>
                <button onclick="toggleFilter('action','alert')" class="filter-btn px-3 py-1.5 rounded-lg text-[11px] font-bold transition border border-white/10 bg-white/5 text-amber-400" data-filter="action" data-value="alert"><?= __('threats.action_alert') ?></button>
                <div class="ml-auto flex items-center gap-3">
                    <button onclick="exportThreats()" class="px-3 py-1.5 bg-white/5 hover:bg-white/10 text-slate-400 hover:text-white rounded-lg text-[11px] font-bold transition border border-white/10">
                        <i data-lucide="download" class="w-3.5 h-3.5 inline mr-1"></i> CSV
                    </button>
                    <div class="relative">
                        <i data-lucide="search" class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-500"></i>
                        <input type="text" id="search-input" placeholder="<?= __('threats.search_placeholder') ?>" oninput="currentPage=1; applyFilters()"
                               class="pl-10 pr-4 py-2 bg-slate-900/50 border border-white/10 rounded-xl text-sm text-white placeholder-slate-500 focus:outline-none focus:border-orange-500/50 transition w-48">
                    </div>
                </div>
            </div>

            <!-- Events + Sidebar -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                <div class="lg:col-span-2">
                    <div class="glass-card p-6">
                        <div class="flex items-center justify-between mb-4">
                            <h2 class="text-lg font-bold text-white flex items-center gap-2">
                                <i data-lucide="list" class="w-5 h-5 text-orange-400"></i> <?= __('threats.events_title') ?>
                            </h2>
                            <div class="flex items-center gap-2">
                                <span class="text-[11px] font-mono text-slate-500" id="events-count">— <?= __('threats.events_suffix') ?></span>
                                <div id="loading-spinner" class="hidden"><i data-lucide="loader-2" class="w-4 h-4 text-orange-400 animate-spin"></i></div>
                            </div>
                        </div>
                        <div id="events-container" class="space-y-2"></div>
                        <!-- Pagination -->
                        <div id="pagination-bar" class="flex-wrap items-center justify-between mt-4 pt-4 border-t border-white/5" style="display:none">
                            <div class="flex items-center gap-2">
                                <span class="text-[11px] font-black text-slate-500 uppercase tracking-widest"><?= __('common.show_count') ?></span>
                                <div class="flex gap-1 bg-slate-900/50 p-1 rounded-xl border border-white/5">
                                    <button onclick="setPageSize(25)" class="page-size-btn px-3 py-1.5 rounded-lg text-xs font-bold transition active">25</button>
                                    <button onclick="setPageSize(50)" class="page-size-btn px-3 py-1.5 rounded-lg text-xs font-bold transition">50</button>
                                    <button onclick="setPageSize(100)" class="page-size-btn px-3 py-1.5 rounded-lg text-xs font-bold transition">100</button>
                                </div>
                            </div>
                            <div class="flex items-center gap-2">
                                <button onclick="prevPage()" id="btn-prev" class="p-2 rounded-lg bg-white/5 hover:bg-white/10 text-slate-400 hover:text-white transition border border-white/10 disabled:opacity-30 disabled:cursor-not-allowed">
                                    <i data-lucide="chevron-left" class="w-4 h-4"></i>
                                </button>
                                <span class="text-xs font-mono text-slate-400">
                                    <span id="page-start" class="text-orange-400 font-bold">1</span>–<span id="page-end" class="text-orange-400 font-bold">25</span>
                                    <?= __('common.of') ?>
                                    <span id="page-total" class="text-white font-bold">0</span>
                                </span>
                                <button onclick="nextPage()" id="btn-next" class="p-2 rounded-lg bg-white/5 hover:bg-white/10 text-slate-400 hover:text-white transition border border-white/10 disabled:opacity-30 disabled:cursor-not-allowed">
                                    <i data-lucide="chevron-right" class="w-4 h-4"></i>
                                </button>
                            </div>
                        </div>
                        <div id="events-empty" class="hidden px-6 py-12 text-center bg-slate-900/40 rounded-3xl border border-white/5">
                            <div class="p-4 bg-emerald-500/10 text-emerald-500 rounded-full w-fit mx-auto mb-4"><i data-lucide="shield-check" class="w-8 h-8"></i></div>
                            <p class="text-white font-bold"><?= __('threats.no_threats') ?></p>
                            <p class="text-slate-500 text-xs mt-1"><?= __('threats.no_threats_desc') ?></p>
                        </div>
                    </div>
                </div>
                <div class="space-y-6">
                    <div class="glass-card p-6 border-orange-500/10">
                        <h3 class="text-sm font-black text-slate-500 uppercase tracking-widest mb-6 flex items-center justify-between">
                            <?= __('threats.top_countries') ?> <i data-lucide="globe" class="w-4 h-4 text-orange-400"></i>
                        </h3>
                        <div id="sidebar-countries" class="space-y-3">
                            <p class="text-[12px] text-slate-600 italic text-center py-4"><?= __('common.loading') ?></p>
                        </div>
                    </div>
                    <div class="glass-card p-6">
                        <h3 class="text-sm font-black text-slate-500 uppercase tracking-widest mb-6 flex items-center justify-between">
                            <?= __('threats.risk_breakdown') ?> <i data-lucide="bar-chart-3" class="w-4 h-4 text-orange-400"></i>
                        </h3>
                        <div id="sidebar-risk" class="space-y-4">
                            <p class="text-[12px] text-slate-600 italic text-center py-4"><?= __('common.loading') ?></p>
                        </div>
                    </div>
                    <div class="glass-card p-6">
                        <h3 class="text-sm font-black text-slate-500 uppercase tracking-widest mb-6 flex items-center justify-between">
                            <?= __('threats.top_categories') ?> <i data-lucide="tag" class="w-4 h-4 text-orange-400"></i>
                        </h3>
                        <div id="sidebar-categories" class="space-y-3">
                            <p class="text-[12px] text-slate-600 italic text-center py-4"><?= __('common.loading') ?></p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php include 'includes/confirm_modal.php'; ?>

    <!-- Event Detail Modal -->
    <div id="eventDetailModal" class="modal-overlay" onclick="if(event.target===this) closeEventDetail()">
        <div class="modal-container max-w-2xl" onclick="event.stopPropagation()">
            <div class="modal-header">
                <div><h2 class="text-xl font-bold text-white flex items-center gap-2"><i data-lucide="scan-eye" class="w-6 h-6 text-orange-400"></i> <?= __('threats.event_detail') ?></h2></div>
                <button type="button" onclick="closeEventDetail()" class="p-2 hover:bg-white/5 rounded-xl transition text-slate-500 hover:text-white"><i data-lucide="x" class="w-6 h-6"></i></button>
            </div>
            <div class="modal-body p-6" id="event-detail-body"></div>
        </div>
    </div>

    <!-- Security Score Modal -->
    <div id="securityScoreModal" class="modal-overlay" onclick="if(event.target===this) closeSecurityScoreModal()">
        <div class="modal-container max-w-4xl" onclick="event.stopPropagation()">
            <div class="modal-header">
                <div>
                    <h2 class="text-xl font-bold flex items-center gap-2 text-white"><i data-lucide="shield-check" class="w-6 h-6 text-emerald-400"></i> <?= __('security.security_score_title') ?></h2>
                    <p class="text-slate-500 text-xs mt-1"><?= __('security.score_breakdown_desc') ?></p>
                </div>
                <button type="button" onclick="closeSecurityScoreModal()" class="p-2 hover:bg-white/5 rounded-xl transition text-slate-500 hover:text-white"><i data-lucide="x" class="w-6 h-6"></i></button>
            </div>
            <div class="modal-body p-8">
                <div class="flex items-center justify-center mb-8">
                    <div class="relative">
                        <svg class="w-48 h-48 transform -rotate-90">
                            <circle cx="96" cy="96" r="88" stroke="rgba(255,255,255,0.05)" stroke-width="12" fill="none"/>
                            <circle cx="96" cy="96" r="88" stroke="url(#scoreGradient)" stroke-width="12" fill="none"
                                    stroke-dasharray="<?= 2 * 3.14159 * 88 ?>" stroke-dashoffset="<?= 2 * 3.14159 * 88 * (1 - $security_score / 100) ?>"
                                    stroke-linecap="round" class="transition-all duration-1000"/>
                            <defs><linearGradient id="scoreGradient" x1="0%" y1="0%" x2="100%" y2="100%">
                                <stop offset="0%" style="stop-color:#10b981" /><stop offset="100%" style="stop-color:#059669" />
                            </linearGradient></defs>
                        </svg>
                        <div class="absolute inset-0 flex flex-col items-center justify-center">
                            <div class="text-5xl font-black text-white"><?= $security_score ?></div>
                            <div class="text-sm text-slate-400 font-bold">/100</div>
                        </div>
                    </div>
                </div>
                <div class="space-y-4">
                    <h3 class="text-sm font-bold text-white uppercase tracking-widest mb-4"><?= __('security.score_factors') ?></h3>
                    <?php
                    $factors = [
                        ['check' => $ips_enabled, 'label' => __('security.ips_ids_label'), 'pts' => 20],
                        ['check' => $active_rules >= 10, 'label' => __('security.firewall_coverage'), 'pts' => 15, 'info' => number_format($active_rules) . ' ' . __('security.rules_count_label')],
                        ['check' => $threat_detection_enabled, 'label' => __('security.threat_detection_label'), 'pts' => 15],
                        ['check' => $monitoring_active, 'label' => __('security.monitoring_active_label'), 'pts' => 10],
                        ['check' => $vpn_secure, 'label' => __('security.vpn_security'), 'pts' => 10],
                        ['check' => $ad_blocking || $honeypot, 'label' => __('security.ai_threat_defense'), 'pts' => 10],
                    ];
                    foreach ($factors as $f):
                    ?>
                    <div class="bg-slate-900/50 rounded-xl p-4 border border-white/5">
                        <div class="flex items-center justify-between mb-2">
                            <div class="flex items-center gap-3">
                                <i data-lucide="<?= $f['check'] ? 'check-circle' : 'x-circle' ?>" class="w-5 h-5 <?= $f['check'] ? 'text-emerald-400' : 'text-red-400' ?>"></i>
                                <span class="text-sm font-bold text-white"><?= $f['label'] ?></span>
                                <?php if (!empty($f['info'])): ?><span class="text-xs text-slate-500 font-mono">(<?= $f['info'] ?>)</span><?php endif; ?>
                            </div>
                            <span class="text-xs font-mono <?= $f['check'] ? 'text-emerald-400' : 'text-red-400' ?>">
                                <?= $f['check'] ? '+' . $f['pts'] : '0' ?> <?= __('security.points') ?>
                            </span>
                        </div>
                        <div class="w-full bg-slate-800 rounded-full h-2">
                            <div class="bg-emerald-500 h-2 rounded-full transition-all" style="width: <?= $f['check'] ? '100' : '0' ?>%"></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="modal-footer p-6 border-t border-white/5 bg-slate-900/30 flex justify-between items-center">
                <p class="text-[12px] text-slate-500 font-bold uppercase tracking-widest">
                    <?= __('security.final_score') ?>: <span class="text-emerald-400 text-lg"><?= $security_score ?>/100</span>
                </p>
                <button type="button" onclick="closeSecurityScoreModal()" class="px-8 py-2.5 bg-emerald-600 hover:bg-emerald-500 text-white rounded-xl text-[12px] font-black uppercase tracking-widest transition shadow-lg shadow-emerald-600/20"><?= __('common.close') ?></button>
            </div>
        </div>
    </div>

    <!-- Security Rules Modal -->
    <div id="securityRulesModal" class="modal-overlay" onclick="if(event.target===this) closeSecurityRulesModal()">
        <div class="modal-container max-w-4xl" onclick="event.stopPropagation()">
            <div class="modal-header">
                <div><h2 class="text-xl font-bold flex items-center gap-2 text-white"><i data-lucide="list-checks" class="w-6 h-6 text-blue-400"></i> <?= __('security.ips_rules') ?></h2></div>
                <button type="button" onclick="closeSecurityRulesModal()" class="p-2 hover:bg-white/5 rounded-xl transition text-slate-500 hover:text-white"><i data-lucide="x" class="w-6 h-6"></i></button>
            </div>
            <div class="modal-body p-8">
                <?php if (empty($rule_list)): ?>
                <div class="text-center text-slate-500 py-8"><?= __('security.no_rules_defined') ?></div>
                <?php else: ?>
                <div class="overflow-x-auto bg-slate-900/50 rounded-2xl border border-white/5">
                    <table class="w-full text-left border-collapse">
                        <thead><tr class="bg-slate-950/30 text-[11px] font-black text-slate-500 uppercase tracking-widest border-b border-white/5">
                            <th class="px-6 py-4"><?= __('common.name') ?></th><th class="px-6 py-4"><?= __('table_headers.category') ?></th><th class="px-6 py-4"><?= __('table_headers.priority') ?></th><th class="px-6 py-4"><?= __('common.action') ?></th><th class="px-6 py-4 text-right"><?= __('common.status') ?></th>
                        </tr></thead>
                        <tbody class="divide-y divide-white/[0.02]">
                        <?php foreach ($rule_list as $rule):
                            $prio = strtolower($rule['priority'] ?? '');
                            $prioClass = $prio === 'high' ? 'bg-rose-500/20 text-rose-400' : ($prio === 'medium' ? 'bg-amber-500/20 text-amber-400' : 'bg-slate-700 text-slate-400');
                            $action = strtolower($rule['action'] ?? '');
                            $actionClass = ($action === 'block' || $action === 'drop') ? 'bg-rose-500/20 text-rose-400' : ($action === 'alert' ? 'bg-blue-500/20 text-blue-400' : 'bg-slate-700 text-slate-400');
                            $enabled = $rule['enabled'] ?? false;
                        ?>
                        <tr class="hover:bg-white/[0.02] transition-colors">
                            <td class="px-6 py-4 text-sm font-bold text-white"><?= htmlspecialchars($rule['name'] ?? '-') ?></td>
                            <td class="px-6 py-4"><span class="px-2 py-0.5 rounded-md bg-purple-500/10 text-[12px] font-mono text-purple-400 border border-purple-500/20"><?= htmlspecialchars($rule['category'] ?? '-') ?></span></td>
                            <td class="px-6 py-4"><span class="px-2 py-0.5 rounded-md text-[12px] font-bold border <?= $prioClass ?>"><?= htmlspecialchars($rule['priority'] ?? '-') ?></span></td>
                            <td class="px-6 py-4"><span class="px-2 py-0.5 rounded-md text-[12px] font-bold uppercase <?= $actionClass ?>"><?= htmlspecialchars($rule['action'] ?? '-') ?></span></td>
                            <td class="px-6 py-4 text-right">
                                <div class="flex items-center justify-end gap-2">
                                    <div class="w-2 h-2 rounded-full <?= $enabled ? 'bg-emerald-500 animate-pulse' : 'bg-slate-500' ?>"></div>
                                    <span class="text-xs font-bold <?= $enabled ? 'text-emerald-400' : 'text-slate-400' ?>"><?= $enabled ? __('security.ips_rules_active') : __('security.ips_rules_disabled') ?></span>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Geo-blocking Modal -->
    <div id="geoBlockModal" class="modal-overlay" onclick="if(event.target===this) closeGeoBlockModal()">
        <div class="modal-container max-w-3xl" onclick="event.stopPropagation()">
            <div class="modal-header">
                <div><h2 class="text-xl font-bold flex items-center gap-2 text-white"><i data-lucide="globe" class="w-6 h-6 text-purple-400"></i> <?= __('security.geo_blocking') ?></h2></div>
                <button type="button" onclick="closeGeoBlockModal()" class="p-2 hover:bg-white/5 rounded-xl transition text-slate-500 hover:text-white"><i data-lucide="x" class="w-6 h-6"></i></button>
            </div>
            <div class="modal-body p-8">
                <?php $geo_rules = $security_settings['geo_rules'] ?? []; $blocked_countries_list = $security_settings['blocked_countries'] ?? []; ?>
                <?php if (!empty($geo_rules)): ?>
                <div class="space-y-4">
                    <?php foreach ($geo_rules as $rule):
                        $direction = strtoupper($rule['direction'] ?? 'IN');
                        $countries = $rule['countries'] ?? [];
                        $counts = $rule['counts'] ?? [];
                        $dirColor = $direction === 'OUT' ? 'bg-amber-500/20 text-amber-400' : ($direction === 'BOTH' ? 'bg-purple-500/20 text-purple-400' : 'bg-rose-500/20 text-rose-400');
                    ?>
                    <div class="bg-slate-800/50 border border-white/5 rounded-2xl p-5">
                        <div class="flex items-center gap-3 mb-4">
                            <span class="px-2 py-0.5 rounded text-xs font-black uppercase <?= $dirColor ?>"><?= $direction ?></span>
                            <span class="text-sm font-bold text-white"><?= htmlspecialchars($rule['name'] ?? __('security.fallback_rule_name')) ?></span>
                            <span class="text-xs text-slate-500"><?= count($countries) ?> <?= __('security.geo_blocking_countries') ?></span>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <?php foreach ($countries as $cc):
                                $code = strtolower(is_string($cc) ? $cc : ($cc['code'] ?? ''));
                                if (!$code || $code === 'un') continue;
                            ?>
                            <div class="flex items-center gap-1.5 bg-slate-700/50 rounded-lg px-2 py-1">
                                <img src="img/flags/<?= $code ?>.png" class="w-5 h-3.5 rounded-sm" title="<?= strtoupper($code) ?>">
                                <span class="text-xs text-slate-300 font-mono"><?= strtoupper($code) ?></span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php elseif (!empty($blocked_countries_list)): ?>
                <div class="bg-slate-800/50 border border-white/5 rounded-2xl p-5">
                    <div class="flex flex-wrap gap-2">
                        <?php foreach ($blocked_countries_list as $cc):
                            $code = strtolower(is_string($cc) ? $cc : '');
                            if (!$code || is_numeric($code)) continue;
                        ?>
                        <div class="flex items-center gap-1.5 bg-slate-700/50 rounded-lg px-2 py-1">
                            <img src="img/flags/<?= $code ?>.png" class="w-5 h-3.5 rounded-sm"><span class="text-xs text-slate-300 font-mono"><?= strtoupper($code) ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php else: ?>
                <div class="text-center text-slate-500 py-8"><?= __('security.no_country_data') ?></div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Ignore List Modal -->
    <div id="ignoreListModal" class="modal-overlay" onclick="if(event.target===this) closeIgnoreModal()">
        <div class="modal-container max-w-2xl" onclick="event.stopPropagation()">
            <div class="modal-header">
                <div><h2 class="text-xl font-bold text-white flex items-center gap-2"><i data-lucide="shield-off" class="w-6 h-6 text-amber-400"></i> <?= __('security.ignored_ip') ?></h2></div>
                <button type="button" onclick="closeIgnoreModal()" class="p-2 hover:bg-white/5 rounded-xl transition text-slate-500 hover:text-white"><i data-lucide="x" class="w-6 h-6"></i></button>
            </div>
            <div class="modal-body p-6">
                <div class="flex gap-3 mb-6">
                    <input type="text" id="ignore-ip" placeholder="<?= __('security.ip_placeholder') ?>" class="flex-1 bg-slate-800/50 border border-white/10 rounded-xl px-4 py-2 text-sm text-white placeholder-slate-600 focus:outline-none focus:ring-2 focus:ring-amber-500/50">
                    <input type="text" id="ignore-label" placeholder="<?= __('security.label_placeholder') ?>" class="flex-1 bg-slate-800/50 border border-white/10 rounded-xl px-4 py-2 text-sm text-white placeholder-slate-600 focus:outline-none focus:ring-2 focus:ring-amber-500/50">
                    <button onclick="addIgnoreIP()" class="px-4 py-2 bg-amber-600 hover:bg-amber-500 text-white rounded-xl text-sm font-bold transition"><i data-lucide="plus" class="w-4 h-4"></i></button>
                </div>
                <div id="ignore-list-body"><div class="text-center text-slate-500 py-4"><?= __('common.loading') ?></div></div>
            </div>
        </div>
    </div>

    <?php include __DIR__ . '/includes/footer.php'; ?>

    <script>
    // ── Threats state (pre-loaded from PHP) ──
    let allEvents = <?= json_encode(array_values($threat_events)) ?>;
    let currentRange = '24h';
    let activeFilters = { risk: null, action: null };
    let refreshTimer = null;
    let currentPage = 1;
    let pageSize = 25;
    let threatsLoaded = false;

    // ── Tab switching ──
    function switchTab(tab) {
        document.getElementById('tab-overview').classList.toggle('hidden', tab !== 'overview');
        document.getElementById('tab-threats').classList.toggle('hidden', tab !== 'threats');

        document.querySelectorAll('.tab-btn').forEach(b => {
            b.classList.remove('bg-white/10', 'text-white');
            b.classList.add('text-slate-400');
        });
        document.getElementById('tab-btn-' + tab).classList.add('bg-white/10', 'text-white');
        document.getElementById('tab-btn-' + tab).classList.remove('text-slate-400');

        if (tab === 'threats' && !threatsLoaded) {
            threatsLoaded = true;
            applyFilters();
            initSidebar();
            startAutoRefresh();
        }

        history.replaceState(null, '', '#' + tab);
    }

    const riskColors = {
        high:   { bg: 'bg-red-500/10',   text: 'text-red-400',   icon: 'alert-triangle' },
        medium: { bg: 'bg-amber-500/10', text: 'text-amber-400', icon: 'alert-circle'   },
        low:    { bg: 'bg-blue-500/10',  text: 'text-blue-400',  icon: 'info'            }
    };

    function escHtml(s) { const d = document.createElement('div'); d.textContent = s || ''; return d.innerHTML; }

    function renderEventRow(e, globalIdx) {
        const c = riskColors[e.risk] || riskColors.medium;
        const cc = e.country_code || 'un';
        const actionClass = e.action === 'blocked' ? 'bg-rose-500/10 text-rose-400 border-rose-500/20' : 'bg-amber-500/10 text-amber-400 border-amber-500/20';
        const actionLabel = e.action === 'blocked' ? '<?= __('threats.action_blocked') ?>' : '<?= __('threats.action_alert') ?>';
        const time = new Date(e.timestamp * 1000).toLocaleTimeString('pl-PL', {hour:'2-digit', minute:'2-digit', second:'2-digit'});
        const date = new Date(e.timestamp * 1000).toLocaleDateString('pl-PL', {day:'2-digit', month:'2-digit'});
        return `<div class="bg-slate-900/50 rounded-2xl border border-white/5 p-4 hover:border-orange-500/30 transition-all cursor-pointer relative overflow-hidden" onclick="showEventDetail(${globalIdx})">
            <div class="absolute left-0 top-0 bottom-0 w-1 ${c.bg}"></div>
            <div class="flex items-center gap-4">
                <div class="p-2.5 ${c.bg} rounded-xl ${c.text} shrink-0"><i data-lucide="${c.icon}" class="w-5 h-5"></i></div>
                <div class="flex-grow min-w-0">
                    <div class="flex items-center justify-between gap-3">
                        <div class="flex-grow min-w-0">
                            <div class="flex items-center gap-2 mb-1">
                                ${cc !== 'un' && cc !== 'local' ? `<img src="img/flags/${cc}.png" class="w-4 h-3 rounded-sm opacity-80">` : ''}
                                <h3 class="font-bold text-white text-sm truncate">${escHtml(e.signature)}</h3>
                            </div>
                            <div class="flex items-center flex-wrap gap-x-3 gap-y-1 text-[12px] font-medium">
                                <span class="flex items-center gap-1.5 text-slate-400"><i data-lucide="clock" class="w-3 h-3 text-slate-500"></i>${date} ${time}</span>
                                <div class="flex items-center gap-1.5 bg-white/5 px-2 py-0.5 rounded-md border border-white/5">
                                    <span class="text-rose-400/80 font-mono text-[11px]">${e.src_ip}${e.src_port ? ':'+e.src_port : ''}</span>
                                    <span class="text-slate-600">\u00bb</span>
                                    <span class="text-emerald-400/80 font-mono text-[11px]">${e.dst_ip || 'Local'}${e.dst_port ? ':'+e.dst_port : ''}</span>
                                </div>
                                ${e.protocol ? `<span class="text-slate-500 font-mono text-[11px]">${e.protocol}</span>` : ''}
                                ${e.category ? `<span class="text-slate-500 italic text-[11px]">${escHtml(e.category)}</span>` : ''}
                            </div>
                        </div>
                        <div class="shrink-0 flex flex-col items-end gap-1.5">
                            <span class="px-2 py-0.5 rounded-md text-[11px] font-black uppercase tracking-widest border ${actionClass}">${actionLabel}</span>
                            <span class="text-[11px] font-black ${c.text} uppercase tracking-widest">${(e.risk||'').toUpperCase()}</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>`;
    }

    function renderEvents(events) {
        const container = document.getElementById('events-container');
        const empty = document.getElementById('events-empty');
        const paginationBar = document.getElementById('pagination-bar');

        if (!events.length) {
            container.innerHTML = '';
            empty.classList.remove('hidden');
            paginationBar.style.display = 'none';
            document.getElementById('events-count').textContent = '0 <?= __('threats.events_suffix') ?>';
            return;
        }
        empty.classList.add('hidden');
        document.getElementById('events-count').textContent = events.length + ' <?= __('threats.events_suffix') ?>';

        const totalPages = Math.ceil(events.length / pageSize);
        if (currentPage > totalPages) currentPage = totalPages;
        if (currentPage < 1) currentPage = 1;

        const start = (currentPage - 1) * pageSize;
        const end = Math.min(start + pageSize, events.length);
        const page = events.slice(start, end);

        container.innerHTML = page.map((e, i) => renderEventRow(e, start + i)).join('');

        // Update pagination bar
        paginationBar.style.display = events.length > pageSize ? 'flex' : 'none';
        document.getElementById('page-start').textContent = start + 1;
        document.getElementById('page-end').textContent = end;
        document.getElementById('page-total').textContent = events.length;
        document.getElementById('btn-prev').disabled = currentPage <= 1;
        document.getElementById('btn-next').disabled = currentPage >= totalPages;

        if (typeof lucide !== 'undefined') lucide.createIcons();
    }

    function setPageSize(size) {
        pageSize = size;
        currentPage = 1;
        document.querySelectorAll('.page-size-btn').forEach(b => b.classList.remove('active'));
        event.target.classList.add('active');
        applyFilters();
    }
    function prevPage() { if (currentPage > 1) { currentPage--; applyFilters(); } }
    function nextPage() {
        const total = getFilteredEvents().length;
        if (currentPage < Math.ceil(total / pageSize)) { currentPage++; applyFilters(); }
    }

    // ── Event Detail ──
    function showEventDetail(idx) {
        const filtered = getFilteredEvents();
        const e = filtered[idx];
        if (!e) return;
        const c = riskColors[e.risk] || riskColors.medium;
        const time = new Date(e.timestamp * 1000).toLocaleString('pl-PL');
        const actionLabel = e.action === 'blocked' ? '<?= __('threats.action_blocked') ?>' : '<?= __('threats.action_alert') ?>';
        const srcGeo = e.src_geo || {};
        const dstGeo = e.dst_geo || {};

        document.getElementById('event-detail-body').innerHTML = `
            <div class="space-y-6">
                <div class="p-4 rounded-2xl ${c.bg} border border-white/5">
                    <div class="flex items-center gap-3 mb-2"><i data-lucide="${c.icon}" class="w-6 h-6 ${c.text}"></i><h3 class="text-lg font-bold text-white">${escHtml(e.signature)}</h3></div>
                    <div class="flex items-center gap-3 text-sm">
                        <span class="px-2 py-0.5 rounded-md text-[11px] font-black uppercase ${c.text} ${c.bg} border border-white/5">${(e.risk||'').toUpperCase()}</span>
                        <span class="text-slate-400">${actionLabel}</span>
                        <span class="text-slate-500 text-xs">${time}</span>
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div class="p-4 bg-slate-900/50 rounded-2xl border border-white/5">
                        <h4 class="text-[11px] font-black text-slate-500 uppercase tracking-widest mb-3"><?= __('threats.source') ?></h4>
                        <p class="text-white font-mono font-bold">${e.src_ip}${e.src_port ? ':'+e.src_port : ''}</p>
                        ${srcGeo.country ? `<p class="text-slate-400 text-xs mt-1">${srcGeo.country}${srcGeo.city ? ', '+srcGeo.city : ''}</p>` : ''}
                        ${srcGeo.org ? `<p class="text-slate-500 text-[11px] mt-0.5">${escHtml(srcGeo.org)}</p>` : ''}
                    </div>
                    <div class="p-4 bg-slate-900/50 rounded-2xl border border-white/5">
                        <h4 class="text-[11px] font-black text-slate-500 uppercase tracking-widest mb-3"><?= __('threats.destination') ?></h4>
                        <p class="text-white font-mono font-bold">${e.dst_ip || 'Local'}${e.dst_port ? ':'+e.dst_port : ''}</p>
                        ${dstGeo.country ? `<p class="text-slate-400 text-xs mt-1">${dstGeo.country}</p>` : ''}
                    </div>
                </div>
                <div class="p-4 bg-slate-900/50 rounded-2xl border border-white/5">
                    <h4 class="text-[11px] font-black text-slate-500 uppercase tracking-widest mb-3"><?= __('threats.details') ?></h4>
                    <div class="grid grid-cols-2 gap-3 text-sm">
                        <div><span class="text-slate-500"><?= __('threats.protocol') ?>:</span> <span class="text-white font-mono">${e.protocol || '-'}</span></div>
                        <div><span class="text-slate-500"><?= __('threats.direction') ?>:</span> <span class="text-white">${e.direction || '-'}</span></div>
                        <div><span class="text-slate-500"><?= __('threats.category') ?>:</span> <span class="text-white">${escHtml(e.category || '-')}</span></div>
                        <div><span class="text-slate-500">Signature ID:</span> <span class="text-white font-mono">${e.signature_id || '-'}</span></div>
                    </div>
                </div>
                <div class="flex gap-3">
                    <button onclick="addToIgnore('${e.src_ip}')" class="flex-1 px-4 py-2.5 bg-amber-600/20 hover:bg-amber-600/30 text-amber-400 rounded-xl text-xs font-bold transition border border-amber-500/20">
                        <i data-lucide="shield-off" class="w-4 h-4 inline mr-1"></i> <?= __('threats.ignore_ip') ?>
                    </button>
                    <button onclick="closeEventDetail()" class="flex-1 px-4 py-2.5 bg-white/5 hover:bg-white/10 text-slate-400 rounded-xl text-xs font-bold transition border border-white/10"><?= __('common.close') ?></button>
                </div>
            </div>`;
        document.getElementById('eventDetailModal').classList.add('active');
        document.body.style.overflow = 'hidden';
        setTimeout(() => lucide.createIcons(), 50);
    }
    function closeEventDetail() { document.getElementById('eventDetailModal').classList.remove('active'); document.body.style.overflow = ''; }

    // ── Filters ──
    function getFilteredEvents() {
        let filtered = [...allEvents];
        const search = (document.getElementById('search-input')?.value || '').toLowerCase();
        if (activeFilters.risk) filtered = filtered.filter(e => e.risk === activeFilters.risk);
        if (activeFilters.action) filtered = filtered.filter(e => e.action === activeFilters.action);
        if (search) filtered = filtered.filter(e => (e.signature||'').toLowerCase().includes(search) || (e.src_ip||'').includes(search) || (e.dst_ip||'').includes(search) || (e.category||'').toLowerCase().includes(search));
        return filtered;
    }
    function applyFilters() { renderEvents(getFilteredEvents()); }
    function toggleFilter(type, value) {
        activeFilters[type] = activeFilters[type] === value ? null : value;
        currentPage = 1;
        document.querySelectorAll(`.filter-btn[data-filter="${type}"]`).forEach(btn => {
            const isActive = btn.dataset.value === activeFilters[type];
            btn.classList.toggle('ring-1', isActive); btn.classList.toggle('ring-white/30', isActive); btn.classList.toggle('bg-white/10', isActive);
        });
        applyFilters();
    }

    function setRange(range) {
        currentRange = range;
        document.querySelectorAll('.range-btn').forEach(btn => {
            const active = btn.dataset.range === range;
            btn.classList.toggle('bg-blue-600', active); btn.classList.toggle('text-white', active); btn.classList.toggle('border-blue-500/50', active);
            btn.classList.toggle('shadow-lg', active); btn.classList.toggle('shadow-blue-600/20', active);
            btn.classList.toggle('bg-white/5', !active); btn.classList.toggle('text-slate-400', !active); btn.classList.toggle('border-white/10', !active);
        });
        document.getElementById('stat-range').textContent = range.toUpperCase();
        refreshData();
    }

    // ── AJAX ──
    async function refreshData() {
        const spinner = document.getElementById('loading-spinner');
        spinner.classList.remove('hidden');
        try {
            const resp = await fetch(`api_threats.php?range=${currentRange}`);
            const data = await resp.json();
            allEvents = data.events || [];
            updateStats(data.stats || {});
            updateSidebar(data);
            applyFilters();
        } catch (err) { console.error('Threat refresh error:', err); }
        finally { spinner.classList.add('hidden'); }
    }
    function updateStats(s) {
        document.getElementById('stat-total').textContent = s.total ?? 0;
        document.getElementById('stat-blocked').textContent = s.blocked ?? 0;
        document.getElementById('stat-alerts').textContent = s.alerts ?? 0;
        document.getElementById('stat-high').textContent = s.high ?? 0;
    }
    function updateSidebar(data) {
        // Countries
        const countries = data.top_countries || {};
        const cc_entries = Object.entries(countries);
        const maxC = cc_entries.length ? cc_entries[0][1] : 1;
        document.getElementById('sidebar-countries').innerHTML = cc_entries.length
            ? cc_entries.map(([cc, cnt]) => `<div class="flex items-center justify-between group"><div class="flex items-center gap-3"><img src="img/flags/${cc}.png" class="w-6 h-auto rounded shadow-sm opacity-80"><span class="text-sm font-bold text-slate-300">${cc.toUpperCase()}</span></div><div class="flex items-center gap-3"><span class="text-xs font-mono text-orange-400 bg-orange-500/10 px-2 py-1 rounded-lg border border-orange-500/20">${cnt}</span><div class="w-12 h-1 bg-white/5 rounded-full overflow-hidden"><div class="h-full bg-orange-500" style="width:${Math.min(100,(cnt/maxC)*100)}%"></div></div></div></div>`).join('')
            : '<p class="text-[12px] text-slate-600 italic text-center py-4"><?= __('threats.no_geo_data') ?></p>';

        // Risk
        const s = data.stats || {};
        const total = s.total || 1;
        const risks = [{key:'high',color:'red',label:'<?= __('threats.risk_high') ?>',count:s.high||0},{key:'medium',color:'amber',label:'<?= __('threats.risk_medium') ?>',count:s.medium||0},{key:'low',color:'blue',label:'<?= __('threats.risk_low') ?>',count:s.low||0}];
        document.getElementById('sidebar-risk').innerHTML = risks.map(r => {
            const pct = Math.round((r.count/total)*100);
            return `<div><div class="flex items-center justify-between mb-1.5"><span class="text-xs font-bold text-${r.color}-400 uppercase tracking-widest">${r.label}</span><span class="text-xs font-mono text-slate-400">${r.count} (${pct}%)</span></div><div class="w-full h-2 bg-white/5 rounded-full overflow-hidden"><div class="h-full bg-${r.color}-500 rounded-full" style="width:${pct}%"></div></div></div>`;
        }).join('');

        // Categories
        const cats = Object.entries(data.top_categories || {});
        document.getElementById('sidebar-categories').innerHTML = cats.length
            ? cats.map(([cat, cnt]) => `<div class="flex items-center justify-between p-2 rounded-xl bg-white/[0.02] hover:bg-white/5 transition"><span class="text-xs font-bold text-slate-300 truncate max-w-[180px]">${escHtml(cat)}</span><span class="text-xs font-mono text-orange-400 bg-orange-500/10 px-2 py-0.5 rounded-md border border-orange-500/20">${cnt}</span></div>`).join('')
            : '<p class="text-[12px] text-slate-600 italic text-center py-4"><?= __('threats.no_category_data') ?></p>';
    }
    function startAutoRefresh() { if (refreshTimer) clearInterval(refreshTimer); refreshTimer = setInterval(refreshData, 60000); }

    // ── Export CSV ──
    function exportThreats() {
        const filtered = getFilteredEvents();
        if (!filtered.length) return;
        const headers = ['Timestamp','Source IP','Source Port','Dest IP','Dest Port','Signature','Category','Risk','Action','Protocol','Country'];
        const rows = filtered.map(e => [new Date(e.timestamp*1000).toISOString(), e.src_ip, e.src_port, e.dst_ip, e.dst_port, `"${(e.signature||'').replace(/"/g,'""')}"`, `"${(e.category||'').replace(/"/g,'""')}"`, e.risk, e.action, e.protocol, e.country_code]);
        const csv = [headers.join(','), ...rows.map(r => r.join(','))].join('\n');
        const blob = new Blob(['\uFEFF'+csv], {type:'text/csv;charset=utf-8;'});
        const link = document.createElement('a'); link.href = URL.createObjectURL(blob); link.download = `threats_${currentRange}_${new Date().toISOString().slice(0,10)}.csv`; link.click();
    }

    // ── Ignore IP ──
    async function addToIgnore(ip) {
        if (!confirm('<?= __('threats.confirm_ignore') ?> ' + ip + '?')) return;
        await fetch('api_threat_ignore.php', {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({ip, reason:'Ignored from Threat Watch'})});
        closeEventDetail();
        allEvents = allEvents.filter(e => e.src_ip !== ip);
        applyFilters();
    }

    // ── Modals ──
    function openSecurityScoreModal() { document.getElementById('securityScoreModal').classList.add('active'); document.body.style.overflow = 'hidden'; setTimeout(() => lucide.createIcons(), 50); }
    function closeSecurityScoreModal() { document.getElementById('securityScoreModal').classList.remove('active'); document.body.style.overflow = ''; }
    function openSecurityRulesModal() { document.getElementById('securityRulesModal').classList.add('active'); document.body.style.overflow = 'hidden'; setTimeout(() => lucide.createIcons(), 50); }
    function closeSecurityRulesModal() { document.getElementById('securityRulesModal').classList.remove('active'); document.body.style.overflow = ''; }
    function openGeoBlockModal() { document.getElementById('geoBlockModal').classList.add('active'); document.body.style.overflow = 'hidden'; setTimeout(() => lucide.createIcons(), 50); }
    function closeGeoBlockModal() { document.getElementById('geoBlockModal').classList.remove('active'); document.body.style.overflow = ''; }
    function openIgnoreModal() { document.getElementById('ignoreListModal').classList.add('active'); document.body.style.overflow = 'hidden'; loadIgnoreList(); setTimeout(() => lucide.createIcons(), 50); }
    function closeIgnoreModal() { document.getElementById('ignoreListModal').classList.remove('active'); document.body.style.overflow = ''; }

    async function loadIgnoreList() {
        const resp = await fetch('api_threat_ignore.php');
        const json = await resp.json();
        const list = json.data || [];
        const body = document.getElementById('ignore-list-body');
        if (!list.length) { body.innerHTML = '<div class="text-center text-slate-500 py-4"><?= __('security.no_ignore_ips') ?></div>'; return; }
        body.innerHTML = '<table class="w-full"><thead><tr class="text-xs text-slate-500 uppercase"><th class="text-left py-2 px-3">IP</th><th class="text-left py-2 px-3"><?= __('security.label_col') ?></th><th class="text-left py-2 px-3"><?= __('security.added_col') ?></th><th class="py-2 px-3"></th></tr></thead><tbody>' +
            list.map(item => `<tr class="border-t border-white/5 hover:bg-white/[0.02]"><td class="py-3 px-3 text-sm font-mono text-white">${item.ip}</td><td class="py-3 px-3 text-sm text-slate-400">${item.label||'-'}</td><td class="py-3 px-3 text-xs text-slate-500">${item.added_at}</td><td class="py-3 px-3 text-right"><button onclick="removeIgnoreIP(${item.id})" class="text-red-400 hover:text-red-300 transition"><i data-lucide="trash-2" class="w-4 h-4"></i></button></td></tr>`).join('') + '</tbody></table>';
        lucide.createIcons();
    }
    async function addIgnoreIP() {
        const ip = document.getElementById('ignore-ip').value.trim();
        const label = document.getElementById('ignore-label').value.trim();
        if (!ip) return;
        await fetch('api_threat_ignore.php', {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({ip, label})});
        document.getElementById('ignore-ip').value = ''; document.getElementById('ignore-label').value = '';
        loadIgnoreList();
    }
    async function removeIgnoreIP(id) {
        await fetch('api_threat_ignore.php', {method:'DELETE', headers:{'Content-Type':'application/json'}, body:JSON.stringify({id})});
        loadIgnoreList();
    }

    // ── Sidebar init from pre-loaded data ──
    function initSidebar() {
        const events = allEvents;
        const total = events.length || 1;
        const blocked = events.filter(e => e.action === 'blocked').length;
        const high = events.filter(e => e.risk === 'high').length;
        const medium = events.filter(e => e.risk === 'medium').length;
        const low = events.filter(e => e.risk === 'low').length;

        // Countries
        const countries = {};
        events.forEach(e => {
            const cc = e.country_code || 'un';
            if (cc !== 'un' && cc !== 'local') countries[cc] = (countries[cc] || 0) + 1;
        });
        const cc_entries = Object.entries(countries).sort((a, b) => b[1] - a[1]).slice(0, 8);
        const maxC = cc_entries.length ? cc_entries[0][1] : 1;
        document.getElementById('sidebar-countries').innerHTML = cc_entries.length
            ? cc_entries.map(([cc, cnt]) => `<div class="flex items-center justify-between group"><div class="flex items-center gap-3"><img src="img/flags/${cc}.png" class="w-6 h-auto rounded shadow-sm opacity-80"><span class="text-sm font-bold text-slate-300">${cc.toUpperCase()}</span></div><div class="flex items-center gap-3"><span class="text-xs font-mono text-orange-400 bg-orange-500/10 px-2 py-1 rounded-lg border border-orange-500/20">${cnt}</span><div class="w-12 h-1 bg-white/5 rounded-full overflow-hidden"><div class="h-full bg-orange-500" style="width:${Math.min(100,(cnt/maxC)*100)}%"></div></div></div></div>`).join('')
            : '<p class="text-[12px] text-slate-600 italic text-center py-4"><?= __('threats.no_geo_data') ?></p>';

        // Risk
        const risks = [{key:'high',color:'red',label:'<?= __('threats.risk_high') ?>',count:high},{key:'medium',color:'amber',label:'<?= __('threats.risk_medium') ?>',count:medium},{key:'low',color:'blue',label:'<?= __('threats.risk_low') ?>',count:low}];
        document.getElementById('sidebar-risk').innerHTML = risks.map(r => {
            const pct = Math.round((r.count/total)*100);
            return `<div><div class="flex items-center justify-between mb-1.5"><span class="text-xs font-bold text-${r.color}-400 uppercase tracking-widest">${r.label}</span><span class="text-xs font-mono text-slate-400">${r.count} (${pct}%)</span></div><div class="w-full h-2 bg-white/5 rounded-full overflow-hidden"><div class="h-full bg-${r.color}-500 rounded-full" style="width:${pct}%"></div></div></div>`;
        }).join('');

        // Categories
        const cats = {};
        events.forEach(e => { const c = e.category || 'Unknown'; cats[c] = (cats[c] || 0) + 1; });
        const cat_entries = Object.entries(cats).sort((a, b) => b[1] - a[1]).slice(0, 6);
        document.getElementById('sidebar-categories').innerHTML = cat_entries.length
            ? cat_entries.map(([cat, cnt]) => `<div class="flex items-center justify-between p-2 rounded-xl bg-white/[0.02] hover:bg-white/5 transition"><span class="text-xs font-bold text-slate-300 truncate max-w-[180px]">${escHtml(cat)}</span><span class="text-xs font-mono text-orange-400 bg-orange-500/10 px-2 py-0.5 rounded-md border border-orange-500/20">${cnt}</span></div>`).join('')
            : '<p class="text-[12px] text-slate-600 italic text-center py-4"><?= __('threats.no_category_data') ?></p>';

        if (typeof lucide !== 'undefined') lucide.createIcons();
    }

    // ── Init ──
    document.addEventListener('DOMContentLoaded', () => {
        lucide.createIcons();
        if (location.hash === '#threats') switchTab('threats');
    });
    </script>
</body>
</html>
