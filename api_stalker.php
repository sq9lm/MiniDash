<?php
/** Created by Łukasz Misiura (c) 2026 | www.lm-ads.com **/
require_once 'config.php';
require_once 'db.php';
require_once 'functions.php';

// Auth check
if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    header('Content-Type: application/json');
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// CSV export sets its own headers
if ($action !== 'export') {
    header('Content-Type: application/json');
}

// ---------------------------------------------------------------------------
// Helper: POST to UniFi controller (fetch_api only does GET)
// ---------------------------------------------------------------------------
function fetch_api_post(string $endpoint, array $payload): array
{
    global $config;

    if (!function_exists('curl_init')) {
        return ['data' => [], 'error' => 'cURL not installed'];
    }

    try {
        $url    = $config['controller_url'] . $endpoint;
        $apiKey = $config['api_key'];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "X-API-KEY: $apiKey",
            "Content-Type: application/json"
        ]);

        $output     = curl_exec($ch);
        $curl_error = curl_error($ch);
        curl_close($ch);

        if ($output === false) {
            return ['data' => [], 'error' => $curl_error];
        }

        $data = json_decode($output, true);

        if (isset($data['data']) && is_array($data['data'])) {
            return $data;
        }
        if (is_array($data) && (empty($data) || array_keys($data) === range(0, count($data) - 1))) {
            return ['data' => $data];
        }

        return ['data' => [], 'original' => $data];
    } catch (Throwable $e) {
        return ['data' => [], 'error' => $e->getMessage()];
    }
}

// ---------------------------------------------------------------------------
// Helper: determine Wi-Fi band from radio type and channel
// ---------------------------------------------------------------------------
function determine_band(string $radio, int $channel): string
{
    if ($radio === '6e' || $channel > 177) {
        return '6GHz';
    }
    if ($radio === 'na' || $channel > 14) {
        return '5GHz';
    }
    return '2.4GHz';
}

