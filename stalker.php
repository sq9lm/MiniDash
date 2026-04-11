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
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= __('stalker.title') ?> | MiniDash</title>
    <link rel="icon" type="image/png" href="img/favicon.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="assets/css/fonts.css">
    <link rel="stylesheet" href="dashboard.css">
    <script src="assets/js/lucide.min.js"></script>
</head>
<body class="custom-scrollbar">

    <?php render_nav("Wi-Fi Stalker", $navbar_stats); ?>

    <div class="max-w-7xl mx-auto p-4 md:p-8">

        <!-- Page Header -->
        <div class="flex items-center justify-between mb-8">
            <div class="flex items-center gap-4">
                <div class="w-14 h-14 rounded-2xl bg-purple-500/10 flex items-center justify-center border border-purple-500/20">
                    <i data-lucide="radar" class="w-7 h-7 text-purple-400"></i>
                </div>
                <div>
                    <h1 class="text-2xl font-black text-white"><?= __('stalker.title') ?></h1>
                    <p class="text-xs text-slate-500 uppercase tracking-wider"><?= __('stalker.subtitle') ?></p>
                </div>
            </div>
            <div class="flex items-center gap-3">
                <a href="index.php" class="px-4 py-2 bg-slate-800 hover:bg-slate-700 text-slate-300 rounded-xl text-xs font-bold transition border border-white/5">
                    <i data-lucide="arrow-left" class="w-4 h-4 inline mr-1"></i> Dashboard
                </a>
            </div>
        </div>

        <!-- Filter Bar -->
        <div class="glass-card rounded-3xl p-6 mb-6">
            <div class="flex flex-wrap items-center gap-4">
                <!-- Search -->
                <div class="relative flex-1 min-w-[200px]">
                    <i data-lucide="search" class="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-slate-500"></i>
                    <input type="text" id="stalker-search" placeholder="Szukaj (MAC / nazwa)..." oninput="loadSessions()"
                        class="w-full bg-slate-900/50 border border-white/10 rounded-xl pl-10 pr-4 py-2 text-sm text-white placeholder-slate-600 focus:outline-none focus:ring-2 focus:ring-purple-500/50">
                </div>

                <!-- Time Filter -->
                <div class="flex gap-1 bg-slate-900/50 rounded-xl p-1 border border-white/10">
                    <button onclick="setTimeFilter('1h', this)" class="time-btn px-3 py-1.5 rounded-lg text-xs font-bold transition text-slate-400">1h</button>
                    <button onclick="setTimeFilter('24h', this)" class="time-btn active-filter px-3 py-1.5 rounded-lg text-xs font-bold transition bg-purple-600 text-white">24h</button>
                    <button onclick="setTimeFilter('7d', this)" class="time-btn px-3 py-1.5 rounded-lg text-xs font-bold transition text-slate-400">7d</button>
                    <button onclick="setTimeFilter('30d', this)" class="time-btn px-3 py-1.5 rounded-lg text-xs font-bold transition text-slate-400">30d</button>
                </div>

                <!-- Band Filter -->
                <div class="flex gap-1 bg-slate-900/50 rounded-xl p-1 border border-white/10">
                    <button onclick="setBandFilter('', this)" class="band-btn active-filter px-3 py-1.5 rounded-lg text-xs font-bold transition bg-purple-600 text-white">Wszystkie</button>
                    <button onclick="setBandFilter('2.4GHz', this)" class="band-btn px-3 py-1.5 rounded-lg text-xs font-bold transition text-slate-400">2.4</button>
                    <button onclick="setBandFilter('5GHz', this)" class="band-btn px-3 py-1.5 rounded-lg text-xs font-bold transition text-slate-400">5</button>
                    <button onclick="setBandFilter('6GHz', this)" class="band-btn px-3 py-1.5 rounded-lg text-xs font-bold transition text-slate-400">6</button>
                </div>

                <!-- CSV Export -->
                <a href="api_stalker.php?action=export" class="px-4 py-2 bg-slate-800 hover:bg-slate-700 text-slate-300 rounded-xl text-xs font-bold transition border border-white/5 flex items-center gap-1">
                    <i data-lucide="download" class="w-4 h-4"></i> CSV
                </a>

                <!-- Watchlist -->
                <button onclick="openWatchlistModal()" class="px-4 py-2 bg-amber-600/20 hover:bg-amber-600/30 text-amber-400 rounded-xl text-xs font-bold transition border border-amber-500/20 flex items-center gap-1">
                    <i data-lucide="eye" class="w-4 h-4"></i> <?= __('stalker.watchlist') ?>
                </button>
            </div>
        </div>

        <!-- Active Sessions Table -->
        <div class="glass-card rounded-3xl p-6 mb-6">
            <h2 class="text-sm font-bold text-slate-400 uppercase tracking-wider mb-4 flex items-center gap-2">
                <i data-lucide="wifi" class="w-4 h-4 text-emerald-400"></i>
                <?= __('stalker.active_sessions') ?>
                <span id="sessions-count" class="text-purple-400 ml-1">0</span>
            </h2>
            <div class="overflow-x-auto">
                <table class="w-full" id="sessions-table">
                    <thead>
                        <tr class="bg-white/[0.02]">
                            <th class="text-left py-3 px-4 text-xs font-bold text-slate-500 uppercase tracking-wider rounded-tl-xl">Urzadzenie</th>
                            <th class="text-left py-3 px-4 text-xs font-bold text-slate-500 uppercase tracking-wider">AP</th>
                            <th class="text-left py-3 px-4 text-xs font-bold text-slate-500 uppercase tracking-wider whitespace-nowrap">Pasmo / Kanal</th>
                            <th class="text-left py-3 px-4 text-xs font-bold text-slate-500 uppercase tracking-wider">RSSI</th>
                            <th class="text-left py-3 px-4 text-xs font-bold text-slate-500 uppercase tracking-wider">RX / TX</th>
                            <th class="text-left py-3 px-4 text-xs font-bold text-slate-500 uppercase tracking-wider whitespace-nowrap">Czas polaczenia</th>
                            <th class="text-right py-3 px-4 text-xs font-bold text-slate-500 uppercase tracking-wider rounded-tr-xl">Akcja</th>
                        </tr>
                    </thead>
                    <tbody id="sessions-body">
                        <tr><td colspan="7" class="text-center py-8 text-slate-500"><?= __('stalker.loading') ?></td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Roaming History -->
        <div class="glass-card rounded-3xl p-6">
            <h2 class="text-sm font-bold text-slate-400 uppercase tracking-wider mb-4 flex items-center gap-2">
                <i data-lucide="repeat" class="w-4 h-4 text-amber-400"></i>
                <?= __('stalker.roaming_history') ?>
                <span id="roaming-count" class="text-amber-400 ml-1">0</span>
            </h2>
            <div id="roaming-body">
                <div class="text-center py-8 text-slate-500"><?= __('stalker.loading') ?></div>
            </div>
        </div>

    </div>

    <!-- Watchlist Modal -->
    <div id="watchlistModal" class="fixed inset-0 bg-black/70 backdrop-blur-sm z-50 hidden items-center justify-center" onclick="if(event.target===this)closeWatchlistModal()">
        <div class="bg-slate-900/95 backdrop-blur-xl border border-white/10 rounded-3xl p-8 w-full max-w-2xl max-h-[80vh] overflow-y-auto shadow-2xl mx-4">
            <div class="flex items-center justify-between mb-6">
                <h2 class="text-xl font-bold text-white flex items-center gap-3">
                    <i data-lucide="eye" class="w-6 h-6 text-amber-400"></i>
                    <?= __('stalker.watchlist') ?>
                </h2>
                <button onclick="closeWatchlistModal()" class="text-slate-500 hover:text-white transition">
                    <i data-lucide="x" class="w-6 h-6"></i>
                </button>
            </div>

            <!-- Add Form -->
            <div class="flex gap-3 mb-6">
                <input type="text" id="watch-mac" placeholder="Adres MAC (np. aa:bb:cc:dd:ee:ff)"
                    class="flex-1 bg-slate-800/50 border border-white/10 rounded-xl px-4 py-2 text-sm text-white placeholder-slate-600 focus:outline-none focus:ring-2 focus:ring-amber-500/50">
                <input type="text" id="watch-label" placeholder="Etykieta (np. Laptop Jana)"
                    class="flex-1 bg-slate-800/50 border border-white/10 rounded-xl px-4 py-2 text-sm text-white placeholder-slate-600 focus:outline-none focus:ring-2 focus:ring-amber-500/50">
                <button onclick="addToWatchlist()" class="px-4 py-2 bg-amber-600 hover:bg-amber-500 text-white rounded-xl text-sm font-bold transition flex items-center gap-1">
                    <i data-lucide="plus" class="w-4 h-4"></i>
                </button>
            </div>

            <!-- Watchlist Table -->
            <div id="watchlist-body">
                <div class="text-center text-slate-500 py-4"><?= __('stalker.loading') ?></div>
            </div>
        </div>
    </div>

    <?php include __DIR__ . '/includes/footer.php'; ?>

    <script>
    let currentTimeFilter = '24h';
    let currentBandFilter = '';

    function setTimeFilter(t, btn) {
        currentTimeFilter = t;
        document.querySelectorAll('.time-btn').forEach(b => {
            b.classList.remove('bg-purple-600', 'text-white', 'active-filter');
            b.classList.add('text-slate-400');
        });
        btn.classList.add('bg-purple-600', 'text-white', 'active-filter');
        btn.classList.remove('text-slate-400');
        loadSessions();
        loadRoaming();
    }

    function setBandFilter(b, btn) {
        currentBandFilter = b;
        document.querySelectorAll('.band-btn').forEach(el => {
            el.classList.remove('bg-purple-600', 'text-white', 'active-filter');
            el.classList.add('text-slate-400');
        });
        btn.classList.add('bg-purple-600', 'text-white', 'active-filter');
        btn.classList.remove('text-slate-400');
        loadSessions();
    }

    function rssiColor(rssi) {
        if (rssi > -50) return 'text-emerald-400';
        if (rssi > -70) return 'text-amber-400';
        return 'text-red-400';
    }

    function rssiDot(rssi) {
        if (rssi > -50) return 'bg-emerald-500';
        if (rssi > -70) return 'bg-amber-500';
        return 'bg-red-500';
    }

    function timeSince(dateStr) {
        if (!dateStr) return '—';
        const diff = Math.floor((Date.now() - new Date(dateStr.replace(' ', 'T') + 'Z').getTime()) / 1000);
        if (isNaN(diff) || diff < 0) return '—';
        if (diff < 60) return diff + 's';
        if (diff < 3600) return Math.floor(diff / 60) + 'min';
        if (diff < 86400) return Math.floor(diff / 3600) + 'h ' + Math.floor((diff % 3600) / 60) + 'min';
        return Math.floor(diff / 86400) + 'd ' + Math.floor((diff % 86400) / 3600) + 'h';
    }

    function fmtRate(val) {
        if (!val || val == 0) return '0';
        const mbps = parseFloat(val) / 1000000;
        return mbps >= 1 ? mbps.toFixed(1) : (parseFloat(val) / 1000).toFixed(0) + 'k';
    }

    async function loadSessions() {
        const search = document.getElementById('stalker-search').value;
        const params = new URLSearchParams({
            action: 'sessions',
            time: currentTimeFilter,
            band: currentBandFilter,
            search: search
        });

        let data = [];
        try {
            const resp = await fetch('api_stalker.php?' + params);
            const json = await resp.json();
            data = json.data || [];
        } catch (e) {
            data = [];
        }

        document.getElementById('sessions-count').textContent = data.length;
        const tbody = document.getElementById('sessions-body');

        if (data.length === 0) {
            tbody.innerHTML = '<tr><td colspan="7" class="text-center py-8 text-slate-500">Brak aktywnych sesji WiFi w wybranym filtrze</td></tr>';
            return;
        }

        tbody.innerHTML = data.map(s => {
            const watchIcon = (s.is_watched > 0) ? 'eye' : 'eye-off';
            const hostname = s.hostname || s.mac || '—';
            const safeHostname = hostname.replace(/'/g, "\\'").replace(/"/g, '&quot;');
            const safeMac = (s.mac || '').replace(/'/g, "\\'");
            return `
            <tr class="hover:bg-white/[0.01] transition-colors border-t border-white/5">
                <td class="py-4 px-4">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-slate-800/80 flex items-center justify-center border border-white/5 shrink-0">
                            <i data-lucide="wifi" class="w-5 h-5 text-slate-400"></i>
                        </div>
                        <div class="min-w-0">
                            <div class="font-bold text-sm text-white truncate">${escHtml(hostname)}</div>
                            <div class="text-[12px] text-slate-500 font-mono">${escHtml(s.mac || '')}</div>
                        </div>
                    </div>
                </td>
                <td class="py-4 px-4">
                    <div class="text-sm text-slate-300">${escHtml(s.ap_name || '—')}</div>
                    <div class="text-[12px] text-slate-500">${escHtml(s.ssid || '—')}</div>
                </td>
                <td class="py-4 px-4 whitespace-nowrap">
                    <span class="text-xs font-mono text-purple-400">${escHtml(s.band || '—')}</span>
                    <span class="text-xs text-slate-500 ml-1">Ch${s.channel || '?'}</span>
                </td>
                <td class="py-4 px-4">
                    <div class="flex items-center gap-2">
                        <div class="w-2 h-2 rounded-full ${rssiDot(s.rssi)} shrink-0"></div>
                        <span class="text-sm font-mono ${rssiColor(s.rssi)}">${s.rssi || 0}dBm</span>
                    </div>
                </td>
                <td class="py-4 px-4">
                    <span class="text-xs font-mono text-slate-400">${fmtRate(s.rx_rate)}/${fmtRate(s.tx_rate)} Mbps</span>
                </td>
                <td class="py-4 px-4 whitespace-nowrap">
                    <span class="text-xs text-slate-400">${timeSince(s.connected_at)}</span>
                </td>
                <td class="py-4 px-4 text-right">
                    <button onclick="addToWatchlistQuick('${safeMac}','${safeHostname}')" class="text-slate-500 hover:text-amber-400 transition p-1" title="<?= __('stalker.add_to_watchlist') ?>">
                        <i data-lucide="${watchIcon}" class="w-4 h-4"></i>
                    </button>
                </td>
            </tr>`;
        }).join('');

        lucide.createIcons();
    }

    async function loadRoaming() {
        const params = new URLSearchParams({
            action: 'roaming',
            time: currentTimeFilter
        });

        let data = [];
        try {
            const resp = await fetch('api_stalker.php?' + params);
            const json = await resp.json();
            data = json.data || [];
        } catch (e) {
            data = [];
        }

        document.getElementById('roaming-count').textContent = data.length;
        const body = document.getElementById('roaming-body');

        if (data.length === 0) {
            body.innerHTML = '<div class="text-center py-8 text-slate-500">Brak zdarzen roamingu w wybranym okresie</div>';
            return;
        }

        body.innerHTML = data.map(r => `
            <div class="flex items-center gap-4 py-3 border-t border-white/5 hover:bg-white/[0.01] transition-colors rounded-xl px-2">
                <div class="w-10 h-10 rounded-xl bg-amber-500/10 flex items-center justify-center border border-amber-500/20 shrink-0">
                    <i data-lucide="repeat" class="w-5 h-5 text-amber-400"></i>
                </div>
                <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-2 flex-wrap">
                        <span class="font-bold text-sm text-white">${escHtml(r.hostname || r.mac || '—')}</span>
                        <span class="text-xs text-slate-500 font-mono">${escHtml(r.from_ap || '—')}</span>
                        <i data-lucide="arrow-right" class="w-3 h-3 text-amber-400 shrink-0"></i>
                        <span class="text-xs text-slate-300 font-mono">${escHtml(r.to_ap || '—')}</span>
                    </div>
                    <div class="text-[12px] text-slate-500 mt-0.5 font-mono">
                        RSSI: ${r.rssi_before || 0}dBm &rarr; ${r.rssi_after || 0}dBm
                        &middot; Ch${r.from_channel || '?'} &rarr; Ch${r.to_channel || '?'}
                        &middot; <span class="font-mono text-slate-400">${escHtml(r.mac || '')}</span>
                    </div>
                </div>
                <div class="text-xs text-slate-500 whitespace-nowrap shrink-0">${timeSince(r.roamed_at)}</div>
            </div>
        `).join('');

        lucide.createIcons();
    }

    async function pollStalker() {
        try {
            await fetch('api_stalker.php?action=poll', { method: 'POST' });
        } catch (e) {}
        loadSessions();
        loadRoaming();
    }

    // Watchlist Modal
    function openWatchlistModal() {
        const modal = document.getElementById('watchlistModal');
        modal.classList.remove('hidden');
        modal.classList.add('flex');
        loadWatchlist();
        lucide.createIcons();
    }

    function closeWatchlistModal() {
        const modal = document.getElementById('watchlistModal');
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    }

    async function loadWatchlist() {
        let data = [];
        try {
            const resp = await fetch('api_stalker.php?action=watchlist');
            const json = await resp.json();
            data = json.data || [];
        } catch (e) {
            data = [];
        }

        const body = document.getElementById('watchlist-body');

        if (data.length === 0) {
            body.innerHTML = '<div class="text-center text-slate-500 py-6">Watchlist jest pusta. Dodaj urzadzenie powyzej.</div>';
            return;
        }

        body.innerHTML = `
            <table class="w-full">
                <thead>
                    <tr class="text-xs text-slate-500 uppercase tracking-wider">
                        <th class="text-left py-2 px-3">MAC</th>
                        <th class="text-left py-2 px-3">Etykieta</th>
                        <th class="text-left py-2 px-3">Powiadomienia</th>
                        <th class="py-2 px-3 w-10"></th>
                    </tr>
                </thead>
                <tbody>
                    ${data.map(w => `
                    <tr class="border-t border-white/5 hover:bg-white/[0.01] transition-colors">
                        <td class="py-3 px-3 text-sm font-mono text-white">${escHtml(w.mac || '')}</td>
                        <td class="py-3 px-3 text-sm text-slate-400">${escHtml(w.label || '—')}</td>
                        <td class="py-3 px-3">
                            <span class="text-xs font-bold ${w.notify ? 'text-emerald-400' : 'text-slate-500'}">
                                ${w.notify ? 'Aktywne' : 'Wylaczone'}
                            </span>
                        </td>
                        <td class="py-3 px-3 text-right">
                            <button onclick="removeFromWatchlist(${parseInt(w.id)})" class="text-red-400 hover:text-red-300 transition p-1" title="Usun z watchlist">
                                <i data-lucide="trash-2" class="w-4 h-4"></i>
                            </button>
                        </td>
                    </tr>`).join('')}
                </tbody>
            </table>`;

        lucide.createIcons();
    }

    async function addToWatchlist() {
        const mac = document.getElementById('watch-mac').value.trim();
        const label = document.getElementById('watch-label').value.trim();
        if (!mac) {
            document.getElementById('watch-mac').focus();
            return;
        }
        try {
            await fetch('api_stalker.php?action=watchlist', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ mac, label })
            });
        } catch (e) {}
        document.getElementById('watch-mac').value = '';
        document.getElementById('watch-label').value = '';
        loadWatchlist();
        loadSessions(); // refresh eye icons
    }

    async function addToWatchlistQuick(mac, label) {
        try {
            await fetch('api_stalker.php?action=watchlist', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ mac, label })
            });
        } catch (e) {}
        loadSessions();
    }

    async function removeFromWatchlist(id) {
        try {
            await fetch('api_stalker.php?action=watchlist', {
                method: 'DELETE',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id })
            });
        } catch (e) {}
        loadWatchlist();
        loadSessions(); // refresh eye icons
    }

    // HTML escape helper
    function escHtml(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    // Init on page load
    document.addEventListener('DOMContentLoaded', () => {
        pollStalker();
        setInterval(pollStalker, 30000);
    });

    // Render initial Lucide icons (for static elements)
    lucide.createIcons();
    </script>
</body>
</html>
