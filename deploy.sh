#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")"

# 與其他開利手專案共用部署輸出慣例。
source tools/deploy-output.sh

abort() { fail "$1"; exit 1; }
check_local_syntax() {
    local logfile status=0 kind message
    logfile="$(mktemp)"
    php tools/check.php --summary > "$logfile" 2>&1 || status=$?
    while IFS=$'\t' read -r kind message; do
        case "$kind" in
            pass) ok "$message" ;;
            skip) warn "$message" ;;
            fail) fail "$message" ;;
            detail) printf '    %s\n' "$message" ;;
            *) printf '    %s\n' "$kind${message:+ $message}" ;;
        esac
    done < "$logfile"
    rm -f "$logfile"
    [[ "$status" -eq 0 ]] || exit "$status"
}
run_logged() {
    local label="$1" logfile status
    shift
    logfile="$(mktemp)"
    if "$@" > "$logfile" 2>&1; then
        rm -f "$logfile"
        ok "$label"
    else
        status=$?
        fail "$label"
        sed 's/^/    /' "$logfile" >&2
        rm -f "$logfile"
        exit "$status"
    fi
}
url_file=.deploy_check_url
check_only=0
deps_only=0

valid_url() {
    php -r '$u=parse_url($argv[1]); exit(is_array($u) && in_array($u["scheme"]??"",["https","http"],true) && !empty($u["host"]) && !isset($u["user"]) && !isset($u["pass"]) && !isset($u["query"]) && !isset($u["fragment"]) ? 0 : 1);' "$1"
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --check-only) check_only=1 ;;
        --check-deps) deps_only=1 ;;
        --set-check-url)
            [[ $# -ge 2 ]] || abort '請提供網站網址。'
            command -v php >/dev/null || abort '需要 PHP CLI。'
            valid_url "$2" || abort '請提供不含帳密、查詢參數的 http(s) 網站網址。'
            printf '%s\n' "${2%/}" > "$url_file"
            chmod 600 "$url_file"
            ok '已儲存網站檢查網址'
            exit 0 ;;
        -h|--help)
            cat <<'HELP'
用法：./deploy.sh [--check-only | --check-deps | --set-check-url URL]
預設：檢查依賴與乾淨工作目錄，fetch、驗證遠端 PHP，fast-forward 更新，再檢查網站。
--check-only    檢查依賴、目前程式語法與網站，不更新 Git。
--check-deps    只檢查伺服器依賴與暫存目錄。
--set-check-url 儲存網站網址；DEPLOY_CHECK_URL 可覆蓋。
環境變數：DEPLOY_BRANCH（main）、DEPLOY_CHECK_URL、DEPLOY_RELOAD_CMD。
工具路徑：KHAIFILE_OFFICE_BIN、KHAIFILE_GS_BIN、KHAIFILE_TEMP_DIR。
正常部署會補齊 Debian／Ubuntu 依賴、PHP-FPM 限制與清理排程（需要 root／sudo）。
DEPLOY_SETUP_SYSTEM=0 可略過；DEPLOY_PHP_USER 可指定網站 PHP 帳號。
會偵測 /opt 下運行中的自編 FPM；多個版本可用 DEPLOY_FPM_CONFIG 指定。
DEPLOY_NGINX_SITE 可指定既有開利手網站設定，加入 KhaiFile 私有路徑拒絕規則與限速。
--check-only／--check-deps 不修改系統。不會更新其他工具；未指定網站設定不修改 Nginx。
HELP
            exit 0 ;;
        *) abort "未知參數：$1" ;;
    esac
    shift
done

if [[ "$deps_only" -eq 1 ]]; then
    step '檢查執行期依賴'
    command -v php >/dev/null || abort '需要 PHP CLI 8.2+。'
    run_logged 'PHP、轉換工具與暫存目錄正常' php tools/deps.php
    exit 0
fi

