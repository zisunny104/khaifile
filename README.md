# KhaiFile

開利手的開放格式文件工具，供教育單位準備網站附件的原始檔、開放格式與
PDF 並列版本。一次拖入多份文件，依序補齊格式、調整名稱並下載。

## 功能

- DOC／DOCX → 保留原始檔、ODT、PDF
- XLS／XLSX → 保留原始檔、ODS、PDF
- PPT／PPTX → 保留原始檔、ODP、PDF
- ODT／ODS／ODP → 保留原始檔、PDF
- PDF → 保留原始檔並嘗試壓縮，不轉成可編輯文件
- PDF 壓縮預設開啟，可取消；沒有更小就保留原 PDF，偵測到 `/ByteRange` 簽章標記時不重寫
- 每組可在處理前後改名，預設保留名稱主體，格式副檔名自動決定
- 單檔下載、單組 ZIP、批次逐檔下載、批次逐組 ZIP、全部 ZIP
- 失敗的文件不會中斷其他文件，可單獨重試

每檔上限 50 MB；每個工作階段最多 100 組、250 MB 原始檔。檔案在
伺服器自建立起一小時後停止提供下載；實際刪除由後續請求或每五分鐘的
清理排程執行，可能晚於到期時間。不使用第三方轉換服務；使用者可手動清除。
250 MB 不包含轉換結果、設定目錄與 ZIP；每個工作階段最多保留十份 ZIP，
完整暫存用量每個工作階段上限 1 GB、同一暫存根目錄上限 5 GB。
新增轉換或 ZIP 前會保留所需額度，並要求磁碟保留至少 512 MB 可用空間；
保留額度可能使操作在尚未用滿配額前被拒絕。每份文件的轉換目錄上限
512 MB，執行期間每 0.1 秒檢查，超量時終止；可能有短暫超量。
批次 ZIP 以來源名稱分資料夾，同名資料夾加序號；單檔下載仍保持指定名稱。
若同組有原始 PDF 與壓縮 PDF，ZIP 中原始 PDF 放在 `原始檔/`。

## 開發

標準 KoiLiSu 工具結構：`config.php`、`index.php`、`view.php`。`index.php`
也支援獨立部署，API 透過相同入口的 `?api=` 處理。

執行期需要 PHP 8.2+（zip、mbstring）、LibreOffice Writer／Calc／Impress、
Ghostscript、GNU timeout、prlimit（util-linux）、Bubblewrap，以及適合文件
內容的中文字型。Node 只用於
JavaScript 語法檢查；未安裝時會明確略過，不影響文件轉換。範例：

```sh
apt-get install php-cli php-zip php-mbstring libreoffice-writer libreoffice-calc \
  libreoffice-impress ghostscript fonts-noto-cjk bubblewrap util-linux coreutils
php -d upload_max_filesize=50M -d post_max_size=52M -d max_execution_time=300 \
  -S 127.0.0.1:8081 -t . tools/router.php
```

在已完成開利手雲端環境設定、且存在下列啟用腳本的工作區，可先執行：

```sh
source /workspace/.onboarding/activate.sh
cd /workspace/khaifile
```

環境變數：`KHAIFILE_OFFICE_BIN`（預設 `soffice`）、`KHAIFILE_GS_BIN`
（預設 `gs`）、`KHAIFILE_TEMP_DIR`。三者優先於 `config.local.php`；沒有
環境變數或本機設定時，暫存路徑才預設為系統暫存目錄下的 `khaifile`。
自動部署會將暫存路徑、LibreOffice 與 Ghostscript 的絕對路徑寫入
`config.local.php`，暫存預設位於 `/var/lib/khaifile/<專案路徑雜湊>`。
暫存目錄必須在網站根目錄之外，供 PHP 執行身分寫入。Tocas UI 5.7.0 與
圖示已固定版本放在 `vendor/tocas/`，無 npm 建置或 CDN 啟動依賴。

## 檢查

```sh
php tools/check.php
php tests/backend.php
php tests/security.php
python3 tests/download-lock.py
python3 tests/integration.py
python3 tests/deploy.py
python3 tests/setup-system.py
```

整合測試需要 Python 的 python-docx、openpyxl、python-pptx、pypdf、Pillow、
Playwright 與 Chromium；產生文件後啟動自己的 PHP 伺服器，驗證
實際轉換、PDF 頁數與文字、ZIP 內容、重新命名、工作階段隔離、刪除與瀏覽器下載。
整合測試明確使用 `KHAIFILE_ALLOW_UNSANDBOXED=1`，只驗證轉換與網頁功能，
不驗證核心命名空間隔離。此開關僅作用於 CLI／CLI 開發伺服器，PHP-FPM
始終要求隔離。`tests/security.php` 另驗證配額、環境變數、逾時與鎖定；
主機不支援命名空間時，實際隔離檢查會列為略過，不能宣稱已驗證隔離成功。
`tests/security.php` 也需要系統 Python 3（`/usr/bin/python3`）驗證記憶體限制。
Git 部署測試同樣明確停用隔離，只驗證部署流程；系統設定測試使用模擬
服務與暫存設定，不會修改真實 PHP-FPM 或 Nginx。

