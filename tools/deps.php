<?php
if (PHP_SAPI !== 'cli') exit(1);
require dirname(__DIR__) . '/api/lib.php';
$config = require dirname(__DIR__) . '/config.php';
$failed = false;
function dependency(bool $ok, string $label): void {
    global $failed;
    echo ($ok ? '通過  ' : '缺少  ') . $label . "\n";
    $failed |= !$ok;
}
dependency(PHP_VERSION_ID >= 80200, 'PHP 8.2+（目前 ' . PHP_VERSION . '）');
foreach (['zip','mbstring'] as $extension) dependency(extension_loaded($extension), 'PHP ' . $extension);
foreach ([$config['office_bin']=>'LibreOffice（Writer／Calc／Impress）', $config['gs_bin']=>'Ghostscript', 'timeout'=>'GNU timeout', 'prlimit'=>'程序資源限制', 'bwrap'=>'Bubblewrap 隔離工具'] as $binary=>$label) dependency(kf_binary($binary) !== null, $label);
dependency(function_exists('proc_open') && !in_array('proc_open', array_map('trim', explode(',', ini_get('disable_functions'))), true), 'PHP proc_open 可啟動轉換程序');
dependency(is_dir(dirname($config['temp_dir'])) && is_writable(is_dir($config['temp_dir']) ? $config['temp_dir'] : dirname($config['temp_dir'])), '暫存目錄可寫入');
$base = realpath(is_dir($config['temp_dir']) ? $config['temp_dir'] : dirname($config['temp_dir']));
$web = realpath(dirname(__DIR__));
dependency($base !== false && $web !== false && $base !== $web && !str_starts_with($base, $web . DIRECTORY_SEPARATOR), '暫存目錄位於專案之外');
if (!$failed && !$config['allow_unsandboxed']) {
    $probe = sys_get_temp_dir().'/khaifile-probe-'.bin2hex(random_bytes(8));
    mkdir($probe, 0700);
    try { kf_run(['/usr/bin/true'], $probe, 5, $config); dependency(true, '隔離程序可啟動'); }
    catch (Throwable $e) {
        dependency(false, '隔離程序不可啟動：'.$e->getMessage());
        if (is_file($probe.'/process.log')) echo file_get_contents($probe.'/process.log', false, null, 0, 4096);
    }
    finally { kf_remove($probe); }
} elseif ($config['allow_unsandboxed']) {
    echo "警告  已明確停用隔離，僅供受控開發測試。\n";
}
echo "中文字型：建議 fonts-noto-cjk；部署環境須安裝原文件使用的字型。\n";
echo "網頁服務：PHP-FPM＋Nginx／Apache；上傳 50 MB、請求 52 MB、逾時至少 300 秒。\n";
echo "維運：定期執行 php tools/cleanup.php；不需要 npm 建置或資料庫。\n";
if ($failed) echo "Debian／Ubuntu 範例：apt-get install php-cli php-fpm php-zip php-mbstring libreoffice-writer libreoffice-calc libreoffice-impress ghostscript fonts-noto-cjk coreutils curl git bubblewrap util-linux\n";
exit($failed ? 1 : 0);
