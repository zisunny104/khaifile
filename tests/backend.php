<?php
require dirname(__DIR__) . '/api/lib.php';
$config = require dirname(__DIR__) . '/config.php';
$passed = 0;
function check(bool $condition, string $message): void {
    global $passed;
    if (!$condition) throw new RuntimeException($message);
    $passed++;
}
$dir = sys_get_temp_dir() . '/khaifile-unit-' . bin2hex(random_bytes(8));
mkdir($dir, 0700);
try {
    check(kf_name('報告 2026') === '報告 2026', 'Unicode filename must remain unchanged');
    check(!str_contains(kf_name('../../report'), '/'), 'Filename traversal must be removed');
    check(kf_name('CON') === '_CON', 'Windows reserved names must be escaped');
    check(strlen(kf_name(str_repeat('字', 200))) <= 160, 'Filename byte length must be bounded');
    check(mb_check_encoding(kf_name(str_repeat('字', 200)), 'UTF-8'), 'Truncation must preserve Unicode');
    try { kf_name('   '); check(false, 'Empty name must fail'); } catch (KfError $e) { check($e->status === 400, 'Empty name rejected'); }
    try { kf_id('../'); check(false, 'Traversal ID must fail'); } catch (KfError $e) { check($e->status === 404, 'ID rejected'); }
    $ids = [str_repeat('a',32), str_repeat('b',32)];
    foreach ($ids as $id) {
        mkdir($dir.'/'.$id, 0700);
        file_put_contents($dir.'/'.$id.'/source.pdf', '%PDF-fixture-original');
        file_put_contents($dir.'/'.$id.'/compressed.pdf', '%PDF-fixture-compressed');
        $manifest = ['id'=>$id,'name'=>'同名','created'=>time(),'source_size'=>21,'notes'=>[], 'outputs'=>[
            'original'=>['path'=>'source.pdf','ext'=>'pdf','label'=>'原始檔','size'=>21],
            'pdf'=>['path'=>'compressed.pdf','ext'=>'pdf','label'=>'PDF','size'=>23],
        ]];
        kf_save($dir.'/'.$id, $manifest);
    }
    $archive = kf_archive($dir, $ids, $config);
    $zip = new ZipArchive();
    check($zip->open($dir.'/bundle-'.$archive['bundle'].'.zip') === true, 'Batch archive must open');
    check($zip->numFiles === 4, 'No outputs may be overwritten');
    check($zip->getFromName('同名/原始檔/同名.pdf') === '%PDF-fixture-original', 'Original PDF must keep separate archive path');
    check($zip->getFromName('同名 (2)/同名.pdf') === '%PDF-fixture-compressed', 'Same-named groups must be disambiguated');
    $zip->close();
    $single = kf_archive($dir, [$ids[0]], $config);
    $zip->open($dir.'/bundle-'.$single['bundle'].'.zip');
    check($zip->locateName('同名.pdf') !== false && $zip->locateName('原始檔/同名.pdf') !== false, 'Single group ZIP paths');
    $zip->close();
    $manifest = kf_manifest($dir, $ids[0], $config);
    $manifest['created'] -= $config['ttl'] + 1;
    kf_save($dir.'/'.$ids[0], $manifest);
    try { kf_manifest($dir, $ids[0], $config); check(false, 'Expired files must fail'); } catch (KfError $e) { check($e->status === 410, 'Expiration enforced'); }
    $cleanupBase = $dir.'/cleanup';
    $owner = $cleanupBase.'/'.str_repeat('c',32);
    $oldJob = $owner.'/'.str_repeat('d',32);
    mkdir($oldJob, 0700, true);
    file_put_contents($oldJob.'/source.pdf', '%PDF-expired');
    touch($oldJob, time()-$config['ttl']-10);
    $ownerLock = fopen($owner.'/.lock','c');
    flock($ownerLock, LOCK_EX);
    $environment = getenv();
    $environment['KHAIFILE_TEMP_DIR'] = $cleanupBase;
    $process = proc_open([PHP_BINARY, dirname(__DIR__).'/tools/cleanup.php'], [1=>['file','/dev/null','w'],2=>STDERR], $pipes, null, $environment);
    check(proc_close($process) === 0 && is_file($oldJob.'/source.pdf'), 'Cleanup must not remove files during an active request');
    fclose($ownerLock);
    $process = proc_open([PHP_BINARY, dirname(__DIR__).'/tools/cleanup.php'], [1=>['file','/dev/null','w'],2=>STDERR], $pipes, null, $environment);
    check(proc_close($process) === 0 && !is_dir($oldJob), 'Cleanup removes expired files after request completes');
    echo "PASS $passed backend checks\n";
} finally { kf_remove($dir); }
