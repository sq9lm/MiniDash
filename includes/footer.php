<!-- MiniDash Footer | Created by Łukasz Misiura (c) 2025 | dev.lm-ads.com -->
<?php
$git_hash = '';
$git_dir = __DIR__ . '/../.git';
if (is_dir($git_dir)) {
    // Read HEAD ref
    $head = @file_get_contents($git_dir . '/HEAD');
    if ($head && str_starts_with(trim($head), 'ref: ')) {
        $ref = trim(substr(trim($head), 5));
        $hash = @file_get_contents($git_dir . '/' . $ref);
        if ($hash) $git_hash = substr(trim($hash), 0, 7);
    } elseif ($head) {
        $git_hash = substr(trim($head), 0, 7);
    }
}

// Read changelog from RELEASE_NOTES.md
$changelog_lines = [];
$release_file = __DIR__ . '/../RELEASE_NOTES.md';
if (file_exists($release_file)) {
    $changelog_lines = file($release_file, FILE_IGNORE_NEW_LINES);
}
?>
<footer class="mt-12 border-t border-white/5">
    <div class="max-w-7xl mx-auto px-6 py-6 flex items-end justify-between">
        <div>
            <div class="text-xs text-slate-500"><a href="https://www.lm-ads.com" target="_blank" class="hover:text-slate-300 transition">LM-Networks</a> &copy; 2025-2026 &middot; Wszelkie prawa zastrzezone</div>
            <div class="text-[10px] text-slate-600 mt-1">Czesc ekosystemu LuMiGRAF Solutions obejmujacego rowniez <a href="https://dev.lm-ads.com" target="_blank" class="hover:text-slate-400 transition">narzedzia deweloperskie</a></div>
        </div>
        <div class="text-right">
            <a href="#" onclick="openChangelogModal(); return false;" class="text-xs text-slate-600 font-mono hover:text-slate-400 transition cursor-pointer">
                MiniDash v<?= MINIDASH_VERSION ?><?php if ($git_hash): ?> <span class="text-slate-700">#<?= $git_hash ?></span><?php endif; ?>
            </a>
        </div>
    </div>
</footer>

<!-- Changelog Modal -->
<div id="changelogModal" class="fixed inset-0 bg-black/70 backdrop-blur-sm z-50 hidden items-center justify-center" onclick="if(event.target===this)closeChangelogModal()">
    <div class="bg-slate-900/95 backdrop-blur-xl border border-white/10 rounded-3xl p-8 w-full max-w-2xl max-h-[80vh] overflow-y-auto shadow-2xl">
        <div class="flex items-center justify-between mb-6">
            <h2 class="text-xl font-bold text-white flex items-center gap-3">
                <i data-lucide="git-branch" class="w-6 h-6 text-purple-400"></i>
                Changelog
            </h2>
            <button onclick="closeChangelogModal()" class="text-slate-500 hover:text-white transition">
                <i data-lucide="x" class="w-6 h-6"></i>
            </button>
        </div>
        <div class="mb-4">
            <div class="text-sm text-slate-400">Wersja <span class="text-white font-bold">v<?= MINIDASH_VERSION ?></span><?php if ($git_hash): ?> &middot; <span class="font-mono text-purple-400">#<?= $git_hash ?></span><?php endif; ?></div>
        </div>
        <div class="space-y-1 text-sm max-h-[60vh] overflow-y-auto custom-scrollbar pr-2">
            <?php if (!empty($changelog_lines)): ?>
                <?php foreach ($changelog_lines as $line):
                    $line = htmlspecialchars($line);
                    // Style markdown headings
                    if (str_starts_with($line, '## ')) {
                        $text = substr($line, 3);
                        echo '<div class="pt-6 pb-2 text-lg font-bold text-white border-b border-white/10 mb-2">' . $text . '</div>';
                    } elseif (str_starts_with($line, '### ')) {
                        $text = substr($line, 4);
                        echo '<div class="pt-4 pb-1 text-sm font-bold text-purple-400">' . $text . '</div>';
                    } elseif (str_starts_with($line, '# ')) {
                        // Skip main title
                    } elseif (str_starts_with($line, '- ')) {
                        $text = substr($line, 2);
                        echo '<div class="py-0.5 pl-4 text-xs text-slate-400 before:content-[\'•\'] before:text-purple-500 before:mr-2">' . $text . '</div>';
                    } elseif (str_starts_with($line, '---')) {
                        echo '<div class="my-4 h-px bg-white/5"></div>';
                    } elseif (trim($line) !== '') {
                        echo '<div class="py-0.5 text-xs text-slate-500">' . $line . '</div>';
                    }
                endforeach; ?>
            <?php else: ?>
                <div class="text-slate-500 py-4 text-center">Brak pliku RELEASE_NOTES.md</div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Toast Notifications -->
