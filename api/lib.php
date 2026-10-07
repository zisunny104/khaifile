<?php
declare(strict_types=1);

final class KfError extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 400) { parent::__construct($message); }
}

function kf_session(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start(['cookie_httponly' => true, 'cookie_samesite' => 'Lax', 'cookie_secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off']);
    }
    $_SESSION['khaifile'] ??= ['owner' => bin2hex(random_bytes(16)), 'csrf' => bin2hex(random_bytes(32))];
}

function kf_json(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}

function kf_remove(string $path): void
{
    if (is_link($path) || is_file($path)) { unlink($path); return; }
    if (!is_dir($path)) return;
    foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $entry) kf_remove($entry->getPathname());
    rmdir($path);
}

function kf_storage(array $config, string $owner): array
{
    $base = $config['temp_dir'];
    if (is_link($base)) throw new KfError('暫存目錄設定無法使用。', 503);
    if (!is_dir($base) && !mkdir($base, 0700, true)) throw new KfError('無法建立暫存目錄。', 503);
    $base = realpath($base);
    $web = realpath($_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__));
    if ($web && ($base === $web || str_starts_with($base, $web . DIRECTORY_SEPARATOR))) throw new KfError('暫存目錄必須位於網站目錄之外。', 503);
    // Inactive owners are removed only while no request is using their directory.
    foreach (glob($base . '/*', GLOB_ONLYDIR) as $dir) {
        if (!preg_match('/^[a-f0-9]{32}$/D', basename($dir)) || is_link($dir) || filemtime($dir) >= time() - $config['ttl']) continue;
        $lock = fopen($dir . '/.lock', 'c');
        if ($lock && flock($lock, LOCK_EX | LOCK_NB)) { kf_remove($dir); }
        if ($lock) fclose($lock);
    }
    $dir = $base . '/' . $owner;
    if (!is_dir($dir)) mkdir($dir, 0700);
    $lock = fopen($dir . '/.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX)) throw new KfError('暫存空間忙碌，請稍後重試。', 503);
    touch($dir);
    foreach (glob($dir . '/*') as $entry) {
        if (filemtime($entry) < time() - $config['ttl']) kf_remove($entry);
    }
    return [$base, $dir, $lock];
}

function kf_name(mixed $value): string
{
    if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) throw new KfError('檔案名稱無效。');
    $value = preg_replace('/[\x00-\x1f\x7f\/\\\\<>:"|?*]/u', '_', $value);
    $value = trim($value, " .\t\n\r\0\x0B");
    if ($value === '') throw new KfError('請輸入檔案名稱。');
    $value = mb_strcut($value, 0, 160, 'UTF-8');
    if (preg_match('/^(CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])(?:\.|$)/i', $value)) $value = '_' . $value;
    return $value;
}

function kf_id(mixed $value): string
{
    if (!is_string($value) || !preg_match('/^[a-f0-9]{32}$/D', $value)) throw new KfError('找不到這組檔案。', 404);
    return $value;
}

function kf_manifest(string $ownerDir, mixed $id, array $config): array
{
    $id = kf_id($id);
    $path = $ownerDir . '/' . $id . '/manifest.json';
    if (!is_file($path)) throw new KfError('檔案已到期或不存在，請重新上傳。', 410);
    $data = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    if ($data['created'] + $config['ttl'] < time()) throw new KfError('檔案已到期，請重新上傳。', 410);
    return $data;
}

function kf_save(string $dir, array $manifest): void
{
    file_put_contents($dir . '/manifest.json', json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), LOCK_EX);
}

function kf_public(array $manifest): array
{
    $outputs = [];
    foreach ($manifest['outputs'] as $id => $file) {
        $outputs[] = ['id' => $id, 'name' => $manifest['name'] . '.' . $file['ext'], 'label' => $file['label'], 'size' => $file['size']];
    }
    return ['id' => $manifest['id'], 'name' => $manifest['name'], 'outputs' => $outputs, 'notes' => $manifest['notes'], 'expires' => $manifest['created'] + 3600];
}

function kf_path(): string
{
    // 自編 PHP-FPM 可能清除 PATH；仍保留部署者設定的非標準工具路徑。
    return implode(PATH_SEPARATOR, array_unique(array_filter(array_merge(
        explode(PATH_SEPARATOR, getenv('PATH') ?: ''), ['/usr/local/bin', '/usr/bin', '/bin']
    ))));
}

function kf_binary(string $command): ?string
{
    if (str_contains($command, '/')) return is_executable($command) ? $command : null;
    foreach (explode(PATH_SEPARATOR, kf_path()) as $path) {
        if (is_executable($path . '/' . $command) && is_file($path . '/' . $command)) return $path . '/' . $command;
    }
    return null;
}