// ---------------------------------------------------------------------------
// action=poll  (POST)
// ---------------------------------------------------------------------------
if ($action === 'poll') {

    $sta_resp    = fetch_api('/proxy/network/api/s/default/stat/sta');
    $dev_resp    = fetch_api('/proxy/network/api/s/default/stat/device');

    $clients = $sta_resp['data'] ?? [];
    $devices = $dev_resp['data'] ?? [];

    // Build AP mac → friendly name map
    $ap_names = [];
    foreach ($devices as $dev) {
        $ap_mac = strtolower($dev['mac'] ?? '');
        if ($ap_mac !== '') {
            $ap_names[$ap_mac] = $dev['name'] ?? $ap_mac;
        }
    }

    $active_macs  = [];
    $new_count    = 0;
    $roam_count   = 0;

    foreach ($clients as $c) {
        // Skip wired clients
        if (!empty($c['is_wired'])) {
            continue;
        }

        $mac      = strtolower($c['mac'] ?? '');
        if ($mac === '') continue;

        $hostname = $c['name'] ?? $c['hostname'] ?? $mac;
        $ap_mac   = strtolower($c['ap_mac'] ?? '');
        $essid    = $c['essid'] ?? '';
        $channel  = (int)($c['channel'] ?? 0);
        $rssi     = (int)($c['rssi'] ?? $c['signal'] ?? 0);
        $rx_rate  = (float)($c['rx_rate'] ?? 0);
        $tx_rate  = (float)($c['tx_rate'] ?? 0);
        $radio    = $c['radio'] ?? '';
        $band     = determine_band($radio, $channel);
        $ap_name  = $ap_names[$ap_mac] ?? $ap_mac;

        $active_macs[] = $mac;

        // Check for an open session
        $stmt = $db->prepare(
            "SELECT id, ap_mac, rssi FROM stalker_sessions
             WHERE mac = ? AND disconnected_at IS NULL
             ORDER BY connected_at DESC LIMIT 1"
        );
        $stmt->execute([$mac]);
        $session = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$session) {
            // No open session → new connection
            $ins = $db->prepare(
                "INSERT INTO stalker_sessions
                    (mac, hostname, ap_mac, ap_name, ssid, channel, band, rssi, rx_rate, tx_rate)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $ins->execute([$mac, $hostname, $ap_mac, $ap_name, $essid, $channel, $band, $rssi, $rx_rate, $tx_rate]);
            $new_count++;

        } elseif (!empty($ap_mac) && strtolower($session['ap_mac']) !== $ap_mac) {
            // AP changed → roaming
            $from_ap      = $session['ap_mac'];
            $from_ap_name = $ap_names[$from_ap] ?? $from_ap;
            $rssi_before  = (int)$session['rssi'];

            // Close old session
            $close = $db->prepare(
                "UPDATE stalker_sessions SET disconnected_at = datetime('now')
                 WHERE id = ?"
            );
            $close->execute([$session['id']]);

            // Record roaming event
            $roam = $db->prepare(
                "INSERT INTO stalker_roaming
                    (mac, hostname, from_ap, to_ap, from_channel, to_channel, rssi_before, rssi_after)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $roam->execute([$mac, $hostname, $from_ap_name, $ap_name, 0, $channel, $rssi_before, $rssi]);
            $roam_count++;

            // Open new session on new AP
            $ins2 = $db->prepare(
                "INSERT INTO stalker_sessions
                    (mac, hostname, ap_mac, ap_name, ssid, channel, band, rssi, rx_rate, tx_rate)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $ins2->execute([$mac, $hostname, $ap_mac, $ap_name, $essid, $channel, $band, $rssi, $rx_rate, $tx_rate]);

            // Check watchlist for alert
            $watch = $db->prepare("SELECT label FROM stalker_watchlist WHERE mac = ? LIMIT 1");
            $watch->execute([$mac]);
            $watched = $watch->fetch(PDO::FETCH_ASSOC);
            if ($watched) {
                $label   = $watched['label'] ?: $hostname;
                $subject = "Wi-Fi Roaming: $label";
                $message = "Device **$label** ($mac) roamed from **$from_ap_name** to **$ap_name** (RSSI: $rssi_before → $rssi dBm)";
                sendAlert($subject, $message);
            }

        } else {
            // Same AP → update signal/rates
            $upd = $db->prepare(
                "UPDATE stalker_sessions SET rssi = ?, rx_rate = ?, tx_rate = ?
                 WHERE id = ?"
            );
            $upd->execute([$rssi, $rx_rate, $tx_rate, $session['id']]);
        }
    }

    // Mark disconnected clients
    $disco_count = 0;
    if (!empty($active_macs)) {
        $placeholders = implode(',', array_fill(0, count($active_macs), '?'));
        $disco = $db->prepare(
            "UPDATE stalker_sessions
             SET disconnected_at = datetime('now')
             WHERE mac NOT IN ($placeholders) AND disconnected_at IS NULL"
        );
        $disco->execute($active_macs);
        $disco_count = $disco->rowCount();
    } else {
        // No wireless clients at all — disconnect everyone
        $disco = $db->prepare(
            "UPDATE stalker_sessions SET disconnected_at = datetime('now')
             WHERE disconnected_at IS NULL"
        );
        $disco->execute();
        $disco_count = $disco->rowCount();
    }

    echo json_encode([
        'success' => true,
        'changes' => [
            'new'          => $new_count,
            'roaming'      => $roam_count,
            'disconnected' => $disco_count,
        ]
    ]);
    exit;
}

// ---------------------------------------------------------------------------
// action=sessions  (GET)
// ---------------------------------------------------------------------------
if ($action === 'sessions') {
    $band   = $_GET['band']   ?? '';
    $search = $_GET['search'] ?? '';

    $where  = ['s.disconnected_at IS NULL'];
    $params = [];

    if ($band !== '') {
        $where[]  = 's.band = ?';
        $params[] = $band;
    }

    if ($search !== '') {
        $where[]  = '(s.hostname LIKE ? OR s.mac LIKE ?)';
        $like     = '%' . $search . '%';
        $params[] = $like;
        $params[] = $like;
    }

    $whereSQL = implode(' AND ', $where);

    $stmt = $db->prepare(
        "SELECT s.*,
                (SELECT COUNT(*) FROM stalker_watchlist w WHERE w.mac = s.mac) AS is_watched
         FROM stalker_sessions s
         WHERE $whereSQL
         ORDER BY s.connected_at DESC
         LIMIT 200"
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'data' => $rows]);
    exit;
}

// ---------------------------------------------------------------------------
// action=roaming  (GET)
// ---------------------------------------------------------------------------
if ($action === 'roaming') {
    $time  = $_GET['time'] ?? '24h';

    // Convert time param to hours
    $hours_map = ['1h' => 1, '24h' => 24, '7d' => 168, '30d' => 720];
    $hours = $hours_map[$time] ?? 24;

    $stmt = $db->prepare(
        "SELECT * FROM stalker_roaming
         WHERE roamed_at >= datetime('now', ? || ' hours')
         ORDER BY roamed_at DESC
         LIMIT 200"
    );
    $stmt->execute(['-' . $hours]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'data' => $rows]);
    exit;
}

