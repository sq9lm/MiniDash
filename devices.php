<?php
/** Created by Łukasz Misiura (c) 2025 | dev.lm-ads.com **/
require_once 'config.php';
require_once 'db.php';
require_once 'functions.php';
require_once 'includes/navbar_stats.php';

// Get navbar stats for system monitor
$navbar_stats = get_navbar_stats();

// Sprawdzenie czy użytkownik jest zalogowany
if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    header('Location: login.php');
    exit;
}
session_write_close();

// Auto-detect UniFi Protect if not set
if ($config['protect']['enabled'] === null) {
    $isAvailable = is_protect_available();
    $config['protect']['enabled'] = $isAvailable;
    
    // Save the detected state to config.json
    $currentConfig = json_decode(file_get_contents(__DIR__ . '/data/config.json'), true) ?: [];
    if (!isset($currentConfig['protect'])) $currentConfig['protect'] = [];
    $currentConfig['protect']['enabled'] = $isAvailable;
    file_put_contents(__DIR__ . '/data/config.json', json_encode($currentConfig, JSON_PRETTY_PRINT));
}

// Obsługa operacji CRUD i Konfiguracji
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $devices = loadDevices();

    // Akcja: Zapis modułów (Protect, Monitoring ON/OFF)
    if (isset($_POST['action']) && $_POST['action'] === 'save_modules') {
        $currentConfig = json_decode(file_get_contents(__DIR__ . '/data/config.json'), true) ?: [];
        if (!isset($currentConfig['protect'])) $currentConfig['protect'] = [];
        $currentConfig['protect']['enabled'] = isset($_POST['protect_enabled']);
        
        if (!isset($currentConfig['modules'])) $currentConfig['modules'] = [];
        $currentConfig['modules']['monitoring_enabled'] = isset($_POST['monitoring_enabled']);
        
        file_put_contents(__DIR__ . '/data/config.json', json_encode($currentConfig, JSON_PRETTY_PRINT));
        $_SESSION['success'] = __('settings.modules_saved');
        header('Location: devices.php');
        exit;
    }
    
    // Akcja: Zapis konfiguracji
    if (isset($_POST['action']) && $_POST['action'] === 'save_config') {
        // Wczytaj obecną konfigurację do scalenia
        $currentConfig = $config; // $config jest już załadowany w config.php
        
        $newConfig = $currentConfig;
        
        // Aktualizuj tylko to, co przyszło w POST
        if (isset($_POST['controller_url'])) $newConfig['controller_url'] = $_POST['controller_url'];
        if (isset($_POST['api_key'])) $newConfig['api_key'] = $_POST['api_key'];
        if (isset($_POST['site'])) $newConfig['site'] = $_POST['site'];
        
        if (isset($_POST['email_notifications_update'])) {
            $newConfig['email_notifications']['enabled'] = isset($_POST['email_enabled']);
        }
        
        if (isset($_POST['telegram_notifications_update'])) {
            $newConfig['telegram_notifications']['enabled'] = isset($_POST['tg_enabled']);
        }

        // Zapisz do pliku JSON
        if (!is_dir(__DIR__ . '/data')) mkdir(__DIR__ . '/data', 0777, true);
        file_put_contents(__DIR__ . '/data/config.json', json_encode($newConfig, JSON_PRETTY_PRINT));
        
        $_SESSION['success'] = __('settings.saved_successfully');
        header('Location: devices.php');
        exit;
    }

    // Akcja: Zapis Hostów Ping
    if (isset($_POST['action']) && $_POST['action'] === 'save_ping_hosts') {
        $currentConfig = $config;
        
        $names = $_POST['ping_name'] ?? [];
        $hosts = $_POST['ping_host'] ?? [];
        
        $newHosts = [];
        for ($i = 0; $i < count($names); $i++) {
            if (!empty($names[$i]) && !empty($hosts[$i])) {
                $newHosts[] = ['name' => $names[$i], 'host' => $hosts[$i]];
            }
        }
        
        $currentConfig['ping_hosts'] = $newHosts;

        if (!is_dir(__DIR__ . '/data')) mkdir(__DIR__ . '/data', 0777, true);
        file_put_contents(__DIR__ . '/data/config.json', json_encode($currentConfig, JSON_PRETTY_PRINT));

        $_SESSION['success'] = __('settings.ping_hosts_saved');
        header('Location: devices.php');
        exit;
    }

    // Akcja: Zapis retencji danych
    if (isset($_POST['action']) && $_POST['action'] === 'save_purge') {
        $currentConfig = $config;
        $currentConfig['purge_days'] = [
            'wan_stats' => max(7, (int)($_POST['purge_wan_stats'] ?? 90)),
            'client_history' => max(7, (int)($_POST['purge_client_history'] ?? 30)),
            'events' => max(7, (int)($_POST['purge_events'] ?? 30)),
            'stalker_sessions' => max(7, (int)($_POST['purge_stalker_sessions'] ?? 60)),
            'stalker_roaming' => max(7, (int)($_POST['purge_stalker_roaming'] ?? 60)),
            'device_status_history' => max(7, (int)($_POST['purge_device_status_history'] ?? 90)),
            'login_history' => max(30, (int)($_POST['purge_login_history'] ?? 180)),
        ];
        require_once __DIR__ . '/crypto.php';
        encrypt_config($currentConfig);
        file_put_contents(__DIR__ . '/data/config.json', json_encode($currentConfig, JSON_PRETTY_PRINT));
        $_SESSION['success'] = __('settings.retention_saved');
        header('Location: devices.php');
        exit;
    }

    // Akcja: Zapis ustawień bezpieczeństwa
    if (isset($_POST['action']) && $_POST['action'] === 'save_security') {
        $currentConfig = $config;
        $currentConfig['session_timeout'] = max(5, (int)($_POST['session_timeout'] ?? 60));
        $currentConfig['max_login_attempts'] = max(1, (int)($_POST['max_login_attempts'] ?? 5));
        $currentConfig['lock_duration'] = max(1, (int)($_POST['lock_duration'] ?? 15));
        require_once __DIR__ . '/crypto.php';
        encrypt_config($currentConfig);
        file_put_contents(__DIR__ . '/data/config.json', json_encode($currentConfig, JSON_PRETTY_PRINT));
        $_SESSION['success'] = __('settings.security_saved');
        header('Location: devices.php');
        exit;
    }

    // Akcja: Zapis interwału odświeżania
    if (isset($_POST['action']) && $_POST['action'] === 'save_refresh') {
        $currentConfig = $config;
        $currentConfig['poll_interval'] = max(5, min(120, (int)($_POST['poll_interval'] ?? 30)));
        require_once __DIR__ . '/crypto.php';
        encrypt_config($currentConfig);
        file_put_contents(__DIR__ . '/data/config.json', json_encode($currentConfig, JSON_PRETTY_PRINT));
        $_SESSION['success'] = __('settings.interval_saved');
        header('Location: devices.php');
        exit;
    }

    // Akcje urządzeń
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add':
                $mac = normalize_mac($_POST['mac'] ?? '');
                $name = $_POST['name'] ?? '';
                $vlan = $_POST['vlan'] ?? '';
                
                if ($mac && $name) {
                    $devices[$mac] = [
                        'name' => $name,
                        'vlan' => $vlan,
                        'added_at' => date('Y-m-d H:i:s')
                    ];
                    saveDevices($devices);
                    $_SESSION['success'] = __('settings.asset_saved');
                }
                break;
                
            case 'delete':
                $mac = $_POST['mac'] ?? '';
                if ($mac && isset($devices[$mac])) {
                    unset($devices[$mac]);
                    saveDevices($devices);
                    $_SESSION['success'] = __('settings.asset_deleted');
                }
                break;
        }
    }
    
    header('Location: devices.php');
    exit;
}

