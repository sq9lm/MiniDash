<?php
/** Created by Łukasz Misiura (c) 2026 | www.lm-ads.com **/
// Force error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'config.php';
require_once 'functions.php';

header('Content-Type: application/json');

// Precise logging for Windows debugging
function log_settings_debug($message) {
    $logFile = __DIR__ . '/logs/settings_debug.log';
    if (!is_dir(__DIR__ . '/logs')) {
        @mkdir(__DIR__ . '/logs', 0777, true);
    }
    file_put_contents($logFile, "[" . date('Y-m-d H:i:s') . "] " . $message . "\n", FILE_APPEND);
}

if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    log_settings_debug("Unauthorized access attempt");
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$action = $_POST['action'] ?? '';
log_settings_debug("Action received: " . $action);

if ($action === 'update_profile') {
    $username = $_POST['username'] ?? '';
    $full_name = $_POST['full_name'] ?? '';
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';
    
    log_settings_debug("Updating profile: user=$username, name=$full_name, email=$email");

    if (empty($username)) {
        echo json_encode(['success' => false, 'message' => 'Username cannot be empty']);
        exit;
    }

    // Prepare current dynamic config
    $configFile = __DIR__ . '/data/config.json';
    $currentDynamicConfig = [];
    if (file_exists($configFile)) {
        $content = file_get_contents($configFile);
        $currentDynamicConfig = json_decode($content, true) ?: [];
        log_settings_debug("Existing config loaded");
    }

    $newConfig = $currentDynamicConfig; // Start with current
    $newConfig['admin_username'] = $username;
    $newConfig['admin_full_name'] = $full_name;
    $newConfig['admin_email'] = $email;
    
    if (!empty($password)) {
        $newConfig['admin_password'] = $password;
        log_settings_debug("Password updated");
    }

    // Handle Avatar Upload
    if (isset($_FILES['avatar']) && $_FILES['avatar']['error'] === UPLOAD_ERR_OK) {
        log_settings_debug("Avatar upload detected: " . $_FILES['avatar']['name']);
        
        $uploadDir = __DIR__ . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'avatars' . DIRECTORY_SEPARATOR;
        if (!is_dir($uploadDir)) {
            @mkdir($uploadDir, 0777, true);
        }

        $fileExtension = strtolower(pathinfo($_FILES['avatar']['name'], PATHINFO_EXTENSION));
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

        if (in_array($fileExtension, $allowedExtensions)) {
            $fileName = 'avatar_' . time() . '.' . $fileExtension;
            $targetPath = $uploadDir . $fileName;

            log_settings_debug("Attempting to move file to: " . $targetPath);

            if (move_uploaded_file($_FILES['avatar']['tmp_name'], $targetPath)) {
                $newConfig['admin_avatar'] = 'data/avatars/' . $fileName;
                log_settings_debug("Avatar saved to config as: " . $newConfig['admin_avatar']);
            } else {
                log_settings_debug("FAILED to move uploaded file. Check permissions.");
            }
        } else {
            log_settings_debug("Invalid file extension: " . $fileExtension);
        }
    } elseif (isset($_FILES['avatar']) && $_FILES['avatar']['error'] !== UPLOAD_ERR_NO_FILE) {
        log_settings_debug("Avatar upload error code: " . $_FILES['avatar']['error']);
    }

    // Save to config.json
    require_once __DIR__ . '/crypto.php';
    encrypt_config($newConfig);
    $jsonContent = json_encode($newConfig, JSON_PRETTY_PRINT);
    if ($jsonContent === false) {
        log_settings_debug("JSON encode error: " . json_last_error_msg());
        echo json_encode(['success' => false, 'message' => 'JSON Error']);
        exit;
    }

    log_settings_debug("Writing to config.json...");
    if (file_put_contents($configFile, $jsonContent, LOCK_EX)) {
        // Update session
        $_SESSION['username'] = $username;
        log_settings_debug("Config saved successfully");
        echo json_encode(['success' => true, 'message' => 'Profil zaktualizowany']);
    } else {
        $error = error_get_last();
        log_settings_debug("WRITE FAILED: " . ($error['message'] ?? 'Unknown error'));
        echo json_encode(['success' => false, 'message' => 'Błąd zapisu pliku config.json']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid action']);
}




