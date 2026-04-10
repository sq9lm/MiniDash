<?php
require_once 'config.php';
require_once 'functions.php';

$resp = fetch_api("/proxy/network/api/s/default/stat/event?limit=10");
echo json_encode($resp, JSON_PRETTY_PRINT);
