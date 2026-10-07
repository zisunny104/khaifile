<?php
require_once __DIR__ . '/lib.php';
try {
    kf_session();
    $owner = $_SESSION['khaifile']['owner'];
    $csrf = $_SESSION['khaifile']['csrf'];
    session_write_close();
    if (!is_string($api)) throw new KfError('找不到此操作。', 404);
    if ($api !== 'download') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new KfError('此操作需要 POST。', 405);
        if (!$_POST && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) throw new KfError('上傳內容超過伺服器限制，請改用較小的檔案。', 413);
        if (!is_string($_POST['csrf'] ?? null) || !hash_equals($csrf, $_POST['csrf'])) throw new KfError('頁面驗證已失效，請重新整理後重試。', 403);
    } elseif ($_SERVER['REQUEST_METHOD'] !== 'GET') { throw new KfError('下載需要 GET。', 405); }
    [$base, $ownerDir, $ownerLock] = kf_storage($config, $owner);
    switch ($api) {
        case 'process':
            kf_json(['job'=>kf_public(kf_process($ownerDir, $base, $config))]);
        case 'rename':
            $manifest = kf_manifest($ownerDir, $_POST['id'] ?? null, $config);
            $manifest['name'] = kf_name($_POST['name'] ?? null);
            kf_save($ownerDir.'/'.$manifest['id'], $manifest);
            kf_json(['job'=>kf_public($manifest)]);
        case 'archive':
            $ids = $_POST['ids'] ?? null;
            if (!is_array($ids) || array_filter($ids, fn($id) => !is_string($id))) throw new KfError('請選擇要下載的檔案。');
            kf_json(kf_archive($ownerDir, $ids, $config));
        case 'delete':
            $id = kf_id($_POST['id'] ?? null);
            kf_remove($ownerDir.'/'.$id);
            // Generated ZIPs may contain the deleted source; clear cached bundles too.
            foreach (glob($ownerDir.'/bundle-*') as $path) kf_remove($path);
            kf_json(['ok'=>true]);
        case 'clear':
            foreach (glob($ownerDir.'/*') as $path) kf_remove($path);
            kf_json(['ok'=>true]);
        case 'download':
            if (isset($_GET['bundle'])) {
                $id = kf_id($_GET['bundle']);
                $meta = $ownerDir.'/bundle-'.$id.'.json';
                if (!is_file($meta)) throw new KfError('ZIP 已到期，請重新打包。', 410);
                $data = json_decode(file_get_contents($meta), true, 512, JSON_THROW_ON_ERROR);
                if ($data['created'] + $config['ttl'] < time()) throw new KfError('ZIP 已到期，請重新打包。', 410);
                kf_send($ownerDir.'/bundle-'.$id.'.zip', $data['name']);
            }
            $manifest = kf_manifest($ownerDir, $_GET['id'] ?? null, $config);
            $role = $_GET['file'] ?? null;
            if (!is_string($role) || !isset($manifest['outputs'][$role])) throw new KfError('找不到此檔案。', 404);
            $file = $manifest['outputs'][$role];
            kf_send($ownerDir.'/'.$manifest['id'].'/'.$file['path'], $manifest['name'].'.'.$file['ext']);
        default: throw new KfError('找不到此操作。', 404);
    }
} catch (KfError $e) {
    kf_json(['error'=>$e->getMessage()], $e->status);
} catch (Throwable $e) {
    error_log('KhaiFile: ' . get_class($e) . ': ' . $e->getMessage());
    kf_json(['error'=>'伺服器無法完成操作，請稍後重試。'], 500);
}
