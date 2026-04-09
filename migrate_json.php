<?php
/**
 * migrate_json.php - Migrate existing JSON data files to SQLite
 * MiniDash v2.0.0
 *
 * Works from browser (requires login) or CLI.
 * Idempotent: checks for existing data before inserting.
 */

// ── Bootstrap ──────────────────────────────────────────────────────────────────
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';   // provides $db (PDO)

$is_cli = (php_sapi_name() === 'cli');

// Browser requires an authenticated session
if (!$is_cli) {
    if (empty($_SESSION['logged_in'])) {
        http_response_code(403);
        exit('Forbidden: please log in first.');
    }
    header('Content-Type: text/plain; charset=utf-8');
}

// ── Helpers ────────────────────────────────────────────────────────────────────

/**
 * Log a migration message to stdout / browser output.
 */
function mlog(string $msg): void
{
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg;
    echo $line . "\n";
    if (php_sapi_name() !== 'cli') {
        flush();
        ob_flush();
    }
}

/**
 * Load a JSON file from the data directory.
 * Returns the decoded value or null on failure.
 */
function load_json(string $filename)
{
    $path = __DIR__ . '/data/' . $filename;
    if (!file_exists($path)) {
        mlog("SKIP  $filename – file not found.");
        return null;
    }
    $raw = file_get_contents($path);
    $data = json_decode($raw, true);
    if ($data === null) {
        mlog("SKIP  $filename – invalid JSON.");
        return null;
    }
    return $data;
}

// ── Migration 1: login_history.json → login_history ───────────────────────────

