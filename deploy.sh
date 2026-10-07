#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")"

step() { printf '\n==> %s\n' "$1"; }
fail() { printf '錯誤：%s\n' "$1" >&2; exit 1; }
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
            [[ $# -ge 2 ]] || fail '請提供網站網址。'
            command -v php >/dev/null || fail '需要 PHP CLI。'
            valid_url "$2" || fail '請提供不含帳密、查詢參數的 http(s) 網站網址。'
            printf '%s\n' "${2%/}" > "$url_file"
            chmod 600 "$url_file"
            printf '已儲存網站檢查網址。\n'
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
不會自動安裝套件、變更權限、排程清理或啟動公開服務。
HELP
            exit 0 ;;
        *) fail "未知參數：$1" ;;
    esac
    shift
done

step '檢查執行期依賴'
command -v php >/dev/null || fail '需要 PHP CLI 8.2+。'
php tools/deps.php
[[ "$deps_only" -eq 0 ]] || exit 0

selfcheck() {
    local base html code prefix origin asset_base target probe
    base="${DEPLOY_CHECK_URL:-}"
    if [[ -z "$base" && -f "$url_file" ]]; then IFS= read -r base < "$url_file" || true; fi
    if [[ -z "$base" ]]; then
        printf '未設定檢查網址：未驗證網站可用性。請使用 --set-check-url URL。\n'
        return 0
    fi
    valid_url "$base" || fail '檢查網址無效。'
    base="${base%/}"
    command -v curl >/dev/null || fail '網站檢查需要 curl。'
    html="$(mktemp)"
    code="$(curl --silent --show-error --max-time 15 --max-redirs 0 --output "$html" --write-out '%{http_code}' "$base/")" || { rm -f "$html"; fail '無法連線至網站。'; }
    if [[ "$code" != 200 ]] || ! rg_or_grep 'KhaiFile' "$html"; then rm -f "$html"; fail "網站未回傳 KhaiFile 頁面（HTTP $code）。"; fi
    prefix="$(sed -n 's/.*href="\([^"]*\)\/vendor\/tocas\/tocas.min.css".*/\1/p' "$html" | head -n 1)"
    rm -f "$html"
    origin="$(php -r '$p=parse_url($argv[1]); echo $p["scheme"]."://".$p["host"].(isset($p["port"])?":".$p["port"]:"");' "$base")"
    [[ -z "$prefix" || "$prefix" == /* ]] || fail '無法判斷第一方資源路徑。'
    asset_base="$origin$prefix"
    for target in "$base" "$asset_base"; do
        for probe in '.git/HEAD' 'config.php' 'api/lib.php' 'tools/cleanup.php'; do
            code="$(curl --silent --show-error --max-time 8 --max-redirs 0 --output /dev/null --write-out '%{http_code}' "$target/$probe")" || fail "無法檢查 $probe。"
            [[ "$code" == 403 || "$code" == 404 ]] || fail "$probe 未確認阻擋（HTTP $code）。請設定網站拒絕規則。"
        done
    done
    code="$(curl --silent --show-error --max-time 8 --output /dev/null --write-out '%{http_code}' "$asset_base/vendor/tocas/tocas.min.css")" || fail 'Tocas UI 資源無法連線。'
    [[ "$code" == 200 ]] || fail 'Tocas UI 資源未正確供應。'
    printf '網站、第一方 Tocas UI 與內部路徑拒絕檢查通過。\n'
}

rg_or_grep() {
    if command -v rg >/dev/null; then rg --quiet "$1" "$2"; else grep -q "$1" "$2"; fi
}

if [[ "$check_only" -eq 1 ]]; then
    step '檢查目前程式語法'
    php tools/check.php
    step '網站檢查'
    selfcheck
    exit 0
fi

step '檢查 Git 工作目錄'
command -v git >/dev/null || fail '需要 Git。'
git rev-parse --verify HEAD >/dev/null 2>&1 || fail '尚無提交，請先建立初始 commit。'
[[ -z "$(git status --porcelain --untracked-files=no)" ]] || fail '有尚未提交的修改，已停止部署。'
branch="${DEPLOY_BRANCH:-main}"
git check-ref-format "refs/heads/$branch" || fail 'DEPLOY_BRANCH 無效。'
[[ "$(git symbolic-ref --quiet --short HEAD)" == "$branch" ]] || fail "請先切換到 $branch 分支。"

step '取得遠端更新'
git fetch origin "refs/heads/$branch"
target="$(git rev-parse --verify 'FETCH_HEAD^{commit}')"
git merge-base --is-ancestor HEAD "$target" || fail '本機與遠端已分歧，不能 fast-forward。'

step '驗證遠端 PHP 語法'
syntax_dir="$(mktemp -d)"
trap 'rm -rf -- "$syntax_dir"' EXIT
while IFS= read -r -d '' path; do
    if [[ "$path" == *.php ]]; then
        git show "$target:$path" > "$syntax_dir/check.php"
        php -l "$syntax_dir/check.php" >/dev/null || fail "遠端 PHP 語法錯誤：$path"
    fi
done < <(git ls-tree -r -z --name-only "$target")

step 'Fast-forward 更新'
git merge --ff-only "$target"
php tools/deps.php
php tools/check.php
if [[ -n "${DEPLOY_RELOAD_CMD:-}" ]]; then
    step '執行設定的服務重載指令'
    bash -lc "$DEPLOY_RELOAD_CMD"
fi
step '網站檢查'
selfcheck
printf '程式更新完成。請確認 PHP-FPM 上傳限制與定期清理排程已設定。\n'
