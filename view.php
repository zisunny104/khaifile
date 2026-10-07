<?php
$assetBase = $_APP['name'] ?? null;
// Framework routes do not serve app assets. Standalone deployments use their own directory.
$assetBase = isset($_APP['dir']) ? '/koilisu/apps/khaifile' : rtrim(dirname(parse_url($_SERVER['SCRIPT_NAME'], PHP_URL_PATH)), '/');
$assetBase = $assetBase === '/' ? '' : $assetBase;
$e = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>KhaiFile — 開放格式文件工具</title>
    <link rel="stylesheet" href="<?= $e($assetBase) ?>/vendor/tocas/tocas.min.css">
    <link rel="stylesheet" href="<?= $e($assetBase) ?>/assets/app.css">
    <script src="<?= $e($assetBase) ?>/vendor/tocas/tocas.min.js" defer></script>
    <script src="<?= $e($assetBase) ?>/assets/app.js" type="module"></script>
</head>
<body class="is-rounded">
<a href="#main" class="skip-link">跳到主要內容</a>
<header class="ts-content is-secondary">
    <div class="ts-container header-row">
        <a class="brand" href="/koilisu/">KoiLiSu 開利手</a>
        <span class="ts-text is-description">開放格式文件工具</span>
    </div>
</header>
<main id="main" class="ts-container has-vertically-padded-large">
    <div class="intro">
        <div class="ts-text is-description">DOCUMENTS, READY TO SHARE</div>
        <h1 class="ts-header is-huge is-heavy">KhaiFile <span class="version">v<?= $e($config['version']) ?></span></h1>
        <p class="ts-text is-large">丟進文件，備齊格式。</p>
        <p class="ts-text is-description">保留原始檔，補上開放格式與 PDF。整理名稱，再一次下載。</p>
    </div>

    <section class="ts-box has-top-spaced-large" aria-labelledby="upload-title">
        <div class="ts-content">
            <h2 id="upload-title" class="ts-header is-large">加入檔案</h2>
            <div id="drop-zone" class="drop-zone has-top-spaced" tabindex="0" role="button" aria-label="選擇或拖放多個檔案">
                <span class="ts-icon is-file-arrow-up-icon is-huge" aria-hidden="true"></span>
                <div class="ts-header is-big has-top-spaced-small">把檔案拖到這裡</div>
                <div class="ts-text is-description has-top-spaced-small">或點一下選擇檔案，可混合加入多份文件</div>
                <div class="ts-wrap is-center-aligned has-top-spaced">
                    <span class="ts-badge">Word → ODT・PDF</span>
                    <span class="ts-badge">Excel → ODS・PDF</span>
                    <span class="ts-badge">PowerPoint → ODP・PDF</span>
                    <span class="ts-badge">PDF → 壓縮</span>
                </div>
            </div>
            <input id="file-input" type="file" accept=".doc,.docx,.xls,.xlsx,.ppt,.pptx,.odt,.ods,.odp,.pdf" multiple hidden>
            <div class="settings has-top-spaced">
                <label class="ts-checkbox"><input id="compress" type="checkbox" checked><span>壓縮 PDF</span></label>
                <span class="ts-text is-description">壓縮後沒有更小就保留原 PDF；圖片畫質可能降低。</span>
            </div>
            <p class="ts-text is-description has-top-spaced-small">每個檔案最多 50 MB，每次工作最多 100 組、250 MB 原始檔。檔案會上傳至伺服器處理，暫存一小時，可隨時清除。</p>
        </div>
    </section>

    <section class="has-top-spaced-large" aria-labelledby="queue-title">
        <div class="section-heading">
            <div><h2 id="queue-title" class="ts-header is-large">檔案清單 <span id="count" class="ts-badge">0</span></h2><p id="summary" class="ts-text is-description">加入檔案後，即可開始處理。</p></div>
            <div class="ts-wrap"><button id="clear" class="ts-button is-outlined" disabled>清除全部</button><button id="start" class="ts-button is-primary" disabled>開始處理</button></div>
        </div>
        <div id="message" class="ts-notice is-negative has-top-spaced" role="alert" hidden></div>
        <div id="live" class="sr-only" role="status" aria-live="polite"></div>
        <div id="empty" class="empty-state ts-text is-description">尚未加入檔案。原檔、開放格式與 PDF 會在每組中並列顯示。</div>
        <div id="queue" class="queue has-top-spaced"></div>
    </section>

    <section id="download-panel" class="ts-box has-top-spaced-large" aria-labelledby="download-title" hidden>
        <div class="ts-content">
            <h2 id="download-title" class="ts-header is-large">批次下載</h2>
            <p class="ts-text is-description has-top-spaced-small">對勾選的已完成檔案操作。名稱修改會套用到各格式與 ZIP。</p>
            <div class="ts-wrap has-top-spaced"><button id="select-all" class="ts-button is-outlined">全選已完成</button><button id="download-files" class="ts-button">逐一下載檔案</button><button id="download-groups" class="ts-button">逐組下載 ZIP</button><button id="download-all" class="ts-button is-primary">全部打包 ZIP</button></div>
            <p id="download-status" class="ts-text is-description has-top-spaced-small" role="status"></p>
            <p class="ts-text is-description has-top-spaced-small">逐一下載時，瀏覽器可能詢問是否允許多個檔案下載；若有阻擋，可改用 ZIP。</p>
        </div>
    </section>

    <details class="ts-box has-top-spaced-large">
        <summary class="ts-content">使用說明與格式注意事項</summary>
        <div class="ts-content">
            <ol class="help-list"><li>加入 Office、ODF 或 PDF；每個來源是一組檔案。</li><li>修改名稱主體，副檔名由格式決定；預設沿用原名稱。</li><li>開始處理後依序轉換；失敗的檔案可重試，不影響其他組。</li><li>下載單一格式、整組 ZIP，或勾選多組批次下載。</li></ol>
            <p>PDF 暫不支援轉換為可編輯文件。已是 ODF 的文件會保留原檔並產生 PDF。</p>
            <p>複雜排版、缺少的字型、公式與試算表列印範圍，可能和原軟體呈現不同，發布前請檢查結果。格式轉換不等於完成文件無障礙。</p>
            <p>PDF 壓縮會重新寫入文件；含數位簽章的 PDF 會保留原檔。需維持標籤、表單或其他結構時，請取消 PDF 壓縮。</p>
        </div>
    </details>
