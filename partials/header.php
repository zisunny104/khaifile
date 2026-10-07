<?php defined('KHAIFILE_VIEW') || exit; ?>
<div id="app-header">
    <div class="ts-grid is-middle-aligned">
        <div class="column is-fluid">
            <div class="ts-wrap is-middle-aligned is-compact">
                <h1 class="ts-header is-heavy is-large is-start-icon">
                    <span class="ts-icon is-file-arrow-up-icon" aria-hidden="true"></span>
                    KhaiFile
                </h1>
                <span class="ts-text is-description app-version">v<?= $e($config['version']) ?></span>
            </div>
            <div class="ts-text is-description">補齊開放格式與 PDF，保留原檔、調整名稱並批次下載。</div>
        </div>
    </div>
    <div class="ts-divider has-vertically-spaced-small tablet+:has-vertically-spaced"></div>
</div>
