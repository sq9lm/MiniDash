<?php
/** Created by Łukasz Misiura (c) 2025 | dev.lm-ads.com **/
require_once 'config.php';
require_once 'db.php';
require_once 'functions.php';

$status = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $newConfig = [
        'email_notifications' => [
            'enabled' => isset($_POST['email_enabled']),
            'smtp_host' => $_POST['email_host'],
            'smtp_port' => (int)$_POST['email_port'],
            'smtp_username' => $_POST['email_user'],
            'smtp_password' => $_POST['email_pass'],
            'from_email' => $_POST['email_from'],
            'to_email' => $_POST['email_to']
        ],
        'telegram_notifications' => [
            'enabled' => isset($_POST['tg_enabled']),
            'bot_token' => $_POST['tg_token'],
            'chat_id' => $_POST['tg_chatid']
        ],
        'whatsapp_notifications' => [
            'enabled' => isset($_POST['wa_enabled']),
            'api_url' => $_POST['wa_url'],
            'api_key' => $_POST['wa_key'],
            'phone_number' => $_POST['wa_phone']
        ],
        'slack_notifications' => [
            'enabled' => isset($_POST['slack_enabled']),
            'webhook_url' => $_POST['slack_url']
        ],
        'sms_notifications' => [
            'enabled' => isset($_POST['sms_enabled']),
            'api_url' => $_POST['sms_url'],
            'api_key' => $_POST['sms_key'],
            'to_number' => $_POST['sms_phone']
        ]
    ];

    // Merge with existing config to preserve other keys like controller_url
    $configFile = __DIR__ . '/data/config.json';
    $existing = file_exists($configFile) ? json_decode(file_get_contents($configFile), true) : [];
    if (!is_array($existing)) $existing = [];
    
    $finalConfig = array_replace_recursive($existing, $newConfig);
    
    if (file_put_contents($configFile, json_encode($finalConfig, JSON_PRETTY_PRINT))) {
        $status = "success";
        // Update current $config for rendering the form
        $config = array_replace_recursive($config, $finalConfig);
    } else {
        $status = "error";
    }
}

// Ensure keys exist in $config for rendering
$notif_keys = [
    'email_notifications' => ['enabled' => false, 'smtp_host' => '', 'smtp_port' => 587, 'smtp_username' => '', 'smtp_password' => '', 'from_email' => '', 'to_email' => ''],
    'telegram_notifications' => ['enabled' => false, 'bot_token' => '', 'chat_id' => ''],
    'whatsapp_notifications' => ['enabled' => false, 'api_url' => '', 'api_key' => '', 'phone_number' => ''],
    'slack_notifications' => ['enabled' => false, 'webhook_url' => ''],
    'sms_notifications' => ['enabled' => false, 'api_url' => '', 'api_key' => '', 'to_number' => ''],
    'discord_notifications' => ['enabled' => false, 'webhook_url' => '', 'username' => 'MiniDash'],
    'n8n_notifications' => ['enabled' => false, 'webhook_url' => '']
];

foreach ($notif_keys as $key => $defaults) {
    if (!isset($config[$key])) $config[$key] = $defaults;
    else $config[$key] = array_merge($defaults, $config[$key]);
}

?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Konfiguracja Powiadomień | MiniDash</title>
    <link rel="icon" type="image/svg+xml" href="img/favicon.svg">
    <link rel="stylesheet" href="dashboard.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        .setting-card {
            background: rgba(15, 23, 42, 0.4);
            border: 1px border white/5;
            transition: all 0.3s ease;
        }
        .setting-card:hover {
            border-color: rgba(255,255,255,0.1);
            background: rgba(15, 23, 42, 0.6);
        }
        .input-dark {
            background: rgba(0,0,0,0.2);
            border: 1px border white/5;
            color: white;
            transition: all 0.2s;
        }
        .input-dark:focus {
            border-color: #3b82f6;
            background: rgba(0,0,0,0.4);
            outline: none;
        }
        .toggle-switch {
            cursor: pointer;
        }
    </style>
