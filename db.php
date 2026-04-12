<?php
/** Created by Łukasz Misiura (c) 2025 | dev.lm-ads.com **/
/**
 * SQLite Database Layer - MiniDash v2.0.0
 * Include after config.php on every page that needs DB access.
 */

$db_path = __DIR__ . '/data/minidash.db';
$migrations_dir = __DIR__ . '/migrations';

try {
    $db = new PDO("sqlite:$db_path");
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec("PRAGMA journal_mode=WAL");
    $db->exec("PRAGMA foreign_keys=ON");
} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}

// Auto-run migrations
run_migrations($db, $migrations_dir);

// Auto-purge old data (once per session)
if (!isset($_SESSION['purge_done'])) {
    run_purge($db);
    $_SESSION['purge_done'] = true;
}

function run_migrations(PDO $db, string $dir): void {
    // Ensure migrations table exists
    $db->exec("CREATE TABLE IF NOT EXISTS migrations (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        version TEXT NOT NULL UNIQUE,
        name TEXT NOT NULL,
        applied_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    $applied = [];
    $stmt = $db->query("SELECT version FROM migrations");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $applied[] = $row['version'];
    }

    $files = glob("$dir/*.sql");
    if (!$files) return;
    sort($files);

    foreach ($files as $file) {
        $filename = basename($file);
        $version = explode('_', $filename, 2)[0]; // "001" from "001_initial.sql"

        if (in_array($version, $applied)) continue;

        $sql = file_get_contents($file);
        $db->exec($sql);

        $stmt2 = $db->prepare("INSERT INTO migrations (version, name) VALUES (?, ?)");
        $stmt2->execute([$version, $filename]);
    }
}

function run_purge(PDO $db): void {
    global $config;
    $purge = $config['purge_days'] ?? [];

    $rules = [
        'wan_stats' => ['col' => 'recorded_at', 'days' => $purge['wan_stats'] ?? 90],
        'client_history' => ['col' => 'seen_at', 'days' => $purge['client_history'] ?? 30],
        'events' => ['col' => 'created_at', 'days' => $purge['events'] ?? 30],
        'stalker_sessions' => ['col' => 'connected_at', 'days' => $purge['stalker_sessions'] ?? 60],
        'stalker_roaming' => ['col' => 'roamed_at', 'days' => $purge['stalker_roaming'] ?? 60],
        'device_status_history' => ['col' => 'timestamp', 'days' => $purge['device_status_history'] ?? 90],
        'login_history' => ['col' => 'logged_at', 'days' => $purge['login_history'] ?? 180],
    ];

    foreach ($rules as $table => $r) {
        $db->exec("DELETE FROM {$table} WHERE {$r['col']} < datetime('now', '-{$r['days']} days')");
    }

    // Purge expired remember_me tokens
    $db->exec("DELETE FROM remember_tokens WHERE expires_at < datetime('now')");
}
