<?php
/** Created by Łukasz Misiura (c) 2025 | dev.lm-ads.com **/
require_once 'config.php';
require_once 'db.php';

// Funkcja do weryfikacji logowania
function verifyLogin($username, $password) {
    global $config;
    return $username === $config['admin_username'] && 
           $password === $config['admin_password'];
}

// Obsługa formularza logowania
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    
    // Rate limiting — max login attempts
    $max_attempts = $config['max_login_attempts'] ?? 5;
    $lock_duration = ($config['lock_duration'] ?? 15) * 60; // minutes to seconds
    $login_attempts = $_SESSION['login_attempts'] ?? 0;
    $lock_until = $_SESSION['lock_until'] ?? 0;

    if (time() < $lock_until) {
        $remaining = ceil(($lock_until - time()) / 60);
        $error = "Zbyt wiele prob logowania. Sprobuj za $remaining min.";
    } elseif (verifyLogin($username, $password)) {
        require_once 'functions.php';
        // Regenerate session ID to prevent fixation
        session_regenerate_id(true);
        $_SESSION['logged_in'] = true;
        $_SESSION['username'] = $username;
        $_SESSION['last_login_time'] = time();
        $_SESSION['login_attempts'] = 0;
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

        log_login_event($username);

        header('Location: index.php');
        exit;
    } else {
        $login_attempts++;
        $_SESSION['login_attempts'] = $login_attempts;
        if ($login_attempts >= $max_attempts) {
            $_SESSION['lock_until'] = time() + $lock_duration;
            $error = "Zbyt wiele prob. Konto zablokowane na " . ($lock_duration / 60) . " min.";
        } else {
            $remaining = $max_attempts - $login_attempts;
            $error = "Nieprawidlowy uzytkownik lub haslo ($remaining prob pozostalo)";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Logowanie - MiniDash</title>
    <link rel="icon" type="image/svg+xml" href="img/favicon.svg">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="assets/css/fonts.css">
    <link rel="stylesheet" href="dashboard.css">
    <script src="assets/js/lucide.min.js"></script>
</head>
<body class="min-h-screen flex items-center justify-center p-4">
    <div class="w-full max-w-md">
        <!-- Logo/Header -->
        <div class="text-center mb-8">
            <div class="inline-flex items-center justify-center w-16 h-16 rounded-2xl bg-blue-600/20 text-blue-500 mb-4 shadow-xl shadow-blue-500/10 border border-blue-500/20">
                <i data-lucide="shield-check" class="w-8 h-8"></i>
            </div>
            <h1 class="text-3xl font-bold bg-clip-text text-transparent bg-gradient-to-r from-blue-400 to-indigo-400">
                MiniDash
            </h1>
            <p class="text-slate-500 text-sm mt-2 font-medium tracking-wide uppercase">Dostęp Autoryzowany</p>
        </div>

        <div class="glass-card p-8 shadow-2xl relative overflow-hidden">
            <!-- Subtle background light -->
            <div class="absolute -top-24 -right-24 w-48 h-48 bg-blue-500/10 rounded-full blur-3xl"></div>
            
            <?php if (isset($error)): ?>
                <div class="bg-red-500/10 border border-red-500/20 text-red-400 px-4 py-3 rounded-lg mb-6 flex items-center gap-3 animate-pulse">
                    <i data-lucide="alert-circle" class="w-5 h-5"></i>
                    <span class="text-sm font-medium"><?= htmlspecialchars($error); ?></span>
                </div>
            <?php endif; ?>
            
            <form method="POST" class="space-y-6 relative z-10">
                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase tracking-widest mb-2 px-1">Użytkownik</label>
                    <div class="relative">
                        <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-500">
                            <i data-lucide="user" class="w-4 h-4"></i>
                        </span>
                        <input type="text" name="username" required
                               class="w-full pl-10 pr-4 py-3 bg-slate-800/50 border border-white/5 rounded-xl focus:outline-none focus:ring-2 focus:ring-blue-500/50 transition text-slate-200 placeholder-slate-600"
                               placeholder="Admin">
                    </div>
                </div>
                
                <div>
                    <label class="block text-xs font-bold text-slate-500 uppercase tracking-widest mb-2 px-1">Hasło</label>
                    <div class="relative">
                        <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-500">
                            <i data-lucide="lock" class="w-4 h-4"></i>
                        </span>
                        <input type="password" name="password" required
                               class="w-full pl-10 pr-4 py-3 bg-slate-800/50 border border-white/5 rounded-xl focus:outline-none focus:ring-2 focus:ring-blue-500/50 transition text-slate-200 placeholder-slate-600"
                               placeholder="••••••••">
                    </div>
                </div>
                
                <button type="submit"
                        class="w-full bg-blue-600 hover:bg-blue-500 text-white font-bold py-3.5 rounded-xl transition shadow-xl shadow-blue-600/20 group flex items-center justify-center gap-2">
                    <span>Zaloguj do Dashboardu</span>
                    <i data-lucide="arrow-right" class="w-4 h-4 group-hover:translate-x-1 transition"></i>
                </button>
            </form>
        </div>

        <footer class="mt-12 text-center text-slate-500 text-[11px] uppercase tracking-[0.3em] font-medium">
            &copy; 2026 <span class="text-slate-400">lm-network</span> 
            <span class="mx-3 text-slate-700">/</span> 
            <a href="https://www.lm-ads.com" target="_blank" class="hover:text-blue-400 transition hover:tracking-[0.4em]">Łukasz Misiura</a>
        </footer>
    </div>

    <script>
        lucide.createIcons();
    </script>
</body>
</html>



