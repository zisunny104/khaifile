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
伺服器自建立起一小時後停止提供下載；實際刪除由後續請求或每五分鐘的
清理排程執行，可能晚於到期時間。不使用第三方轉換服務；使用者可手動清除。
250 MB 不包含轉換結果、設定目錄與 ZIP；每個工作階段最多保留十份 ZIP，
目前沒有工作階段完整磁碟用量或全站總量上限。
批次 ZIP 以來源名稱分資料夾，同名資料夾加序號；單檔下載仍保持指定名稱。
若同組有原始 PDF 與壓縮 PDF，ZIP 中原始 PDF 放在 `原始檔/`。

## 開發

標準 KoiLiSu 工具結構：`config.php`、`index.php`、`view.php`。`index.php`
也支援獨立部署，API 透過相同入口的 `?api=` 處理。

執行期需要 PHP 8.2+（zip、mbstring）、LibreOffice Writer／Calc／Impress、
Ghostscript、GNU timeout，以及適合文件內容的中文字型。Node 只用於
JavaScript 語法檢查；未安裝時會明確略過，不影響文件轉換。範例：

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
python3 tests/setup-system.py
```

整合測試需要 Python 的 python-docx、openpyxl、python-pptx、pypdf、Pillow、
Playwright 與 Chromium；產生文件後啟動自己的 PHP 伺服器，驗證
實際轉換、PDF 頁數與文字、ZIP 內容、重新命名、工作階段隔離、刪除與瀏覽器下載。
目前整合測試可能因連續請求觸發 HTTP 429 而中止；不能將前段通過視為
整套測試通過，瀏覽器檢查也可能尚未執行。

## 部署與維運

`./deploy.sh` 檢查執行期依賴與工作目錄，fetch 後先驗證遠端 PHP 語法，
再 fast-forward 更新與檢查網站，操作與訊息沿用其他開利手工具。
正常部署在 Debian／Ubuntu 透過 root／sudo 補齊缺少的依賴，設定使用中的
PHP-FPM 上傳至少 50 MB、請求至少 52 MB、執行時間至少 300 秒，保留較大的
既有設定。會建立網站之外的暫存目錄與每五分鐘清理排程，重複部署不新增排程。
只更新 KhaiFile，不會同步其他子專案；子模組的 detached HEAD 也可快轉更新。

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

`--check-only`、`--check-deps` 只檢查，不修改系統。`DEPLOY_SETUP_SYSTEM=0`
略過主機設定；多個 PHP pool 使用不同帳號時，以 `DEPLOY_PHP_USER` 指定此
網站帳號。自動設定產生忽略的 `config.local.php` 與專屬 `/etc/cron.d/khaifile-*`，
不修改其他工具程式。使用中的 PHP-FPM 會重載，套用新設定。
非 Debian／Ubuntu 或容器部署請先備妥環境，再略過系統設定。
也支援 `/opt` 下正在運行的自編 PHP-FPM：從 master 程序找出執行檔與 FPM
設定檔，再讀取其實際 PHP ini 路徑與 pool 帳號；不以 apt 替換自編 PHP。
有多個自編版本時，用 `DEPLOY_FPM_CONFIG` 指定網站使用的設定檔。
沒有 ini 掃描目錄時只更新 php.ini 的 KhaiFile 區塊，保留原始備份；檢查成功
後以 USR2 平滑重載該 master，不重載其他 FPM。
`DEPLOY_NGINX_SITE` 可指定既有 Nginx 開利手網站設定，加入僅針對 KhaiFile
的私有路徑拒絕規則與限速設定；保留備份，通過 `nginx -t` 才重載，失敗會還原。
限速設定為每 IP 每分鐘 20 次、允許突發 5 次，只針對 `process`、`archive`。
但目前限速鍵只匹配 `/koilisu/apps/khaifile/`，未涵蓋一般工具入口
`/koilisu/khaifile`；不能據此認定正式入口已受限速保護。
未指定時不修改 Nginx。工具本身也拒絕未知 action，避免母專案 fallback
將 `config.php` 等不存在的工具子路徑當成正常頁面。

放入 KoiLiSu 的 `apps/khaifile` 後，工具入口為 `/koilisu/khaifile`，
靜態資源位於 `/koilisu/apps/khaifile/`。母專案已加入 KhaiFile 子模組。
若其他工具在 VPS 有較新的進度，可只在 `apps/khaifile` 內部署此工具，
保留其他工具的版本；母專案日後重新同步子模組時，仍以其提交記錄的版本為準。

PHP-FPM 可使用 `.user.ini`；PHP CLI 請使用上方 `-d` 參數。反向代理的
請求內容大小限制至少須為 52 MB。部署腳本設定 PHP 執行時間至少 300 秒，
但 ODF、PDF 與壓縮程序各有 120 秒的逾時，加上終止寬限及其他處理時間，
單次請求可能超過 360 秒；300 秒不足以涵蓋所有情況。反向代理及 FPM 的
請求終止時間需依整份文件的處理預算設定，目前程式沒有共用的總逾時。網站應拒絕
`.git`、`api`、`tools`、`tests` 與設定檔的直接請求；Apache 有附 `.htaccess`。
內部 API 不需要直接公開 `api/` 路徑。

每份文件使用獨立 LibreOffice 設定目錄、停用巨集及自動外部連結更新。
轉換以同一暫存根目錄的共用鎖限制為一次一份，忙碌時回 HTTP 503。
同一工作階段的鎖會持續到轉換或下載結束，其他操作會等待。
使用 timeout 終止逾時程序；找到 `prlimit` 時，另限制程序 CPU 時間與
單一輸出檔案大小 100 MB。目前沒有記憶體、總磁碟用量、網路或檔案存取隔離，
轉換程序也繼承 PHP 的環境變數。巨集與連結設定不能取代程序隔離。
正式公開服務應將
文件轉換程序放入有 CPU／記憶體／磁碟限制且禁止對外網路的隔離 worker 或
容器，並由反向代理設定使用量限制；PHP CLI 開發伺服器供本機驗證。

部署腳本自動建立定期清理，避免無後續請求時的到期檔案停留。
略過系統設定時，請使用既有排程機制執行：

```sh
php tools/cleanup.php
# cron 範例：每五分鐘，以與 PHP-FPM 相同的身分執行
*/5 * * * * cd /path/to/khaifile && php tools/cleanup.php
```

LibreOffice 轉換可能改變複雜排版、字型、公式或試算表列印範圍。PDF 壓縮
可能改變標籤、表單與中繼資料；如需保留請取消壓縮。開放格式轉換並不保證
文件符合無障礙規範，發布前仍需檢查內容。部署者應使用受支援的穩定版本
與及時安全更新；本雲端驗證機預裝 LibreOfficeDev 26.8 alpha。

## 已知操作限制

後端對每個工作階段的 `process`、`archive` 分別設定兩秒請求間隔。
快速完成的文件可能讓下一份處理收到 HTTP 429；前端逐組 ZIP 只等待
0.7 秒，也可能在批次中途被拒絕。目前沒有自動等待或重試機制，需稍候
再操作；批次 ZIP 可改用「全部 ZIP」減少打包請求。這些限制尚未修正。

## 授權

MIT；第三方元件與伺服器工具依各自授權使用，詳見 LICENSE。
完整盤點見 [授權說明](docs/LICENSING.md)，包含 Ghostscript 的 AGPL 條款與
另行打包伺服器依賴時的注意事項。