$devices = loadDevices();

$siteId = $_SESSION['site_id'] ?? $config['site'] ?? 'default';

// Pobieranie informacji o systemie
$sysinfo = [];
$gateway = null;
try {
    $tradSite = get_trad_site_id($siteId);
    $sys_resp = fetch_api("/proxy/network/api/s/$tradSite/stat/sysinfo");
    $sysinfo = $sys_resp['data'][0] ?? $sys_resp['data'] ?? [];
    
    $dev_resp = fetch_api("/proxy/network/integration/v1/sites/$siteId/devices");
    foreach (($dev_resp['data'] ?? []) as $d) {
        $m = $d['model'] ?? '';
        if (isset($d['wan1']) || in_array($m, ['UDR', 'UDM', 'UXG', 'USG', 'UCG', 'UX', 'UXG-LITE', 'UXG-MAX', 'UDMPRO', 'UDMSE', 'UDM-SE', 'UDM-PRO-MAX'])) {
            $gateway = $d;
            break;
        }
    }
} catch (Throwable $e) {
    // Silent fail for background stats
}

// GET: Vacuum
if (isset($_GET['action']) && $_GET['action'] === 'vacuum') {
    $db->exec('VACUUM');
    $_SESSION['success'] = __('settings.db_optimized');
    header('Location: devices.php');
    exit;
}