// ---------------------------------------------------------------------------
// action=roaming_count  (GET)
// ---------------------------------------------------------------------------
if ($action === 'roaming_count') {
    $cnt_stmt = $db->query(
        "SELECT COUNT(*) AS cnt FROM stalker_roaming
         WHERE roamed_at >= datetime('now', '-24 hours')"
    );
    $count = (int)($cnt_stmt->fetchColumn());

    $last_stmt = $db->query(
        "SELECT hostname, from_ap, to_ap, roamed_at
         FROM stalker_roaming
         ORDER BY roamed_at DESC
         LIMIT 1"
    );
    $last = $last_stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    echo json_encode(['success' => true, 'count' => $count, 'last' => $last]);
    exit;
}

// ---------------------------------------------------------------------------
// action=watchlist  (GET / POST / DELETE)
// ---------------------------------------------------------------------------
if ($action === 'watchlist') {
    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'GET') {
        $stmt = $db->query("SELECT * FROM stalker_watchlist ORDER BY added_at DESC");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'data' => $rows]);
        exit;
    }

    $body = json_decode(file_get_contents('php://input'), true) ?? [];

    if ($method === 'POST') {
        $mac   = strtolower(trim($body['mac']   ?? ''));
        $label = trim($body['label'] ?? '');

        if ($mac === '') {
            echo json_encode(['success' => false, 'error' => 'mac required']);
            exit;
        }

        $stmt = $db->prepare(
            "INSERT OR IGNORE INTO stalker_watchlist (mac, label) VALUES (?, ?)"
        );
        $stmt->execute([$mac, $label]);

        echo json_encode(['success' => true, 'inserted' => $stmt->rowCount()]);
        exit;
    }

    if ($method === 'DELETE') {
        $id = (int)($body['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['success' => false, 'error' => 'id required']);
            exit;
        }

        $stmt = $db->prepare("DELETE FROM stalker_watchlist WHERE id = ?");
        $stmt->execute([$id]);

        echo json_encode(['success' => true, 'deleted' => $stmt->rowCount()]);
        exit;
    }

    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// ---------------------------------------------------------------------------
// action=block  (POST)
// ---------------------------------------------------------------------------
if ($action === 'block') {
    $body  = json_decode(file_get_contents('php://input'), true) ?? [];
    $mac   = strtolower(trim($body['mac'] ?? ''));
    $block = filter_var($body['block'] ?? true, FILTER_VALIDATE_BOOLEAN);

    if ($mac === '') {
        echo json_encode(['success' => false, 'error' => 'mac required']);
        exit;
    }

    $cmd     = $block ? 'block-sta' : 'unblock-sta';
    $result  = fetch_api_post('/proxy/network/api/s/default/cmd/stamgr', [
        'cmd' => $cmd,
        'mac' => $mac,
    ]);

    // Update watchlist blocked status if the device is listed
    $stmt = $db->prepare(
        "UPDATE stalker_watchlist SET blocked = ? WHERE mac = ?"
    );
    $stmt->execute([$block ? 1 : 0, $mac]);

    echo json_encode([
        'success'  => true,
        'blocked'  => $block,
        'mac'      => $mac,
        'unifi'    => $result,
    ]);
    exit;
}

// ---------------------------------------------------------------------------
// action=export  (GET)
// ---------------------------------------------------------------------------
if ($action === 'export') {
    $filename = 'wifi_sessions_' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache, no-store, must-revalidate');

    $stmt = $db->query(
        "SELECT id, mac, hostname, ap_mac, ap_name, ssid, channel, band,
                rssi, rx_rate, tx_rate, connected_at, disconnected_at
         FROM stalker_sessions
         ORDER BY connected_at DESC"
    );

    $out = fopen('php://output', 'w');

    // UTF-8 BOM for Excel compatibility
    fputs($out, "\xEF\xBB\xBF");

    // Header row
    fputcsv($out, ['ID', 'MAC', 'Hostname', 'AP MAC', 'AP Name', 'SSID',
                   'Channel', 'Band', 'RSSI', 'RX Rate', 'TX Rate',
                   'Connected At', 'Disconnected At']);

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($out, $row);
    }

    fclose($out);
    exit;
}

// ---------------------------------------------------------------------------
// Unknown action
// ---------------------------------------------------------------------------
http_response_code(400);
echo json_encode(['success' => false, 'error' => 'Unknown action: ' . htmlspecialchars($action)]);
