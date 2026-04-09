<?php
/** Created by Łukasz Misiura (c) 2026 | www.lm-ads.com **/
require_once 'config.php';
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

// Obsługa operacji CRUD i Konfiguracji
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $devices = loadDevices();
    
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
        
        $_SESSION['success'] = "Ustawienia zostały zapisane.";
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
        
        $_SESSION['success'] = "Lista hostów ping została zaktualizowana.";
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
                    $_SESSION['success'] = "Zasób zapisany pomyślnie";
                }
                break;
                
            case 'delete':
                $mac = $_POST['mac'] ?? '';
                if ($mac && isset($devices[$mac])) {
                    unset($devices[$mac]);
                    saveDevices($devices);
                    $_SESSION['success'] = "Zasób usunięty pomyślnie";
                }
                break;
        }
    }
    
    header('Location: devices.php');
    exit;
}

$devices = loadDevices();

// Pobieranie informacji o systemie
$sysinfo = [];
$gateway = null;
try {
    $sys_resp = fetch_api("/proxy/network/api/s/default/stat/sysinfo");
    $sysinfo = $sys_resp['data'][0] ?? [];
    
    $dev_resp = fetch_api("/proxy/network/integration/v1/sites/" . $config['site'] . "/devices");
    foreach (($dev_resp['data'] ?? []) as $d) {
        if (isset($d['wan1']) || in_array($d['model'] ?? '', ['UDR', 'UDM', 'UXG', 'USG'])) {
            $gateway = $d;
            break;
        }
    }
} catch (Throwable $e) {
    $error_msg = $e->getMessage();
    echo "<div style='background: red; color: white; padding: 20px; z-index: 9999; position: relative;'>PHP Error: $error_msg<br>File: " . $e->getFile() . " line " . $e->getLine() . "</div>";
}

?>

<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ustawienia - UniFi MiniDash</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="dashboard.css">
    <script src="https://unpkg.com/lucide@latest"></script>
</head>
<body class="custom-scrollbar">
    <?php render_nav("Konfiguracja Systemu", $navbar_stats); ?>

    <div class="max-w-6xl mx-auto p-4 md:p-8">

        <?php if (isset($_SESSION['success'])): ?>
            <div class="bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 px-4 py-3 rounded-xl mb-6 flex items-center gap-3">
                <i data-lucide="check-circle" class="w-5 h-5"></i>
                <span class="text-sm font-medium"><?= htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></span>
            </div>
        <?php endif; ?>

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
                                 Połączenie z Kontrolerem
                            </h2>
                            <p class="text-slate-500 text-[10px] mt-1 font-bold uppercase tracking-widest">Klucze dostępu i adresacja</p>
                        </div>
                        <span class="px-3 py-1 bg-emerald-500/10 text-emerald-400 text-[10px] font-black rounded-full border border-emerald-500/20 flex items-center gap-2">
                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span>
                            ACTIVE
                        </span>
                    </div>
                    
                    <div class="space-y-6">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div class="md:col-span-2">
                                <label class="block text-[10px] font-black text-slate-500 uppercase tracking-widest mb-2 px-1">Controller URL</label>
                                <input type="text" name="controller_url" value="<?= htmlspecialchars($config['controller_url']) ?>" required
                                    class="w-full px-4 py-3 bg-slate-900/50 border border-white/10 rounded-xl focus:outline-none focus:ring-2 focus:ring-blue-500/30 text-xs font-mono transition-all">
                            </div>
                            <div>
                                <label class="block text-[10px] font-black text-slate-500 uppercase tracking-widest mb-2 px-1">Site ID</label>
                                <input type="text" name="site" value="<?= htmlspecialchars($config['site']) ?>" required
                                    class="w-full px-4 py-3 bg-slate-900/50 border border-white/10 rounded-xl focus:outline-none focus:ring-2 focus:ring-blue-500/30 text-xs font-mono transition-all">
                            </div>
                            <div>
                                <label class="block text-[10px] font-black text-slate-500 uppercase tracking-widest mb-2 px-1">API Key</label>
                                <input type="password" name="api_key" value="<?= htmlspecialchars($config['api_key']) ?>"
                                    class="w-full px-4 py-3 bg-slate-900/50 border border-white/10 rounded-xl focus:outline-none focus:ring-2 focus:ring-blue-500/30 text-xs font-mono transition-all">
                            </div>
                        </div>
                        
                        <div class="pt-4">
                             <button type="submit" class="w-full bg-blue-600 hover:bg-blue-500 text-white font-black py-4 rounded-xl transition shadow-xl shadow-blue-600/20 text-xs uppercase tracking-[0.2em] flex items-center justify-center gap-3">
                                <i data-lucide="refresh-cw" class="w-4 h-4"></i> Zaktualizuj Konfigurację
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
                                 Monitoring Opóźnień (Ping)
                            </h2>
                            <p class="text-slate-500 text-[10px] mt-1 font-bold uppercase tracking-widest">Definicja hostów do sprawdzania pingu</p>
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
                            + Dodaj Wiersz
                        </button>
                         <button type="submit" class="flex-1 bg-purple-600 hover:bg-purple-500 text-white font-black py-2 rounded-xl transition shadow-xl shadow-purple-600/20 text-xs uppercase tracking-[0.2em] flex items-center justify-center gap-2">
                            <i data-lucide="save" class="w-4 h-4"></i> Zapisz Listę
                         </button>
                    </div>
                </form>
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
</body>
</html>



