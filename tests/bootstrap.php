<?php
/**
 * MiniDash Test Bootstrap
 * Sets up minimal environment for unit tests without web server.
 */

// Fake session
$_SESSION = ['logged_in' => true, 'csrf_token' => 'test_token'];
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_USER_AGENT'] = 'PHPUnit';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['PHP_SELF'] = '/index.php';

// Minimal $_ENV
$_ENV['UNIFI_CONTROLLER_URL'] = 'https://127.0.0.1';
$_ENV['UNIFI_API_KEY'] = 'test-key';
$_ENV['UNIFI_SITE'] = 'default';
$_ENV['ADMIN_USERNAME'] = 'admin';
$_ENV['ADMIN_PASSWORD'] = 'test';
$_ENV['ADMIN_FULL_NAME'] = 'Test User';
$_ENV['ADMIN_EMAIL'] = 'test@test.com';
$_ENV['DEBUG'] = 'false';

// Load app
$_minidash_root = dirname(__DIR__);
require_once $_minidash_root . '/config.php';
require_once $_minidash_root . '/functions.php';
