<?php
if (PHP_SAPI !== 'cli') exit(1);
require dirname(__DIR__) . '/api/lib.php';
$config = require dirname(__DIR__) . '/config.php';
$base = $config['temp_dir'];
$removed = 0;
foreach (glob($base . '/*', GLOB_ONLYDIR) as $dir) {
    if (!preg_match('/^[a-f0-9]{32}$/D', basename($dir)) || is_link($dir)) continue;
    $lock = fopen($dir . '/.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { if ($lock) fclose($lock); continue; }
    if (filemtime($dir) < time() - $config['ttl']) { kf_remove($dir); $removed++; }
    else foreach (glob($dir . '/*') as $entry) { if (filemtime($entry) < time() - $config['ttl']) { kf_remove($entry); $removed++; } }
    fclose($lock);
}
echo "已清除 $removed 個到期暫存項目。\n";
