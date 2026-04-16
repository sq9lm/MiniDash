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
    <title>System Logs</title>
    <link rel="icon" type="image/png" href="img/favicon.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="assets/css/fonts.css">
    <link rel="stylesheet" href="dashboard.css">
    <script src="assets/js/lucide.min.js"></script>
</head>
<body class="custom-scrollbar">
    <?php render_nav("Logs", $navbar_stats); ?>

    <div class="max-w-7xl mx-auto p-4 md:p-8">
        <!-- Page Header -->
        <div class="mb-8 flex flex-col md:flex-row md:items-center justify-between gap-4">
            <div>
                <h1 class="text-3xl font-black text-white mb-2 flex items-center gap-3">
                    <i data-lucide="file-text" class="w-8 h-8 text-amber-400"></i>
                    <?= __('logs.title') ?>
                </h1>
                <p class="text-slate-500 text-sm"><?= __('logs.subtitle') ?></p>
            </div>

            <!-- Severity Filter -->
            <div class="flex items-center gap-2">
                <span class="text-xs font-bold text-slate-500 uppercase tracking-widest hidden md:block"><?= __('logs.filter_label') ?></span>
                <select id="levelFilter" onchange="loadLogs()" class="bg-slate-900 border border-white/10 rounded-xl px-4 py-2 text-xs text-white font-bold uppercase tracking-wider focus:outline-none focus:border-amber-500 transition-colors cursor-pointer">
                    <option value=""><?= __('logs.all_levels') ?></option>
                    <option value="INFO">Info</option>
                    <option value="WARNING">Warning</option>
                    <option value="ERROR">Error</option>
                    <option value="CRITICAL">Critical</option>
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
                    <span id="loadStatus" class="text-xs text-slate-600"></span>
                </div>
                <!-- Pagination Controls Top -->
                <div class="flex items-center gap-2 text-xs">
                    <button onclick="changePage(-1)" id="prevBtn" class="p-2 hover:bg-white/5 rounded-lg text-slate-400 hover:text-white disabled:opacity-50 disabled:pointer-events-none" disabled>
                        <i data-lucide="chevron-left" class="w-4 h-4"></i>
                    </button>
                    <span class="text-slate-500">Strona <span id="pageNum" class="text-white font-bold">1</span></span>
                    <button onclick="changePage(1)" id="nextBtn" class="p-2 hover:bg-white/5 rounded-lg text-slate-400 hover:text-white disabled:opacity-50 disabled:pointer-events-none" disabled>
                        <i data-lucide="chevron-right" class="w-4 h-4"></i>
                    </button>
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
                    <tbody id="logBody" class="divide-y divide-white/[0.02] text-xs font-mono">
                        <tr>
                            <td colspan="4" class="px-6 py-12 text-center">
                                <div class="flex items-center justify-center gap-3 text-slate-500">
                                    <svg class="animate-spin h-5 w-5" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" fill="none"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                                    <?= __('logs.title') ?>...
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Footer Pagination -->
            <div class="p-4 border-t border-white/5 flex items-center justify-between bg-slate-950/30 text-xs text-slate-500">
                <div class="flex items-center gap-2">
                    <span>Wierszy na stronę:</span>
                    <select id="limitSelect" onchange="currentPage=1; loadLogs()" class="bg-slate-900 border border-white/10 rounded px-2 py-1 text-white focus:outline-none focus:border-amber-500">
                        <option value="25">25</option>
                        <option value="50">50</option>
                        <option value="100" selected>100</option>
                        <option value="500">500</option>
                    </select>
                </div>
                <div class="flex items-center gap-1">
                    <button onclick="changePage(-1)" class="prevBtnBot p-1.5 hover:bg-white/5 rounded-lg text-slate-400 hover:text-white disabled:opacity-50 disabled:pointer-events-none" disabled>
                        <i data-lucide="chevron-left" class="w-4 h-4"></i>
                    </button>
                    <button onclick="changePage(1)" class="nextBtnBot p-1.5 hover:bg-white/5 rounded-lg text-slate-400 hover:text-white disabled:opacity-50 disabled:pointer-events-none" disabled>
                        <i data-lucide="chevron-right" class="w-4 h-4"></i>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Log Detail Modal -->
    <div id="logDetailModal" class="modal-overlay" onclick="closeModal('logDetailModal')">
        <div class="modal-container w-[600px] max-w-[95vw]" onclick="event.stopPropagation()">
            <div class="modal-header border-b border-white/5 bg-slate-900/50">
                <h2 class="text-sm font-bold text-white flex items-center gap-3" id="modal-title-date">
                    <?= __('logs.event_detail_title') ?>
                </h2>
                <button onclick="closeModal('logDetailModal')" class="p-2 hover:bg-white/5 rounded-xl transition text-slate-500 hover:text-white">
                    <i data-lucide="x" class="w-5 h-5"></i>
                </button>
            </div>
            <div class="modal-body p-6 space-y-6">
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
                <div class="bg-slate-950/50 rounded-xl p-4 border border-white/5">
                    <h3 class="text-slate-500 text-[12px] font-bold uppercase tracking-widest mb-2">Pełna treść</h3>
                    <p id="modal-message" class="text-slate-300 font-mono text-xs break-all leading-relaxed"></p>
                </div>
                <div id="modal-raw" class="bg-slate-950/50 rounded-xl p-4 border border-white/5 hidden">
                    <h3 class="text-slate-500 text-[12px] font-bold uppercase tracking-widest mb-2">Raw JSON</h3>
                    <pre id="modal-raw-json" class="text-slate-400 font-mono text-[10px] break-all leading-relaxed max-h-60 overflow-auto custom-scrollbar"></pre>
                </div>
            </div>
        </div>
    </div>

    <script>
        lucide.createIcons();

        let allLogs = [];
        let currentPage = 1;
        let eventsLoaded = false;
        let alarmsLoaded = false;

        function getLimit() { return parseInt(document.getElementById('limitSelect').value) || 100; }
        function getLevel() { return document.getElementById('levelFilter').value; }

        function sevClass(s) {
            if (s === 'CRITICAL') return 'bg-rose-600 text-white shadow-lg shadow-rose-500/20 border border-rose-500 ring-1 ring-white/10';
            if (s === 'ERROR') return 'bg-red-500/10 text-red-400 border border-red-500/20';
            if (s === 'WARNING') return 'bg-amber-500/10 text-amber-400 border border-amber-500/20';
            if (s === 'INFO') return 'bg-blue-500/10 text-blue-400 border border-blue-500/20';
            return 'bg-slate-800 text-slate-300';
        }

        function renderTable() {
            const level = getLevel();
            const limit = getLimit();
            let filtered = level ? allLogs.filter(l => l.severity === level) : allLogs;

            // Sort by timestamp DESC
            filtered.sort((a, b) => (b.ts || 0) - (a.ts || 0));

            const total = filtered.length;
            const offset = (currentPage - 1) * limit;
            const page = filtered.slice(offset, offset + limit);
            const hasNext = (offset + limit) < total;
            const hasPrev = currentPage > 1;

            document.getElementById('pageNum').textContent = currentPage;
            document.getElementById('prevBtn').disabled = !hasPrev;
            document.getElementById('nextBtn').disabled = !hasNext;
            document.querySelectorAll('.prevBtnBot').forEach(b => b.disabled = !hasPrev);
            document.querySelectorAll('.nextBtnBot').forEach(b => b.disabled = !hasNext);

            const tbody = document.getElementById('logBody');
            if (page.length === 0) {
                tbody.innerHTML = '<tr><td colspan="4" class="px-6 py-8 text-center text-slate-500 italic"><?= __('logs.no_events') ?></td></tr>';
                return;
            }

            tbody.innerHTML = page.map((l, i) => {
                const json = JSON.stringify(l).replace(/'/g, '&#39;').replace(/"/g, '&quot;');
                return `<tr onclick='openLogDetails(${JSON.stringify(l).replace(/'/g, "\\'")})'
                    class="hover:bg-white/[0.02] transition-colors group cursor-pointer border-l-2 border-transparent hover:border-amber-500">
                    <td class="px-6 py-3 text-slate-400 whitespace-nowrap">${l.date}</td>
                    <td class="px-6 py-3 text-slate-500">${l.category}</td>
                    <td class="px-6 py-3">
                        <span class="px-2 py-0.5 rounded text-[12px] font-bold uppercase tracking-wider ${sevClass(l.severity)} inline-block min-w-[60px] text-center">${l.severity}</span>
                    </td>
                    <td class="px-6 py-3 text-slate-300 break-all group-hover:text-white transition-colors">${escHtml(l.message)}</td>
                </tr>`;
            }).join('');
        }

        function escHtml(s) {
            const d = document.createElement('div');
            d.textContent = s || '';
            return d.innerHTML;
        }

        function changePage(dir) {
            currentPage = Math.max(1, currentPage + dir);
            renderTable();
        }

        function mergeLogs(newItems) {
            const ids = new Set(allLogs.map(l => l.id));
            for (const item of newItems) {
                if (!ids.has(item.id)) {
                    allLogs.push(item);
                    ids.add(item.id);
                }
            }
        }

        function loadLogs() {
            currentPage = 1;
            allLogs = [];
            eventsLoaded = false;
            alarmsLoaded = false;

            const status = document.getElementById('loadStatus');
            status.textContent = 'Loading events...';

            const limit = getLimit();

            // Fetch events and alarms in parallel
            fetch(`api_logs.php?type=event&limit=${limit}`)
                .then(r => r.json())
                .then(d => {
                    if (d.success && d.data) {
                        mergeLogs(d.data);
                        eventsLoaded = true;
                        status.textContent = alarmsLoaded ? '' : 'Events loaded, waiting for alarms...';
                        renderTable();
                    }
                })
                .catch(() => { eventsLoaded = true; });

            fetch(`api_logs.php?type=alarm&limit=${limit}`)
                .then(r => r.json())
                .then(d => {
                    if (d.success && d.data) {
                        mergeLogs(d.data);
                        alarmsLoaded = true;
                        status.textContent = '';
                        renderTable();
                    }
                })
                .catch(() => {
                    alarmsLoaded = true;
                    status.textContent = '';
                });
        }

        function openLogDetails(log) {
            document.getElementById('modal-title-date').innerText = log.date || '<?= __('logs.event_detail_title') ?>';
            document.getElementById('modal-severity').innerText = log.severity;
            document.getElementById('modal-category').innerText = log.category;
            document.getElementById('modal-message').innerText = log.message;

            const sevEl = document.getElementById('modal-severity');
            sevEl.className = 'text-sm font-bold text-right';
            if (log.severity === 'CRITICAL' || log.severity === 'ERROR') sevEl.classList.add('text-red-400');
            else if (log.severity === 'WARNING') sevEl.classList.add('text-amber-400');
            else sevEl.classList.add('text-blue-400');

            if (log.raw) {
                document.getElementById('modal-raw').classList.remove('hidden');
                document.getElementById('modal-raw-json').textContent = JSON.stringify(log.raw, null, 2);
            } else {
                document.getElementById('modal-raw').classList.add('hidden');
            }

            document.getElementById('logDetailModal').classList.add('active');
        }

        function closeModal(id) {
            document.getElementById(id).classList.remove('active');
        }

        // Start loading immediately
        loadLogs();
    </script>
    <?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
