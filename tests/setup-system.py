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

print(f'PASS {passed} system configuration checks (no real system changes)')
