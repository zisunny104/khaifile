<?php
require dirname(__DIR__).'/api/lib.php';
$config = require dirname(__DIR__).'/config.php';
$passed = 0;
function expect(bool $ok, string $label): void {
    global $passed;
    if (!$ok) throw new RuntimeException($label);
    $passed++;
    echo "PASS $label\n";
}
$base = sys_get_temp_dir().'/kf-security-'.bin2hex(random_bytes(8));
$owner = $base.'/'.str_repeat('a',32);
mkdir($owner,0700,true);
try {
    file_put_contents($owner.'/source','12345');
    $small = $config;
    $small['max_owner_disk_bytes'] = 10;
    try { kf_capacity_lock($base,$owner,$small,6); expect(false,'Owner quota enforced'); }
    catch (KfError $e) { expect($e->status === 413,'Owner quota includes generated files and reservations'); }
    $small = $config;
    $small['max_total_disk_bytes'] = 10;
    mkdir($base.'/'.str_repeat('b',32));
    file_put_contents($base.'/'.str_repeat('b',32).'/other','12345');
    try { kf_capacity_lock($base,$owner,$small,1); expect(false,'Global quota enforced'); }
    catch (KfError $e) { expect($e->status === 413,'Global quota counts other sessions'); }
    $lock = kf_capacity_lock($base,$owner,$config);
    try { kf_capacity_lock($base,$owner,$config); expect(false,'Concurrent reservation rejected'); }
    catch (KfError $e) { expect($e->status === 503,'Concurrent reservations cannot oversubscribe storage'); }
    fclose($lock);
    $config['allow_unsandboxed'] = true; // 僅驗證資源限制，隔離另行檢查。
    putenv('KHAIFILE_TEST_SECRET=must-not-reach-worker');
    kf_run(['/bin/sh','-c','test -z "$KHAIFILE_TEST_SECRET"'],$owner,5,$config);
    expect(true,'Worker does not inherit website environment secrets');
    $memory = $config;
    $memory['worker_memory_bytes'] = 64 * 1024 * 1024;
    try { kf_run(['/usr/bin/python3','-c','a=bytearray(128*1024*1024)'],$owner,5,$memory); expect(false,'Memory allocation must be bounded'); }
    catch (KfError $e) { expect(str_contains(file_get_contents($owner.'/process.log'),'MemoryError'),'Kernel memory limit rejects oversized allocation'); }
    $deadline = $config;
    $deadline['deadline'] = microtime(true)-1;
    try { kf_run(['/usr/bin/true'],$owner,5,$deadline); expect(false,'Expired total deadline'); }
    catch (KfError $e) { expect($e->status === 422,'Conversion stages share one total deadline'); }
    $quota = $config;
    $quota['max_job_disk_bytes'] = 1024;
    try { kf_run(['/bin/sh','-c','head -c 32768 /dev/zero > large; sleep 3'],$owner,5,$quota); expect(false,'Disk monitor enforced'); }
    catch (KfError $e) { expect($e->status === 413,'Growing conversion files trigger quota termination'); }
    unlink($owner.'/large');
    $isolated = $config;
    $isolated['allow_unsandboxed'] = false;
    $sandboxAvailable = false;
    try { kf_run(['/usr/bin/true'],$owner,5,$isolated); $sandboxAvailable = true; }
    catch (KfError $e) {
        expect($e->status >= 400,'Unavailable sandbox rejects conversion without fallback');
        echo "SKIP Real namespace isolation unavailable on this host; no sandbox success claim\n";
    }
    if ($sandboxAvailable) {
        kf_run(['/bin/sh','-c','test ! -e /etc/passwd && test ! -e /sys/class/net'],$owner,5,$isolated);
        expect(true,'Real sandbox excludes host configuration and network devices');
    }
    // 同一工作階段忙碌時，不讓另一個請求阻塞 PHP worker。
    $ownerLock = fopen($owner.'/.lock','c');flock($ownerLock,LOCK_EX);
    $_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__);
    $storageConfig = $config;$storageConfig['temp_dir']=$base;
    $started=microtime(true);
    try { kf_storage($storageConfig,str_repeat('a',32)); expect(false,'Busy owner rejected'); }
    catch (KfError $e) { expect($e->status===503 && microtime(true)-$started<1,'Busy session returns promptly instead of blocking'); }
    fclose($ownerLock);
    echo "PASS $passed security and resource checks\n";
} finally { kf_remove($base); }
