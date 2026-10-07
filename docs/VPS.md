# VPS 第一次部署

以下以 **Ubuntu 24.04 LTS、Nginx、PHP 8.3-FPM、獨立網域**為例。
Debian 或其他版本請調整 PHP 套件版本與服務名稱。已有網站的 VPS 請先檢查
現有 virtual host，避免重複網域；本文件不會修改既有網站。
將 `files.example.edu.tw` 換成自己的網域，先將 DNS A／AAAA 指向 VPS。
有設定 AAAA 時，IPv6 也必須可連線。指令中的 `sudo` 需要管理權限。

## 1. 安裝依賴與取得程式

使用一般部署帳號登入，執行：

```sh
sudo apt-get update
sudo apt-get install git curl nginx php8.3-cli php8.3-fpm php8.3-zip \
  php8.3-mbstring libreoffice-writer libreoffice-calc libreoffice-impress \
  ghostscript fonts-noto-cjk coreutils certbot python3-certbot-nginx
sudo install -d -o "$(id -un)" -g "$(id -gn)" -m 755 /srv/khaifile
git clone --branch main https://github.com/zisunny104/khaifile.git /srv/khaifile
sudo install -d -o www-data -g www-data -m 700 /var/lib/khaifile
cd /srv/khaifile
```

`/srv/khaifile` 必須是空目錄；若已有程式，先確認內容，不要覆蓋。
程式由部署帳號持有，PHP 執行身分只需讀取程式、寫入暫存目錄。
不需要資料庫、npm 建置或 Node；Tocas UI 已包含在專案中。

## 2. 建立專用 PHP-FPM pool

建立 `/etc/php/8.3/fpm/pool.d/khaifile.conf`：

```ini
[khaifile]
user = www-data
group = www-data
listen = /run/php/khaifile.sock
listen.owner = www-data
listen.group = www-data
listen.mode = 0660
pm = ondemand
pm.max_children = 2
pm.process_idle_timeout = 30s
request_terminate_timeout = 300s
env[PATH] = /usr/local/bin:/usr/bin:/bin
env[KHAIFILE_TEMP_DIR] = /var/lib/khaifile
php_admin_value[upload_max_filesize] = 50M
php_admin_value[post_max_size] = 52M
php_admin_value[max_execution_time] = 300
php_admin_flag[display_errors] = off
php_admin_flag[log_errors] = on
```

確認系統的 `disable_functions` 未停用 `proc_open`，並執行：

```sh
sudo php-fpm8.3 -t
sudo systemctl reload php8.3-fpm
sudo -u www-data env KHAIFILE_TEMP_DIR=/var/lib/khaifile \
  php /srv/khaifile/tools/deps.php
```

CLI 環境與 FPM 設定不同，所以網站啟用後仍須實際上傳文件確認轉換。

## 3. 建立 Nginx 網站

建立 `/etc/nginx/sites-available/khaifile`：

```nginx
server {
    listen 80;
    listen [::]:80;
    server_name files.example.edu.tw;
    root /srv/khaifile;
    index index.php;
    client_max_body_size 52m;

    location ~ (^|/)\. { return 404; }
    location ~ ^/(api|tools|tests|docs)(/|$) { return 404; }
    location ~* \.(ini|local\.php|log)$ { return 404; }

    location = /index.php {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root/index.php;
        fastcgi_pass unix:/run/php/khaifile.sock;
        fastcgi_read_timeout 300s;
    }
    location ~ \.php(?:/|$) { return 404; }

    location / {
        try_files $uri $uri/ =404;
    }
}
```

Nginx 不會讀取 `.htaccess`，所以必須保留上方內部路徑與 PHP 拒絕規則。
API 使用 `/index.php?api=…`，不需要公開 `api/`。

```sh
sudo ln -s /etc/nginx/sites-available/khaifile /etc/nginx/sites-enabled/khaifile
sudo nginx -t
sudo systemctl reload nginx
sudo certbot --nginx -d files.example.edu.tw
```

Certbot 請選擇 HTTP 轉 HTTPS。確認 VPS 與供應商防火牆允許 TCP 80／443，
保留 SSH 存取；若無 IPv6，刪除 IPv6 listen 並不要設定 AAAA。

## 4. 排程清理與驗證

建立 `/etc/cron.d/khaifile`（檔案末尾保留換行）：

```cron
*/5 * * * * www-data KHAIFILE_TEMP_DIR=/var/lib/khaifile /usr/bin/php /srv/khaifile/tools/cleanup.php
```

清理工作與 PHP-FPM 必須使用相同身分及暫存路徑。
確認 cron 服務有啟動：`sudo systemctl enable --now cron`；若未安裝，先安裝 `cron`。

```sh
cd /srv/khaifile
./deploy.sh --set-check-url https://files.example.edu.tw
```

檢查網址設定檔權限為 600，PHP 身分不能讀取部署帳號的設定檔，因此網站
檢查明確傳入網址，同時以實際網站執行身分確認暫存權限：

```sh
sudo -u www-data env KHAIFILE_TEMP_DIR=/var/lib/khaifile \
  DEPLOY_CHECK_URL=https://files.example.edu.tw \
  bash /srv/khaifile/deploy.sh --check-only
```

上傳一份 DOCX、一份 XLSX、一份 PPTX，確認各自能下載原始檔、開放格式與 PDF，
再測試改名、ZIP 與清除。網站檢查成功不代表實際轉換已驗證。

## 5. 後續更新

用原部署帳號執行，不要讓 PHP 身分持有或更新程式：

```sh
cd /srv/khaifile
./deploy.sh
sudo systemctl reload php8.3-fpm
```

這裡的部署帳號使用預設 `/tmp/khaifile` 進行 CLI 依賴檢查；網站仍使用 FPM
pool 指定的 `/var/lib/khaifile`。更新後另外以上方 `www-data` 指令檢查網站。
`deploy.sh` 只允許乾淨工作目錄與 fast-forward，不會處理主機套件或自動排程。

## 公開服務前

以上是網站與依賴的安裝步驟。公開接受不特定使用者的文件前，還須將執行轉換的
PHP／LibreOffice／Ghostscript 放在禁止對外網路、限制 CPU／記憶體／磁碟的
容器或其他隔離環境，並設定使用量限制。程式目前在 PHP 主機啟動轉換程序，
沒有內建遠端 worker；專用 FPM pool 不提供這種作業系統隔離。
教育單位可先在限制存取的測試環境驗證排版與字型。
保留第三方授權文件，詳見 [授權說明](LICENSING.md)。