<div id="toast-container" class="fixed bottom-8 left-8 z-[200] flex flex-col gap-3 pointer-events-none"></div>

<script>
function showToast(message, type = 'success') {
    const container = document.getElementById('toast-container');
    if (!container) return;
    
    const toast = document.createElement('div');
    
    const colors = {
        success: {
            bg: 'bg-emerald-950/40',
            border: 'border-emerald-500/30',
            text: 'text-emerald-400',
            icon: 'check-circle',
            glow: 'shadow-[0_0_20px_rgba(16,185,129,0.1)]'
        },
        error: {
            bg: 'bg-red-950/40',
            border: 'border-red-500/30',
            text: 'text-red-400',
            icon: 'alert-circle',
            glow: 'shadow-[0_0_20px_rgba(239,68,68,0.1)]'
        }
    };
    
    const config = colors[type] || colors.success;
    
    toast.className = `flex items-center gap-4 px-6 py-4 rounded-2xl backdrop-blur-xl border ${config.bg} ${config.border} ${config.text} ${config.glow} transform -translate-x-full opacity-0 transition-all duration-500 pointer-events-auto cursor-pointer`;
    toast.innerHTML = `
        <div class="flex-shrink-0"><i data-lucide="${config.icon}" class="w-5 h-5"></i></div>
        <span class="text-xs font-black uppercase tracking-widest leading-none">${message}</span>
    `;
    
    container.appendChild(toast);
    if (typeof lucide !== 'undefined') lucide.createIcons();
    
    // Animate in
    requestAnimationFrame(() => {
        toast.classList.remove('-translate-x-full', 'opacity-0');
    });
    
    // Auto remove
    const timer = setTimeout(() => hideToast(toast), 5000);
    
    toast.onclick = () => {
        clearTimeout(timer);
        hideToast(toast);
    };
}

function hideToast(toast) {
    toast.classList.add('-translate-x-full', 'opacity-0');
    setTimeout(() => toast.remove(), 500);
}

// Auto-show session toasts
window.addEventListener('load', () => {
    <?php if (isset($_SESSION['success'])): ?>
        showToast("<?= htmlspecialchars($_SESSION['success']) ?>", 'success');
        <?php unset($_SESSION['success']); ?>
    <?php endif; ?>
    <?php if (isset($_SESSION['error'])): ?>
        showToast("<?= htmlspecialchars($_SESSION['error']) ?>", 'error');
        <?php unset($_SESSION['error']); ?>
    <?php endif; ?>
});

function openChangelogModal() {
    document.getElementById('changelogModal').classList.remove('hidden');
    document.getElementById('changelogModal').classList.add('flex');
    if (typeof lucide !== 'undefined') lucide.createIcons();
}
function closeChangelogModal() {
    document.getElementById('changelogModal').classList.add('hidden');
    document.getElementById('changelogModal').classList.remove('flex');
}

