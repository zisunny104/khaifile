#!/usr/bin/env bash
# KhaiFile 的 Debian／Ubuntu 主機設定；由 deploy.sh 呼叫。
set -euo pipefail

supported_php_version() {
    [[ "$1" =~ ^([0-9]+)\.([0-9]+)$ ]] &&
        (( BASH_REMATCH[1] > 8 || (BASH_REMATCH[1] == 8 && BASH_REMATCH[2] >= 2) ))
}

pool_users() {
    # FPM -tt 的 NOTICE 前綴與縮排可包含 tab，不限定單一輸出排版。
    sed -n 's/.*[[:space:]]user[[:space:]]*=[[:space:]]*\([^[:space:]]*\).*/\1/p' | sort -u
}

write_managed() {
    local destination="$1" mode="$2" temporary
    [[ ! -L "$destination" ]] || { echo "拒絕寫入符號連結：$destination" >&2; return 1; }
    temporary="$(mktemp "$(dirname "$destination")/.khaifile.XXXXXX")"
    cat > "$temporary"
    if [[ -e "$destination" ]] && ! grep -q 'KhaiFile managed' "$destination"; then
        rm -f "$temporary"
        echo "保留既有設定，請先檢查：$destination" >&2
        return 1
    fi
    if [[ -f "$destination" ]] && cmp -s "$temporary" "$destination"; then
        rm -f "$temporary"
        return 0
    fi
    chmod "$mode" "$temporary"
    mv -f "$temporary" "$destination"
}

configure_fpm() {
    local directory="$1" binary="$2" current service temporary
    service="php$(basename "$(dirname "$directory")")-fpm"
    temporary="$(mktemp)"
    # 使用該版本 PHP 的現有 FPM ini；不得降低其他工具已設定的上限。
    if ! PHP_INI_SCAN_DIR="$directory/conf.d" "$binary" -c "$directory/php.ini" -r '
        function size($s) { $s=trim($s); $factors=["g"=>1073741824,"m"=>1048576,"k"=>1024]; return (float)$s * ($factors[strtolower(substr($s,-1))] ?? 1); }
        $settings=parse_ini_file(php_ini_loaded_file(),false,INI_SCANNER_RAW) ?: [];
        foreach (explode(",",php_ini_scanned_files() ?: "") as $file) {
            if (trim($file)!=="") $settings=array_replace($settings,parse_ini_file(trim($file),false,INI_SCANNER_RAW) ?: []);
        }
        echo "; KhaiFile managed\n";
        foreach (["upload_max_filesize"=>50*1048576,"post_max_size"=>52*1048576,"max_execution_time"=>300] as $key=>$minimum) {
            // CLI 強制 max_execution_time=0，故此項直接讀取 FPM ini。
            $old=$key==="max_execution_time" ? ($settings[$key] ?? "30") : get_cfg_var($key);
            if ($old===false) $old=$key==="post_max_size" ? "8M" : "2M";
            $value=$key==="max_execution_time" ? (int)$old : size($old);
            // 執行時間與 post_max_size 的 0 代表無限制，不覆蓋。
            if (($value==0 && $key!=="upload_max_filesize") || $value >= $minimum) $new=$old;
            else $new=$minimum;
            echo "$key = $new\n";
        }
    ' > "$temporary"; then rm -f "$temporary"; return 1; fi
    if ! write_managed "$directory/conf.d/99-khaifile.ini" 644 < "$temporary"; then
        rm -f "$temporary"; return 1
    fi
    rm -f "$temporary"
    local fpm="${3:-/usr/sbin/$service}"
    "$fpm" -t
    systemctl reload "$service"
    echo "  ✓ $service 上傳至少 50 MB、請求至少 52 MB、逾時至少 300 秒"
}

