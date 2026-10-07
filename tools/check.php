<?php
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__);
require $root . '/api/lib.php';
$node = kf_binary('node');
if (!$node) echo "略過 JavaScript 語法檢查：Node 僅供開發檢查，非執行期必需。\n";
$failed = false;
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
    if (!$file->isFile() || str_contains($file->getPathname(), '/vendor/') || str_contains($file->getPathname(), '/.git/')) continue;
    $command = match ($file->getExtension()) { 'php'=>[PHP_BINARY, '-l', $file->getPathname()], 'js'=>$node ? [$node,'--check',$file->getPathname()] : null, default=>null };
    if (!$command) continue;
    $process = proc_open($command, [1=>STDOUT,2=>STDERR], $pipes, $root);
    $failed |= proc_close($process) !== 0;
}
foreach (['zip','mbstring'] as $extension) {
    if (!extension_loaded($extension)) { echo "缺少 PHP 擴充：$extension\n"; $failed = true; }
}
exit($failed ? 1 : 0);
