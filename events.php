<?php
/** Created by Łukasz Misiura (c) 2025 | dev.lm-ads.com **/
require_once 'config.php';
require_once 'db.php';
require_once 'functions.php';

$title = __('history.history_timeline');
$all_events = get_recent_events(100, false); // Pokaż wszystko, nawet "wyczyszczone"
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= __('history.title') ?> | MiniDash</title>
    <link rel="icon" type="image/png" href="img/favicon.png">
    <link rel="stylesheet" href="dashboard.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="assets/css/fonts.css">
    <script src="assets/js/lucide.min.js"></script>
</head>
<body class="pt-24 pb-12 antialiased">
    <?php render_nav(__('history.title')); ?>
    
    <div class="max-w-4xl mx-auto px-6">
        <div class="flex items-center justify-between mb-8">
            <div>
                <h2 class="text-3xl font-black text-white tracking-tight"><?= __('history.subtitle') ?></h2>
                <p class="text-slate-500 mt-1 font-medium">Historia połączeń i rozłączeń wszystkich urządzeń</p>
            </div>
            <button onclick="location.href='index.php'" class="p-3 bg-white/5 border border-white/10 rounded-2xl text-slate-400 hover:text-white transition">
                <i data-lucide="arrow-left" class="w-6 h-6"></i>
            </button>
        </div>

        <?php if (empty($all_events)): ?>
            <div class="py-40 text-center bg-slate-900/40 rounded-3xl border border-white/5 border-dashed">
                <div class="w-20 h-20 bg-slate-800 rounded-full flex items-center justify-center mx-auto mb-6">
                    <i data-lucide="bell-off" class="w-10 h-10 text-slate-600"></i>
                </div>
                <h3 class="text-xl font-bold text-slate-400"><?= __('history.no_events') ?></h3>
                <p class="text-slate-600 mt-2">Historia zostanie uzupełniona przy kolejnych zmianach statusu urządzeń.</p>
            </div>
        <?php else: ?>
            <div class="space-y-4">
                <?php foreach ($all_events as $ev): 
                    $is_up = ($ev['status'] === 'on');
                    $color = $is_up ? 'emerald' : 'red';
                    $icon = $is_up ? 'check-circle' : 'alert-circle';
                ?>
                    <div class="p-6 rounded-3xl bg-slate-900/40 border border-white/5 hover:border-white/10 transition-all flex items-center gap-6 group">
                        <div class="w-14 h-14 rounded-2xl bg-<?= $color ?>-500/10 text-<?= $color ?>-400 flex items-center justify-center flex-shrink-0 shadow-lg shadow-<?= $color ?>-500/5">
                            <i data-lucide="<?= $icon ?>" class="w-7 h-7"></i>
                        </div>
                        <div class="flex-grow">
                            <div class="flex items-center gap-3 mb-1">
                                <span class="text-xs font-black uppercase tracking-widest text-<?= $color ?>-500 bg-<?= $color ?>-500/10 px-2 py-0.5 rounded-md">
                                    <?= $is_up ? __('common.online') : __('common.offline') ?>
                                </span>
                                <span class="text-sm font-mono text-slate-500"><?= $ev['mac'] ?></span>
                            </div>
                            <h3 class="text-lg font-bold text-white"><?= htmlspecialchars($ev['deviceName']) ?></h3>
                        </div>
                        <div class="text-right">
                            <div class="text-base font-mono text-slate-200"><?= date('H:i:s', strtotime($ev['timestamp'])) ?></div>
                            <div class="text-xs font-mono text-slate-500 mt-1"><?= date('d.m.Y', strtotime($ev['timestamp'])) ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <script>lucide.createIcons();</script>
</body>
</html>