</main>
<footer class="ts-content is-secondary has-top-spaced-large">
    <div class="ts-container footer-row">
        <div class="ts-wrap is-middle-aligned"><a class="brand" href="/koilisu/">KoiLiSu 開利手</a><span class="ts-text is-description">讓工具使用更順手</span><button id="license-button" class="ts-button is-small is-outlined">License</button></div>
        <div class="ts-selection is-compact" role="radiogroup" aria-label="佈景主題">
            <label class="item"><input type="radio" name="theme" value="light"><span class="text">淺色</span></label>
            <label class="item"><input type="radio" name="theme" value="system" checked><span class="text">系統</span></label>
            <label class="item"><input type="radio" name="theme" value="dark"><span class="text">深色</span></label>
        </div>
    </div>
</footer>
<dialog id="license-dialog" class="license-dialog"><div class="ts-content"><h2 class="ts-header is-large">授權</h2><p>KhaiFile 採 MIT License。Tocas UI 採 MIT License；伺服器部署的 LibreOffice、Ghostscript 與字型依各自授權使用。</p><pre id="license-text"></pre><form method="dialog"><button class="ts-button">關閉</button></form></div></dialog>
<script id="app-data" type="application/json"><?= json_encode(['csrf'=>$csrf,'assetBase'=>$assetBase,'maxFileBytes'=>$config['max_file_bytes'],'maxSessionBytes'=>$config['max_session_bytes'],'maxJobs'=>$config['max_jobs']], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
</body>
</html>
