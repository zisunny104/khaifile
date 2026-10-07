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
foreach ([$config['office_bin']=>'LibreOffice（Writer／Calc／Impress）', $config['gs_bin']=>'Ghostscript', 'timeout'=>'GNU timeout'] as $binary=>$label) dependency(kf_binary($binary) !== null, $label);
dependency(function_exists('proc_open') && !in_array('proc_open', array_map('trim', explode(',', ini_get('disable_functions'))), true), 'PHP proc_open 可啟動轉換程序');
dependency(is_dir(dirname($config['temp_dir'])) && is_writable(is_dir($config['temp_dir']) ? $config['temp_dir'] : dirname($config['temp_dir'])), '暫存目錄可寫入');
$base = realpath(is_dir($config['temp_dir']) ? $config['temp_dir'] : dirname($config['temp_dir']));
$web = realpath(dirname(__DIR__));
dependency($base !== false && $web !== false && $base !== $web && !str_starts_with($base, $web . DIRECTORY_SEPARATOR), '暫存目錄位於專案之外');
echo "中文字型：建議 fonts-noto-cjk；部署環境須安裝原文件使用的字型。\n";
echo "網頁服務：PHP-FPM＋Nginx／Apache；上傳 50 MB、請求 52 MB、逾時至少 300 秒。\n";
echo "維運：定期執行 php tools/cleanup.php；不需要 npm 建置或資料庫。\n";
if ($failed) echo "Debian／Ubuntu 範例：apt-get install php-cli php-fpm php-zip php-mbstring libreoffice-writer libreoffice-calc libreoffice-impress ghostscript fonts-noto-cjk coreutils curl git\n";
exit($failed ? 1 : 0);
