<?php
// 開利手會將未知子路徑交回工具入口；僅 index 是有效頁面。
if (isset($_APP) && ($_APP['action'] ?? 'index') !== 'index') {
    http_response_code(404);
    return;
}
$config = require __DIR__ . '/config.php';
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store');
$api = $_GET['api'] ?? null;
if ($api !== null) {
    require __DIR__ . '/api/index.php';
    return;
}
require __DIR__ . '/api/lib.php';
kf_session();
$csrf = $_SESSION['khaifile']['csrf'];
session_write_close();
require __DIR__ . '/view.php';
