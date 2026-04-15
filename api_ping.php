<?php
/** Created by Łukasz Misiura (c) 2025 | dev.lm-ads.com **/
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';
header('Content-Type: application/json');

if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Default hosts
$gateway_ip = parse_url($config['controller_url'] ?? '', PHP_URL_HOST) ?: '192.168.1.1';
$default_hosts = [
    ['name' => 'Gateway', 'host' => $gateway_ip],
    ['name' => 'Google DNS', 'host' => '8.8.8.8'],
    ['name' => 'Cloudflare', 'host' => '1.1.1.1'],
    ['name' => 'Onet.pl', 'host' => 'onet.pl'],
    ['name' => 'Wirtualna Polska', 'host' => 'wp.pl']
];

// Get hosts from input
$input = json_decode(file_get_contents('php://input'), true);
$hosts = $input['hosts'] ?? $default_hosts;

$results = [];

foreach ($hosts as $h) {
    // Sanitize host to prevent injection
    $host_addr = escapeshellcmd($h['host']);
    
    $latency = 0;
    $status = 'offline';

    if (PHP_OS_FAMILY === 'Windows') {
        // Windows Ping
        $cmd = "ping -n 1 -w 1000 " . $host_addr;
        exec($cmd, $output, $return_var);
        
        // Check data dir
        if (!is_dir(__DIR__ . '/data')) mkdir(__DIR__ . '/data', 0777, true);

        // Debug logging with absolute path
        file_put_contents(__DIR__ . '/data/ping_debug.txt', "CMD: $cmd\nRES: $return_var\nOUT: " . implode("\n", $output) . "\n\n", FILE_APPEND);
        
        if ($return_var === 0) {
            foreach ($output as $line) {
                // Regex improvement: allow spaces around = and before ms
                // Matches: time=27ms, time = 27ms, time=27 ms
                if (preg_match('/(czas|time)\s*[=<]\s*(\d+)\s*ms/i', $line, $matches)) {
                    $latency = (int)$matches[2];
                    $status = 'online';
                    break;
                }
                // Check if <1ms
                if (preg_match('/(czas|time)\s*[=<]\s*<1\s*ms/i', $line)) {
                    $latency = 1;
                    $status = 'online';
                    break;
                }
            }
        }
    } else {
        // Linux/Unix Ping with TCP Fallback
        $cmd = "ping -c 1 -t 50 -W 1 " . $host_addr;
        
        exec($cmd . " 2>&1", $output, $return_var); 
        
        // Debug ICMP Ping
        if (!is_dir(__DIR__ . '/data')) mkdir(__DIR__ . '/data', 0777, true);
        file_put_contents(__DIR__ . '/data/ping_debug.txt', "OS: Linux | Host: $host_addr\nCMD: $cmd\nRES: $return_var\nOUT: " . implode("\n", $output) . "\n", FILE_APPEND);
        
        $pingSuccess = false;
        if ($return_var === 0) {
            foreach ($output as $line) {
                if (preg_match('/time=([\d\.]+)\s*ms/', $line, $matches)) {
                    $latency = floatval($matches[1]);
                    $status = 'online';
                    $pingSuccess = true;
                    break;
                }
            }
        }
        
        // If ping failed (permission denied or no route), try TCP Connect
        if (!$pingSuccess) {
            $ports = [80, 443, 53];
            foreach ($ports as $port) {
                $start = microtime(true);
                $fp = @fsockopen($host_addr, $port, $errno, $errstr, 0.5);
                if ($fp) {
                    $latency = round((microtime(true) - $start) * 1000);
                    $status = 'online';
                    fclose($fp);
                    file_put_contents(__DIR__ . '/data/ping_debug.txt', "TCP SUCCESS: $host_addr:$port | Latency: {$latency}ms\n\n", FILE_APPEND);
                    break;
                }
            }
            if ($status === 'offline') {
                file_put_contents(__DIR__ . '/data/ping_debug.txt', "TCP FAILED: $host_addr (tried " . implode(',', $ports) . ")\n\n", FILE_APPEND);
            }
        } else {
             file_put_contents(__DIR__ . '/data/ping_debug.txt', "ICMP SUCCESS: $host_addr | Latency: {$latency}ms\n\n", FILE_APPEND);
        }
    }

    $results[] = [
        'name' => htmlspecialchars($h['name']),
        'host' => htmlspecialchars($h['host']),
        'latency' => $latency,
        'status' => $status,
        'timestamp' => time()
    ];
    
    unset($output); // Clear output buffer for next loop
}

echo json_encode(['data' => $results]);