// Global Pagination Component
window.MiniPagination = {
    instances: {},

    create: function(containerId, items, renderFn, opts = {}) {
        const defaults = { pageSize: parseInt(localStorage.getItem('minidash_pageSize') || '50'), pageSizes: [25, 50, 100, 200] };
        const o = {...defaults, ...opts};
        const inst = { items, renderFn, page: 1, pageSize: o.pageSize, pageSizes: o.pageSizes };
        this.instances[containerId] = inst;
        this.render(containerId);
    },

    render: function(containerId) {
        const inst = this.instances[containerId];
        if (!inst) return;
        const total = inst.items.length;
        const totalPages = Math.max(1, Math.ceil(total / inst.pageSize));
        if (inst.page > totalPages) inst.page = totalPages;
        const start = (inst.page - 1) * inst.pageSize;
        const pageItems = inst.items.slice(start, start + inst.pageSize);

        // Render items
        inst.renderFn(pageItems);

        // Render pagination controls
        const container = document.getElementById(containerId);
        if (!container) return;

        // Remove old pagination
        const oldPag = container.querySelector('.mini-pagination');
        if (oldPag) oldPag.remove();

        if (total <= inst.pageSizes[0]) return; // No pagination needed for small lists

        const pag = document.createElement('div');
        pag.className = 'mini-pagination flex items-center justify-between px-4 py-3 border-t border-white/5 mt-2';
        pag.innerHTML = `
            <div class="flex items-center gap-2">
                <span class="text-[10px] text-slate-500 uppercase font-bold">Wczytano ${start + 1}-${Math.min(start + inst.pageSize, total)} z ${total}</span>
            </div>
            <div class="flex items-center gap-2">
                <div class="flex gap-1 bg-slate-900/50 rounded-lg p-0.5 border border-white/5">
                    ${inst.pageSizes.map(s => `<button onclick="MiniPagination.setPageSize('${containerId}', ${s})" class="px-2 py-1 rounded text-[10px] font-bold transition ${s === inst.pageSize ? 'bg-blue-600 text-white' : 'text-slate-500 hover:text-white'}">${s}</button>`).join('')}
                </div>
                <button onclick="MiniPagination.prevPage('${containerId}')" class="p-1.5 rounded-lg ${inst.page > 1 ? 'text-slate-400 hover:text-white hover:bg-white/5' : 'text-slate-700 cursor-not-allowed'}" ${inst.page <= 1 ? 'disabled' : ''}>
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                </button>
                <span class="text-[10px] text-slate-400 font-mono min-w-[60px] text-center">${inst.page} / ${totalPages}</span>
                <button onclick="MiniPagination.nextPage('${containerId}')" class="p-1.5 rounded-lg ${inst.page < totalPages ? 'text-slate-400 hover:text-white hover:bg-white/5' : 'text-slate-700 cursor-not-allowed'}" ${inst.page >= totalPages ? 'disabled' : ''}>
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                </button>
            </div>
        `;
        container.appendChild(pag);
    },

    setPageSize: function(containerId, size) {
        const inst = this.instances[containerId];
        if (!inst) return;
        inst.pageSize = size;
        inst.page = 1;
        localStorage.setItem('minidash_pageSize', size);
        this.render(containerId);
    },

    prevPage: function(containerId) {
        const inst = this.instances[containerId];
        if (!inst || inst.page <= 1) return;
        inst.page--;
        this.render(containerId);
    },

    nextPage: function(containerId) {
        const inst = this.instances[containerId];
        if (!inst) return;
        const totalPages = Math.ceil(inst.items.length / inst.pageSize);
        if (inst.page >= totalPages) return;
        inst.page++;
        this.render(containerId);
    },

    updateItems: function(containerId, items) {
        const inst = this.instances[containerId];
        if (!inst) return;
        inst.items = items;
        this.render(containerId);
    }
};
</script>
<?php if (function_exists('render_personal_modal')) render_personal_modal(); ?>
