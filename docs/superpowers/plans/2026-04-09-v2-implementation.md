# UniFi MiniDash v2.0.0 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Migrate MiniDash from JSON file storage to SQLite, add Wi-Fi Stalker, Threat Watch improvements, Network Pulse drilldown, Discord/n8n webhooks, credential encryption, and footer.

**Architecture:** SQLite via PDO replaces all JSON data files. New `db.php` include provides `$db` global alongside existing `$config`. Auto-migration system runs SQL files from `migrations/` folder. All new features (stalker, threat improvements) built on SQLite from day one.

**Tech Stack:** PHP 8.x, PDO/SQLite3, vanilla JS, Tailwind CSS, Chart.js, Lucide icons, sodium_crypto_secretbox.

**Note:** This project has no test framework. Verification steps use browser checks and curl commands against the running app.

---

## File Map

### New Files
| File | Responsibility |
|------|---------------|
| `db.php` | SQLite connection, auto-migration runner, auto-purge |
| `crypto.php` | Fernet-style encryption for config credentials |
| `stalker.php` | Wi-Fi Stalker full page |
| `api_stalker.php` | Stalker AJAX backend (poll, history, block/unblock, CSV) |
| `api_threat_ignore.php` | Threat ignore list CRUD API |
| `migrate_json.php` | One-time JSON-to-SQLite migration script |
| `includes/footer.php` | Footer with branding + version |
| `migrations/001_initial.sql` | Base schema (all tables + indexes) |

### Modified Files
| File | Changes |
|------|---------|
| `config.php` | Add `MINIDASH_VERSION` constant, `discord_notifications`, `n8n_notifications`, `purge_days` config, `sendDiscordNotification()`, `sendN8nNotification()`, update `sendAlert()` |
| `functions.php` | Replace JSON read/write with SQLite queries for: `log_login_event()`, `loadDevices()`, `saveDevices()`, `loadDeviceHistory()`, `saveDeviceHistory()` |
| `index.php` | Add stalker widget card, AP->client drilldown in infr modal, include footer |
| `security.php` | Add ignore list button+modal, time range filter buttons, include footer |
| `settings_notifications.php` | Add Discord + n8n panels, include footer |
| `api_save_settings.php` | Add Discord + n8n fields |
| `login.php` | Use SQLite for login history |
| `update_wan.php` | Write to SQLite instead of wan_stats.json |
| `.gitignore` | Already updated with `data/minidash.db`, `data/.encryption_key` |

---

## Task 1: SQLite Layer — db.php

**Files:**
- Create: `db.php`
- Create: `migrations/001_initial.sql`

- [ ] **Step 1: Create migrations/001_initial.sql**

```sql
-- 001_initial.sql
-- Base schema for MiniDash v2.0.0

CREATE TABLE IF NOT EXISTS migrations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    version TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL,
    applied_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS login_history (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL,
    ip TEXT,
    location TEXT,
    os TEXT,
    browser TEXT,
    user_agent TEXT,
    logged_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS wan_stats (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    rx_bytes INTEGER,
    tx_bytes INTEGER,
    recorded_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS client_history (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    mac TEXT NOT NULL,
    hostname TEXT,
    ip TEXT,
    network TEXT,
    vlan INTEGER,
    is_wired INTEGER DEFAULT 0,
    rx_bytes INTEGER,
    tx_bytes INTEGER,
    uptime_sec INTEGER,
    seen_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS device_monitors (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    mac TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL,
    vlan TEXT,
    enabled INTEGER DEFAULT 1,
    added_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS device_status_history (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    mac TEXT NOT NULL,
    status TEXT NOT NULL,
    duration INTEGER,
    timestamp DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    type TEXT NOT NULL,
    severity TEXT DEFAULT 'INFO',
    message TEXT,
    details_json TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS stalker_sessions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    mac TEXT NOT NULL,
    hostname TEXT,
    ap_mac TEXT,
    ap_name TEXT,
    ssid TEXT,
    channel INTEGER,
    band TEXT,
    rssi INTEGER,
    rx_rate REAL,
    tx_rate REAL,
    connected_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    disconnected_at DATETIME
);

CREATE TABLE IF NOT EXISTS stalker_roaming (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    mac TEXT NOT NULL,
    hostname TEXT,
    from_ap TEXT,
    to_ap TEXT,
    from_channel INTEGER,
    to_channel INTEGER,
    rssi_before INTEGER,
    rssi_after INTEGER,
    roamed_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS stalker_watchlist (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    mac TEXT NOT NULL UNIQUE,
    label TEXT,
    notify INTEGER DEFAULT 1,
    blocked INTEGER DEFAULT 0,
    added_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS threat_ignore (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    ip TEXT NOT NULL UNIQUE,
    label TEXT,
    reason TEXT,
    added_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_login_history_time ON login_history(logged_at);
CREATE INDEX IF NOT EXISTS idx_wan_stats_recorded ON wan_stats(recorded_at);
CREATE INDEX IF NOT EXISTS idx_client_history_mac ON client_history(mac);
CREATE INDEX IF NOT EXISTS idx_client_history_seen ON client_history(seen_at);
CREATE INDEX IF NOT EXISTS idx_device_status_mac ON device_status_history(mac);
CREATE INDEX IF NOT EXISTS idx_stalker_sessions_mac ON stalker_sessions(mac);
CREATE INDEX IF NOT EXISTS idx_stalker_roaming_mac ON stalker_roaming(mac);
CREATE INDEX IF NOT EXISTS idx_stalker_roaming_time ON stalker_roaming(roamed_at);
CREATE INDEX IF NOT EXISTS idx_events_created ON events(created_at);
CREATE INDEX IF NOT EXISTS idx_events_severity ON events(severity);
```

- [ ] **Step 2: Create db.php**

```php
<?php
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
}
```

- [ ] **Step 3: Verify db.php works**

Add temporarily to the top of index.php after `require_once 'config.php';`:
```php
require_once 'db.php';
```
Load dashboard in browser. Verify `data/minidash.db` file is created and no errors appear.

- [ ] **Step 4: Commit**

```bash
git add db.php migrations/001_initial.sql
git commit -m "feat: add SQLite database layer with auto-migration system"
```

---

## Task 2: JSON to SQLite Migration Script

**Files:**
- Create: `migrate_json.php`

- [ ] **Step 1: Create migrate_json.php**

```php
<?php
/**
 * One-time migration: JSON data files -> SQLite
 * Safe to run multiple times (idempotent).
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

$is_cli = php_sapi_name() === 'cli';
$is_web = !$is_cli;

if ($is_web) {
    if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
        header('Location: login.php');
        exit;
    }
    header('Content-Type: text/plain; charset=utf-8');
}

function mlog($msg) {
    echo $msg . "\n";
    flush();
}

mlog("=== MiniDash JSON -> SQLite Migration ===\n");

// 1. Login History
$file = __DIR__ . '/data/login_history.json';
if (file_exists($file)) {
    $data = json_decode(file_get_contents($file), true) ?: [];
    $existing = $db->query("SELECT COUNT(*) FROM login_history")->fetchColumn();
    if ($existing == 0 && count($data) > 0) {
        $stmt = $db->prepare("INSERT INTO login_history (username, ip, location, os, browser, user_agent, logged_at) VALUES (?, ?, ?, ?, ?, ?, datetime(?, 'unixepoch'))");
        $count = 0;
        foreach ($data as $entry) {
            $stmt->execute([
                $entry['username'] ?? '',
                $entry['ip'] ?? '',
                $entry['location'] ?? '',
                $entry['os'] ?? '',
                $entry['browser'] ?? '',
                $entry['ua'] ?? '',
                $entry['timestamp'] ?? time()
            ]);
            $count++;
        }
        mlog("[OK] login_history: migrated $count entries");
    } else {
        mlog("[SKIP] login_history: already has $existing entries or source empty");
    }
} else {
    mlog("[SKIP] login_history: file not found");
}

// 2. WAN Stats
$file = __DIR__ . '/data/wan_stats.json';
if (file_exists($file)) {
    $data = json_decode(file_get_contents($file), true) ?: [];
    $existing = $db->query("SELECT COUNT(*) FROM wan_stats")->fetchColumn();
    if ($existing == 0 && count($data) > 0) {
        $stmt = $db->prepare("INSERT INTO wan_stats (rx_bytes, tx_bytes, recorded_at) VALUES (?, ?, datetime(?, 'unixepoch'))");
        $count = 0;
        foreach ($data as $entry) {
            $stmt->execute([
                $entry['rx'] ?? 0,
                $entry['tx'] ?? 0,
                $entry['timestamp'] ?? time()
            ]);
            $count++;
        }
        mlog("[OK] wan_stats: migrated $count entries");
    } else {
        mlog("[SKIP] wan_stats: already has $existing entries or source empty");
    }
} else {
    mlog("[SKIP] wan_stats: file not found");
}

// 3. Device Monitors
$file = __DIR__ . '/data/devices.json';
if (file_exists($file)) {
    $data = json_decode(file_get_contents($file), true) ?: [];
    $existing = $db->query("SELECT COUNT(*) FROM device_monitors")->fetchColumn();
    if ($existing == 0 && count($data) > 0) {
        $stmt = $db->prepare("INSERT OR IGNORE INTO device_monitors (mac, name, vlan, added_at) VALUES (?, ?, ?, ?)");
        $count = 0;
        foreach ($data as $mac => $dev) {
            $stmt->execute([
                $mac,
                $dev['name'] ?? 'Unknown',
                $dev['vlan'] ?? '0',
                $dev['added_at'] ?? date('Y-m-d H:i:s')
            ]);
            $count++;
        }
        mlog("[OK] device_monitors: migrated $count entries");
    } else {
        mlog("[SKIP] device_monitors: already has $existing entries or source empty");
    }
} else {
    mlog("[SKIP] device_monitors: file not found");
}

// 4. Device Status History
$file = __DIR__ . '/data/history.json';
if (file_exists($file)) {
    $data = json_decode(file_get_contents($file), true) ?: [];
    $existing = $db->query("SELECT COUNT(*) FROM device_status_history")->fetchColumn();
    if ($existing == 0 && count($data) > 0) {
        $stmt = $db->prepare("INSERT INTO device_status_history (mac, status, duration, timestamp) VALUES (?, ?, ?, ?)");
        $count = 0;
        foreach ($data as $mac => $entries) {
            foreach ($entries as $entry) {
                $stmt->execute([
                    $mac,
                    $entry['status'] ?? 'unknown',
                    $entry['duration'] ?? 0,
                    $entry['timestamp'] ?? date('Y-m-d H:i:s')
                ]);
                $count++;
            }
        }
        mlog("[OK] device_status_history: migrated $count entries");
    } else {
        mlog("[SKIP] device_status_history: already has $existing entries or source empty");
    }
} else {
    mlog("[SKIP] device_status_history: file not found");
}

mlog("\n=== Migration complete ===");
```

