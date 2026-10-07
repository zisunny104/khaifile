<?php
$localPath = __DIR__ . '/config.local.php';
$local = is_file($localPath) ? require $localPath : [];
return [
    'name' => 'KhaiFile',
    'description' => '補齊開放文件格式與 PDF，保留原檔、調整名稱並批次下載。',
    'version' => '0.1.0',
    'author' => 'Tokas(Xiang-zi Xie)',
    'tags' => ['文件', '開放格式', 'PDF'],
    'max_file_bytes' => 50 * 1024 * 1024,
    'max_session_bytes' => 250 * 1024 * 1024,
    'max_jobs' => 100,
    'ttl' => 3600,
    'timeout' => 120,
    'total_timeout' => 240,
    'max_job_disk_bytes' => 512 * 1024 * 1024,
    'max_owner_disk_bytes' => 1024 * 1024 * 1024,
    'max_total_disk_bytes' => 5 * 1024 * 1024 * 1024,
    'worker_memory_bytes' => 4 * 1024 * 1024 * 1024,
    // 僅供受控開發測試；正式部署必須使用隔離程序。
    'allow_unsandboxed' => in_array(PHP_SAPI, ['cli','cli-server'], true) && getenv('KHAIFILE_ALLOW_UNSANDBOXED') === '1',
    'temp_dir' => getenv('KHAIFILE_TEMP_DIR') ?: ($local['temp_dir'] ?? sys_get_temp_dir() . '/khaifile'),
    'office_bin' => getenv('KHAIFILE_OFFICE_BIN') ?: ($local['office_bin'] ?? 'soffice'),
    'gs_bin' => getenv('KHAIFILE_GS_BIN') ?: ($local['gs_bin'] ?? 'gs'),
];