// GET: Export config
if (isset($_GET['action']) && $_GET['action'] === 'export_config') {
    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="minidash_config_' . date('Y-m-d') . '.json"');
    echo file_get_contents(__DIR__ . '/data/config.json');
    exit;
}

// GET: Export DB
if (isset($_GET['action']) && $_GET['action'] === 'export_db') {
    $dbFile = __DIR__ . '/data/minidash.db';
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="minidash_' . date('Y-m-d') . '.db"');
    header('Content-Length: ' . filesize($dbFile));
    readfile($dbFile);
    exit;
}

// Statystyki bazy danych
$db_file = __DIR__ . '/data/minidash.db';
$db_size = file_exists($db_file) ? filesize($db_file) : 0;
$db_tables = ['wan_stats', 'client_history', 'events', 'stalker_sessions', 'stalker_roaming', 'login_history', 'device_monitors', 'device_status_history'];
$db_counts = [];
$db_total = 0;
foreach ($db_tables as $t) {
    try { 
        $c = $db->query("SELECT COUNT(*) FROM $t")->fetchColumn(); 
    } catch(Exception $e) { 
        $c = 0; 
    }
    $db_counts[$t] = $c;
    $db_total += $c;
}

?>

<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= __('settings.title') ?></title>
    <link rel="icon" type="image/png" href="img/favicon.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="assets/css/fonts.css">
    <link rel="stylesheet" href="dashboard.css">
    <script src="assets/js/lucide.min.js"></script>