function kf_run(array $command, string $dir, int $timeout): void
{
    $runner = kf_binary('timeout');
    if (!$runner) throw new KfError('伺服器缺少 timeout 執行工具。', 503);
    $log = $dir . '/process.log';
    $cache = $dir . '/fontcache';
    if (!is_dir($cache)) mkdir($cache, 0700);
    $fontConfig = $dir . '/fonts.conf';
    file_put_contents($fontConfig, '<?xml version="1.0"?><!DOCTYPE fontconfig SYSTEM "urn:fontconfig:fonts.dtd"><fontconfig><include>/etc/fonts/fonts.conf</include><cachedir>' . htmlspecialchars($cache, ENT_XML1) . '</cachedir></fontconfig>');
    $environment = getenv();
    $environment['PATH'] = kf_path();
    $environment['FONTCONFIG_FILE'] = $fontConfig;
    $limits = kf_binary('prlimit');
    if ($limits) $command = [$limits, '--fsize=104857600', '--cpu=' . ($timeout + 5), '--', ...$command];
    $process = proc_open([$runner, '--kill-after=5', (string)$timeout, ...$command], [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'w'], 2 => ['file', $log, 'a']], $pipes, $dir, $environment);
    if (!is_resource($process)) throw new KfError('無法啟動轉換工具。', 503);
    $status = proc_close($process);
    if ($status !== 0) throw new KfError(in_array($status, [124, 137], true) ? '處理時間過長，請改用較小或較簡單的文件。' : '無法處理這份文件，請確認檔案未損壞、未加密，且可正常開啟。', 422);
}

function kf_validate(string $file, string $ext): void
{
    $header = file_get_contents($file, false, null, 0, 8);
    if ($ext === 'pdf') {
        if (!str_starts_with($header, '%PDF-')) throw new KfError('檔案內容不是有效的 PDF。', 422);
        return;
    }
    if (in_array($ext, ['doc', 'xls', 'ppt'], true)) {
        if ($header !== "\xd0\xcf\x11\xe0\xa1\xb1\x1a\xe1") throw new KfError('檔案內容與 Office 副檔名不符。', 422);
        return;
    }
    $zip = new ZipArchive();
    if ($zip->open($file) !== true) throw new KfError('文件容器無法讀取。', 422);
    try {
        $expanded = 0;
        if ($zip->numFiles > 10000) throw new KfError('文件內含過多項目。', 413);
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = $zip->statIndex($i);
            $expanded += $entry['size'];
            if ($expanded > 500 * 1024 * 1024) throw new KfError('文件展開後過大。', 413);
        }
        $required = ['docx'=>'word/document.xml', 'xlsx'=>'xl/workbook.xml', 'pptx'=>'ppt/presentation.xml', 'odt'=>'content.xml', 'ods'=>'content.xml', 'odp'=>'content.xml'];
        if ($zip->locateName($required[$ext]) === false) throw new KfError('文件內容與副檔名不符。', 422);
        if (in_array($ext, ['odt','ods','odp'], true)) {
            $mimes = ['odt'=>'text', 'ods'=>'spreadsheet', 'odp'=>'presentation'];
            if ($zip->getFromName('mimetype') !== 'application/vnd.oasis.opendocument.' . $mimes[$ext]) throw new KfError('開放文件格式與副檔名不符。', 422);
        }
    } finally { $zip->close(); }
}

function kf_profile(string $dir): void
{
    mkdir($dir . '/profile/user', 0700, true);
    file_put_contents($dir . '/profile/user/registrymodifications.xcu', '<?xml version="1.0"?><oor:items xmlns:oor="http://openoffice.org/2001/registry"><item oor:path="/org.openoffice.Office.Common/Security/Scripting"><prop oor:name="MacroSecurityLevel" oor:op="fuse"><value>3</value></prop></item><item oor:path="/org.openoffice.Office.Writer/Content/Update"><prop oor:name="Link" oor:op="fuse"><value>0</value></prop></item><item oor:path="/org.openoffice.Office.Calc/Content/Update"><prop oor:name="Link" oor:op="fuse"><value>0</value></prop></item></oor:items>');
}

