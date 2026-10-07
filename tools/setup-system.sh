#!/usr/bin/env bash
# KhaiFile 的 Debian／Ubuntu 主機設定；由 deploy.sh 呼叫。
set -euo pipefail

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
        function size($s) { $s=trim($s); $n=(float)$s; return $n * match(strtolower(substr($s,-1))) {"g"=>1073741824,"m"=>1048576,"k"=>1024,default=>1}; }
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

setup_main() {
    [[ "$(id -u)" == 0 ]] || { echo '主機設定需要 root／sudo。' >&2; return 1; }
    cd "$(dirname "${BASH_SOURCE[0]}")/.."
    local project="$PWD" version directory binary user temp_dir group cron_id local_file
    [[ -f /etc/debian_version ]] || { echo '自動設定支援 Debian／Ubuntu；其他系統請備妥環境後設 DEPLOY_SETUP_SYSTEM=0。' >&2; return 1; }
    local -a packages=() fpm_dirs=() pool_files=()
    version="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
    php -r 'exit(extension_loaded("zip")?0:1);' || packages+=("php$version-zip")
    php -r 'exit(extension_loaded("mbstring")?0:1);' || packages+=("php$version-mbstring")
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
        [[ -d "$directory/conf.d" && -f "$directory/php.ini" ]] || continue
        version="$(basename "$(dirname "$directory")")"
        # 只修改正在使用的 FPM 版本，保留其他版本設定。
        systemctl is-active --quiet "php$version-fpm" || continue
        fpm_dirs+=("$directory")
        binary="/usr/bin/php$version"
        [[ -x "$binary" ]] || packages+=("php$version-cli")
        dpkg-query -W -f='${Status}' "php$version-zip" 2>/dev/null | grep -q 'install ok installed' || packages+=("php$version-zip")
        dpkg-query -W -f='${Status}' "php$version-mbstring" 2>/dev/null | grep -q 'install ok installed' || packages+=("php$version-mbstring")
    done
    [[ ${#fpm_dirs[@]} -gt 0 ]] || { echo '沒有啟動中的 PHP-FPM；未變更系統。其他 PHP 服務請設定 DEPLOY_SETUP_SYSTEM=0。' >&2; return 1; }
    user="${DEPLOY_PHP_USER:-}"
    if [[ -z "$user" ]]; then
        shopt -s nullglob
        for directory in "${fpm_dirs[@]}"; do pool_files+=("$directory"/pool.d/*.conf); done
        [[ ${#pool_files[@]} -gt 0 ]] || { echo '找不到 PHP pool 設定。' >&2; return 1; }
        user="$(awk -F= '/^[[:space:]]*user[[:space:]]*=/ {sub(/;.*/,"",$2);gsub(/[[:space:]]/,"",$2);print $2}' "${pool_files[@]}" | sort -u)"
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
    php -r 'echo "<?php // KhaiFile managed\nreturn [\x27temp_dir\x27 => ".var_export($argv[1],true)."];\n";' "$temp_dir" | write_managed "$local_file" 644
    for directory in "${fpm_dirs[@]}"; do
        version="$(basename "$(dirname "$directory")")"
        configure_fpm "$directory" "/usr/bin/php$version"
    done
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
