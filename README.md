# KhaiFile

開利手的開放格式文件工具。一次拖入多份文件，依序補齊格式、調整名稱並下載。

## 功能

- DOC／DOCX → 保留原始檔、ODT、PDF
- XLS／XLSX → 保留原始檔、ODS、PDF
- PPT／PPTX → 保留原始檔、ODP、PDF
- ODT／ODS／ODP → 保留原始檔、PDF
- PDF → 保留原始檔並嘗試壓縮，不轉成可編輯文件
- PDF 壓縮預設開啟，可取消；沒有更小就保留原 PDF，數位簽章檔不重寫
- 每組可在處理前後改名，預設保留名稱主體，格式副檔名自動決定
- 單檔下載、單組 ZIP、批次逐檔下載、批次逐組 ZIP、全部 ZIP
- 失敗的文件不會中斷其他文件，可單獨重試

每檔上限 50 MB；每個工作階段最多 100 組、250 MB 原始檔。檔案在
伺服器暫存一小時，不上傳到第三方轉換服務；使用者可手動清除。
批次 ZIP 以來源名稱分資料夾，同名資料夾加序號；單檔下載仍保持指定名稱。
若同組有原始 PDF 與壓縮 PDF，ZIP 中原始 PDF 放在 `原始檔/`。

## 開發

標準 KoiLiSu 工具結構：`config.php`、`index.php`、`view.php`。`index.php`
也支援獨立部署，API 透過相同入口的 `?api=` 處理。

需要 PHP 8.2+（zip、mbstring）、Node（語法檢查）、LibreOffice Writer／Calc／
Impress、Ghostscript、GNU timeout，以及適合文件內容的中文字型。範例：

```sh
apt-get install php-cli php-zip php-mbstring libreoffice-writer libreoffice-calc \
  libreoffice-impress ghostscript fonts-noto-cjk
php -d upload_max_filesize=50M -d post_max_size=52M -d max_execution_time=300 \
  -S 127.0.0.1:8081 -t . tools/router.php
```

這個雲端工作區已有本機 PHP 設定，可先執行：

```sh
source /workspace/.onboarding/activate.sh
cd /workspace/khaifile
```

環境變數：`KHAIFILE_OFFICE_BIN`（預設 `soffice`）、`KHAIFILE_GS_BIN`
（預設 `gs`）、`KHAIFILE_TEMP_DIR`（預設系統暫存目錄下的 `khaifile`）。
暫存目錄必須在網站根目錄之外，供 PHP 執行身分寫入。Tocas UI 5.7.0 與
圖示已固定版本放在 `vendor/tocas/`，無 npm 建置或 CDN 啟動依賴。

## 檢查

```sh
php tools/check.php
php tests/backend.php
python3 tests/integration.py
python3 tests/deploy.py
```

整合測試需要 Python 的 python-docx、openpyxl、python-pptx、pypdf、Pillow、
Playwright 與 Chromium；產生文件後啟動自己的 PHP 伺服器，驗證
實際轉換、PDF 頁數與文字、ZIP 內容、重新命名、隔離、刪除與瀏覽器下載。

## 部署與維運

第一次在 VPS 安裝，請參考 [Ubuntu 24.04／Nginx 部署步驟](docs/VPS.md)，
包含 PHP-FPM、HTTPS、內部路徑保護與暫存清理設定。

`./deploy.sh` 檢查執行期依賴與工作目錄，fetch 後先驗證遠端 PHP 語法，
再 fast-forward 更新與檢查網站。它不會自動安裝作業系統套件。

```sh
./deploy.sh --check-deps
./deploy.sh --set-check-url https://example.com/khaifile
./deploy.sh --check-only
./deploy.sh
```

`DEPLOY_BRANCH` 預設 `main`；`DEPLOY_CHECK_URL` 覆蓋儲存網址；
`DEPLOY_RELOAD_CMD` 可設定 PHP-FPM 重載指令。網站檢查會確認頁面與
本機 Tocas 資源可用，並確認工具與靜態資源路徑的 `.git`、設定檔、API
原始碼、內部工具均回 403／404。未設定網址時會明確列為未驗證。

放入 KoiLiSu 的 `apps/khaifile` 後，工具入口為 `/koilisu/khaifile`，
靜態資源位於 `/koilisu/apps/khaifile/`。這個專案不會自動修改母專案的
submodule 列表；正式加入請透過母專案的版本審閱流程。

PHP-FPM 可使用 `.user.ini`；PHP CLI 請使用上方 `-d` 參數。反向代理的
request body 限制至少須為 52 MB、等待時間至少 300 秒。網站應拒絕
`.git`、`api`、`tools`、`tests` 與設定檔的直接請求；Apache 有附 `.htaccess`。
內部 API 不需要直接公開 `api/` 路徑。

每份文件使用獨立 LibreOffice 設定目錄、停用巨集及自動外部連結更新。
轉換以伺服器共用鎖限制並行，使用 timeout 終止逾時程序。正式公開服務應將
文件轉換程序放入有 CPU／記憶體／磁碟限制且禁止對外網路的隔離 worker 或
容器，並由反向代理設定使用量限制；PHP CLI 開發伺服器供本機驗證。

定期執行清理，避免無後續請求時的到期檔案停留：

```sh
php tools/cleanup.php
# cron 範例：每五分鐘，以與 PHP-FPM 相同的身分執行
*/5 * * * * cd /path/to/khaifile && php tools/cleanup.php
```

LibreOffice 轉換可能改變複雜排版、字型、公式或試算表列印範圍。PDF 壓縮
可能改變標籤、表單與中繼資料；如需保留請取消壓縮。開放格式轉換並不保證
文件符合無障礙規範，發布前仍需檢查內容。部署者應使用受支援的穩定版本
與及時安全更新；本雲端驗證機預裝 LibreOfficeDev 26.8 alpha。

## 授權

MIT；第三方元件與伺服器工具依各自授權使用，詳見 LICENSE。
完整盤點見 [授權說明](docs/LICENSING.md)，包含 Ghostscript 的 AGPL 條款與
另行打包伺服器依賴時的注意事項。
