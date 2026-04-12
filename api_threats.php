<?php
/** Created by Łukasz Misiura (c) 2025 | dev.lm-ads.com **/
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}
session_write_close();

$range = $_GET['range'] ?? '24h';
if (!in_array($range, ['1h', '24h', '7d'])) {
    $range = '24h';
}

// Load ignore list
$ignore_ips = [];
if (isset($db)) {
    try {
        $stmt = $db->query("SELECT ip FROM threat_ignore");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $ignore_ips[] = $row['ip'];
        }
    } catch (Exception $e) { /* ignore */ }
}

$result = fetch_threat_events($range);
$events = $result['events'] ?? [];

// Filter out ignored IPs
if (!empty($ignore_ips)) {
    $events = array_values(array_filter($events, function($ev) use ($ignore_ips) {
        return !in_array($ev['src_ip'], $ignore_ips);
    }));
}

// Build stats
$total = count($events);
$blocked = count(array_filter($events, fn($e) => $e['action'] === 'blocked'));
$alerts = $total - $blocked;
$high = count(array_filter($events, fn($e) => $e['risk'] === 'high'));
$medium = count(array_filter($events, fn($e) => $e['risk'] === 'medium'));
$low = count(array_filter($events, fn($e) => $e['risk'] === 'low'));

// Top countries
$countries = [];
foreach ($events as $ev) {
    $cc = $ev['country_code'] ?? 'un';
    if ($cc === 'local' || $cc === 'un') continue;
    $countries[$cc] = ($countries[$cc] ?? 0) + 1;
}
arsort($countries);
$top_countries = array_slice($countries, 0, 8, true);

// Top categories
$categories = [];
foreach ($events as $ev) {
    $cat = $ev['category'] ?: 'Unknown';
    $categories[$cat] = ($categories[$cat] ?? 0) + 1;
}
arsort($categories);
$top_categories = array_slice($categories, 0, 6, true);

echo json_encode([
    'events'  => $events,
    'source'  => $result['source'] ?? 'unknown',
    'stats'   => [
        'total'   => $total,
        'blocked' => $blocked,
        'alerts'  => $alerts,
        'high'    => $high,
        'medium'  => $medium,
        'low'     => $low,
    ],
    'top_countries'  => $top_countries,
    'top_categories' => $top_categories,
]);