function kf_compress(string $pdf, string $dir, array $config): array
{
    $gs = kf_binary($config['gs_bin']);
    if (!$gs) return [$pdf, '伺服器尚未提供 PDF 壓縮工具，已保留原 PDF。'];
    // Rewriting a signed PDF invalidates its signature; retain the signed original.
    $stream = fopen($pdf, 'rb');
    $carry = '';
    while (!feof($stream)) {
        $chunk = $carry . fread($stream, 65536);
        if (str_contains($chunk, '/ByteRange')) { fclose($stream); return [$pdf, 'PDF 含數位簽章，已保留原檔以維持簽章。']; }
        $carry = substr($chunk, -32);
    }
    fclose($stream);
    $dest = $dir . '/compressed.pdf';
    try {
        kf_run([$gs, '-dSAFER', '-dBATCH', '-dNOPAUSE', '-sDEVICE=pdfwrite', '-dCompatibilityLevel=1.7', '-dPDFSETTINGS=/ebook', '-dDetectDuplicateImages=true', '-dColorImageResolution=150', '-dGrayImageResolution=150', '-sOutputFile=' . $dest, $pdf], $dir, $config['timeout']);
        if (is_file($dest) && filesize($dest) > 0 && filesize($dest) < filesize($pdf)) return [$dest, 'PDF 已壓縮：' . filesize($pdf) . ' → ' . filesize($dest) . ' 位元組。'];
        if (is_file($dest)) unlink($dest);
        return [$pdf, '壓縮後沒有更小，已保留原 PDF。'];
    } catch (KfError $e) {
        if (is_file($dest)) unlink($dest);
        return [$pdf, 'PDF 壓縮未成功，已保留可下載的原 PDF。'];
    }
}

function kf_process(string $ownerDir, string $base, array $config): array
{
    $file = $_FILES['file'] ?? null;
    if (!$file || !is_array($file) || !is_int($file['error'] ?? null)) throw new KfError('請選擇一個檔案。');
    if ($file['error'] !== UPLOAD_ERR_OK) throw new KfError('上傳未完成，或檔案超過伺服器大小限制。', 413);
    if ($file['size'] < 1 || $file['size'] > $config['max_file_bytes']) throw new KfError('每個檔案須介於 1 位元組與 50 MB 之間。', 413);
    if (!is_string($file['name'])) throw new KfError('檔名無效。');
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $types = ['doc'=>'odt','docx'=>'odt','odt'=>'odt','xls'=>'ods','xlsx'=>'ods','ods'=>'ods','ppt'=>'odp','pptx'=>'odp','odp'=>'odp','pdf'=>'pdf'];
    if (!isset($types[$ext])) throw new KfError('不支援此格式，請使用 Office、ODF 或 PDF 檔案。', 415);
    $existing = glob($ownerDir . '/*/manifest.json');
    if (count($existing) >= $config['max_jobs']) throw new KfError('已達檔案組數上限，請先清除不需要的檔案。', 413);
    $used = 0;
    foreach ($existing as $path) $used += json_decode(file_get_contents($path), true)['source_size'];
    if ($used + $file['size'] > $config['max_session_bytes']) throw new KfError('暫存總量已達上限，請先清除部分檔案。', 413);
    $name = kf_name($_POST['name'] ?? pathinfo($file['name'], PATHINFO_FILENAME));
    $id = bin2hex(random_bytes(16));
    $dir = $ownerDir . '/' . $id;
    mkdir($dir, 0700);
    $source = $dir . '/source.' . $ext;
    $workerLock = null;
    try {
        if (!move_uploaded_file($file['tmp_name'], $source)) throw new KfError('無法儲存上傳檔案。', 503);
        kf_validate($source, $ext);
        $workerLock = fopen($base . '/worker.lock', 'c');
        if (!$workerLock || !flock($workerLock, LOCK_EX | LOCK_NB)) throw new KfError('轉換工具正在處理其他文件，請稍後重試。', 503);
        $manifest = ['id'=>$id,'name'=>$name,'created'=>time(),'source_size'=>$file['size'],'outputs'=>['original'=>['path'=>'source.'.$ext,'ext'=>$ext,'label'=>'原始檔','size'=>filesize($source)]],'notes'=>[]];
        if ($ext === 'pdf') {
            $pdf = $source;
            $manifest['notes'][] = 'PDF 暫不支援轉換為可編輯文件；可壓縮並下載。';
        } else {
            $office = kf_binary($config['office_bin']);
            if (!$office) throw new KfError('伺服器尚未安裝文件轉換工具，請聯絡管理者。', 503);
            {
                kf_profile($dir);
                $args = [$office, '-env:UserInstallation=file://' . $dir . '/profile', '--headless', '--nologo', '--nodefault', '--nofirststartwizard', '--norestore'];
                if ($ext !== $types[$ext]) {
                    mkdir($dir . '/odf', 0700);
                    kf_run([...$args,'--convert-to',$types[$ext],'--outdir',$dir.'/odf',$source], $dir, $config['timeout']);
                    $odf = 'odf/source.' . $types[$ext];
                    if (!is_file($dir.'/'.$odf) || !filesize($dir.'/'.$odf)) throw new KfError('開放格式轉換未產生檔案，請確認原檔可以正常開啟。', 422);
                    kf_validate($dir.'/'.$odf, $types[$ext]);
                    $manifest['outputs']['odf'] = ['path'=>$odf,'ext'=>$types[$ext],'label'=>'開放格式','size'=>filesize($dir.'/'.$odf)];
                } else {
                    $manifest['outputs']['original']['label'] = '原始檔／開放格式';
                }
                mkdir($dir . '/pdf', 0700);
                $filter = ['odt'=>'writer_pdf_Export','ods'=>'calc_pdf_Export','odp'=>'impress_pdf_Export'][$types[$ext]];
                kf_run([...$args,'--convert-to','pdf:'.$filter,'--outdir',$dir.'/pdf',$source], $dir, $config['timeout']);
                $pdf = $dir . '/pdf/source.pdf';
                if (!is_file($pdf) || !filesize($pdf)) throw new KfError('PDF 轉換未產生檔案，請確認原檔未加密或損壞。', 422);
            }
        }
        $chosen = $pdf;
        if (($_POST['compress'] ?? '1') === '1') {
            [$chosen, $note] = kf_compress($pdf, $dir, $config);
            $manifest['notes'][] = $note;
        }
        if ($ext !== 'pdf' || $chosen !== $source) {
            $manifest['outputs']['pdf'] = ['path'=>substr($chosen, strlen($dir)+1),'ext'=>'pdf','label'=>$chosen !== $pdf ? 'PDF（已壓縮）' : 'PDF','size'=>filesize($chosen)];
        }
        kf_save($dir, $manifest);
        return $manifest;
    } catch (Throwable $e) { kf_remove($dir); throw $e; }
    finally { if ($workerLock) { flock($workerLock, LOCK_UN); fclose($workerLock); } }
}

