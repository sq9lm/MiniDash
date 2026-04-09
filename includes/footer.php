<!-- MiniDash Footer -->
<?php
$git_hash = '';
$git_hash_full = '';
if (is_dir(__DIR__ . '/../.git')) {
    $hash = @exec('git -C "' . __DIR__ . '/.." rev-parse HEAD 2>/dev/null');
    if ($hash) {
        $git_hash = substr($hash, 0, 7);
        $git_hash_full = $hash;
    }
}

// Get git tags for changelog
$git_tags = [];
if (is_dir(__DIR__ . '/../.git')) {
    $tags_raw = [];
    @exec('git -C "' . __DIR__ . '/.." log --oneline --decorate=short -50 2>/dev/null', $tags_raw);
    foreach ($tags_raw as $line) {
        $git_tags[] = htmlspecialchars($line);
    }
}
?>
<footer class="mt-12 border-t border-white/5">
    <div class="max-w-7xl mx-auto px-6 py-6 flex items-end justify-between">
        <div>
            <div class="text-xs text-slate-500"><a href="https://www.lm-ads.com" target="_blank" class="hover:text-slate-300 transition">LM-Networks</a> &copy; <?= date('Y') ?> &middot; Wszelkie prawa zastrzezone</div>
            <div class="text-[10px] text-slate-600 mt-1">Czesc ekosystemu <a href="https://www.lm-ads.com" target="_blank" class="hover:text-slate-400 transition">LuMiGRAF Solutions</a> obejmujacego rowniez narzedzia deweloperskie</div>
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
        <div class="space-y-1 font-mono text-xs">
            <?php if (!empty($git_tags)): ?>
                <?php foreach ($git_tags as $line): ?>
                    <div class="py-1.5 px-3 rounded-lg hover:bg-white/[0.02] text-slate-400 border-l-2 <?= str_contains($line, 'tag:') ? 'border-purple-500 text-white bg-purple-500/5' : 'border-transparent' ?>"><?= $line ?></div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="text-slate-500 py-4 text-center">Brak historii git</div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
function openChangelogModal() {
    document.getElementById('changelogModal').classList.remove('hidden');
    document.getElementById('changelogModal').classList.add('flex');
    if (typeof lucide !== 'undefined') lucide.createIcons();
}
function closeChangelogModal() {
    document.getElementById('changelogModal').classList.add('hidden');
    document.getElementById('changelogModal').classList.remove('flex');
}
</script>
