<?php
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__);
require $root . '/api/lib.php';
$node = kf_binary('node');
$summary = in_array('--summary', $argv, true);
if (!$node && !$summary) echo "略過 JavaScript 語法檢查：找不到 Node。\n";
$failed = false;
$counts = ['php'=>0, 'js'=>0];
$errors = ['php'=>0, 'js'=>0];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
    if (!$file->isFile() || str_contains($file->getPathname(), '/vendor/') || str_contains($file->getPathname(), '/.git/')) continue;
    $command = match ($file->getExtension()) { 'php'=>[PHP_BINARY, '-l', $file->getPathname()], 'js'=>$node ? [$node,'--check',$file->getPathname()] : null, default=>null };
    if (!$command) continue;
    $extension = $file->getExtension();
    $counts[$extension]++;
    $process = proc_open($command, [1=>['pipe','w'],2=>['redirect',1]], $pipes, $root);
    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $status = proc_close($process);
    if (!$summary) echo $output;
    if ($status !== 0) {
        $failed = true;
        $errors[$extension]++;
        if ($summary) {
            echo "fail\t" . substr($file->getPathname(), strlen($root) + 1) . "：語法錯誤\n";
            foreach (explode("\n", trim($output)) as $line) echo "detail\t$line\n";
        }
    }
}
if ($summary) {
    $label = $errors['php'] ? 'fail' : 'pass';
    echo "$label\t本機 PHP：{$counts['php']} 檔、{$errors['php']} 個錯誤（" . PHP_BINARY . " -l）\n";
    if ($node) {
        $label = $errors['js'] ? 'fail' : 'pass';
        echo "$label\t本機 JavaScript：{$counts['js']} 檔、{$errors['js']} 個錯誤（$node --check）\n";
    } else echo "skip\tJavaScript：未檢查（找不到 Node）\n";
}
foreach (['zip','mbstring'] as $extension) {
    if (!extension_loaded($extension)) { echo ($summary ? "fail\t" : '') . "缺少 PHP 擴充：$extension\n"; $failed = true; }
}
exit($failed ? 1 : 0);