- [ ] **Step 2: Run migration**

Open in browser: `https://<server>/migrate_json.php` (must be logged in) or run `php migrate_json.php` from CLI. Verify output shows `[OK]` for each data source.

- [ ] **Step 3: Commit**

```bash
git add migrate_json.php
git commit -m "feat: add JSON to SQLite migration script"
```

---

## Task 3: Update functions.php — Replace JSON with SQLite

**Files:**
- Modify: `functions.php` (lines 7-14, 172-222, 708-793)

- [ ] **Step 1: Replace loadDevices() (lines 7-14)**

Old:
```php
function loadDevices() {
    $file = __DIR__ . '/data/devices.json';
    if (!file_exists($file)) return [];
    $data = json_decode(file_get_contents($file), true);
    return is_array($data) ? $data : [];
}
```

New:
```php
function loadDevices() {
    global $db;
    if (!isset($db)) {
        // Fallback to JSON if db.php not loaded
        $file = __DIR__ . '/data/devices.json';
        if (!file_exists($file)) return [];
        $data = json_decode(file_get_contents($file), true);
        return is_array($data) ? $data : [];
    }
    $stmt = $db->query("SELECT mac, name, vlan, added_at FROM device_monitors WHERE enabled = 1");
    $devices = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $devices[$row['mac']] = [
            'name' => $row['name'],
            'vlan' => $row['vlan'],
            'added_at' => $row['added_at']
        ];
    }
    return $devices;
}
```

- [ ] **Step 2: Replace saveDevices() (lines 708-712)**

Old:
```php
function saveDevices(array $devices) {
    $file = __DIR__ . '/data/devices.json';
    file_put_contents($file, json_encode($devices, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}
```

New:
```php
function saveDevices(array $devices) {
    global $db;
    if (!isset($db)) {
        $file = __DIR__ . '/data/devices.json';
        file_put_contents($file, json_encode($devices, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        return;
    }
    // Sync: insert new, update existing, remove deleted
    $existing_macs = [];
    $stmt = $db->query("SELECT mac FROM device_monitors");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $existing_macs[] = $row['mac'];
    }

    $insert = $db->prepare("INSERT OR REPLACE INTO device_monitors (mac, name, vlan, added_at) VALUES (?, ?, ?, ?)");
    foreach ($devices as $mac => $dev) {
        $insert->execute([$mac, $dev['name'], $dev['vlan'] ?? '0', $dev['added_at'] ?? date('Y-m-d H:i:s')]);
    }

    // Remove devices no longer in list
    $new_macs = array_keys($devices);
    $to_delete = array_diff($existing_macs, $new_macs);
    if ($to_delete) {
        $placeholders = implode(',', array_fill(0, count($to_delete), '?'));
        $db->prepare("DELETE FROM device_monitors WHERE mac IN ($placeholders)")->execute(array_values($to_delete));
    }
}
```

- [ ] **Step 3: Replace log_login_event() (lines 172-222)**

Old function writes to `data/login_history.json`. Replace body with:

```php
function log_login_event($username) {
    global $db;

    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';

    // Parse OS
    $os = 'Unknown';
    if (stripos($ua, 'Windows') !== false) $os = 'Windows';
    elseif (stripos($ua, 'Mac') !== false) $os = 'macOS';
    elseif (stripos($ua, 'Linux') !== false) $os = 'Linux';
    elseif (stripos($ua, 'Android') !== false) $os = 'Android';
    elseif (stripos($ua, 'iPhone') !== false || stripos($ua, 'iPad') !== false) $os = 'iOS';

    // Parse Browser
    $browser = 'Unknown';
    if (stripos($ua, 'Chrome') !== false && stripos($ua, 'Edg') === false) $browser = 'Chrome';
    elseif (stripos($ua, 'Firefox') !== false) $browser = 'Firefox';
    elseif (stripos($ua, 'Safari') !== false && stripos($ua, 'Chrome') === false) $browser = 'Safari';
    elseif (stripos($ua, 'Edg') !== false) $browser = 'Edge';
    elseif (stripos($ua, 'Opera') !== false || stripos($ua, 'OPR') !== false) $browser = 'Opera';

    // GeoIP (skip for local IPs)
    $location = 'Local Network';
    if (!preg_match('/^(10\.|192\.168\.|172\.(1[6-9]|2[0-9]|3[01])\.|127\.)/', $ip)) {
        $location = get_ip_location($ip) ?: 'Unknown';
    }

    if (isset($db)) {
        $stmt = $db->prepare("INSERT INTO login_history (username, ip, location, os, browser, user_agent) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$username, $ip, $location, $os, $browser, $ua]);
    }
}
```

- [ ] **Step 4: Replace loadDeviceHistory() and saveDeviceHistory() (lines 714-793)**

Old `loadDeviceHistory($mac)` reads from `data/history.json`. Replace:

```php
function loadDeviceHistory($mac) {
    global $db;
    $mac = strtolower(preg_replace('/[^a-fA-F0-9]/', '', $mac));
    if (isset($db)) {
        $stmt = $db->prepare("SELECT status, duration, timestamp FROM device_status_history WHERE mac = ? ORDER BY timestamp DESC LIMIT 50");
        $stmt->execute([$mac]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    // Fallback to JSON
    $file = __DIR__ . '/data/history.json';
    if (!file_exists($file)) return [];
    $all = json_decode(file_get_contents($file), true) ?: [];
    return $all[$mac] ?? [];
}
```

Old `saveDeviceHistory($mac, $status)` writes JSON + sends alert. Replace:

```php
function saveDeviceHistory($mac, $status) {
    global $db, $config;
    $mac = strtolower(preg_replace('/[^a-fA-F0-9]/', '', $mac));

    if (isset($db)) {
        // Get last entry to calculate duration
        $stmt = $db->prepare("SELECT id, status, timestamp FROM device_status_history WHERE mac = ? ORDER BY timestamp DESC LIMIT 1");
        $stmt->execute([$mac]);
        $last = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($last && $last['status'] === $status) return; // No change

        $now = date('Y-m-d H:i:s');
        $duration = 0;
        if ($last) {
            $duration = strtotime($now) - strtotime($last['timestamp']);
            // Update previous entry duration
            $db->prepare("UPDATE device_status_history SET duration = ? WHERE id = ?")->execute([$duration, $last['id']]);
        }

        $db->prepare("INSERT INTO device_status_history (mac, status, timestamp) VALUES (?, ?, ?)")->execute([$mac, $status, $now]);

        // Send alert on status change
        $devices = loadDevices();
        $name = $devices[$mac]['name'] ?? $mac;
        if ($status === 'off') {
            sendAlert("Device Offline: $name", "**$name** ($mac) is now OFFLINE");
        } elseif ($status === 'on' && $last) {
            sendAlert("Device Online: $name", "**$name** ($mac) is back ONLINE after " . formatDuration($duration));
        }
    }
}
```

- [ ] **Step 5: Verify dashboard still works**

Load index.php in browser. Check: clients load, devices show, monitored devices display correctly. Check that device add/remove in devices.php still works.

- [ ] **Step 6: Commit**

```bash
git add functions.php
git commit -m "feat: migrate functions.php from JSON to SQLite storage"
```

---

## Task 4: Update WAN Stats to SQLite

**Files:**
- Modify: `update_wan.php`

- [ ] **Step 1: Read current update_wan.php**

Read the file to understand current JSON writing pattern.

- [ ] **Step 2: Add SQLite write alongside JSON**

At the top of update_wan.php, after `require_once 'config.php';`, add:
```php
require_once 'db.php';
```

Find where `wan_stats.json` is written (the `file_put_contents` for wan_stats) and add after it:
```php
// Write to SQLite
if (isset($db)) {
    $stmt = $db->prepare("INSERT INTO wan_stats (rx_bytes, tx_bytes) VALUES (?, ?)");
    $stmt->execute([$rx ?? 0, $tx ?? 0]);
}
```

Keep JSON write as fallback for now — remove in final cleanup.

- [ ] **Step 3: Verify**

Load dashboard, wait 30s for auto-update. Check SQLite:
```bash
sqlite3 data/minidash.db "SELECT COUNT(*) FROM wan_stats;"
```

- [ ] **Step 4: Commit**

```bash
git add update_wan.php
git commit -m "feat: write WAN stats to SQLite"
```

---

## Task 5: Add db.php Include to All Pages

**Files:**
- Modify: `index.php`, `security.php`, `logs.php`, `protect.php`, `monitored.php`, `history.php`, `devices.php`, `settings_notifications.php`, `login.php`

- [ ] **Step 1: Add require_once 'db.php' to each page**