function kf_archive(string $ownerDir, array $ids, array $config): array
{
    if (!$ids || count($ids) > $config['max_jobs']) throw new KfError('請選擇要下載的檔案組合。');
    $manifests = array_map(fn($id) => kf_manifest($ownerDir, $id, $config), array_values(array_unique($ids)));
    $bundles = glob($ownerDir . '/bundle-*.zip');
    usort($bundles, fn($a, $b) => filemtime($a) <=> filemtime($b));
    while (count($bundles) >= 10) {
        $old = array_shift($bundles);
        unlink($old);
        $meta = substr($old, 0, -4) . '.json';
        if (is_file($meta)) unlink($meta);
    }
    $id = bin2hex(random_bytes(16));
    $archive = $ownerDir . '/bundle-' . $id . '.zip';
    $zip = new ZipArchive();
    if ($zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new KfError('無法建立 ZIP，請重試。', 503);
    $seen = [];
    try {
        foreach ($manifests as $manifest) {
            $folder = $manifest['name'];
            $candidate = $folder;
            for ($n = 2; isset($seen[mb_strtolower($candidate)]); $n++) $candidate = $folder . ' (' . $n . ')';
            $folder = $candidate;
            $seen[mb_strtolower($folder)] = true;
            $counts = array_count_values(array_column($manifest['outputs'], 'ext'));
            foreach ($manifest['outputs'] as $role => $file) {
                $name = ($counts[$file['ext']] > 1 && $role === 'original' ? '原始檔/' : '') . $manifest['name'] . '.' . $file['ext'];
                if (count($manifests) > 1) $name = $folder . '/' . $name;
                if (!$zip->addFile($ownerDir.'/'.$manifest['id'].'/'.$file['path'], $name)) throw new KfError('無法加入 ZIP 檔案。', 503);
            }
        }
        if (!$zip->close()) throw new KfError('ZIP 建立失敗，請重試。', 503);
    } catch (Throwable $e) { @$zip->close(); if (is_file($archive)) unlink($archive); throw $e; }
    $name = count($manifests) === 1 ? $manifests[0]['name'] . '.zip' : 'KhaiFile.zip';
    file_put_contents($ownerDir . '/bundle-' . $id . '.json', json_encode(['name'=>$name, 'created'=>time()], JSON_THROW_ON_ERROR));
    return ['bundle'=>$id, 'name'=>$name];
}

function kf_send(string $path, string $name): never
{
    if (!is_file($path)) throw new KfError('檔案已到期，請重新上傳。', 410);
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="download.'.pathinfo($name, PATHINFO_EXTENSION).'"; filename*=UTF-8\'\'' . rawurlencode($name));
    header('Content-Length: ' . filesize($path));
    header('Cache-Control: private, no-store');
    readfile($path);
    exit;
}