</head>
<body class="pt-24 pb-20 antialiased min-h-screen">
    <?php render_nav("Ustawienia Powiadomień"); ?>

    <div class="max-w-5xl mx-auto px-6">
        <div class="flex items-center justify-between mb-10">
            <div>
                <h2 class="text-3xl font-black text-white tracking-tight">System Powiadomień</h2>
                <p class="text-slate-500 mt-2 font-medium">Zarządzaj kanałami alertów o statusie urządzeń</p>
            </div>
            <div class="flex gap-4">
                <?php if ($status === "success"): ?>
                    <div class="px-4 py-2 bg-emerald-500/10 text-emerald-400 rounded-xl border border-emerald-500/20 flex items-center gap-2 animate-bounce">
                        <i data-lucide="check-circle" class="w-5 h-5"></i> Ustawienia zapisane
                    </div>
                <?php endif; ?>
                <button onclick="location.href='index.php'" class="p-3 bg-white/5 border border-white/10 rounded-2xl text-slate-400 hover:text-white transition group">
                   <i data-lucide="x" class="w-6 h-6 group-hover:rotate-90 transition-transform"></i>
                </button>
            </div>
        </div>

        <form method="POST" class="space-y-8">
            <!-- Email / SMTP -->
            <div class="setting-card rounded-3xl p-8 border border-white/5">
                <div class="flex items-center justify-between mb-8">
                    <div class="flex items-center gap-4">
                        <div class="w-12 h-12 rounded-2xl bg-blue-500/10 text-blue-400 flex items-center justify-center">
                            <i data-lucide="mail" class="w-6 h-6"></i>
                        </div>
                        <div>
                            <h3 class="text-xl font-bold text-white">Email (SMTP / Gmail)</h3>
                            <p class="text-sm text-slate-500">Powiadomienia na skrzynkę pocztową</p>
                        </div>
                    </div>
                    <label class="relative inline-flex items-center cursor-pointer">
                        <input type="checkbox" name="email_enabled" class="sr-only peer" <?= $config['email_notifications']['enabled'] ? 'checked' : '' ?>>
                        <div class="w-11 h-6 bg-slate-700 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full rtl:peer-checked:after:-translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:start-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div>
                    </label>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                    <div class="lg:col-span-3">
                        <label class="block text-[10px] font-black text-slate-500 uppercase tracking-widest mb-2">Host SMTP</label>
                        <input type="text" name="email_host" value="<?= htmlspecialchars($config['email_notifications']['smtp_host']) ?>" class="w-full p-4 rounded-xl input-dark text-sm" placeholder="e.g. smtp.gmail.com">
                    </div>
                    <div>
                        <label class="block text-[10px] font-black text-slate-500 uppercase tracking-widest mb-2">Port</label>
                        <input type="number" name="email_port" value="<?= htmlspecialchars($config['email_notifications']['smtp_port']) ?>" class="w-full p-4 rounded-xl input-dark text-sm" placeholder="587">
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-[10px] font-black text-slate-500 uppercase tracking-widest mb-2">Użytkownik / Login</label>
                        <input type="text" name="email_user" value="<?= htmlspecialchars($config['email_notifications']['smtp_username']) ?>" class="w-full p-4 rounded-xl input-dark text-sm" placeholder="user@gmail.com">
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-[10px] font-black text-slate-500 uppercase tracking-widest mb-2">Hasło (lub hasło aplikacji)</label>
                        <input type="password" name="email_pass" value="<?= htmlspecialchars($config['email_notifications']['smtp_password']) ?>" class="w-full p-4 rounded-xl input-dark text-sm" placeholder="••••••••••••">
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-[10px] font-black text-slate-500 uppercase tracking-widest mb-2">Email nadawcy</label>
                        <input type="text" name="email_from" value="<?= htmlspecialchars($config['email_notifications']['from_email']) ?>" class="w-full p-4 rounded-xl input-dark text-sm" placeholder="alert@domena.pl">
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-[10px] font-black text-slate-500 uppercase tracking-widest mb-2">Gdzie wysłać alerty?</label>
                        <input type="text" name="email_to" value="<?= htmlspecialchars($config['email_notifications']['to_email']) ?>" class="w-full p-4 rounded-xl input-dark text-sm" placeholder="twoj@email.com">
                    </div>
                </div>
            </div>

            <!-- Telegram -->
            <div class="setting-card rounded-3xl p-8 border border-white/5">
                <div class="flex items-center justify-between mb-8">
                    <div class="flex items-center gap-4">
                        <div class="w-12 h-12 rounded-2xl bg-sky-500/10 text-sky-400 flex items-center justify-center">
                            <i data-lucide="send" class="w-6 h-6"></i>
                        </div>
                        <div>
                            <h3 class="text-xl font-bold text-white">Telegram Bot</h3>
                            <p class="text-sm text-slate-500">Natychmiastowe alerty na komunikator</p>
                        </div>
                    </div>
                    <label class="relative inline-flex items-center cursor-pointer">
                        <input type="checkbox" name="tg_enabled" class="sr-only peer" <?= $config['telegram_notifications']['enabled'] ? 'checked' : '' ?>>
                        <div class="w-11 h-6 bg-slate-700 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full rtl:peer-checked:after:-translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:start-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-sky-500"></div>
                    </label>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div class="md:col-span-2">
                        <label class="block text-[10px] font-black text-slate-500 uppercase tracking-widest mb-2">Bot Token</label>
                        <input type="text" name="tg_token" value="<?= htmlspecialchars($config['telegram_notifications']['bot_token']) ?>" class="w-full p-4 rounded-xl input-dark text-sm" placeholder="123456789:ABCDEF....">
                    </div>
                    <div>
                        <label class="block text-[10px] font-black text-slate-500 uppercase tracking-widest mb-2">Chat ID</label>
                        <input type="text" name="tg_chatid" value="<?= htmlspecialchars($config['telegram_notifications']['chat_id']) ?>" class="w-full p-4 rounded-xl input-dark text-sm" placeholder="12345678">
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                <!-- WhatsApp -->
                <div class="setting-card rounded-3xl p-8 border border-white/5">
                    <div class="flex items-center justify-between mb-8">
                        <div class="flex items-center gap-4">
                            <div class="w-12 h-12 rounded-2xl bg-emerald-500/10 text-emerald-400 flex items-center justify-center">
                                <i data-lucide="message-square" class="w-6 h-6"></i>
                            </div>
                            <div>
                                <h3 class="text-lg font-bold text-white">WhatsApp API</h3>
                            </div>
                        </div>
                        <label class="relative inline-flex items-center cursor-pointer">
                            <input type="checkbox" name="wa_enabled" class="sr-only peer" <?= $config['whatsapp_notifications']['enabled'] ? 'checked' : '' ?>>
                            <div class="w-11 h-6 bg-slate-700 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full rtl:peer-checked:after:-translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:start-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-emerald-500"></div>
                        </label>
                    </div>
                    <div class="space-y-6">
                        <div>
                            <label class="block text-[10px] font-black text-slate-500 uppercase tracking-widest mb-2">WhatsApp API URL</label>
                            <input type="text" name="wa_url" value="<?= htmlspecialchars($config['whatsapp_notifications']['api_url']) ?>" class="w-full p-4 rounded-xl input-dark text-sm" placeholder="https://api.whatsapp.com/...">
                        </div>
                        <div>
                            <label class="block text-[10px] font-black text-slate-500 uppercase tracking-widest mb-2">API Key / Token</label>
                            <input type="password" name="wa_key" value="<?= htmlspecialchars($config['whatsapp_notifications']['api_key']) ?>" class="w-full p-4 rounded-xl input-dark text-sm" placeholder="WA_SECRET_TOKEN">
                        </div>
                        <div>
                            <label class="block text-[10px] font-black text-slate-500 uppercase tracking-widest mb-2">Numer Telefonu Docelowy</label>
                            <input type="text" name="wa_phone" value="<?= htmlspecialchars($config['whatsapp_notifications']['phone_number']) ?>" class="w-full p-4 rounded-xl input-dark text-sm" placeholder="+48123456789">
                        </div>
                    </div>
                </div>

                <!-- Slack -->
                <div class="setting-card rounded-3xl p-8 border border-white/5">
                    <div class="flex items-center justify-between mb-8">
                        <div class="flex items-center gap-4">
                            <div class="w-12 h-12 rounded-2xl bg-purple-500/10 text-purple-400 flex items-center justify-center">
                                <i data-lucide="hash" class="w-6 h-6"></i>
                            </div>
                            <div>
                                <h3 class="text-lg font-bold text-white">Slack Webhook</h3>
                            </div>
                        </div>
                        <label class="relative inline-flex items-center cursor-pointer">
                            <input type="checkbox" name="slack_enabled" class="sr-only peer" <?= $config['slack_notifications']['enabled'] ? 'checked' : '' ?>>
                            <div class="w-11 h-6 bg-slate-700 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full rtl:peer-checked:after:-translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:start-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-purple-600"></div>
                        </label>
                    </div>
                    <div class="space-y-6">
                        <div>
                            <label class="block text-[10px] font-black text-slate-500 uppercase tracking-widest mb-2">Webhook URL</label>
                            <input type="text" name="slack_url" value="<?= htmlspecialchars($config['slack_notifications']['webhook_url']) ?>" class="w-full p-4 rounded-xl input-dark text-sm" placeholder="https://hooks.slack.com/services/...">
                        </div>
                    </div>
                </div>
            </div>

            <!-- SMS -->
            <div class="setting-card rounded-3xl p-8 border border-white/5">
                <div class="flex items-center justify-between mb-8">
                    <div class="flex items-center gap-4">
                        <div class="w-12 h-12 rounded-2xl bg-rose-500/10 text-rose-400 flex items-center justify-center">
                            <i data-lucide="smartphone" class="w-6 h-6"></i>
                        </div>
                        <div>
                            <h3 class="text-xl font-bold text-white">SMS GSM / API</h3>
                            <p class="text-sm text-slate-500">Powiadomienia SMS przez bramkę API</p>
                        </div>
                    </div>
                    <label class="relative inline-flex items-center cursor-pointer">
                        <input type="checkbox" name="sms_enabled" class="sr-only peer" <?= $config['sms_notifications']['enabled'] ? 'checked' : '' ?>>
                        <div class="w-11 h-6 bg-slate-700 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full rtl:peer-checked:after:-translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:start-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-rose-500"></div>
                    </label>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div class="md:col-span-2">
                        <label class="block text-[10px] font-black text-slate-500 uppercase tracking-widest mb-2">API Gateway URL</label>
                        <input type="text" name="sms_url" value="<?= htmlspecialchars($config['sms_notifications']['api_url']) ?>" class="w-full p-4 rounded-xl input-dark text-sm" placeholder="https://api.sms-gateway.com/send">
                    </div>
                    <div>
                        <label class="block text-[10px] font-black text-slate-500 uppercase tracking-widest mb-2">API Key</label>
                        <input type="password" name="sms_key" value="<?= htmlspecialchars($config['sms_notifications']['api_key']) ?>" class="w-full p-4 rounded-xl input-dark text-sm" placeholder="SMS_API_SECRET">
                    </div>
                    <div>
                        <label class="block text-[10px] font-black text-slate-500 uppercase tracking-widest mb-2">Numer Telefonu Docelowy</label>
                        <input type="text" name="sms_phone" value="<?= htmlspecialchars($config['sms_notifications']['to_number']) ?>" class="w-full p-4 rounded-xl input-dark text-sm" placeholder="+48999888777">
                    </div>
                </div>
            </div>

            <!-- Discord -->
            <div class="setting-card rounded-3xl p-8 border border-white/5">
                <div class="flex items-center justify-between mb-8">
                    <div class="flex items-center gap-4">
                        <div class="w-12 h-12 rounded-2xl bg-indigo-500/10 text-indigo-400 flex items-center justify-center">
                            <i data-lucide="message-circle" class="w-6 h-6"></i>
                        </div>
                        <div>
                            <h3 class="text-xl font-bold text-white">Discord</h3>
                            <p class="text-sm text-slate-500">Powiadomienia przez Discord Webhook</p>
                        </div>
                    </div>
                    <label class="relative inline-flex items-center cursor-pointer">
                        <input type="checkbox" name="discord_enabled" class="sr-only peer" <?= !empty($config['discord_notifications']['enabled']) ? 'checked' : '' ?>>
                        <div class="w-11 h-6 bg-slate-700 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full rtl:peer-checked:after:-translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:start-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-indigo-600"></div>
                    </label>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-[10px] font-black text-slate-500 uppercase tracking-widest mb-2">Webhook URL</label>
                        <input type="text" name="discord_webhook_url" value="<?= htmlspecialchars($config['discord_notifications']['webhook_url'] ?? '') ?>" class="w-full p-4 rounded-xl input-dark text-sm" placeholder="https://discord.com/api/webhooks/...">
                    </div>
                    <div>
                        <label class="block text-[10px] font-black text-slate-500 uppercase tracking-widest mb-2">Nazwa bota</label>
                        <input type="text" name="discord_username" value="<?= htmlspecialchars($config['discord_notifications']['username'] ?? 'MiniDash') ?>" class="w-full p-4 rounded-xl input-dark text-sm" placeholder="MiniDash">
                    </div>
                </div>
            </div>

            <!-- n8n -->
            <div class="setting-card rounded-3xl p-8 border border-white/5">
                <div class="flex items-center justify-between mb-8">
                    <div class="flex items-center gap-4">
                        <div class="w-12 h-12 rounded-2xl bg-orange-500/10 text-orange-400 flex items-center justify-center">
                            <i data-lucide="webhook" class="w-6 h-6"></i>
                        </div>
                        <div>
                            <h3 class="text-xl font-bold text-white">n8n</h3>
                            <p class="text-sm text-slate-500">Generic webhook do automatyzacji n8n</p>
                        </div>
                    </div>
                    <label class="relative inline-flex items-center cursor-pointer">
                        <input type="checkbox" name="n8n_enabled" class="sr-only peer" <?= !empty($config['n8n_notifications']['enabled']) ? 'checked' : '' ?>>
                        <div class="w-11 h-6 bg-slate-700 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full rtl:peer-checked:after:-translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:start-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-orange-600"></div>
                    </label>
                </div>

                <div class="grid grid-cols-1 gap-6">
                    <div>
                        <label class="block text-[10px] font-black text-slate-500 uppercase tracking-widest mb-2">Webhook URL</label>
                        <input type="text" name="n8n_webhook_url" value="<?= htmlspecialchars($config['n8n_notifications']['webhook_url'] ?? '') ?>" class="w-full p-4 rounded-xl input-dark text-sm" placeholder="https://n8n.example.com/webhook/...">
                    </div>
                </div>
            </div>

            <div class="fixed bottom-0 left-0 right-0 p-6 bg-slate-900/80 backdrop-blur-xl border-t border-white/5 z-50">
                <div class="max-w-5xl mx-auto flex justify-end gap-4">
                    <button type="button" onclick="location.href='index.php'" class="px-8 py-4 bg-slate-800 hover:bg-slate-700 text-slate-300 rounded-2xl font-bold transition">
                        Anuluj
                    </button>
                    <button type="submit" class="px-12 py-4 bg-blue-600 hover:bg-blue-500 text-white rounded-2xl font-black uppercase tracking-widest transition shadow-2xl shadow-blue-600/40">
                        Zapisz Konfigurację
                    </button>
                </div>
            </div>
        </form>
    </div>

    <script>lucide.createIcons();</script>
    <?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>