function migrate_login_history(PDO $db): void
{
    mlog('--- Migrating login_history.json ---');

    $rows = load_json('login_history.json');
    if ($rows === null) return;
    if (!is_array($rows)) {
        mlog('ERROR login_history.json is not an array.');
        return;
    }

    $insert = $db->prepare("
        INSERT INTO login_history (username, ip, location, os, browser, user_agent, logged_at)
        VALUES (:username, :ip, :location, :os, :browser, :user_agent, :logged_at)
    ");

    // Idempotency: key on (username, ip, logged_at)
    $exists = $db->prepare("
        SELECT 1 FROM login_history WHERE username = :username AND ip = :ip AND logged_at = :logged_at LIMIT 1
    ");

    $inserted = 0;
    $skipped  = 0;

    foreach ($rows as $row) {
        // Convert unix timestamp → datetime string
        $ts = isset($row['timestamp']) ? (int) $row['timestamp'] : 0;
        $logged_at = $ts > 0 ? date('Y-m-d H:i:s', $ts) : date('Y-m-d H:i:s');

        $username = $row['username'] ?? 'unknown';
        $ip       = $row['ip']       ?? '';

        $exists->execute([':username' => $username, ':ip' => $ip, ':logged_at' => $logged_at]);
        if ($exists->fetchColumn()) {
            $skipped++;
            continue;
        }

        $insert->execute([
            ':username'   => $username,
            ':ip'         => $ip,
            ':location'   => $row['location'] ?? '',
            ':os'         => $row['os']       ?? '',
            ':browser'    => $row['browser']  ?? '',
            ':user_agent' => $row['ua']       ?? '',
            ':logged_at'  => $logged_at,
        ]);
        $inserted++;
    }

    mlog("  login_history: inserted=$inserted, skipped=$skipped");
}

// ── Migration 2: wan_stats.json → wan_stats ────────────────────────────────────

function migrate_wan_stats(PDO $db): void
{
    mlog('--- Migrating wan_stats.json ---');

    $rows = load_json('wan_stats.json');
    if ($rows === null) return;
    if (!is_array($rows)) {
        mlog('ERROR wan_stats.json is not an array.');
        return;
    }

    $insert = $db->prepare("
        INSERT INTO wan_stats (rx_bytes, tx_bytes, recorded_at)
        VALUES (:rx_bytes, :tx_bytes, :recorded_at)
    ");

    // Idempotency: key on (rx_bytes, tx_bytes, recorded_at)
    $exists = $db->prepare("
        SELECT 1 FROM wan_stats WHERE rx_bytes = :rx AND tx_bytes = :tx AND recorded_at = :recorded_at LIMIT 1
    ");

    $inserted = 0;
    $skipped  = 0;

    foreach ($rows as $row) {
        $ts          = isset($row['timestamp']) ? (int) $row['timestamp'] : 0;
        $recorded_at = $ts > 0 ? date('Y-m-d H:i:s', $ts) : date('Y-m-d H:i:s');
        $rx          = (int) ($row['rx'] ?? 0);
        $tx          = (int) ($row['tx'] ?? 0);

        $exists->execute([':rx' => $rx, ':tx' => $tx, ':recorded_at' => $recorded_at]);
        if ($exists->fetchColumn()) {
            $skipped++;
            continue;
        }

        $insert->execute([
            ':rx_bytes'    => $rx,
            ':tx_bytes'    => $tx,
            ':recorded_at' => $recorded_at,
        ]);
        $inserted++;
    }

    mlog("  wan_stats: inserted=$inserted, skipped=$skipped");
}

// ── Migration 3: devices.json → device_monitors ───────────────────────────────

function migrate_devices(PDO $db): void
{
    mlog('--- Migrating devices.json ---');

    $data = load_json('devices.json');
    if ($data === null) return;
    if (!is_array($data)) {
        mlog('ERROR devices.json is not an object/array.');
        return;
    }

    // INSERT OR REPLACE so re-running is safe
    $upsert = $db->prepare("
        INSERT INTO device_monitors (mac, name, vlan, added_at)
        VALUES (:mac, :name, :vlan, :added_at)
        ON CONFLICT(mac) DO UPDATE SET
            name     = excluded.name,
            vlan     = excluded.vlan,
            added_at = excluded.added_at
    ");

    $inserted = 0;
    $updated  = 0;

    // Check existence for reporting only
    $exists = $db->prepare("SELECT 1 FROM device_monitors WHERE mac = :mac LIMIT 1");

    foreach ($data as $mac => $info) {
        $exists->execute([':mac' => $mac]);
        $already = (bool) $exists->fetchColumn();

        $added_at = $info['added_at'] ?? date('Y-m-d H:i:s');
        // Normalise: if added_at is a unix timestamp integer, convert it
        if (is_numeric($added_at) && (int) $added_at > 1000000) {
            $added_at = date('Y-m-d H:i:s', (int) $added_at);
        }

        $upsert->execute([
            ':mac'      => $mac,
            ':name'     => $info['name'] ?? $mac,
            ':vlan'     => $info['vlan'] ?? null,
            ':added_at' => $added_at,
        ]);

        $already ? $updated++ : $inserted++;
    }

    mlog("  device_monitors: inserted=$inserted, updated=$updated");
}

// ── Migration 4: history.json → device_status_history ─────────────────────────

function migrate_device_history(PDO $db): void
{
    mlog('--- Migrating history.json ---');

    $data = load_json('history.json');
    if ($data === null) return;
    if (!is_array($data)) {
        mlog('ERROR history.json is not an object/array.');
        return;
    }

    $insert = $db->prepare("
        INSERT INTO device_status_history (mac, status, duration, timestamp)
        VALUES (:mac, :status, :duration, :timestamp)
    ");

    // Idempotency: key on (mac, status, timestamp)
    $exists = $db->prepare("
        SELECT 1 FROM device_status_history WHERE mac = :mac AND status = :status AND timestamp = :timestamp LIMIT 1
    ");

    $inserted = 0;
    $skipped  = 0;

    foreach ($data as $mac => $entries) {
        if (!is_array($entries)) continue;

        foreach ($entries as $entry) {
            $status    = $entry['status']    ?? 'unknown';
            $duration  = isset($entry['duration']) ? (int) $entry['duration'] : null;
            $timestamp = $entry['timestamp'] ?? date('Y-m-d H:i:s');

            // Normalise: unix int → datetime
            if (is_numeric($timestamp) && (int) $timestamp > 1000000) {
                $timestamp = date('Y-m-d H:i:s', (int) $timestamp);
            }

            $exists->execute([':mac' => $mac, ':status' => $status, ':timestamp' => $timestamp]);
            if ($exists->fetchColumn()) {
                $skipped++;
                continue;
            }

            $insert->execute([
                ':mac'       => $mac,
                ':status'    => $status,
                ':duration'  => $duration,
                ':timestamp' => $timestamp,
            ]);
            $inserted++;
        }
    }

    mlog("  device_status_history: inserted=$inserted, skipped=$skipped");
}

// ── Run all migrations ─────────────────────────────────────────────────────────

mlog('=== JSON → SQLite migration started ===');

migrate_login_history($db);
migrate_wan_stats($db);
migrate_devices($db);
migrate_device_history($db);

mlog('=== Migration complete ===');