</head>
<body class="custom-scrollbar">
    <?php render_nav(__('settings.nav_title'), $navbar_stats); ?>

    <div class="max-w-6xl mx-auto p-4 md:p-8">

        <?php // Session alerts are now handled by global toast system in footer.php ?>

        <div class="grid grid-cols-1 xl:grid-cols-2 gap-8 mb-10">
            <!-- Karta 1: Ustawienia API -->
            <div class="glass-card p-8 flex flex-col w-full h-full border-blue-500/20 shadow-[0_0_40px_rgba(59,130,246,0.1)] relative overflow-hidden group">
                <div class="absolute top-0 right-0 w-32 h-32 bg-blue-600/5 blur-3xl -mr-16 -mt-16 group-hover:bg-blue-600/10 transition-all duration-700"></div>
                
                <form method="POST" class="flex flex-col h-full relative z-10">
                    <input type="hidden" name="action" value="save_config">
                    <div class="flex items-center justify-between mb-8">
                        <div>
                            <h2 class="text-lg font-black uppercase tracking-[0.2em] text-blue-400 flex items-center gap-3">
                                 <i data-lucide="shield-check" class="w-6 h-6"></i>
                                 <?= __('settings.controller_connection') ?>
                            </h2>
                            <p class="text-slate-500 text-[12px] mt-1 font-bold uppercase tracking-widest"><?= __('settings.controller_desc') ?></p>
                        </div>
                        <span class="px-3 py-1 bg-emerald-500/10 text-emerald-400 text-[12px] font-black rounded-full border border-emerald-500/20 flex items-center gap-2">
                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span>
                            ACTIVE
                        </span>
                    </div>
                    
                    <div class="space-y-6">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div class="md:col-span-2">
                                <label class="block text-[12px] font-black text-slate-500 uppercase tracking-widest mb-2 px-1">Controller URL</label>
                                <input type="text" name="controller_url" value="<?= htmlspecialchars($config['controller_url']) ?>" required
                                    class="w-full px-4 py-3 bg-slate-900/50 border border-white/10 rounded-xl focus:outline-none focus:ring-2 focus:ring-blue-500/30 text-xs font-mono transition-all">
                            </div>
                            <div>
                                <label class="block text-[12px] font-black text-slate-500 uppercase tracking-widest mb-2 px-1">Site ID</label>
                                <input type="text" name="site" value="<?= htmlspecialchars($config['site']) ?>" required
                                    class="w-full px-4 py-3 bg-slate-900/50 border border-white/10 rounded-xl focus:outline-none focus:ring-2 focus:ring-blue-500/30 text-xs font-mono transition-all">
                            </div>
                            <div>
                                <label class="block text-[12px] font-black text-slate-500 uppercase tracking-widest mb-2 px-1">API Key</label>
                                <input type="password" name="api_key" value="<?= htmlspecialchars($config['api_key']) ?>"
                                    class="w-full px-4 py-3 bg-slate-900/50 border border-white/10 rounded-xl focus:outline-none focus:ring-2 focus:ring-blue-500/30 text-xs font-mono transition-all">
                            </div>
                        </div>
                        
                        <div class="pt-4">
                             <button type="submit" class="w-full bg-blue-600 hover:bg-blue-500 text-white font-black py-4 rounded-xl transition shadow-xl shadow-blue-600/20 text-xs uppercase tracking-[0.2em] flex items-center justify-center gap-3">
                                <i data-lucide="refresh-cw" class="w-4 h-4"></i> <?= __('settings.update_config') ?>
                             </button>
                        </div>
                    </div>
                </form>
            </div>
        <!-- Karta 2: Monitoringu Ping -->
            <div class="glass-card p-8 flex flex-col w-full h-full border-purple-500/20 shadow-[0_0_40px_rgba(168,85,247,0.1)] relative overflow-hidden group">
                <div class="absolute top-0 right-0 w-32 h-32 bg-purple-600/5 blur-3xl -mr-16 -mt-16 group-hover:bg-purple-600/10 transition-all duration-700"></div>
                
                <form method="POST" class="flex flex-col h-full relative z-10" id="pingForm">
                    <input type="hidden" name="action" value="save_ping_hosts">
                    <div class="flex items-center justify-between mb-8">
                        <div>
                            <h2 class="text-lg font-black uppercase tracking-[0.2em] text-purple-400 flex items-center gap-3">
                                 <i data-lucide="activity" class="w-6 h-6"></i>
                                 <?= __('settings.ping_monitoring') ?>
                            </h2>
                            <p class="text-slate-500 text-[12px] mt-1 font-bold uppercase tracking-widest"><?= __('settings.ping_desc') ?></p>
                        </div>
                    </div>
                    
                    <div class="space-y-4" id="pingList">
                        <?php 
                        $ping_hosts = $config['ping_hosts'] ?? [
                            ['name' => 'Gateway', 'host' => '10.0.0.1'],
                            ['name' => 'Google DNS', 'host' => '8.8.8.8'],
                            ['name' => 'Cloudflare', 'host' => '1.1.1.1'],
                            ['name' => 'Onet.pl', 'host' => 'onet.pl'],
                            ['name' => 'Wirtualna Polska', 'host' => 'wp.pl']
                        ];
                        foreach ($ping_hosts as $ph): 
                        ?>
                        <div class="flex gap-2 items-center ping-row">
                            <input type="text" name="ping_name[]" value="<?= htmlspecialchars($ph['name']) ?>" placeholder="Nazwa" class="flex-1 px-4 py-2 bg-slate-900/50 border border-white/10 rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500/30 text-xs font-bold transition-all text-slate-200">
                            <input type="text" name="ping_host[]" value="<?= htmlspecialchars($ph['host']) ?>" placeholder="IP / Host" class="flex-1 px-4 py-2 bg-slate-900/50 border border-white/10 rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500/30 text-xs font-mono transition-all text-slate-200">
                            <button type="button" onclick="this.parentElement.remove()" class="p-2 text-slate-500 hover:text-red-400 transition bg-slate-900/50 hover:bg-red-500/10 rounded-lg border border-white/5"><i data-lucide="trash-2" class="w-4 h-4"></i></button>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <div class="pt-4 flex gap-4">
                        <button type="button" onclick="addPingRow()" class="px-4 py-2 bg-slate-800 hover:bg-slate-700 text-slate-300 rounded-xl text-xs font-bold uppercase tracking-widest transition border border-white/5">
                            <?= __('settings.add_row') ?>
                        </button>
                         <button type="submit" class="flex-1 bg-purple-600 hover:bg-purple-500 text-white font-black py-2 rounded-xl transition shadow-xl shadow-purple-600/20 text-xs uppercase tracking-[0.2em] flex items-center justify-center gap-2">
                            <i data-lucide="save" class="w-4 h-4"></i> <?= __('settings.save_list') ?>
                         </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Sekcja: Moduły Systemowe -->
        <div class="glass-card p-8 mb-8 border-indigo-500/20 shadow-[0_0_40px_rgba(79,70,229,0.08)] relative overflow-hidden group">
            <div class="absolute top-0 right-0 w-40 h-40 bg-indigo-600/5 blur-3xl -mr-20 -mt-20 group-hover:bg-indigo-600/10 transition-all duration-700"></div>
            <form method="POST" class="relative z-10">
                <input type="hidden" name="action" value="save_modules">
                <div class="flex items-center justify-between mb-8">
                    <div>
                        <h2 class="text-lg font-black uppercase tracking-[0.2em] text-indigo-400 flex items-center gap-3">
                            <i data-lucide="layout" class="w-6 h-6"></i>
                            <?= __('settings.system_modules') ?>
                        </h2>
                        <p class="text-slate-500 text-[12px] mt-1 font-bold uppercase tracking-widest"><?= __('settings.modules_desc') ?></p>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                    <div class="flex items-center justify-between p-4 bg-slate-900/50 border border-white/5 rounded-2xl hover:bg-slate-900/80 transition-all">
                        <div class="flex items-center gap-4">
                            <div class="w-12 h-12 rounded-xl bg-purple-600/10 flex items-center justify-center text-purple-400">
                                <i data-lucide="video" class="w-6 h-6"></i>
                            </div>
                            <div>
                                <p class="text-sm font-black text-white uppercase tracking-wider"><?= __('settings.protect_module') ?></p>
                                <p class="text-[12px] text-slate-500 font-bold uppercase tracking-widest mt-0.5"><?= __('settings.protect_module_desc') ?></p>
                            </div>
                        </div>
                        <label class="relative inline-flex items-center cursor-pointer">
                            <input type="checkbox" name="protect_enabled" class="sr-only peer" <?= ($config['protect']['enabled'] ?? false) ? 'checked' : '' ?>>
                            <div class="w-11 h-6 bg-slate-700 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-indigo-600"></div>
                        </label>
                    </div>
                    <div class="flex items-center justify-between p-4 bg-slate-900/50 border border-white/5 rounded-2xl hover:bg-slate-900/80 transition-all">
                        <div class="flex items-center gap-4">
                            <div class="w-12 h-12 rounded-xl bg-emerald-600/10 flex items-center justify-center text-emerald-400">
                                <i data-lucide="activity" class="w-6 h-6"></i>
                            </div>
                            <div>
                                <p class="text-sm font-black text-white uppercase tracking-wider"><?= __('settings.monitoring_module') ?></p>
                                <p class="text-[12px] text-slate-500 font-bold uppercase tracking-widest mt-0.5"><?= __('settings.monitoring_module_desc') ?></p>
                            </div>
                        </div>
                        <label class="relative inline-flex items-center cursor-pointer">
                            <input type="checkbox" name="monitoring_enabled" class="sr-only peer" <?= ($config['modules']['monitoring_enabled'] ?? true) ? 'checked' : '' ?>>
                            <div class="w-11 h-6 bg-slate-700 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-emerald-600"></div>
                        </label>
                    </div>
                </div>

                <div class="flex items-center justify-between p-4 bg-slate-900/20 border border-dashed border-white/5 rounded-2xl mt-4 opacity-50">
                    <div class="flex items-center gap-4">
                        <div class="w-12 h-12 rounded-xl bg-slate-800 flex items-center justify-center text-slate-600">
                            <i data-lucide="phone" class="w-6 h-6"></i>
                        </div>
                        <div>
                            <p class="text-sm font-black text-slate-500 uppercase tracking-wider"><?= __('settings.talk_module') ?></p>
                            <p class="text-[12px] text-slate-700 font-bold uppercase tracking-widest mt-0.5"><?= __('settings.talk_coming_soon') ?></p>
                        </div>
                    </div>
                </div>

                <div class="pt-8">
                    <button type="submit" class="bg-indigo-600 hover:bg-indigo-500 text-white font-black py-4 px-8 rounded-xl transition shadow-xl shadow-indigo-600/20 text-xs uppercase tracking-[0.2em] flex items-center gap-3">
                        <i data-lucide="save" class="w-4 h-4"></i> <?= __('settings.save_modules') ?>
                    </button>
                </div>
            </form>
        </div>

        <!-- Sekcja: Retencja Danych -->
        <div class="glass-card p-8 mb-8 border-amber-500/20 shadow-[0_0_40px_rgba(245,158,11,0.08)] relative overflow-hidden group">
            <div class="absolute top-0 right-0 w-40 h-40 bg-amber-600/5 blur-3xl -mr-20 -mt-20 group-hover:bg-amber-600/10 transition-all duration-700"></div>
            <form method="POST" class="relative z-10">
                <input type="hidden" name="action" value="save_purge">
                <div class="flex items-center justify-between mb-8">
                    <div>
                        <h2 class="text-lg font-black uppercase tracking-[0.2em] text-amber-400 flex items-center gap-3">
                            <i data-lucide="database" class="w-6 h-6"></i>
                            <?= __('settings.data_retention') ?>
                        </h2>
                        <p class="text-slate-500 text-[12px] mt-1 font-bold uppercase tracking-widest"><?= __('settings.data_retention_desc') ?></p>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <?php
                    $purge_fields = [
                        ['key' => 'wan_stats',            'name' => 'purge_wan_stats',            'label' => __('settings.db_labels_wan_stats'),          'def' => 90,  'min' => 7,  'max' => 365],
                        ['key' => 'client_history',       'name' => 'purge_client_history',       'label' => __('settings.retention_client_history'),     'def' => 30,  'min' => 7,  'max' => 180],
                        ['key' => 'events',               'name' => 'purge_events',               'label' => __('settings.retention_events'),             'def' => 30,  'min' => 7,  'max' => 180],
                        ['key' => 'stalker_sessions',     'name' => 'purge_stalker_sessions',     'label' => __('settings.retention_stalker_sessions'),   'def' => 60,  'min' => 7,  'max' => 365],
                        ['key' => 'stalker_roaming',      'name' => 'purge_stalker_roaming',      'label' => __('settings.retention_roaming'),            'def' => 60,  'min' => 7,  'max' => 365],
                        ['key' => 'device_status_history','name' => 'purge_device_status_history','label' => __('settings.retention_device_history'),     'def' => 90,  'min' => 7,  'max' => 365],
                        ['key' => 'login_history',        'name' => 'purge_login_history',        'label' => __('settings.retention_login_history'),      'def' => 180, 'min' => 30, 'max' => 730],
                    ];
                    foreach ($purge_fields as $f):
                        $val = $config['purge_days'][$f['key']] ?? $f['def'];
                    ?>
                    <div>
                        <label class="block text-[12px] font-black text-slate-500 uppercase tracking-widest mb-2 px-1"><?= $f['label'] ?></label>
                        <div class="flex items-center gap-4">
                            <input type="range" name="<?= $f['name'] ?>" min="<?= $f['min'] ?>" max="<?= $f['max'] ?>" value="<?= $val ?>"
                                class="flex-grow accent-amber-500"
                                oninput="this.nextElementSibling.textContent = this.value + ' <?= __('common.days') ?>'">
                            <span class="text-xs font-mono text-amber-400 bg-amber-500/10 px-3 py-1.5 rounded-lg border border-amber-500/20 min-w-[80px] text-center">
                                <?= $val ?> <?= __('common.days') ?>
                            </span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <div class="pt-6">
                    <button type="submit" class="bg-amber-600 hover:bg-amber-500 text-white font-black py-4 px-8 rounded-xl transition shadow-xl shadow-amber-600/20 text-xs uppercase tracking-[0.2em] flex items-center gap-3">
                        <i data-lucide="save" class="w-4 h-4"></i> <?= __('settings.save_retention') ?>
                    </button>
                </div>
            </form>
        </div>

        <div class="grid grid-cols-1 xl:grid-cols-2 gap-8 mb-8">
            <!-- Sekcja: Sesja i Bezpieczenstwo -->
            <div class="glass-card p-8 flex flex-col w-full h-full border-rose-500/20 shadow-[0_0_40px_rgba(244,63,94,0.08)] relative overflow-hidden group">
                <div class="absolute top-0 right-0 w-32 h-32 bg-rose-600/5 blur-3xl -mr-16 -mt-16 group-hover:bg-rose-600/10 transition-all duration-700"></div>
                <form method="POST" class="flex flex-col h-full relative z-10">
                    <input type="hidden" name="action" value="save_security">
                    <div class="flex items-center justify-between mb-8">
                        <div>
                            <h2 class="text-lg font-black uppercase tracking-[0.2em] text-rose-400 flex items-center gap-3">
                                <i data-lucide="shield" class="w-6 h-6"></i>
                                <?= __('settings.session_security') ?>
                            </h2>
                            <p class="text-slate-500 text-[12px] mt-1 font-bold uppercase tracking-widest"><?= __('settings.session_desc') ?></p>
                        </div>
                    </div>

                    <div class="space-y-6 flex-grow">
                        <div>
                            <label class="block text-[12px] font-black text-slate-500 uppercase tracking-widest mb-2 px-1"><?= __('settings.session_timeout') ?></label>
                            <input type="number" name="session_timeout" min="5" max="1440"
                                value="<?= (int)($config['session_timeout'] ?? 60) ?>"
                                class="w-full px-4 py-3 bg-slate-900/50 border border-white/10 rounded-xl focus:outline-none focus:ring-2 focus:ring-rose-500/30 text-xs font-mono transition-all">
                        </div>
                        <div>
                            <label class="block text-[12px] font-black text-slate-500 uppercase tracking-widest mb-2 px-1"><?= __('settings.max_login_attempts') ?></label>
                            <input type="number" name="max_login_attempts" min="1" max="20"
                                value="<?= (int)($config['max_login_attempts'] ?? 5) ?>"
                                class="w-full px-4 py-3 bg-slate-900/50 border border-white/10 rounded-xl focus:outline-none focus:ring-2 focus:ring-rose-500/30 text-xs font-mono transition-all">
                        </div>
                        <div>
                            <label class="block text-[12px] font-black text-slate-500 uppercase tracking-widest mb-2 px-1"><?= __('settings.lock_duration') ?></label>
                            <input type="number" name="lock_duration" min="1" max="1440"
                                value="<?= (int)($config['lock_duration'] ?? 15) ?>"
                                class="w-full px-4 py-3 bg-slate-900/50 border border-white/10 rounded-xl focus:outline-none focus:ring-2 focus:ring-rose-500/30 text-xs font-mono transition-all">
                        </div>
                    </div>

                    <div class="pt-6">
                        <button type="submit" class="w-full bg-rose-600 hover:bg-rose-500 text-white font-black py-4 rounded-xl transition shadow-xl shadow-rose-600/20 text-xs uppercase tracking-[0.2em] flex items-center justify-center gap-3">
                            <i data-lucide="save" class="w-4 h-4"></i> <?= __('settings.save_security') ?>
                        </button>
                    </div>
                </form>
            </div>

            <!-- Sekcja: Odswiezanie Dashboardu -->
            <div class="glass-card p-8 flex flex-col w-full h-full border-cyan-500/20 shadow-[0_0_40px_rgba(6,182,212,0.08)] relative overflow-hidden group">
                <div class="absolute top-0 right-0 w-32 h-32 bg-cyan-600/5 blur-3xl -mr-16 -mt-16 group-hover:bg-cyan-600/10 transition-all duration-700"></div>
                <form method="POST" class="flex flex-col h-full relative z-10">
                    <input type="hidden" name="action" value="save_refresh">
                    <div class="flex items-center justify-between mb-8">
                        <div>
                            <h2 class="text-lg font-black uppercase tracking-[0.2em] text-cyan-400 flex items-center gap-3">
                                <i data-lucide="refresh-cw" class="w-6 h-6"></i>
                                <?= __('settings.dashboard_refresh') ?>
                            </h2>
                            <p class="text-slate-500 text-[12px] mt-1 font-bold uppercase tracking-widest"><?= __('settings.refresh_desc') ?></p>
                        </div>
                    </div>

                    <div class="flex-grow flex flex-col justify-center">
                        <?php $poll_val = (int)($config['poll_interval'] ?? 30); ?>
                        <label class="block text-[12px] font-black text-slate-500 uppercase tracking-widest mb-4 px-1"><?= __('settings.poll_interval') ?></label>
                        <div class="flex items-center gap-4">
                            <input type="range" name="poll_interval" min="5" max="120" value="<?= $poll_val ?>"
                                class="flex-grow accent-cyan-500"
                                oninput="this.nextElementSibling.textContent = this.value + ' s'">
                            <span class="text-xs font-mono text-cyan-400 bg-cyan-500/10 px-3 py-1.5 rounded-lg border border-cyan-500/20 min-w-[60px] text-center">
                                <?= $poll_val ?> s
                            </span>
                        </div>
                        <div class="flex justify-between text-[12px] text-slate-600 font-mono mt-1 px-1">
                            <span>5s</span><span>120s</span>
                        </div>
                    </div>

                    <div class="pt-6">
                        <button type="submit" class="w-full bg-cyan-600 hover:bg-cyan-500 text-white font-black py-4 rounded-xl transition shadow-xl shadow-cyan-600/20 text-xs uppercase tracking-[0.2em] flex items-center justify-center gap-3">
                            <i data-lucide="save" class="w-4 h-4"></i> <?= __('settings.save_interval') ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Sekcja: Baza Danych -->
        <div class="glass-card p-8 mb-10 border-emerald-500/20 shadow-[0_0_40px_rgba(16,185,129,0.08)] relative overflow-hidden group">
            <div class="absolute top-0 right-0 w-40 h-40 bg-emerald-600/5 blur-3xl -mr-20 -mt-20 group-hover:bg-emerald-600/10 transition-all duration-700"></div>
            <div class="relative z-10">
                <div class="flex items-center justify-between mb-8">
                    <div>
                        <h2 class="text-lg font-black uppercase tracking-[0.2em] text-emerald-400 flex items-center gap-3">
                            <i data-lucide="database" class="w-6 h-6"></i>
                            <?= __('settings.database') ?>
                        </h2>
                        <p class="text-slate-500 text-[12px] mt-1 font-bold uppercase tracking-widest"><?= __('settings.database_desc') ?></p>
                    </div>
                </div>

                <!-- Stats grid -->
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
                    <div class="bg-slate-900/50 border border-white/5 rounded-xl p-4">
                        <p class="text-[12px] font-black text-slate-500 uppercase tracking-widest mb-1"><?= __('settings.file_size') ?></p>
                        <p class="text-lg font-black text-emerald-400"><?= format_bytes($db_size) ?></p>
                    </div>
                    <div class="bg-slate-900/50 border border-white/5 rounded-xl p-4">
                        <p class="text-[12px] font-black text-slate-500 uppercase tracking-widest mb-1"><?= __('settings.total_records') ?></p>
                        <p class="text-lg font-black text-emerald-400"><?= number_format($db_total) ?></p>
                    </div>
                </div>

                <!-- Per-table counts -->
                <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-8">
                    <?php
                    $table_labels = [
                        'wan_stats'             => __('settings.db_labels_wan_stats'),
                        'client_history'        => __('settings.db_labels_client_history'),
                        'events'                => __('settings.db_labels_events'),
                        'stalker_sessions'      => __('settings.db_labels_stalker_sessions'),
                        'stalker_roaming'       => __('settings.db_labels_roaming'),
                        'login_history'         => __('settings.db_labels_logins'),
                        'device_monitors'       => __('settings.db_labels_monitors'),
                        'device_status_history' => __('settings.db_labels_device_history'),
                    ];
                    foreach ($db_tables as $t): ?>
                    <div class="bg-slate-900/30 border border-white/5 rounded-lg px-4 py-3 flex items-center justify-between">
                        <span class="text-[12px] font-bold text-slate-500 uppercase tracking-widest"><?= $table_labels[$t] ?></span>
                        <span class="text-xs font-black text-slate-300 font-mono"><?= number_format($db_counts[$t]) ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- Action buttons -->
                <div class="flex flex-wrap gap-3">
                    <a href="?action=vacuum"
                        class="px-5 py-3 bg-emerald-600 hover:bg-emerald-500 text-white font-black rounded-xl transition shadow-xl shadow-emerald-600/20 text-xs uppercase tracking-[0.2em] flex items-center gap-2">
                        <i data-lucide="zap" class="w-4 h-4"></i> <?= __('settings.optimize_vacuum') ?>
                    </a>
                    <a href="?action=export_config"
                        class="px-5 py-3 bg-slate-700 hover:bg-slate-600 text-slate-200 font-black rounded-xl transition text-xs uppercase tracking-[0.2em] flex items-center gap-2 border border-white/5">
                        <i data-lucide="file-json" class="w-4 h-4"></i> <?= __('settings.export_config') ?>
                    </a>
                    <a href="?action=export_db"
                        class="px-5 py-3 bg-slate-700 hover:bg-slate-600 text-slate-200 font-black rounded-xl transition text-xs uppercase tracking-[0.2em] flex items-center gap-2 border border-white/5">
                        <i data-lucide="download" class="w-4 h-4"></i> <?= __('settings.export_db') ?>
                    </a>
                </div>
            </div>
        </div>

    <script>
        lucide.createIcons();
        
        function addPingRow() {
            const list = document.getElementById('pingList');
            const div = document.createElement('div');
            div.className = 'flex gap-2 items-center ping-row';
            div.innerHTML = `
                <input type="text" name="ping_name[]" placeholder="Nazwa" class="flex-1 px-4 py-2 bg-slate-900/50 border border-white/10 rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500/30 text-xs font-bold transition-all text-slate-200">
                <input type="text" name="ping_host[]" placeholder="IP / Host" class="flex-1 px-4 py-2 bg-slate-900/50 border border-white/10 rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500/30 text-xs font-mono transition-all text-slate-200">
                <button type="button" onclick="this.parentElement.remove()" class="p-2 text-slate-500 hover:text-red-400 transition bg-slate-900/50 hover:bg-red-500/10 rounded-lg border border-white/5"><i data-lucide="trash-2" class="w-4 h-4"></i></button>
            `;
            list.appendChild(div);
            lucide.createIcons();
        }
    </script>
    <?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
