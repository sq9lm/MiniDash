<?php
/** Created by Łukasz Misiura (c) 2025 | dev.lm-ads.com **/
session_start();

// Clear Remember Me token from DB and cookie
if (!empty($_COOKIE['remember_me'])) {
    $parts = explode(':', $_COOKIE['remember_me'], 2);
    if (count($parts) === 2) {
        $db_path = __DIR__ . '/data/minidash.db';
        if (file_exists($db_path)) {
            try {
                $db = new PDO("sqlite:$db_path");
                $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $db->prepare("DELETE FROM remember_tokens WHERE selector = ?")->execute([$parts[0]]);
                $db = null;
            } catch (PDOException $e) {
                // ignore
            }
        }
    }
    setcookie('remember_me', '', ['expires' => 1, 'path' => '/']);
}

session_destroy();
header('Location: login.php');
exit;



