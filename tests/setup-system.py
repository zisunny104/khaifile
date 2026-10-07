"""Exercise host configuration with temporary ini files and a mocked service manager."""
from pathlib import Path
import os
import shutil
import subprocess
import tempfile

ROOT = Path(__file__).resolve().parents[1]
php = Path(shutil.which('php')).resolve()
# Cloud onboarding's wrapper resets scan paths; use its real binary for ini fixtures.
if Path('/workspace/.onboarding/php/usr/bin/php8.4').exists():
    php = Path('/workspace/.onboarding/php/usr/bin/php8.4')
passed = 0


def check(ok, label):
    global passed
    assert ok, label
    passed += 1
    print('PASS', label, flush=True)


with tempfile.TemporaryDirectory(prefix='khaifile-system-') as temporary:
    base = Path(temporary)
    fpm = base / '8.4' / 'fpm'
    (fpm / 'conf.d').mkdir(parents=True)
    stub = base / 'fpm-stub'
    stub.write_text('#!/bin/sh\nexit 0\n')
    stub.chmod(0o755)

    def execute(body, *args):
        return subprocess.run(['bash', '-c', 'source "$1/tools/setup-system.sh"\nshift\n' + body,
                               'test', str(ROOT), *map(str, args)],
                              text=True, stdout=subprocess.PIPE, stderr=subprocess.STDOUT)

    # Do not use systemctl: log the requested command instead.
    def configure():
        return execute('log="$4"\nsystemctl() { echo "$*" >> "$log"; }\nconfigure_fpm "$1" "$2" "$3"',
                       fpm, php, stub, base / 'reloads')

    result = execute('! supported_php_version 7.4 && ! supported_php_version 8.1 && supported_php_version 8.2 && supported_php_version 8.4')
    check(result.returncode == 0, 'Legacy FPM versions are excluded before host changes')
    result = execute('printf "NOTICE: \\tuser = www-data\\nNOTICE: [www] user = www-data\\nNOTICE: listen.owner = root\\n" | pool_users')
    check(result.returncode == 0 and result.stdout.strip() == 'www-data',
          'Custom FPM pool account accepts tabs and pool prefixes without confusing socket owner')

    ini = fpm / 'php.ini'
    ini.write_text('upload_max_filesize=2M\npost_max_size=8M\nmax_execution_time=30\n')
    result = configure()
    managed = fpm / 'conf.d' / '99-khaifile.ini'
    check(result.returncode == 0 and 'upload_max_filesize = 52428800' in managed.read_text()
          and 'post_max_size = 54525952' in managed.read_text()
          and 'max_execution_time = 300' in managed.read_text(), 'Small limits raised to required minimums')
    check((base / 'reloads').read_text().strip() == 'reload php8.4-fpm', 'Only the configured FPM service is reloaded')
    before = managed.stat().st_mtime_ns
    result = configure()
    check(result.returncode == 0 and managed.stat().st_mtime_ns == before, 'Repeated setup leaves identical ini unchanged')
    managed.unlink()
    ini.write_text('upload_max_filesize=200M\npost_max_size=256M\nmax_execution_time=600\n')
    result = configure()
    check(result.returncode == 0 and '200M' in managed.read_text() and '256M' in managed.read_text()
          and '600' in managed.read_text(), 'Larger existing limits are preserved')
    managed.unlink()
    ini.write_text('upload_max_filesize=50M\npost_max_size=0\nmax_execution_time=0\n')
    result = configure()
    check(result.returncode == 0 and 'post_max_size = 0' in managed.read_text()
          and 'max_execution_time = 0' in managed.read_text(), 'Unlimited request size and execution time remain unlimited')
    managed.write_text('; administrator config\nupload_max_filesize=100M\n')
    before = managed.read_bytes()
    result = configure()
    check(result.returncode != 0 and managed.read_bytes() == before, 'Unmanaged administrator config is never overwritten')
    cron = base / 'cron'
    body = 'printf "# KhaiFile managed\\n*/5 * * * * www-data php cleanup.php\\n" | write_managed "$1" 644'
    result = execute(body, cron)
    before = cron.stat().st_mtime_ns
    result = execute(body, cron)
    check(result.returncode == 0 and cron.stat().st_mtime_ns == before
          and cron.read_text().count('*/5') == 1, 'Repeated managed cron installation does not duplicate jobs')
    link = base / 'link'
    link.symlink_to(cron)
    result = execute('echo overwritten | write_managed "$1" 644', link)
    check(result.returncode != 0 and cron.read_text().startswith('# KhaiFile managed'), 'Symlink destinations cannot overwrite other files')

    custom_ini = base / 'custom.ini'
    custom_ini.write_text('; existing custom PHP config\nupload_max_filesize=200M\n')
    custom_fpm = base / 'custom-fpm'
    custom_fpm.write_text('#!/bin/sh\nif [ "$1" = -i ]; then\ncat <<EOF\n'
                          f'Loaded Configuration File => {custom_ini}\n'
                          'Scan this dir for additional .ini files => (none)\n'
                          'upload_max_filesize => 200M => 200M\n'
                          'post_max_size => 8M => 8M\n'
                          'max_execution_time => 30 => 30\nEOF\n'
                          'else exit "${TEST_FPM_EXIT:-0}"; fi\n')
    custom_fpm.chmod(0o755)
    body = '''CUSTOM_BIN="$1"; CUSTOM_CONFIG="$2"; CUSTOM_PID=123
readlink() { echo "$CUSTOM_BIN"; }
kill() { echo "$*" >> "$2"; }
configure_custom_fpm'''
    # Capture the reload signal without signaling any real process.
    body = body.replace('kill() { echo "$*" >> "$2"; }', 'kill() { echo "$*" > "${CUSTOM_CONFIG}.signal"; }')
    result = execute(body, custom_fpm, base / 'php-fpm83.conf')
    contents = custom_ini.read_text()
    check(result.returncode == 0 and 'upload_max_filesize = 200M' in contents
          and 'post_max_size = 52M' in contents and 'max_execution_time = 300' in contents
          and (base / 'php-fpm83.conf.signal').read_text().strip() == '-USR2 123',
          'Custom FPM updates actual ini and gracefully reloads only its master')
    before = custom_ini.stat().st_mtime_ns
    result = execute(body, custom_fpm, base / 'php-fpm83.conf')
    check(result.returncode == 0 and custom_ini.stat().st_mtime_ns == before
          and custom_ini.read_text().count('; BEGIN KhaiFile managed') == 1,
          'Custom ini setup is repeatable and preserves existing content')
    custom_ini.write_text('; operator changed current settings\nupload_max_filesize=300M\n')
    before = custom_ini.read_bytes()
    result = execute('export TEST_FPM_EXIT=1\n' + body, custom_fpm, base / 'php-fpm83.conf')
    check(result.returncode != 0 and custom_ini.read_bytes() == before,
          'Failed custom FPM validation restores settings from this run')
    site = base / 'nginx-site'
    original = 'server {\n    location /koilisu/ {\n        try_files $uri /koilisu/index.php$is_args$args;\n    }\n    location ~ \\.php$ { }\n}\n'
    site.write_text(original)
    body = 'nginx() { return "${TEST_NGINX_EXIT:-0}"; }\nsystemctl() { :; }\nconfigure_nginx_site "$1"'
    result = execute(body, site)
    check(result.returncode == 0 and 'return 404;' in site.read_text()
          and site.read_text().index('# BEGIN KhaiFile') < site.read_text().index('location /koilisu/')
          and 'try_files $uri /koilisu/index.php$is_args$args;' in site.read_text(),
          'Nginx protection is scoped to KhaiFile and preserves the existing route')
    before = site.stat().st_mtime_ns
    result = execute(body, site)
    check(result.returncode == 0 and site.stat().st_mtime_ns == before, 'Nginx protection does not duplicate on repeated deployment')
    site.write_text(original)
    result = execute('export TEST_NGINX_EXIT=1\n' + body, site)
    check(result.returncode != 0 and site.read_text() == original, 'Invalid Nginx configuration is restored without reload')

    result = execute('render_nginx_ratelimit')
    import re
    pattern = re.search(r'"~([^"\n]+)" \$binary_remote_addr', result.stdout).group(1)
    check('$request_uri' in result.stdout and re.search(pattern, '1:/koilisu/khaifile?api=process') is not None,
          'Rate limit uses original request URI and covers the public tool entry')
    check(re.search(pattern, '1:/koilisu/apps/khaifile/index.php?api=archive') is not None
          and re.search(pattern, '1:/koilisu/printan?api=process') is None,
          'Rate limit covers direct entry without affecting other projects')

print(f'PASS {passed} system configuration checks (no real system changes)')
