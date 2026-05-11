<?php

$page = $argv[1] ?? 'dashboard';
$query = [];
parse_str($argv[2] ?? '', $query);

require __DIR__ . '/../app/bootstrap.php';
init_db();

$adminId = (int) db()->query("SELECT id FROM users WHERE role = 'admin' ORDER BY id LIMIT 1")->fetchColumn();
if ($adminId <= 0) {
    throw new RuntimeException('Admin kullanici bulunamadi.');
}

$_SESSION['user_id'] = $adminId;
$_GET = ['page' => $page] + $query;
$_POST = [];
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_HOST'] = '127.0.0.1:8001';
$_SERVER['REQUEST_URI'] = '/index.php?' . http_build_query($_GET);

ob_start();
require __DIR__ . '/../index.php';