## 部署與維運

`./deploy.sh` 先檢查本機變更，取得遠端版本並快轉更新程式，接著設定
主機環境與檢查網站。本機與遠端分歧時會停止，不重設本機版本。
部署及 `--check-only` 不執行 PHP／JavaScript 語法檢查或完整測試；
請在提交前或 CI 執行上方的檢查工具。
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
限速設定為每 IP 每分鐘 120 次、允許突發 10 次，只針對 `process`、`archive`。
限速鍵使用原始請求網址，涵蓋 `/koilisu/khaifile` 與
`/koilisu/apps/khaifile/`，避免母專案內部轉址後漏掉檢查；超量回 HTTP 429。
未指定時不修改 Nginx。工具本身也拒絕未知 action，避免母專案 fallback
將 `config.php` 等不存在的工具子路徑當成正常頁面。

放入 KoiLiSu 的 `apps/khaifile` 後，工具入口為 `/koilisu/khaifile`，
靜態資源位於 `/koilisu/apps/khaifile/`。母專案已加入 KhaiFile 子模組。
若其他工具在 VPS 有較新的進度，可只在 `apps/khaifile` 內部署此工具，
保留其他工具的版本；母專案同步時會快轉較舊版本、保留同一歷史較新的提交，分歧時中止。

PHP-FPM 可使用 `.user.ini`；PHP CLI 請使用上方 `-d` 參數。反向代理的
請求內容大小限制至少須為 52 MB。部署腳本設定 PHP 執行時間至少 300 秒，
ODF、PDF 與壓縮程序各最多 120 秒，並共用整份文件的 240 秒處理預算；
程序終止另有最多兩秒寬限。反向代理及 FPM 的請求等待／終止時間至少
300 秒，並另考量上傳時間。ZIP 打包與下載不使用轉換程序的逾時。網站應拒絕
`.git`、`api`、`tools`、`tests` 與設定檔的直接請求；Apache 有附 `.htaccess`。
內部 API 不需要直接公開 `api/` 路徑。

每份文件使用獨立 LibreOffice 設定目錄、停用巨集及自動外部連結更新。
轉換以同一暫存根目錄的共用鎖限制為一次一份，忙碌時回 HTTP 503。
同一工作階段忙碌時立即回 HTTP 503，避免占住 PHP worker 等待；下載先
開啟檔案，再釋放工作階段鎖，其他操作不必等下載結束。所有會增加用量的
轉換與 ZIP 打包共用非阻塞配額鎖，避免跨工作階段同時占用保留額度。

轉換使用 Bubblewrap 建立獨立的網路、PID 等命名空間，只提供唯讀系統工具、
字型與必要設定，僅該文件目錄可寫；不暴露網站目錄或傳遞 PHP 的環境變數。
GNU timeout 終止逾時程序，prlimit 限制每個程序的虛擬記憶體 4 GB、
CPU 時間、單一輸出檔案 100 MB 及同帳號程序數 256（程序數限制不適用
root）。這不是整個程序樹的
實體記憶體配額；大型公開服務仍應使用 cgroup／容器設定整體資源上限。
隔離無法啟動時會拒絕需要程序執行的轉換，沒有自動降級；PDF 壓縮失敗
則依原本流程保留原 PDF。正常自動設定會以 PHP 執行帳號，在 PHP CLI
下啟動隔離的 `/usr/bin/true` 檢查；這不等於已驗證 PHP-FPM 實際請求或
隔離內的 LibreOffice／Ghostscript 轉換。`--check-only`、`--check-deps`
及略過系統設定時，依賴檢查以執行指令的帳號進行。主機必須允許 Bubblewrap 使用核心命名空間，部署腳本
不會放寬全機安全政策。PHP CLI 開發伺服器供本機驗證。

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

正常批次操作不再受每個工作階段兩秒的固定間隔阻擋。前端的處理與打包操作遇到 HTTP 429
或 API 回傳的 HTTP 503 時，會等待後重試，最多三次；仍忙碌則保留該份
文件的錯誤狀態，可單獨重試。逐組下載保留 0.7 秒間隔；瀏覽器可能要求
允許多檔下載，也可改用「全部 ZIP」。

## 授權

MIT；第三方元件與伺服器工具依各自授權使用，詳見 LICENSE。
完整盤點見 [授權說明](docs/LICENSING.md)，包含 Ghostscript 的 AGPL 條款與
另行打包伺服器依賴時的注意事項。