discover_custom_fpm() {
    local pid args binary version config
    CUSTOM_PID= CUSTOM_BIN= CUSTOM_CONFIG=
    while read -r pid args; do
        [[ "$args" =~ ^php-fpm:\ master\ process\ \((.*)\)$ ]] || continue
        config="${BASH_REMATCH[1]}"
        [[ "$config" == /opt/* || -n "${DEPLOY_FPM_CONFIG:-}" ]] || continue
        [[ -z "${DEPLOY_FPM_CONFIG:-}" || "$config" == "$DEPLOY_FPM_CONFIG" ]] || continue
        binary="$(readlink -f "/proc/$pid/exe")"
        [[ -x "$binary" && -f "$config" ]] || continue
        version="$("$binary" -v 2>/dev/null | sed -n 's/^PHP \([0-9]*\.[0-9]*\).*/\1/p' | head -n 1)"
        supported_php_version "$version" || continue
        [[ -z "$CUSTOM_PID" ]] || { echo '有多個自編 FPM，請以 DEPLOY_FPM_CONFIG 指定網站使用的設定檔。' >&2; return 1; }
        CUSTOM_PID="$pid" CUSTOM_BIN="$binary" CUSTOM_CONFIG="$config"
    done < <(ps -eo pid=,args=)
}

custom_limits() {
    # FPM -i 的有效設定；只取三項，不輸出環境變數或其他主機資訊。
    local info="$1" upload post seconds
    upload="$(printf '%s\n' "$info" | awk -F' => ' '$1=="upload_max_filesize" {print $2;exit}')"
    post="$(printf '%s\n' "$info" | awk -F' => ' '$1=="post_max_size" {print $2;exit}')"
    seconds="$(printf '%s\n' "$info" | awk -F' => ' '$1=="max_execution_time" {print $2;exit}')"
    [[ -n "$upload" && -n "$post" && -n "$seconds" ]] || { echo '無法讀取自編 FPM 的 PHP 限制。' >&2; return 1; }
    php -r '
        function bytes($s) { $f=["g"=>1073741824,"m"=>1048576,"k"=>1024]; return (float)$s*($f[strtolower(substr(trim($s),-1))]??1); }
        echo "; KhaiFile managed\n";
        echo "upload_max_filesize = ".(bytes($argv[1])>=52428800?$argv[1]:"50M")."\n";
        echo "post_max_size = ".(bytes($argv[2])==0||bytes($argv[2])>=54525952?$argv[2]:"52M")."\n";
        echo "max_execution_time = ".((int)$argv[3]==0||(int)$argv[3]>=300?$argv[3]:"300")."\n";
    ' "$upload" "$post" "$seconds"
}

configure_custom_fpm() {
    local info ini scan contents temporary backup destination restore
    info="$("$CUSTOM_BIN" -i)"
    ini="$(printf '%s\n' "$info" | sed -n 's/^Loaded Configuration File => //p' | head -n 1)"
    scan="$(printf '%s\n' "$info" | sed -n 's/^Scan this dir for additional .ini files => //p' | head -n 1)"
    contents="$(custom_limits "$info")"
    if [[ "$scan" == /* && -d "$scan" ]]; then
        destination="$scan/99-khaifile.ini"
        restore="$(mktemp)"
        if [[ -f "$destination" ]]; then cp -p "$destination" "$restore"; else rm -f "$restore"; fi
        printf '%s\n' "$contents" | write_managed "$destination" 644
    else
        [[ "$ini" == /* && -f "$ini" && ! -L "$ini" ]] || { echo '自編 FPM 沒有可寫入的 php.ini 或掃描目錄。' >&2; return 1; }
        # 保留原檔，只更新自有區塊；備份只建立一次。
        backup="$ini.khaifile-backup"
        [[ -e "$backup" ]] || cp -p "$ini" "$backup"
        destination="$ini"
        restore="$(mktemp)"
        cp -p "$ini" "$restore"
        temporary="$(mktemp "$(dirname "$ini")/.khaifile.XXXXXX")"
        awk '/^; BEGIN KhaiFile managed$/ {skip=1;next} /^; END KhaiFile managed$/ {skip=0;next} !skip {if ($0=="") {blanks++;next} while(blanks>0) {print "";blanks--} print}' "$ini" > "$temporary"
        printf '; BEGIN KhaiFile managed\n%s\n; END KhaiFile managed\n' "$contents" >> "$temporary"
        chmod --reference="$ini" "$temporary"
        chown --reference="$ini" "$temporary"
        if cmp -s "$temporary" "$ini"; then rm -f "$temporary"; else mv "$temporary" "$ini"; fi
    fi
    if ! "$CUSTOM_BIN" -t -y "$CUSTOM_CONFIG"; then
        if [[ -f "$restore" ]]; then cp -p "$restore" "$destination"; else rm -f "$destination"; fi
        rm -f "$restore"
        echo '自編 FPM 設定檢查未通過，已還原本次變更，未重載。' >&2
        return 1
    fi
    rm -f "$restore"
    [[ "$(readlink -f "/proc/$CUSTOM_PID/exe")" == "$CUSTOM_BIN" ]] || { echo 'FPM 程序已變更，未重載。' >&2; return 1; }
    kill -USR2 "$CUSTOM_PID"
    echo "  ✓ 已設定並平滑重載自編 PHP-FPM：$CUSTOM_CONFIG"
}

configure_nginx_site() {
    local site="$1" temporary previous block count
    site="$(realpath "$site")"
    [[ -f "$site" ]] || { echo '找不到指定的 Nginx 網站設定。' >&2; return 1; }
    count="$(grep -cE '^[[:space:]]*location /koilisu/[[:space:]]*\{' "$site" || true)"
    [[ "$count" == 1 ]] || { echo '網站設定須有唯一的 location /koilisu/，未變更 Nginx。' >&2; return 1; }
    temporary="$(mktemp "$(dirname "$site")/.khaifile.XXXXXX")"
    previous="$(mktemp)"
    block="$(mktemp)"
    cp -p "$site" "$previous"
    [[ -e "$site.khaifile-backup" ]] || cp -p "$site" "$site.khaifile-backup"
    cat > "$block" <<'NGINX'
    # BEGIN KhaiFile managed
    location ~ ^/koilisu/(apps/)?khaifile/((api|tools|tests|partials)(/|$)|(config(\.local)?|view)\.php$) {
        return 404;
    }
    # END KhaiFile managed
NGINX
    awk 'NR==FNR {block=block $0 "\n";next}
        /# BEGIN KhaiFile managed/ {skip=1;next}
        /# END KhaiFile managed/ {skip=0;next}
        !skip {if ($0 ~ /^[[:space:]]*location \/koilisu\/[[:space:]]*\{/) printf "%s",block;print}' "$block" "$site" > "$temporary"
    rm -f "$block"
    chmod --reference="$site" "$temporary"
    chown --reference="$site" "$temporary"
    if cmp -s "$temporary" "$site"; then rm -f "$temporary"; else mv "$temporary" "$site"; fi
    if ! nginx -t; then
        cp -p "$previous" "$site"
        rm -f "$previous"
        echo 'Nginx 設定檢查失敗，已還原，未重載。' >&2
        return 1
    fi
    rm -f "$previous"
    systemctl reload nginx
    echo '  ✓ 已阻擋 KhaiFile 私有路徑，保留其他網站規則'
}

setup_main() {
    [[ "$(id -u)" == 0 ]] || { echo '主機設定需要 root／sudo。' >&2; return 1; }
    cd "$(dirname "${BASH_SOURCE[0]}")/.."
    local project="$PWD" version directory binary user temp_dir group cron_id local_file
    [[ -f /etc/debian_version ]] || { echo '自動設定支援 Debian／Ubuntu；其他系統請備妥環境後設 DEPLOY_SETUP_SYSTEM=0。' >&2; return 1; }
    local -a packages=() fpm_dirs=() pool_files=()
    discover_custom_fpm
    version="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
    if [[ -n "$CUSTOM_PID" ]]; then
        php -r 'exit(extension_loaded("zip")&&extension_loaded("mbstring")?0:1);' || {
            echo '自編 PHP CLI 缺少 zip／mbstring；不使用 apt 替換自編 PHP，請載入既有擴充。' >&2; return 1;
        }
    else
        php -r 'exit(extension_loaded("zip")?0:1);' || packages+=("php$version-zip")
        php -r 'exit(extension_loaded("mbstring")?0:1);' || packages+=("php$version-mbstring")
    fi
    local office_package
    for office_package in libreoffice-writer libreoffice-calc libreoffice-impress; do
        dpkg-query -W -f='${Status}' "$office_package" 2>/dev/null | grep -q 'install ok installed' || packages+=("$office_package")
    done
    command -v gs >/dev/null || packages+=(ghostscript)
    command -v timeout >/dev/null || packages+=(coreutils)
    command -v curl >/dev/null || packages+=(curl)
    command -v fc-match >/dev/null || packages+=(fontconfig)
    dpkg-query -W -f='${Status}' fonts-noto-cjk 2>/dev/null | grep -q 'install ok installed' || packages+=(fonts-noto-cjk)
    command -v cron >/dev/null || packages+=(cron)
    for directory in /etc/php/*/fpm; do
        [[ -z "$CUSTOM_PID" ]] || break
        [[ -d "$directory/conf.d" && -f "$directory/php.ini" ]] || continue
        version="$(basename "$(dirname "$directory")")"
        # 只修改正在使用的 FPM 版本，保留其他版本設定。
        systemctl is-active --quiet "php$version-fpm" || continue
        if ! supported_php_version "$version"; then
            echo "  ! 保留 php$version-fpm：KhaiFile 需要 PHP-FPM 8.2+，不修改舊版服務"
            continue
        fi
        fpm_dirs+=("$directory")
        binary="/usr/bin/php$version"
        [[ -x "$binary" ]] || packages+=("php$version-cli")
        dpkg-query -W -f='${Status}' "php$version-zip" 2>/dev/null | grep -q 'install ok installed' || packages+=("php$version-zip")
        dpkg-query -W -f='${Status}' "php$version-mbstring" 2>/dev/null | grep -q 'install ok installed' || packages+=("php$version-mbstring")
    done
    [[ ${#fpm_dirs[@]} -gt 0 || -n "$CUSTOM_PID" ]] || { echo '找不到啟動中的 PHP-FPM 8.2+；未變更系統。請確認此網站的 FPM 版本，PHP CLI 版本不代表網站版本。' >&2; return 1; }
    user="${DEPLOY_PHP_USER:-}"
    if [[ -z "$user" ]]; then
        if [[ -n "$CUSTOM_PID" ]]; then
            user="$("$CUSTOM_BIN" -tt -y "$CUSTOM_CONFIG" 2>&1 | pool_users)"
            if [[ -z "$user" ]]; then
                user="$(ps --ppid "$CUSTOM_PID" -o user=,args= | awk '/php-fpm: pool/ {print $1}' | sort -u)"
            fi
        else
        shopt -s nullglob
        for directory in "${fpm_dirs[@]}"; do pool_files+=("$directory"/pool.d/*.conf); done
        [[ ${#pool_files[@]} -gt 0 ]] || { echo '找不到 PHP pool 設定。' >&2; return 1; }
        user="$(awk -F= '/^[[:space:]]*user[[:space:]]*=/ {sub(/;.*/,"",$2);gsub(/[[:space:]]/,"",$2);print $2}' "${pool_files[@]}" | sort -u)"
        fi
    fi
    [[ "$user" =~ ^[a-z_][a-z0-9_-]*\$?$ && "$user" != root ]] && id "$user" >/dev/null 2>&1 || {
        echo 'PHP pool 有不同執行帳號或無法辨識；請設 DEPLOY_PHP_USER 為此網站的 PHP 帳號。' >&2; return 1;
    }
    group="$(id -gn "$user")"
    if [[ ${#packages[@]} -gt 0 ]]; then
        echo "  安裝缺少的套件：${packages[*]}"
        apt-get update
        DEBIAN_FRONTEND=noninteractive apt-get install -y "${packages[@]}"
    else echo '  ✓ 所需套件已安裝'; fi
    # 不使用 /tmp：FPM 的 PrivateTmp 可能讓系統清理排程看不到同一目錄。
    cron_id="$(printf '%s' "$project" | sha256sum | cut -c1-16)"
    temp_dir="${KHAIFILE_TEMP_DIR:-/var/lib/khaifile/$cron_id}"
    [[ "$temp_dir" == /* && "$temp_dir" != *$'\n'* && "$temp_dir" != *'%'* && "$project" != *$'\n'* && "$project" != *'%'* ]] || { echo '路徑必須為絕對路徑且不含換行／百分號。' >&2; return 1; }
    temp_dir="$(realpath -m "$temp_dir")"
    [[ "$temp_dir" != "$project" && "$temp_dir" != "$project/"* && "$temp_dir" != / ]] || { echo '暫存必須在網站之外。' >&2; return 1; }
    if [[ -e "$temp_dir" ]]; then
        [[ ! -L "$temp_dir" && -d "$temp_dir" && "$(stat -c %U "$temp_dir")" == "$user" ]] || { echo '暫存目錄身分不符，保留原檔並停止。' >&2; return 1; }
    else
        install -d -o "$user" -g "$group" -m 700 "$temp_dir"
    fi
    local_file="$project/config.local.php"
    local office_bin gs_bin
    office_bin="$(command -v "${KHAIFILE_OFFICE_BIN:-soffice}")"
    gs_bin="$(command -v "${KHAIFILE_GS_BIN:-gs}")"
    php -r 'echo "<?php // KhaiFile managed\nreturn ".var_export(["temp_dir"=>$argv[1],"office_bin"=>$argv[2],"gs_bin"=>$argv[3]],true).";\n";' "$temp_dir" "$office_bin" "$gs_bin" | write_managed "$local_file" 644
    for directory in "${fpm_dirs[@]}"; do
        version="$(basename "$(dirname "$directory")")"
        configure_fpm "$directory" "/usr/bin/php$version"
    done
    if [[ -n "$CUSTOM_PID" ]]; then configure_custom_fpm; fi
    if [[ -n "${DEPLOY_NGINX_SITE:-}" ]]; then configure_nginx_site "$DEPLOY_NGINX_SITE"; fi
    install -d -m 755 /etc/cron.d
    # cron 使用 POSIX shell；單引號與百分號必須正確處理。
    local quoted_project quoted_temp php_path
    quoted_project="${project//\'/\'\\\'\'}"
    quoted_temp="${temp_dir//\'/\'\\\'\'}"
    php_path="$(command -v php)"
    [[ "$php_path" =~ ^/[a-zA-Z0-9_./-]+$ ]] || { echo 'PHP CLI 路徑不支援排程。' >&2; return 1; }
    printf '# KhaiFile managed\n*/5 * * * * %s KHAIFILE_TEMP_DIR=\x27%s\x27 %s \x27%s/tools/cleanup.php\x27\n' "$user" "$quoted_temp" "$php_path" "$quoted_project" |
        write_managed "/etc/cron.d/khaifile-$cron_id" 644
    systemctl enable --now cron
    runuser -u "$user" -- env KHAIFILE_TEMP_DIR="$temp_dir" php tools/deps.php
    echo "  ✓ 已設定共用暫存與每五分鐘清理（$user），重複部署不新增重複排程"
}

if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then setup_main "$@"; fi