In every PHP page that has `require_once 'config.php';` (or `require __DIR__ . '/config.php';`), add immediately after:
```php
require_once 'db.php';
```

Pages to update: `index.php`, `security.php`, `logs.php`, `protect.php`, `monitored.php`, `history.php`, `devices.php`, `settings_notifications.php`, `events.php`.

Special case: `login.php` — add `require_once 'db.php';` after `require_once 'config.php';` but BEFORE the session check (login.php needs DB for log_login_event but doesn't require login).

- [ ] **Step 2: Verify all pages load**

Open each page in browser. Check no errors. Dashboard, security, logs, protect, monitored, history, devices, settings should all load normally.

- [ ] **Step 3: Commit**

```bash
git add index.php security.php logs.php protect.php monitored.php history.php devices.php settings_notifications.php login.php events.php
git commit -m "feat: add SQLite layer to all pages"
```

---

## Task 6: Credential Encryption — crypto.php

**Files:**
- Create: `crypto.php`
- Modify: `config.php` (lines 78-87, load_app_config)

- [ ] **Step 1: Create crypto.php**

```php
<?php
/**
 * Credential Encryption - MiniDash v2.0.0
 * Uses sodium_crypto_secretbox (PHP 8 built-in).
 * Include after config.php when you need to encrypt/decrypt config values.
 */

define('ENCRYPTION_KEY_FILE', __DIR__ . '/data/.encryption_key');
define('ENC_PREFIX', 'ENC:');

function get_encryption_key(): string {
    if (file_exists(ENCRYPTION_KEY_FILE)) {
        $key = file_get_contents(ENCRYPTION_KEY_FILE);
        if (strlen($key) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            return $key;
        }
    }
    // Generate new key
    $key = sodium_crypto_secretbox_keygen();
    $dir = dirname(ENCRYPTION_KEY_FILE);
    if (!is_dir($dir)) mkdir($dir, 0700, true);
    file_put_contents(ENCRYPTION_KEY_FILE, $key);
    chmod(ENCRYPTION_KEY_FILE, 0600);
    return $key;
}

function encrypt_value(string $plaintext): string {
    if (str_starts_with($plaintext, ENC_PREFIX)) return $plaintext; // Already encrypted
    if (empty($plaintext)) return $plaintext;

    $key = get_encryption_key();
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $cipher = sodium_crypto_secretbox($plaintext, $nonce, $key);
    return ENC_PREFIX . base64_encode($nonce . $cipher);
}

function decrypt_value(string $encrypted): string {
    if (!str_starts_with($encrypted, ENC_PREFIX)) return $encrypted; // Not encrypted
    if (empty($encrypted)) return $encrypted;

    $key = get_encryption_key();
    $decoded = base64_decode(substr($encrypted, strlen(ENC_PREFIX)));
    if ($decoded === false) return $encrypted;

    $nonce = substr($decoded, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $cipher = substr($decoded, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

    $plaintext = sodium_crypto_secretbox_open($cipher, $nonce, $key);
    if ($plaintext === false) return $encrypted; // Decryption failed, return as-is

    return $plaintext;
}

// Fields that should be encrypted in config.json
define('ENCRYPTED_FIELDS', [
    'api_key',
    'admin_password',
    'email_notifications.smtp_password',
    'telegram_notifications.bot_token',
    'whatsapp_notifications.api_key',
    'slack_notifications.webhook_url',
    'sms_notifications.api_key',
    'discord_notifications.webhook_url',
    'n8n_notifications.webhook_url',
]);

function encrypt_config(array &$config): void {
    foreach (ENCRYPTED_FIELDS as $path) {
        $parts = explode('.', $path);
        if (count($parts) === 1) {
            if (isset($config[$parts[0]]) && !empty($config[$parts[0]])) {
                $config[$parts[0]] = encrypt_value($config[$parts[0]]);
            }
        } elseif (count($parts) === 2) {
            if (isset($config[$parts[0]][$parts[1]]) && !empty($config[$parts[0]][$parts[1]])) {
                $config[$parts[0]][$parts[1]] = encrypt_value($config[$parts[0]][$parts[1]]);
            }
        }
    }
}

function decrypt_config(array &$config): void {
    foreach (ENCRYPTED_FIELDS as $path) {
        $parts = explode('.', $path);
        if (count($parts) === 1) {
            if (isset($config[$parts[0]])) {
                $config[$parts[0]] = decrypt_value($config[$parts[0]]);
            }
        } elseif (count($parts) === 2) {
            if (isset($config[$parts[0]][$parts[1]])) {
                $config[$parts[0]][$parts[1]] = decrypt_value($config[$parts[0]][$parts[1]]);
            }
        }
    }
}
```

- [ ] **Step 2: Modify config.php — add crypto to load_app_config()**

In config.php, after the `.env` loader block (around line 22), add:
```php
require_once __DIR__ . '/crypto.php';
```

Then modify `load_app_config()` to decrypt after loading:

```php
function load_app_config($defaults) {
    $configFile = __DIR__ . '/data/config.json';
    if (file_exists($configFile)) {
        $dynamic = json_decode(file_get_contents($configFile), true);
        if (is_array($dynamic)) {
            $merged = array_replace_recursive($defaults, $dynamic);
            decrypt_config($merged);
            return $merged;
        }
    }
    return $defaults;
}
```

- [ ] **Step 3: Modify api_save_settings.php — encrypt before saving**

In `api_save_settings.php`, before writing to config.json (the `file_put_contents` call), add:
```php
require_once __DIR__ . '/crypto.php';
encrypt_config($newConfig);
```

- [ ] **Step 4: Modify api_user_settings.php — encrypt before saving**

Same pattern: before writing config.json, call `encrypt_config()`.

- [ ] **Step 5: Verify**

1. Open settings_notifications.php, save settings
2. Check `data/config.json` — sensitive fields should show `ENC:...` prefix
3. Load dashboard — should work normally (decryption transparent)

- [ ] **Step 6: Commit**

```bash
git add crypto.php config.php api_save_settings.php api_user_settings.php
git commit -m "feat: add credential encryption with sodium_crypto_secretbox"
```

---

## Task 7: Footer Component

**Files:**
- Create: `includes/footer.php`
- Modify: `config.php` (add version constant)

- [ ] **Step 1: Add version constant to config.php**

At the very top of config.php, after `<?php` and the copyright comment, add:
```php
define('MINIDASH_VERSION', '2.0.0');
```

- [ ] **Step 2: Create includes/footer.php**

```php
<!-- MiniDash Footer -->
<footer class="mt-12 border-t border-white/5">
    <div class="max-w-7xl mx-auto px-6 py-6 flex items-end justify-between">
        <div>
            <div class="text-xs text-slate-500">LM-Networks &copy; <?= date('Y') ?> &middot; Wszelkie prawa zastrzezone</div>
            <div class="text-[10px] text-slate-600 mt-1">Czesc ekosystemu LuMiGRAF Solutions obejmujacego rowniez narzedzia deweloperskie oraz studio kreatywne</div>
        </div>
        <div class="text-xs text-slate-600 font-mono">MiniDash v<?= MINIDASH_VERSION ?></div>
    </div>
</footer>
```

- [ ] **Step 3: Include footer in all pages**

In every page that has a closing `</body>` tag, add before it:
```php
<?php include __DIR__ . '/includes/footer.php'; ?>
```

Pages: `index.php`, `security.php`, `logs.php`, `protect.php`, `monitored.php`, `history.php`, `devices.php`, `settings_notifications.php`, `stalker.php` (when created).

- [ ] **Step 4: Verify**

Load any page, scroll to bottom. Footer should show:
- Left: "LM-Networks (c) 2026 . Wszelkie prawa zastrzezone" + smaller LuMiGRAF line
- Right: "MiniDash v2.0.0"

- [ ] **Step 5: Commit**

```bash
git add includes/footer.php config.php index.php security.php logs.php protect.php monitored.php history.php devices.php settings_notifications.php
git commit -m "feat: add footer with version and LM-Networks branding"
```

---

## Task 8: Discord + n8n Webhooks

**Files:**
- Modify: `config.php` (add config defaults + send functions + update sendAlert)
- Modify: `settings_notifications.php` (add 2 panels)
- Modify: `api_save_settings.php` (add new fields)

- [ ] **Step 1: Add config defaults in config.php**

In the `$config` array, after `'ntfy_notifications'` block, add:
```php
    'discord_notifications' => [
        'enabled' => false,
        'webhook_url' => '',
        'username' => 'MiniDash'
    ],
    'n8n_notifications' => [
        'enabled' => false,
        'webhook_url' => ''
    ],
```

- [ ] **Step 2: Add send functions in config.php**

After `sendSmsNotification()`, add:

```php
function sendDiscordNotification($message) {
    global $config;
    $url = $config['discord_notifications']['webhook_url'];
    if (empty($url)) return;

    $data = json_encode([
        'username' => $config['discord_notifications']['username'] ?? 'MiniDash',
        'embeds' => [[
            'title' => 'MiniDash Alert',
            'description' => strip_tags($message),
            'color' => 3447003, // Blue
            'timestamp' => date('c'),
            'footer' => ['text' => 'MiniDash v' . MINIDASH_VERSION]
        ]]
    ]);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    @curl_exec($ch);
    curl_close($ch);
}

function sendN8nNotification($message) {
    global $config;
    $url = $config['n8n_notifications']['webhook_url'];
    if (empty($url)) return;

    $data = json_encode([
        'source' => 'MiniDash',
        'version' => MINIDASH_VERSION,
        'message' => strip_tags($message),
        'severity' => 'alert',
        'timestamp' => date('c')
    ]);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    @curl_exec($ch);
    curl_close($ch);
}
```

- [ ] **Step 3: Update sendAlert() in config.php**

Add to the `sendAlert()` function body, after the ntfy block:

```php
    if ($config['discord_notifications'] && $config['discord_notifications']['enabled']) {
        sendDiscordNotification($message);
    }

    if ($config['n8n_notifications'] && $config['n8n_notifications']['enabled']) {
        sendN8nNotification($message);
    }
```

- [ ] **Step 4: Add Discord panel in settings_notifications.php**

After the ntfy panel (last notification panel), before the closing `</div>` of the panels container, add:

```php
                <!-- Discord -->
                <div class="setting-card rounded-3xl p-8">
                    <div class="flex items-center justify-between mb-8">
                        <div class="flex items-center gap-4">
                            <div class="w-12 h-12 rounded-2xl bg-indigo-500/10 flex items-center justify-center">
                                <i data-lucide="message-circle" class="w-6 h-6 text-indigo-400"></i>
                            </div>
                            <div>
                                <h3 class="text-lg font-bold text-white">Discord</h3>
                                <p class="text-xs text-slate-500">Powiadomienia przez Discord Webhook</p>
                            </div>
                        </div>
                        <label class="relative inline-flex items-center cursor-pointer">
                            <input type="checkbox" name="discord_enabled" class="sr-only peer" <?= ($config['discord_notifications']['enabled'] ?? false) ? 'checked' : '' ?>>
                            <div class="w-14 h-7 bg-slate-700 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-6 after:w-6 after:transition-all peer-checked:bg-indigo-600"></div>
                        </label>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label class="text-xs font-bold text-slate-400 uppercase tracking-wider mb-2 block">Webhook URL</label>
                            <input type="url" name="discord_webhook_url" value="<?= htmlspecialchars($config['discord_notifications']['webhook_url'] ?? '') ?>" class="w-full bg-slate-900/50 border border-white/10 rounded-xl px-4 py-3 text-sm text-white placeholder-slate-600 focus:outline-none focus:ring-2 focus:ring-indigo-500/50" placeholder="https://discord.com/api/webhooks/...">
                        </div>
                        <div>
                            <label class="text-xs font-bold text-slate-400 uppercase tracking-wider mb-2 block">Nazwa bota</label>
                            <input type="text" name="discord_username" value="<?= htmlspecialchars($config['discord_notifications']['username'] ?? 'MiniDash') ?>" class="w-full bg-slate-900/50 border border-white/10 rounded-xl px-4 py-3 text-sm text-white placeholder-slate-600 focus:outline-none focus:ring-2 focus:ring-indigo-500/50" placeholder="MiniDash">
                        </div>
                    </div>
                </div>

                <!-- n8n -->
                <div class="setting-card rounded-3xl p-8">
                    <div class="flex items-center justify-between mb-8">
                        <div class="flex items-center gap-4">
                            <div class="w-12 h-12 rounded-2xl bg-orange-500/10 flex items-center justify-center">
                                <i data-lucide="webhook" class="w-6 h-6 text-orange-400"></i>
                            </div>
                            <div>
                                <h3 class="text-lg font-bold text-white">n8n</h3>
                                <p class="text-xs text-slate-500">Generic webhook do automatyzacji n8n</p>
                            </div>
                        </div>
                        <label class="relative inline-flex items-center cursor-pointer">
                            <input type="checkbox" name="n8n_enabled" class="sr-only peer" <?= ($config['n8n_notifications']['enabled'] ?? false) ? 'checked' : '' ?>>
                            <div class="w-14 h-7 bg-slate-700 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-6 after:w-6 after:transition-all peer-checked:bg-orange-600"></div>
                        </label>
                    </div>
                    <div>
                        <label class="text-xs font-bold text-slate-400 uppercase tracking-wider mb-2 block">Webhook URL</label>
                        <input type="url" name="n8n_webhook_url" value="<?= htmlspecialchars($config['n8n_notifications']['webhook_url'] ?? '') ?>" class="w-full bg-slate-900/50 border border-white/10 rounded-xl px-4 py-3 text-sm text-white placeholder-slate-600 focus:outline-none focus:ring-2 focus:ring-indigo-500/50" placeholder="https://n8n.example.com/webhook/...">
                    </div>
                </div>
```

- [ ] **Step 5: Update api_save_settings.php**

In the `$newConfig` array, add:

```php
    'discord_notifications' => [
        'enabled' => isset($_POST['discord_enabled']),
        'webhook_url' => $_POST['discord_webhook_url'] ?? '',
        'username' => $_POST['discord_username'] ?? 'MiniDash'
    ],
    'n8n_notifications' => [
        'enabled' => isset($_POST['n8n_enabled']),
        'webhook_url' => $_POST['n8n_webhook_url'] ?? ''
    ],
```

- [ ] **Step 6: Verify**

Open settings_notifications.php. Discord and n8n panels should appear with correct styling. Toggle enable, fill in test URL, save. Check config.json has the new values (encrypted).

- [ ] **Step 7: Commit**

```bash
git add config.php settings_notifications.php api_save_settings.php
git commit -m "feat: add Discord and n8n webhook notification channels"
```

---

## Task 9: Threat Watch Improvements — API + Security Page

**Files:**
- Create: `api_threat_ignore.php`
- Modify: `security.php`

- [ ] **Step 1: Create api_threat_ignore.php**

```php
<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $stmt = $db->query("SELECT id, ip, label, reason, added_at FROM threat_ignore ORDER BY added_at DESC");
    echo json_encode(['data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $ip = trim($input['ip'] ?? '');
    $label = trim($input['label'] ?? '');
    $reason = trim($input['reason'] ?? '');

    if (empty($ip)) {
        http_response_code(400);
        echo json_encode(['error' => 'IP is required']);
        exit;
    }

    $stmt = $db->prepare("INSERT OR IGNORE INTO threat_ignore (ip, label, reason) VALUES (?, ?, ?)");
    $stmt->execute([$ip, $label, $reason]);

    echo json_encode(['success' => true, 'message' => "IP $ip added to ignore list"]);
    exit;
}

if ($method === 'DELETE') {
    $input = json_decode(file_get_contents('php://input'), true);
    $id = intval($input['id'] ?? 0);

    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'ID is required']);
        exit;
    }

    $db->prepare("DELETE FROM threat_ignore WHERE id = ?")->execute([$id]);
    echo json_encode(['success' => true]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
```

- [ ] **Step 2: Modify security.php — load ignore list**

At the top of security.php, after the data fetching section (around line 18, after `$blocked_ips_list`), add:

```php
// Load threat ignore list
$ignore_ips = [];
if (isset($db)) {
    $stmt = $db->query("SELECT ip FROM threat_ignore");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $ignore_ips[] = $row['ip'];
    }
}
// Filter out ignored IPs from blocked list
$blocked_ips_list = array_filter($blocked_ips_list, function($item) use ($ignore_ips) {
    $ip = $item['src_ip'] ?? $item['ip'] ?? '';
    return !in_array($ip, $ignore_ips);
});
```

- [ ] **Step 3: Add time range filter to security.php**

Find the existing time range selector buttons (around lines 346-364). These already exist in your UI. Make them functional by adding a JS variable and filter logic:

After the events section JS, add:
```javascript
let threatTimeRange = '24h';

function setThreatTimeRange(range) {
    threatTimeRange = range;
    document.querySelectorAll('.time-range-btn').forEach(btn => {
        btn.classList.remove('bg-blue-600', 'text-white');
        btn.classList.add('bg-slate-800', 'text-slate-400');
    });
    event.target.classList.remove('bg-slate-800', 'text-slate-400');
    event.target.classList.add('bg-blue-600', 'text-white');

    const now = Date.now();
    const ranges = { '1h': 3600000, '24h': 86400000, '7d': 604800000, '30d': 2592000000 };
    const cutoff = ranges[range] ? now - ranges[range] : 0;

    document.querySelectorAll('.threat-event-row').forEach(row => {
        const ts = parseInt(row.dataset.timestamp || 0) * 1000;
        row.style.display = (cutoff === 0 || ts >= cutoff) ? '' : 'none';
    });
}
```

Add `class="time-range-btn"` and `onclick="setThreatTimeRange('...')"` to each time range button. Add `data-timestamp="<?= $event['timestamp'] ?>"` and `class="threat-event-row"` to each event row.

- [ ] **Step 4: Add ignore list modal to security.php**

Before the closing `</body>`, add the ignore list modal:

```php
<!-- Threat Ignore List Modal -->
<div id="ignoreListModal" class="fixed inset-0 bg-black/70 backdrop-blur-sm z-50 hidden items-center justify-center" onclick="if(event.target===this)closeIgnoreModal()">
    <div class="bg-slate-900/95 backdrop-blur-xl border border-white/10 rounded-3xl p-8 w-full max-w-2xl max-h-[80vh] overflow-y-auto shadow-2xl">
        <div class="flex items-center justify-between mb-6">
            <h2 class="text-xl font-bold text-white flex items-center gap-3">
                <i data-lucide="shield-off" class="w-6 h-6 text-amber-400"></i>
                Ignorowane adresy IP
            </h2>
            <button onclick="closeIgnoreModal()" class="text-slate-500 hover:text-white transition">
                <i data-lucide="x" class="w-6 h-6"></i>
            </button>
        </div>

        <!-- Add form -->
        <div class="flex gap-3 mb-6">
            <input type="text" id="ignore-ip" placeholder="Adres IP" class="flex-1 bg-slate-800/50 border border-white/10 rounded-xl px-4 py-2 text-sm text-white placeholder-slate-600 focus:outline-none focus:ring-2 focus:ring-amber-500/50">
            <input type="text" id="ignore-label" placeholder="Etykieta (opcjonalnie)" class="flex-1 bg-slate-800/50 border border-white/10 rounded-xl px-4 py-2 text-sm text-white placeholder-slate-600 focus:outline-none focus:ring-2 focus:ring-amber-500/50">
            <button onclick="addIgnoreIP()" class="px-4 py-2 bg-amber-600 hover:bg-amber-500 text-white rounded-xl text-sm font-bold transition">
                <i data-lucide="plus" class="w-4 h-4"></i>
            </button>
        </div>

        <!-- List -->
        <div id="ignore-list-body">
            <div class="text-center text-slate-500 py-4">Ladowanie...</div>
        </div>
    </div>
</div>

<script>
function openIgnoreModal() {
    document.getElementById('ignoreListModal').classList.remove('hidden');
    document.getElementById('ignoreListModal').classList.add('flex');
    loadIgnoreList();
    lucide.createIcons();
}
function closeIgnoreModal() {
    document.getElementById('ignoreListModal').classList.add('hidden');
    document.getElementById('ignoreListModal').classList.remove('flex');
}
async function loadIgnoreList() {
    const resp = await fetch('api_threat_ignore.php');
    const json = await resp.json();
    const list = json.data || [];
    const body = document.getElementById('ignore-list-body');
    if (list.length === 0) {
        body.innerHTML = '<div class="text-center text-slate-500 py-4">Brak ignorowanych adresow IP</div>';
        return;
    }
    body.innerHTML = '<table class="w-full"><thead><tr class="text-xs text-slate-500 uppercase"><th class="text-left py-2 px-3">IP</th><th class="text-left py-2 px-3">Etykieta</th><th class="text-left py-2 px-3">Dodano</th><th class="py-2 px-3"></th></tr></thead><tbody>' +
        list.map(item => `<tr class="border-t border-white/5 hover:bg-white/[0.02]">
            <td class="py-3 px-3 text-sm font-mono text-white">${item.ip}</td>
            <td class="py-3 px-3 text-sm text-slate-400">${item.label || '-'}</td>
            <td class="py-3 px-3 text-xs text-slate-500">${item.added_at}</td>
            <td class="py-3 px-3 text-right"><button onclick="removeIgnoreIP(${item.id})" class="text-red-400 hover:text-red-300 transition"><i data-lucide="trash-2" class="w-4 h-4"></i></button></td>
        </tr>`).join('') + '</tbody></table>';
    lucide.createIcons();
}
async function addIgnoreIP() {
    const ip = document.getElementById('ignore-ip').value.trim();
    const label = document.getElementById('ignore-label').value.trim();
    if (!ip) return;
    await fetch('api_threat_ignore.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ip, label})
    });
    document.getElementById('ignore-ip').value = '';
    document.getElementById('ignore-label').value = '';
    loadIgnoreList();
}
async function removeIgnoreIP(id) {
    await fetch('api_threat_ignore.php', {
        method: 'DELETE',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({id})
    });
    loadIgnoreList();
}
</script>
```

- [ ] **Step 5: Add "Ignorowane IP" button to security.php stats bar**

In the stats grid area (around line 257-322), add a button. Find a good spot in the top action area and add:

```php
<button onclick="openIgnoreModal()" class="px-4 py-2 bg-amber-600/20 hover:bg-amber-600/30 text-amber-400 rounded-xl text-xs font-bold transition border border-amber-500/20">
    <i data-lucide="shield-off" class="w-4 h-4 inline mr-1"></i> Ignorowane IP
</button>
```

- [ ] **Step 6: Verify**

1. Open security.php
2. Click "Ignorowane IP" — modal opens
3. Add an IP, verify it appears in list
4. Delete it, verify it's removed
5. Time filter buttons filter events by time range

- [ ] **Step 7: Commit**

```bash
git add api_threat_ignore.php security.php
git commit -m "feat: add IP ignore list and time filters to Threat Watch"
```

---

## Task 10: Wi-Fi Stalker — Backend API

**Files:**
- Create: `api_stalker.php`

- [ ] **Step 1: Create api_stalker.php**

```php
<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? 'sessions';

switch ($action) {

    case 'poll':
        // Fetch current clients from UniFi and detect roaming
        $siteId = $config['site'] ?? 'default';
        $clients_raw = fetch_api("/proxy/network/api/s/default/stat/sta");
        $clients = $clients_raw['data'] ?? [];

        $changes = ['new' => 0, 'roaming' => 0, 'disconnected' => 0];

        foreach ($clients as $c) {
            $mac = strtolower($c['mac'] ?? '');
            if (empty($mac)) continue;
            if ($c['is_wired'] ?? false) continue; // Only wireless

            $hostname = $c['name'] ?? $c['hostname'] ?? $mac;
            $ap_mac = strtolower($c['ap_mac'] ?? '');
            $ssid = $c['essid'] ?? '';
            $channel = $c['channel'] ?? 0;
            $rssi = $c['rssi'] ?? $c['signal'] ?? 0;
            $rx_rate = ($c['rx_rate'] ?? 0) / 1000;
            $tx_rate = ($c['tx_rate'] ?? 0) / 1000;
            $radio = $c['radio'] ?? '';

            // Determine band from radio or channel
            $band = '2.4GHz';
            if ($radio === 'na' || $channel > 14) $band = '5GHz';
            if ($radio === '6e' || $channel > 177) $band = '6GHz';

            // Find AP name
            $ap_name = $ap_mac;
            // Try to get from infrastructure devices
            static $ap_names = null;
            if ($ap_names === null) {
                $ap_names = [];
                $devs = fetch_api("/proxy/network/api/s/default/stat/device");
                foreach (($devs['data'] ?? []) as $d) {
                    $ap_names[strtolower($d['mac'] ?? '')] = $d['name'] ?? $d['model'] ?? $d['mac'];
                }
            }
            if (isset($ap_names[$ap_mac])) $ap_name = $ap_names[$ap_mac];

            // Check last known session
            $stmt = $db->prepare("SELECT id, ap_mac, ap_name, channel, rssi FROM stalker_sessions WHERE mac = ? AND disconnected_at IS NULL ORDER BY connected_at DESC LIMIT 1");
            $stmt->execute([$mac]);
            $last = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$last) {
                // New session
                $db->prepare("INSERT INTO stalker_sessions (mac, hostname, ap_mac, ap_name, ssid, channel, band, rssi, rx_rate, tx_rate) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                    ->execute([$mac, $hostname, $ap_mac, $ap_name, $ssid, $channel, $band, $rssi, $rx_rate, $tx_rate]);
                $changes['new']++;
            } elseif ($last['ap_mac'] !== $ap_mac) {
                // Roaming detected!
                // Close old session
                $db->prepare("UPDATE stalker_sessions SET disconnected_at = datetime('now') WHERE id = ?")->execute([$last['id']]);

                // Log roaming event
                $db->prepare("INSERT INTO stalker_roaming (mac, hostname, from_ap, to_ap, from_channel, to_channel, rssi_before, rssi_after) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
                    ->execute([$mac, $hostname, $last['ap_name'], $ap_name, $last['channel'], $channel, $last['rssi'], $rssi]);

                // New session
                $db->prepare("INSERT INTO stalker_sessions (mac, hostname, ap_mac, ap_name, ssid, channel, band, rssi, rx_rate, tx_rate) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                    ->execute([$mac, $hostname, $ap_mac, $ap_name, $ssid, $channel, $band, $rssi, $rx_rate, $tx_rate]);

                $changes['roaming']++;

                // Alert if on watchlist
                $watch = $db->prepare("SELECT notify FROM stalker_watchlist WHERE mac = ? AND notify = 1");
                $watch->execute([$mac]);
                if ($watch->fetch()) {
                    sendAlert("Roaming: $hostname", "$hostname przeniosl sie z {$last['ap_name']} na $ap_name (RSSI: {$last['rssi']}dBm -> {$rssi}dBm)");
                }
            } else {
                // Same AP, update stats
                $db->prepare("UPDATE stalker_sessions SET rssi = ?, rx_rate = ?, tx_rate = ?, hostname = ? WHERE id = ?")
                    ->execute([$rssi, $rx_rate, $tx_rate, $hostname, $last['id']]);
            }
        }

        // Mark disconnected clients
        $active_macs = array_map(fn($c) => strtolower($c['mac'] ?? ''), $clients);
        $active_macs = array_filter($active_macs);
        if ($active_macs) {
            $placeholders = implode(',', array_fill(0, count($active_macs), '?'));
            $db->prepare("UPDATE stalker_sessions SET disconnected_at = datetime('now') WHERE disconnected_at IS NULL AND mac NOT IN ($placeholders)")
                ->execute(array_values($active_macs));
        }

        echo json_encode(['success' => true, 'changes' => $changes]);
        break;

    case 'sessions':
        $time_filter = $_GET['time'] ?? '24h';
        $band_filter = $_GET['band'] ?? '';
        $search = $_GET['search'] ?? '';

        $where = ["s.disconnected_at IS NULL"];
        $params = [];

        if ($band_filter) {
            $where[] = "s.band = ?";
            $params[] = $band_filter;
        }
        if ($search) {
            $where[] = "(s.hostname LIKE ? OR s.mac LIKE ?)";
            $params[] = "%$search%";
            $params[] = "%$search%";
        }

        $where_sql = implode(' AND ', $where);
        $stmt = $db->prepare("SELECT s.*, (SELECT COUNT(*) FROM stalker_watchlist w WHERE w.mac = s.mac) as is_watched FROM stalker_sessions s WHERE $where_sql ORDER BY s.connected_at DESC LIMIT 200");
        $stmt->execute($params);
        echo json_encode(['data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        break;

    case 'roaming':
        $time_filter = $_GET['time'] ?? '24h';
        $ranges = ['1h' => 1, '24h' => 24, '7d' => 168, '30d' => 720];
        $hours = $ranges[$time_filter] ?? 24;

        $stmt = $db->prepare("SELECT * FROM stalker_roaming WHERE roamed_at >= datetime('now', '-$hours hours') ORDER BY roamed_at DESC LIMIT 200");
        $stmt->execute();
        echo json_encode(['data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        break;

    case 'roaming_count':
        $stmt = $db->query("SELECT COUNT(*) as cnt FROM stalker_roaming WHERE roamed_at >= datetime('now', '-24 hours')");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        $last = $db->query("SELECT hostname, from_ap, to_ap, roamed_at FROM stalker_roaming ORDER BY roamed_at DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);

        echo json_encode(['count' => $row['cnt'], 'last' => $last]);
        break;

    case 'watchlist':
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            $stmt = $db->query("SELECT * FROM stalker_watchlist ORDER BY added_at DESC");
            echo json_encode(['data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $input = json_decode(file_get_contents('php://input'), true);
            $mac = strtolower(trim($input['mac'] ?? ''));
            $label = trim($input['label'] ?? '');
            if (empty($mac)) { echo json_encode(['error' => 'MAC required']); break; }
            $db->prepare("INSERT OR IGNORE INTO stalker_watchlist (mac, label) VALUES (?, ?)")->execute([$mac, $label]);
            echo json_encode(['success' => true]);
        } elseif ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
            $input = json_decode(file_get_contents('php://input'), true);
            $db->prepare("DELETE FROM stalker_watchlist WHERE id = ?")->execute([intval($input['id'] ?? 0)]);
            echo json_encode(['success' => true]);
        }
        break;

    case 'block':
        $input = json_decode(file_get_contents('php://input'), true);
        $mac = strtolower(trim($input['mac'] ?? ''));
        $siteId = $config['site'] ?? 'default';
        $cmd = $input['block'] ? 'block-sta' : 'unblock-sta';
        $result = fetch_api("/proxy/network/api/s/default/cmd/stamgr", 'POST', json_encode(['cmd' => $cmd, 'mac' => $mac]));
        // Update watchlist blocked status
        $db->prepare("UPDATE stalker_watchlist SET blocked = ? WHERE mac = ?")->execute([$input['block'] ? 1 : 0, $mac]);
        echo json_encode(['success' => true, 'result' => $result]);
        break;

    case 'export':
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="stalker_export_' . date('Y-m-d') . '.csv"');
        $stmt = $db->query("SELECT mac, hostname, ap_name, ssid, band, channel, rssi, rx_rate, tx_rate, connected_at, disconnected_at FROM stalker_sessions ORDER BY connected_at DESC LIMIT 5000");
        $out = fopen('php://output', 'w');
        fputcsv($out, ['MAC', 'Hostname', 'AP', 'SSID', 'Band', 'Channel', 'RSSI', 'RX Mbps', 'TX Mbps', 'Connected', 'Disconnected']);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($out, array_values($row));
        }
        fclose($out);
        exit;

    default:
        echo json_encode(['error' => 'Unknown action']);
}
```

- [ ] **Step 2: Verify API works**

In browser (logged in), visit:
- `api_stalker.php?action=poll` — should return `{"success":true,"changes":{...}}`
- `api_stalker.php?action=sessions` — should return `{"data":[...]}`
- `api_stalker.php?action=roaming_count` — should return `{"count":0,"last":null}`

- [ ] **Step 3: Commit**

```bash
git add api_stalker.php
git commit -m "feat: add Wi-Fi Stalker backend API"
```

---

## Task 11: Wi-Fi Stalker — Frontend Page

**Files:**
- Create: `stalker.php`

- [ ] **Step 1: Create stalker.php**

This is a large file. Create it following the exact patterns from index.php (same head section, same navbar include, same Tailwind classes, same glassmorphism card style). Key sections:

1. PHP header: require config.php, db.php, functions.php, auth check
2. HTML head with Tailwind CDN, Lucide, same dashboard.css
3. Navbar (copy from index.php pattern with `render_nav()` or inline)
4. Filter bar: search input, time buttons (1h/24h/7d/30d), band buttons, CSV export, watchlist
5. Active sessions table
6. Roaming history timeline
7. Watchlist modal
8. JavaScript: polling, table rendering, filters
9. Footer include

The page should use AJAX to load all data (not PHP-rendered tables) so it can auto-refresh without page reload.

Full content for stalker.php — this is the largest new file:

```php
<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    header('Location: login.php');
    exit;
}

$navbar_stats = $_SESSION['navbar_stats'] ?? ['cpu' => 0, 'ram' => 0, 'down' => 0, 'up' => 0];
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Wi-Fi Stalker | MiniDash</title>
    <link rel="icon" type="image/svg+xml" href="img/favicon.svg">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <link rel="stylesheet" href="dashboard.css">
</head>
<body class="bg-[#0a0e1a] text-slate-300 min-h-screen">

    <?php include __DIR__ . '/includes/navbar_stats.php'; ?>

    <div class="max-w-7xl mx-auto px-6 py-8">

        <!-- Header -->
        <div class="flex items-center justify-between mb-8">
            <div class="flex items-center gap-4">
                <div class="w-14 h-14 rounded-2xl bg-purple-500/10 flex items-center justify-center border border-purple-500/20">
                    <i data-lucide="radar" class="w-7 h-7 text-purple-400"></i>
                </div>
                <div>
                    <h1 class="text-2xl font-black text-white">Wi-Fi Stalker</h1>
                    <p class="text-xs text-slate-500 uppercase tracking-wider">Sledzenie roamingu i aktywnosci WiFi</p>
                </div>
            </div>
            <div class="flex items-center gap-3">
                <a href="index.php" class="px-4 py-2 bg-slate-800 hover:bg-slate-700 text-slate-300 rounded-xl text-xs font-bold transition border border-white/5">
                    <i data-lucide="arrow-left" class="w-4 h-4 inline mr-1"></i> Dashboard
                </a>
            </div>
        </div>

        <!-- Filters -->
        <div class="glass-card rounded-3xl p-6 mb-6">
            <div class="flex flex-wrap items-center gap-4">
                <div class="relative flex-1 min-w-[200px]">
                    <i data-lucide="search" class="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-slate-500"></i>
                    <input type="text" id="stalker-search" placeholder="Szukaj (MAC / nazwa)..." oninput="loadSessions()" class="w-full bg-slate-900/50 border border-white/10 rounded-xl pl-10 pr-4 py-2 text-sm text-white placeholder-slate-600 focus:outline-none focus:ring-2 focus:ring-purple-500/50">
                </div>

                <div class="flex gap-1 bg-slate-900/50 rounded-xl p-1 border border-white/10">
                    <button onclick="setTimeFilter('1h')" class="time-btn px-3 py-1.5 rounded-lg text-xs font-bold transition">1h</button>
                    <button onclick="setTimeFilter('24h')" class="time-btn active px-3 py-1.5 rounded-lg text-xs font-bold transition bg-purple-600 text-white">24h</button>
                    <button onclick="setTimeFilter('7d')" class="time-btn px-3 py-1.5 rounded-lg text-xs font-bold transition">7d</button>
                    <button onclick="setTimeFilter('30d')" class="time-btn px-3 py-1.5 rounded-lg text-xs font-bold transition">30d</button>
                </div>

                <div class="flex gap-1 bg-slate-900/50 rounded-xl p-1 border border-white/10">
                    <button onclick="setBandFilter('')" class="band-btn active px-3 py-1.5 rounded-lg text-xs font-bold transition bg-purple-600 text-white">Wszystkie</button>
                    <button onclick="setBandFilter('2.4GHz')" class="band-btn px-3 py-1.5 rounded-lg text-xs font-bold transition">2.4</button>
                    <button onclick="setBandFilter('5GHz')" class="band-btn px-3 py-1.5 rounded-lg text-xs font-bold transition">5</button>
                    <button onclick="setBandFilter('6GHz')" class="band-btn px-3 py-1.5 rounded-lg text-xs font-bold transition">6</button>
                </div>

                <a href="api_stalker.php?action=export" class="px-4 py-2 bg-slate-800 hover:bg-slate-700 text-slate-300 rounded-xl text-xs font-bold transition border border-white/5">
                    <i data-lucide="download" class="w-4 h-4 inline mr-1"></i> CSV
                </a>

                <button onclick="openWatchlistModal()" class="px-4 py-2 bg-amber-600/20 hover:bg-amber-600/30 text-amber-400 rounded-xl text-xs font-bold transition border border-amber-500/20">
                    <i data-lucide="eye" class="w-4 h-4 inline mr-1"></i> Watchlist
                </button>
            </div>
        </div>

        <!-- Active Sessions -->
        <div class="glass-card rounded-3xl p-6 mb-6">
            <h2 class="text-sm font-bold text-slate-400 uppercase tracking-wider mb-4">
                <i data-lucide="wifi" class="w-4 h-4 inline mr-2 text-emerald-400"></i>Aktywne sesje WiFi
                <span id="sessions-count" class="text-purple-400 ml-2">0</span>
            </h2>
            <div class="overflow-x-auto">
                <table class="w-full" id="sessions-table">
                    <thead>
                        <tr class="bg-white/[0.02]">
                            <th class="text-left py-3 px-4 text-xs font-bold text-slate-500 uppercase tracking-wider">Urzadzenie</th>
                            <th class="text-left py-3 px-4 text-xs font-bold text-slate-500 uppercase tracking-wider">AP</th>
                            <th class="text-left py-3 px-4 text-xs font-bold text-slate-500 uppercase tracking-wider whitespace-nowrap">Pasmo / Kanal</th>
                            <th class="text-left py-3 px-4 text-xs font-bold text-slate-500 uppercase tracking-wider">RSSI</th>
                            <th class="text-left py-3 px-4 text-xs font-bold text-slate-500 uppercase tracking-wider">RX / TX</th>
                            <th class="text-left py-3 px-4 text-xs font-bold text-slate-500 uppercase tracking-wider whitespace-nowrap">Czas polaczenia</th>
                            <th class="text-right py-3 px-4 text-xs font-bold text-slate-500 uppercase tracking-wider">Akcja</th>
                        </tr>
                    </thead>
                    <tbody id="sessions-body">
                        <tr><td colspan="7" class="text-center py-8 text-slate-500">Ladowanie...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Roaming History -->
        <div class="glass-card rounded-3xl p-6">
            <h2 class="text-sm font-bold text-slate-400 uppercase tracking-wider mb-4">
                <i data-lucide="repeat" class="w-4 h-4 inline mr-2 text-amber-400"></i>Historia roamingu
                <span id="roaming-count" class="text-amber-400 ml-2">0</span>
            </h2>
            <div id="roaming-body">
                <div class="text-center py-8 text-slate-500">Ladowanie...</div>
            </div>
        </div>

    </div>

    <!-- Watchlist Modal -->
    <div id="watchlistModal" class="fixed inset-0 bg-black/70 backdrop-blur-sm z-50 hidden items-center justify-center" onclick="if(event.target===this)closeWatchlistModal()">
        <div class="bg-slate-900/95 backdrop-blur-xl border border-white/10 rounded-3xl p-8 w-full max-w-2xl max-h-[80vh] overflow-y-auto shadow-2xl">
            <div class="flex items-center justify-between mb-6">
                <h2 class="text-xl font-bold text-white flex items-center gap-3">
                    <i data-lucide="eye" class="w-6 h-6 text-amber-400"></i> Watchlist
                </h2>
                <button onclick="closeWatchlistModal()" class="text-slate-500 hover:text-white transition"><i data-lucide="x" class="w-6 h-6"></i></button>
            </div>
            <div class="flex gap-3 mb-6">
                <input type="text" id="watch-mac" placeholder="Adres MAC" class="flex-1 bg-slate-800/50 border border-white/10 rounded-xl px-4 py-2 text-sm text-white placeholder-slate-600 focus:outline-none focus:ring-2 focus:ring-amber-500/50">
                <input type="text" id="watch-label" placeholder="Etykieta" class="flex-1 bg-slate-800/50 border border-white/10 rounded-xl px-4 py-2 text-sm text-white placeholder-slate-600 focus:outline-none focus:ring-2 focus:ring-amber-500/50">
                <button onclick="addToWatchlist()" class="px-4 py-2 bg-amber-600 hover:bg-amber-500 text-white rounded-xl text-sm font-bold transition"><i data-lucide="plus" class="w-4 h-4"></i></button>
            </div>
            <div id="watchlist-body"></div>
        </div>
    </div>

    <?php include __DIR__ . '/includes/footer.php'; ?>

    <script>
    let currentTimeFilter = '24h';
    let currentBandFilter = '';

    function setTimeFilter(t) {
        currentTimeFilter = t;
        document.querySelectorAll('.time-btn').forEach(b => { b.classList.remove('bg-purple-600','text-white'); b.classList.add('text-slate-400'); });
        event.target.classList.add('bg-purple-600','text-white');
        event.target.classList.remove('text-slate-400');
        loadSessions();
        loadRoaming();
    }

    function setBandFilter(b) {
        currentBandFilter = b;
        document.querySelectorAll('.band-btn').forEach(btn => { btn.classList.remove('bg-purple-600','text-white'); btn.classList.add('text-slate-400'); });
        event.target.classList.add('bg-purple-600','text-white');
        event.target.classList.remove('text-slate-400');
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
        const diff = Math.floor((Date.now() - new Date(dateStr + 'Z').getTime()) / 1000);
        if (diff < 60) return diff + 's';
        if (diff < 3600) return Math.floor(diff/60) + 'min';
        if (diff < 86400) return Math.floor(diff/3600) + 'h ' + Math.floor((diff%3600)/60) + 'min';
        return Math.floor(diff/86400) + 'd ' + Math.floor((diff%86400)/3600) + 'h';
    }

    async function loadSessions() {
        const search = document.getElementById('stalker-search').value;
        const params = new URLSearchParams({action:'sessions', time:currentTimeFilter, band:currentBandFilter, search});
        const resp = await fetch('api_stalker.php?' + params);
        const json = await resp.json();
        const data = json.data || [];

        document.getElementById('sessions-count').textContent = data.length;
        const tbody = document.getElementById('sessions-body');

        if (data.length === 0) {
            tbody.innerHTML = '<tr><td colspan="7" class="text-center py-8 text-slate-500">Brak aktywnych sesji WiFi</td></tr>';
            return;
        }

        tbody.innerHTML = data.map(s => `
            <tr class="hover:bg-white/[0.01] transition-colors border-t border-white/5">
                <td class="py-4 px-4">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-slate-800/80 flex items-center justify-center border border-white/5">
                            <i data-lucide="wifi" class="w-5 h-5 text-slate-400"></i>
                        </div>
                        <div>
                            <div class="font-bold text-sm text-white">${s.hostname || s.mac}</div>
                            <div class="text-[10px] text-slate-500 font-mono">${s.mac}</div>
                        </div>
                    </div>
                </td>
                <td class="py-4 px-4">
                    <div class="text-sm text-slate-300">${s.ap_name}</div>
                    <div class="text-[10px] text-slate-500">${s.ssid}</div>
                </td>
                <td class="py-4 px-4 whitespace-nowrap">
                    <span class="text-xs font-mono text-purple-400">${s.band}</span>
                    <span class="text-xs text-slate-500 ml-1">Ch${s.channel}</span>
                </td>
                <td class="py-4 px-4">
                    <div class="flex items-center gap-2">
                        <div class="w-2 h-2 rounded-full ${rssiDot(s.rssi)}"></div>
                        <span class="text-sm font-mono ${rssiColor(s.rssi)}">${s.rssi}dBm</span>
                    </div>
                </td>
                <td class="py-4 px-4">
                    <span class="text-xs font-mono text-slate-400">${s.rx_rate}/${s.tx_rate} Mbps</span>
                </td>
                <td class="py-4 px-4 whitespace-nowrap">
                    <span class="text-xs text-slate-400">${timeSince(s.connected_at)}</span>
                </td>
                <td class="py-4 px-4 text-right">
                    <button onclick="addToWatchlistQuick('${s.mac}','${(s.hostname||'').replace(/'/g,'')}')" class="text-slate-500 hover:text-amber-400 transition p-1" title="Dodaj do watchlist">
                        <i data-lucide="${s.is_watched > 0 ? 'eye' : 'eye-off'}" class="w-4 h-4"></i>
                    </button>
                </td>
            </tr>
        `).join('');
        lucide.createIcons();
    }

    async function loadRoaming() {
        const params = new URLSearchParams({action:'roaming', time:currentTimeFilter});
        const resp = await fetch('api_stalker.php?' + params);
        const json = await resp.json();
        const data = json.data || [];

        document.getElementById('roaming-count').textContent = data.length;
        const body = document.getElementById('roaming-body');

        if (data.length === 0) {
            body.innerHTML = '<div class="text-center py-8 text-slate-500">Brak zdarzen roamingu w wybranym okresie</div>';
            return;
        }

        body.innerHTML = data.map(r => `
            <div class="flex items-center gap-4 py-3 border-t border-white/5 hover:bg-white/[0.01] transition">
                <div class="w-10 h-10 rounded-xl bg-amber-500/10 flex items-center justify-center border border-amber-500/20 shrink-0">
                    <i data-lucide="repeat" class="w-5 h-5 text-amber-400"></i>
                </div>
                <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-2">
                        <span class="font-bold text-sm text-white">${r.hostname || r.mac}</span>
                        <span class="text-xs text-slate-500">${r.from_ap}</span>
                        <i data-lucide="arrow-right" class="w-3 h-3 text-amber-400 shrink-0"></i>
                        <span class="text-xs text-slate-300">${r.to_ap}</span>
                    </div>
                    <div class="text-[10px] text-slate-500 mt-0.5">
                        RSSI: ${r.rssi_before}dBm → ${r.rssi_after}dBm &middot; Ch${r.from_channel} → Ch${r.to_channel}
                    </div>
                </div>
                <div class="text-xs text-slate-500 whitespace-nowrap shrink-0">${timeSince(r.roamed_at)}</div>
            </div>
        `).join('');
        lucide.createIcons();
    }

    async function pollStalker() {
        try { await fetch('api_stalker.php?action=poll', {method:'POST'}); } catch(e) {}
        loadSessions();
        loadRoaming();
    }

    // Watchlist functions
    function openWatchlistModal() {
        document.getElementById('watchlistModal').classList.remove('hidden');
        document.getElementById('watchlistModal').classList.add('flex');
        loadWatchlist();
        lucide.createIcons();
    }
    function closeWatchlistModal() {
        document.getElementById('watchlistModal').classList.add('hidden');
        document.getElementById('watchlistModal').classList.remove('flex');
    }

    async function loadWatchlist() {
        const resp = await fetch('api_stalker.php?action=watchlist');
        const json = await resp.json();
        const data = json.data || [];
        const body = document.getElementById('watchlist-body');
        if (data.length === 0) {
            body.innerHTML = '<div class="text-center text-slate-500 py-4">Watchlist jest pusta</div>';
            return;
        }
        body.innerHTML = '<table class="w-full"><thead><tr class="text-xs text-slate-500 uppercase"><th class="text-left py-2 px-3">MAC</th><th class="text-left py-2 px-3">Etykieta</th><th class="text-left py-2 px-3">Powiadomienia</th><th class="py-2 px-3"></th></tr></thead><tbody>' +
            data.map(w => `<tr class="border-t border-white/5">
                <td class="py-3 px-3 text-sm font-mono text-white">${w.mac}</td>
                <td class="py-3 px-3 text-sm text-slate-400">${w.label || '-'}</td>
                <td class="py-3 px-3"><span class="text-xs ${w.notify ? 'text-emerald-400' : 'text-slate-500'}">${w.notify ? 'Aktywne' : 'Wylaczone'}</span></td>
                <td class="py-3 px-3 text-right"><button onclick="removeFromWatchlist(${w.id})" class="text-red-400 hover:text-red-300 transition"><i data-lucide="trash-2" class="w-4 h-4"></i></button></td>
            </tr>`).join('') + '</tbody></table>';
        lucide.createIcons();
    }

    async function addToWatchlist() {
        const mac = document.getElementById('watch-mac').value.trim();
        const label = document.getElementById('watch-label').value.trim();
        if (!mac) return;
        await fetch('api_stalker.php?action=watchlist', {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({mac,label})});
        document.getElementById('watch-mac').value = '';
        document.getElementById('watch-label').value = '';
        loadWatchlist();
    }

    async function addToWatchlistQuick(mac, label) {
        await fetch('api_stalker.php?action=watchlist', {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({mac,label})});
        loadSessions();
    }

    async function removeFromWatchlist(id) {
        await fetch('api_stalker.php?action=watchlist', {method:'DELETE', headers:{'Content-Type':'application/json'}, body:JSON.stringify({id})});
        loadWatchlist();
    }

    // Init
    document.addEventListener('DOMContentLoaded', () => {
        pollStalker();
        setInterval(pollStalker, 30000);
    });

    lucide.createIcons();
    </script>
</body>
</html>
```

- [ ] **Step 2: Verify stalker.php**

Open `stalker.php` in browser. Should show:
- Header with purple radar icon
- Filter bar with search, time, band buttons
- Active sessions table (populated after first poll)
- Roaming history (empty initially)
- Watchlist modal opens when button clicked

- [ ] **Step 3: Commit**

```bash
git add stalker.php
git commit -m "feat: add Wi-Fi Stalker page with real-time tracking"
```

---

## Task 12: Wi-Fi Stalker — Dashboard Widget + Navbar Icon

**Files:**
- Modify: `index.php` (add widget card + navbar icon)

- [ ] **Step 1: Add Stalker navbar icon**

In the navbar section of index.php, find the existing nav icons (around line 272-278 where the icons for dashboard, protect, security, logs etc. are). Add a new icon for Stalker:

```php
<a href="stalker.php" class="nav-icon p-2 rounded-xl hover:bg-white/5 transition" title="Wi-Fi Stalker">
    <i data-lucide="radar" class="w-6 h-6 text-slate-400 hover:text-purple-400 transition"></i>
</a>
```

- [ ] **Step 2: Add Stalker widget card on dashboard**

In the top stats grid (around line 280-499, after the WAN Egress card or before the WAN status row), add a new card. Find the grid container and add:

```php
<!-- Stalker Widget -->
<div onclick="window.location='stalker.php'" class="glass-card rounded-3xl p-6 cursor-pointer hover:bg-white/[0.04] transition group">
    <div class="flex items-center justify-between mb-4">
        <div class="w-10 h-10 rounded-xl bg-purple-500/10 flex items-center justify-center border border-purple-500/20 group-hover:scale-110 transition-transform">
            <i data-lucide="radar" class="w-5 h-5 text-purple-400"></i>
        </div>
        <span class="text-[10px] font-bold text-purple-400 uppercase tracking-widest">Roaming</span>
    </div>
    <div class="text-3xl font-black text-white tracking-tight" id="stalker-widget-count">-</div>
    <div class="text-xs text-slate-500 mt-1">Zdarzenia (24h)</div>
    <div class="text-[10px] text-slate-600 mt-2 truncate" id="stalker-widget-last">Ladowanie...</div>
</div>

<script>
// Stalker widget - load count on dashboard
fetch('api_stalker.php?action=roaming_count')
    .then(r => r.json())
    .then(data => {
        document.getElementById('stalker-widget-count').textContent = data.count || 0;
        if (data.last) {
            document.getElementById('stalker-widget-last').textContent =
                (data.last.hostname || 'Unknown') + ': ' + data.last.from_ap + ' → ' + data.last.to_ap;
        } else {
            document.getElementById('stalker-widget-last').textContent = 'Brak zdarzen';
        }
    })
    .catch(() => {
        document.getElementById('stalker-widget-count').textContent = '0';
        document.getElementById('stalker-widget-last').textContent = 'Brak danych';
    });
</script>
```

- [ ] **Step 3: Verify**

Load dashboard. Stalker widget card should appear showing roaming count (24h). Click it — should navigate to stalker.php. Navbar should show radar icon.

- [ ] **Step 4: Commit**

```bash
git add index.php
git commit -m "feat: add Wi-Fi Stalker widget and navbar icon to dashboard"
```

---

## Task 13: Network Pulse — AP Client Drilldown

**Files:**
- Modify: `index.php` (infrastructure modal)

- [ ] **Step 1: Add drilldown to infrastructure device rows**

In index.php, find the infrastructure modal table (around lines 893-995). Each device row `<tr>` needs a click handler and an expandable client list below it.

Modify the device `<tr>` to add click handler:
```php
<tr class="hover:bg-white/[0.01] transition-colors cursor-pointer" onclick="toggleAPClients(this, '<?= htmlspecialchars($d['mac'] ?? '') ?>')">
```

After the infrastructure table's `</tbody>`, add a script:

```javascript
async function toggleAPClients(row, apMac) {
    const existingDetail = row.nextElementSibling;
    if (existingDetail && existingDetail.classList.contains('ap-detail-row')) {
        existingDetail.remove();
        return;
    }
    // Remove any other open detail
    document.querySelectorAll('.ap-detail-row').forEach(r => r.remove());

    const detailRow = document.createElement('tr');
    detailRow.className = 'ap-detail-row';
    detailRow.innerHTML = '<td colspan="6" class="p-0"><div class="bg-slate-800/30 border-t border-b border-white/5 px-8 py-4"><div class="text-xs text-slate-500">Ladowanie klientow...</div></div></td>';
    row.after(detailRow);

    try {
        const resp = await fetch('api_stalker.php?action=sessions&search=');
        const json = await resp.json();
        // Filter clients connected to this AP
        const clients = (json.data || []).filter(c => c.ap_mac === apMac.toLowerCase());

        if (clients.length === 0) {
            detailRow.innerHTML = '<td colspan="6" class="p-0"><div class="bg-slate-800/30 border-t border-b border-white/5 px-8 py-4"><div class="text-xs text-slate-500">Brak klientow WiFi na tym urzadzeniu</div></div></td>';
            return;
        }

        let html = '<td colspan="6" class="p-0"><div class="bg-slate-800/30 border-t border-b border-white/5 px-6 py-3">';
        html += '<table class="w-full"><thead><tr class="text-[10px] text-slate-500 uppercase"><th class="text-left py-1 px-2">Klient</th><th class="text-left py-1 px-2">Pasmo</th><th class="text-left py-1 px-2">RSSI</th><th class="text-left py-1 px-2">Predkosc</th><th class="text-left py-1 px-2">Siec</th></tr></thead><tbody>';
        clients.forEach(c => {
            const rssiClass = c.rssi > -50 ? 'text-emerald-400' : (c.rssi > -70 ? 'text-amber-400' : 'text-red-400');
            html += `<tr class="border-t border-white/5"><td class="py-2 px-2 text-xs text-white">${c.hostname || c.mac}</td><td class="py-2 px-2 text-[10px] text-purple-400">${c.band} Ch${c.channel}</td><td class="py-2 px-2 text-xs font-mono ${rssiClass}">${c.rssi}dBm</td><td class="py-2 px-2 text-[10px] text-slate-400">${c.rx_rate}/${c.tx_rate} Mbps</td><td class="py-2 px-2 text-[10px] text-slate-500">${c.ssid}</td></tr>`;
        });
        html += '</tbody></table></div></td>';
        detailRow.innerHTML = html;
    } catch(e) {
        detailRow.innerHTML = '<td colspan="6" class="p-0"><div class="bg-slate-800/30 border-t border-b border-white/5 px-8 py-4"><div class="text-xs text-red-400">Blad ladowania</div></div></td>';
    }
}
```

- [ ] **Step 2: Verify**

Open dashboard, click infrastructure card, click on "Circle" AP row. Should expand showing its 14 WiFi clients with band, RSSI, speed, SSID. Click again to collapse.

- [ ] **Step 3: Commit**

```bash
git add index.php
git commit -m "feat: add AP client drilldown in infrastructure panel"
```

---

## Task 14: Favicon + Final Polish

**Files:**
- Modify: `index.php`, `security.php`, `logs.php`, `protect.php`, `monitored.php`, `history.php`, `login.php`, `devices.php`, `settings_notifications.php`

- [ ] **Step 1: Add favicon link to all pages**

In every page's `<head>` section, add:
```html
<link rel="icon" type="image/svg+xml" href="img/favicon.svg">
```

- [ ] **Step 2: Commit**

```bash
git add img/favicon.svg index.php security.php logs.php protect.php monitored.php history.php login.php devices.php settings_notifications.php stalker.php
git commit -m "feat: add favicon to all pages"
```

---

## Task 15: Final Commit — Version Tag

- [ ] **Step 1: Verify everything works end-to-end**

1. Run `php migrate_json.php` — all data migrates
2. Dashboard loads, stalker widget shows
3. Stalker page: sessions, roaming, watchlist all work
4. Security page: ignore list works, time filters work
5. Settings: Discord + n8n panels show, can save
6. Footer shows on all pages with v2.0.0
7. Favicon appears in browser tab

- [ ] **Step 2: Tag version**

```bash
git tag -a v2.0.0 -m "MiniDash v2.0.0 - SQLite, Wi-Fi Stalker, Threat Watch, Discord/n8n, encryption"
```
