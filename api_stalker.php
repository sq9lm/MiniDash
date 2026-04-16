<?php
/** Created by Łukasz Misiura (c) 2025 | dev.lm-ads.com **/
ini_set('display_errors', 0);
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

function determine_band(string $radio, int $channel): string
{
    if ($radio === '6e' || $channel > 177) return '6GHz';
    if ($radio === 'na' || $channel > 14) return '5GHz';
    return '2.4GHz';
}

/**
 * action=poll
 */
if ($action === 'poll') {
    $siteId = $config['site'] ?? 'default';
    $tradSite = get_trad_site_id($siteId);
    
    $sta_resp    = fetch_api("/proxy/network/api/s/$tradSite/stat/sta");
    $dev_resp    = fetch_api("/proxy/network/api/s/$tradSite/stat/device");

    if (isset($sta_resp['error']) || isset($dev_resp['error'])) {
        $msg = $sta_resp['error'] ?? $dev_resp['error'];
        echo json_encode(['success' => false, 'error' => "UniFi API Connection Failed: $msg"]);
        exit;
    }

    $clients = $sta_resp['data'] ?? [];
    $devices = $dev_resp['data'] ?? [];

    $ap_names = [];
    foreach ($devices as $dev) {
        if ($mac = strtolower($dev['mac'] ?? '')) $ap_names[$mac] = $dev['name'] ?? $mac;
    }

    $active_macs  = [];
    $new_count    = 0;
    $roam_count   = 0;

    foreach ($clients as $c) {
        if (!empty($c['is_wired'])) continue;
        $mac = strtolower($c['mac'] ?? '');
        if ($mac === '') continue;

        $hostname = $c['name'] ?? $c['hostname'] ?? $mac;
        $ap_mac   = strtolower($c['ap_mac'] ?? '');
        $essid    = $c['essid'] ?? '';
        $channel  = (int)($c['channel'] ?? 0);
        $rssi     = (int)($c['rssi'] ?? $c['signal'] ?? 0);
        $rx_rate  = (float)($c['rx_rate'] ?? 0);
        $tx_rate  = (float)($c['tx_rate'] ?? 0);
        $band     = determine_band($c['radio'] ?? '', $channel);
        $ap_name  = $ap_names[$ap_mac] ?? $ap_mac;

        $active_macs[] = $mac;

        $stmt = $db->prepare("SELECT id, ap_mac, rssi FROM stalker_sessions WHERE mac = ? AND disconnected_at IS NULL ORDER BY connected_at DESC LIMIT 1");
        $stmt->execute([$mac]);
        $session = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$session) {
            $ins = $db->prepare("INSERT INTO stalker_sessions (mac, hostname, ap_mac, ap_name, ssid, channel, band, rssi, rx_rate, tx_rate) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $ins->execute([$mac, $hostname, $ap_mac, $ap_name, $essid, $channel, $band, $rssi, $rx_rate, $tx_rate]);
            $new_count++;
        } elseif (!empty($ap_mac) && strtolower($session['ap_mac']) !== $ap_mac) {
            $from_ap = $session['ap_mac'];
            $from_ap_name = $ap_names[$from_ap] ?? $from_ap;
            $rssi_before = (int)$session['rssi'];

            $db->prepare("UPDATE stalker_sessions SET disconnected_at = datetime('now') WHERE id = ?")->execute([$session['id']]);
            
            $roam = $db->prepare("INSERT INTO stalker_roaming (mac, hostname, from_ap, to_ap, from_channel, to_channel, rssi_before, rssi_after) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $roam->execute([$mac, $hostname, $from_ap_name, $ap_name, 0, $channel, $rssi_before, $rssi]);
            $roam_count++;

            $ins2 = $db->prepare("INSERT INTO stalker_sessions (mac, hostname, ap_mac, ap_name, ssid, channel, band, rssi, rx_rate, tx_rate) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $ins2->execute([$mac, $hostname, $ap_mac, $ap_name, $essid, $channel, $band, $rssi, $rx_rate, $tx_rate]);

            $watch = $db->prepare("SELECT label FROM stalker_watchlist WHERE mac = ? LIMIT 1");
            $watch->execute([$mac]);
            if ($watched = $watch->fetch(PDO::FETCH_ASSOC)) {
                $lbl = $watched['label'] ?: $hostname;
                sendAlert("Wi-Fi Roaming: $lbl", "Device **$lbl** roam from **$from_ap_name** to **$ap_name** (RSSI: $rssi_before -> $rssi dBm)");
            }
        } else {
            $db->prepare("UPDATE stalker_sessions SET rssi = ?, rx_rate = ?, tx_rate = ? WHERE id = ?")->execute([$rssi, $rx_rate, $tx_rate, $session['id']]);
        }
    }

    $disco_count = 0;
    if (!empty($active_macs)) {
        $placeholders = implode(',', array_fill(0, count($active_macs), '?'));
        $disco = $db->prepare("UPDATE stalker_sessions SET disconnected_at = datetime('now') WHERE mac NOT IN ($placeholders) AND disconnected_at IS NULL");
        $disco->execute($active_macs);
        $disco_count = $disco->rowCount();
    } else {
        $db->prepare("UPDATE stalker_sessions SET disconnected_at = datetime('now') WHERE disconnected_at IS NULL")->execute();
    }

    echo json_encode(['success' => true, 'changes' => ['new' => $new_count, 'roaming' => $roam_count, 'disconnected' => $disco_count]]);
    exit;
}

if ($action === 'sessions') {
    $band = $_GET['band'] ?? ''; $search = $_GET['search'] ?? '';
    $where = ['s.disconnected_at IS NULL']; $params = [];
    if ($band !== '') { $where[] = 's.band = ?'; $params[] = $band; }
    if ($search !== '') { $where[] = '(s.hostname LIKE ? OR s.mac LIKE ?)'; $src = "%$search%"; $params[] = $src; $params[] = $src; }
    $sql = "SELECT s.*, (SELECT COUNT(*) FROM stalker_watchlist w WHERE w.mac = s.mac) AS is_watched FROM stalker_sessions s WHERE ".implode(' AND ', $where)." ORDER BY s.connected_at DESC LIMIT 200";
    $stmt = $db->prepare($sql); $stmt->execute($params);
    echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

if ($action === 'roaming') {
    $time = $_GET['time'] ?? '24h';
    $h = ['1h'=>1, '24h'=>24, '7d'=>168, '30d'=>720][$time] ?? 24;
    $stmt = $db->prepare("SELECT * FROM stalker_roaming WHERE roamed_at >= datetime('now', ? || ' hours') ORDER BY roamed_at DESC LIMIT 200");
    $stmt->execute(['-'.$h]);
    echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

if ($action === 'roaming_count') {
    $c = $db->query("SELECT COUNT(*) FROM stalker_roaming WHERE roamed_at >= datetime('now', '-24 hours')")->fetchColumn();
    $l = $db->query("SELECT hostname, from_ap, to_ap, roamed_at FROM stalker_roaming ORDER BY roamed_at DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'count' => (int)$c, 'last' => $l]);
    exit;
}

if ($action === 'watchlist') {
    $method = $_SERVER['REQUEST_METHOD'];
    if ($method === 'GET') {
        echo json_encode(['success' => true, 'data' => $db->query("SELECT * FROM stalker_watchlist ORDER BY added_at DESC")->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    if ($method === 'POST') {
        $mac = strtolower(trim($body['mac'] ?? '')); $label = trim($body['label'] ?? '');
        if ($mac) { $db->prepare("INSERT OR IGNORE INTO stalker_watchlist (mac, label) VALUES (?, ?)")->execute([$mac, $label]); }
        echo json_encode(['success' => true]);
        exit;
    }
    if ($method === 'DELETE') {
        $id = (int)($body['id'] ?? 0);
        if ($id) { $db->prepare("DELETE FROM stalker_watchlist WHERE id = ?")->execute([$id]); }
        echo json_encode(['success' => true]);
        exit;
    }
}

if ($action === 'block') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $mac = strtolower(trim($body['mac'] ?? '')); $block = (bool)($body['block'] ?? true);
    if ($mac) {
        $tradSite = get_trad_site_id($config['site']);
        $res = fetch_api_post("/proxy/network/api/s/{$tradSite}/cmd/stamgr", ['cmd' => $block ? 'block-sta' : 'unblock-sta', 'mac' => $mac]);
        $db->prepare("UPDATE stalker_watchlist SET blocked = ? WHERE mac = ?")->execute([$block ? 1 : 0, $mac]);
        echo json_encode(['success' => true, 'blocked' => $block, 'unifi' => $res]);
    }
    exit;
}

if ($action === 'export') {
    $filename = 'wifi_sessions_' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fputs($out, "\xEF\xBB\xBF");
    fputcsv($out, ['ID', 'MAC', 'Hostname', 'AP MAC', 'AP Name', 'SSID', 'Channel', 'Band', 'RSSI', 'RX Rate', 'TX Rate', 'Connected At', 'Disconnected At']);
    $stmt = $db->query("SELECT * FROM stalker_sessions ORDER BY connected_at DESC");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) fputcsv($out, $row);
    fclose($out);
    exit;
}

http_response_code(400); echo json_encode(['success' => false, 'error' => 'Unknown action']);