selfcheck() {
    local base html code prefix origin asset_base target probe
    base="${DEPLOY_CHECK_URL:-}"
    if [[ -z "$base" && -f "$url_file" ]]; then IFS= read -r base < "$url_file" || true; fi
    if [[ -z "$base" ]]; then
        warn '未設定網址，網站尚未驗證（使用 --set-check-url URL）'
        return 0
    fi
    valid_url "$base" || abort '檢查網址無效。'
    base="${base%/}"
    command -v curl >/dev/null || abort '網站檢查需要 curl。'
    html="$(mktemp)"
    code="$(curl --silent --show-error --max-time 15 --max-redirs 0 --output "$html" --write-out '%{http_code}' "$base/")" || { rm -f "$html"; abort '無法連線至網站。'; }
    if [[ "$code" != 200 ]] || ! rg_or_grep 'KhaiFile' "$html"; then rm -f "$html"; abort "網站未回傳 KhaiFile 頁面（HTTP $code）。"; fi
    prefix="$(sed -n 's/.*href="\([^"]*\)\/vendor\/tocas\/tocas.min.css".*/\1/p' "$html" | head -n 1)"
    rm -f "$html"
    origin="$(php -r '$p=parse_url($argv[1]); echo $p["scheme"]."://".$p["host"].(isset($p["port"])?":".$p["port"]:"");' "$base")"
    [[ -z "$prefix" || "$prefix" == /* ]] || abort '無法判斷第一方資源路徑。'
    asset_base="$origin$prefix"
    for target in "$base" "$asset_base"; do
        for probe in '.git/HEAD' 'config.php' 'config.local.php' 'api/lib.php' 'tools/cleanup.php'; do
            code="$(curl --silent --show-error --max-time 8 --max-redirs 0 --output /dev/null --write-out '%{http_code}' "$target/$probe")" || abort "無法檢查 $probe。"
            [[ "$code" == 403 || "$code" == 404 ]] || abort "$probe 未確認阻擋（HTTP $code）。請設定網站拒絕規則。"
        done
    done
    code="$(curl --silent --show-error --max-time 8 --output /dev/null --write-out '%{http_code}' "$asset_base/vendor/tocas/tocas.min.css")" || abort 'Tocas UI 資源無法連線。'
    [[ "$code" == 200 ]] || abort 'Tocas UI 資源未正確供應。'
    ok '網站、資源與私有路徑保護正常'
}

rg_or_grep() {
    if command -v rg >/dev/null; then rg --quiet "$1" "$2"; else grep -q "$1" "$2"; fi
}

if [[ "$check_only" -eq 1 ]]; then
    command -v php >/dev/null || abort '需要 PHP CLI 8.2+。'
    step '檢查部署狀態'
    run_logged 'PHP、轉換工具與暫存目錄正常' php tools/deps.php
    check_local_syntax
    selfcheck
    exit 0
fi

step '檢查本機變更'
command -v git >/dev/null || abort '需要 Git。'
git rev-parse --verify HEAD >/dev/null 2>&1 || abort '尚無提交，請先建立初始 commit。'
[[ -z "$(git status --porcelain --untracked-files=no)" ]] || abort '有尚未提交的修改，部署已中止。'
branch="${DEPLOY_BRANCH:-main}"
git check-ref-format "refs/heads/$branch" || abort 'DEPLOY_BRANCH 無效。'
# 子模組由母專案初始化時通常是 detached HEAD；只允許向指定遠端分支快轉。
current_branch="$(git symbolic-ref --quiet --short HEAD || true)"
[[ -z "$current_branch" || "$current_branch" == "$branch" ]] || abort "請先切換到 $branch 分支。"
ok '沒有未提交的修改'
command -v php >/dev/null || abort '需要 PHP CLI 8.2+。'
php -r 'exit(PHP_VERSION_ID>=80200?0:1);' || abort '需要 PHP CLI 8.2+。'
ok "PHP CLI：$(php -r 'echo PHP_VERSION;')"

step '取得遠端版本'
run_logged '已取得遠端版本' git fetch origin "refs/heads/$branch"
target="$(git rev-parse --verify 'FETCH_HEAD^{commit}')"
before="$(git rev-parse --short HEAD)"
git merge-base --is-ancestor HEAD "$target" || abort '本機與遠端版本已分歧，無法快轉更新。'

syntax_dir="$(mktemp -d)"
php_count=0
trap 'rm -rf -- "$syntax_dir"' EXIT
while IFS= read -r -d '' path; do
    if [[ "$path" == *.php ]]; then
        php_count=$((php_count + 1))
        git show "$target:$path" > "$syntax_dir/check.php"
        php -l "$syntax_dir/check.php" >/dev/null || abort "遠端 PHP 語法錯誤：$path"
    fi
done < <(git ls-tree -r -z --name-only "$target")
ok "遠端 PHP（$(git rev-parse --short "$target")）：$php_count 檔通過（php -l）"
step '更新程式'

if [[ "$(git rev-parse HEAD)" == "$target" ]]; then
    ok "已是最新版本（$before）"
else
    run_logged "已更新 $before → $(git rev-parse --short "$target")" git merge --ff-only "$target"
fi
step '執行環境'
if [[ "${DEPLOY_SETUP_SYSTEM:-1}" == 1 ]]; then
    if [[ "$(id -u)" == 0 ]]; then
        run_logged 'PHP-FPM、轉換工具與清理排程已就緒' bash tools/setup-system.sh
    else
        command -v sudo >/dev/null || abort '首次系統設定需要 sudo；已備妥環境可設 DEPLOY_SETUP_SYSTEM=0。'
        run_logged 'PHP-FPM、轉換工具與清理排程已就緒' sudo env DEPLOY_PHP_USER="${DEPLOY_PHP_USER:-}" DEPLOY_FPM_CONFIG="${DEPLOY_FPM_CONFIG:-}" DEPLOY_NGINX_SITE="${DEPLOY_NGINX_SITE:-}" KHAIFILE_TEMP_DIR="${KHAIFILE_TEMP_DIR:-}" \
            bash "$PWD/tools/setup-system.sh"
    fi
else
    warn '已略過系統設定（DEPLOY_SETUP_SYSTEM=0）'
    run_logged 'PHP、轉換工具與暫存目錄正常' php tools/deps.php
fi
# 系統設定已用實際 PHP 身分檢查依賴；部署帳號不需取得私人暫存寫入權限。
check_local_syntax
if [[ -n "${DEPLOY_RELOAD_CMD:-}" ]]; then
    run_logged '服務已重載' bash -lc "$DEPLOY_RELOAD_CMD"
fi
step '網站檢查'
selfcheck
VERSION="$(php -r '$c=require "config.php"; echo $c["version"]??"?";')"
deployment_summary "$VERSION" 0
