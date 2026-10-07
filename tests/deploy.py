"""Test deploy.sh against disposable Git remotes and local HTTP servers."""
from pathlib import Path
import os
import shutil
import socket
import subprocess
import tempfile
import time
import urllib.request

ROOT = Path(__file__).resolve().parents[1]
passed = 0


def run(args, cwd, env=None):
    return subprocess.run(args, cwd=cwd, env=env, text=True, stdout=subprocess.PIPE, stderr=subprocess.STDOUT)


def git(cwd, *args):
    result = run(['git', *args], cwd)
    if result.returncode:
        raise RuntimeError(result.stdout)
    return result.stdout.strip()


def check(condition, label):
    global passed
    assert condition, label
    passed += 1
    print('PASS', label, flush=True)


with tempfile.TemporaryDirectory(prefix='khaifile-deploy-') as temp:
    directory = Path(temp)
    source = directory / 'source'
    shutil.copytree(ROOT, source, ignore=shutil.ignore_patterns('.git', 'artifacts', '__pycache__', '.deploy_check_url'))
    git(source, 'init', '-b', 'main')
    git(source, 'config', 'user.name', 'KhaiFile test')
    git(source, 'config', 'user.email', 'test@localhost')
    git(source, 'config', 'commit.gpgsign', 'false')
    git(source, 'add', '.')
    git(source, 'commit', '-m', 'Test baseline')
    remote = directory / 'remote.git'
    remote.mkdir()
    git(remote, 'init', '--bare', '-b', 'main')
    git(source, 'remote', 'add', 'origin', str(remote))
    git(source, 'push', 'origin', 'main')
    checkout = directory / 'checkout'
    git(directory, 'clone', str(remote), str(checkout))
    environment = os.environ.copy()
    environment['DEPLOY_SETUP_SYSTEM'] = '0'
    environment.pop('DEPLOY_CHECK_URL', None)
    result = run(['bash', 'deploy.sh', '--check-deps'], checkout, environment)
    check(result.returncode == 0, 'Runtime dependencies pass in current environment')
    missing = environment.copy()
    missing['KHAIFILE_GS_BIN'] = '/nonexistent/gs'
    result = run(['bash', 'deploy.sh', '--check-deps'], checkout, missing)
    check(result.returncode != 0 and 'Ghostscript' in result.stdout, 'Missing dependency stops deployment')
    result = run(['bash', 'deploy.sh', '--set-check-url', 'https://user@example.invalid'], checkout, environment)
    check(result.returncode != 0 and not (checkout / '.deploy_check_url').exists(), 'Credential-bearing check URL rejected')
    (source / 'future.php').write_text('<?php return 42;\n')
    git(source, 'add', 'future.php')
    git(source, 'commit', '-m', 'Valid remote update')
    git(source, 'push', 'origin', 'main')
    result = run(['bash', 'deploy.sh'], checkout, environment)
    check(result.returncode == 0 and git(checkout, 'rev-parse', 'HEAD') == git(source, 'rev-parse', 'HEAD'), 'Valid update fast-forwards')
    git(checkout, 'checkout', '--detach')
    result = run(['bash', 'deploy.sh'], checkout, environment)
    check(result.returncode == 0, 'Submodule-style detached HEAD deployment is supported')
    git(checkout, 'checkout', 'main')
    setup = checkout / 'tools' / 'setup-system.sh'
    setup.write_text('exit 99\n')
    result = run(['bash', 'deploy.sh', '--check-only'], checkout, environment)
    check(result.returncode == 0, 'Check-only never invokes system setup')
    git(checkout, 'restore', '--worktree', 'tools/setup-system.sh')
    before = git(checkout, 'rev-parse', 'HEAD')
    (source / 'broken.php').write_text('<?php broken {\n')
    git(source, 'add', 'broken.php')
    git(source, 'commit', '-m', 'Invalid remote syntax')
    git(source, 'push', 'origin', 'main')
    result = run(['bash', 'deploy.sh'], checkout, environment)
    check(result.returncode != 0 and git(checkout, 'rev-parse', 'HEAD') == before, 'Remote PHP syntax failure leaves checkout unchanged')
    with (checkout / 'README.md').open('a') as output:
        output.write('\nLocal edit\n')
    result = run(['bash', 'deploy.sh'], checkout, environment)
    check(result.returncode != 0 and '尚未提交' in result.stdout, 'Dirty working tree is preserved')
    git(checkout, 'restore', '--worktree', 'README.md')
    git(checkout, 'config', 'user.name', 'KhaiFile test')
    git(checkout, 'config', 'user.email', 'test@localhost')
    git(checkout, 'config', 'commit.gpgsign', 'false')
    (checkout / 'local.txt').write_text('local commit')
    git(checkout, 'add', 'local.txt')
    git(checkout, 'commit', '-m', 'Local-only commit')
    result = run(['bash', 'deploy.sh'], checkout, environment)
    check(result.returncode != 0 and '分歧' in result.stdout, 'Divergent branch is not reset or merged')
    router = directory / 'router.php'
    router.write_text('<?php $p=parse_url($_SERVER["REQUEST_URI"],PHP_URL_PATH); if ($p==="/config.php") {echo "exposed";} elseif(str_contains($p,".git") || str_contains($p,"api/") || str_contains($p,"tools/")) {http_response_code(403);} else {echo \'KhaiFile <link href="/vendor/tocas/tocas.min.css">\';}')
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0))
        port = sock.getsockname()[1]
    process = subprocess.Popen(['php', '-S', f'127.0.0.1:{port}', '-t', str(directory), str(router)], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    try:
        for _ in range(60):
            try:
                urllib.request.urlopen(f'http://127.0.0.1:{port}/', timeout=1).close()
                break
            except OSError:
                time.sleep(.05)
        web_environment = environment.copy()
        web_environment['DEPLOY_CHECK_URL'] = f'http://127.0.0.1:{port}'
        result = run(['bash', 'deploy.sh', '--check-only'], checkout, web_environment)
        check(result.returncode != 0 and 'config.php' in result.stdout, 'Website check rejects exposed configuration')
    finally:
        process.terminate()
        process.wait(timeout=5)
print(f'PASS {passed} deployment checks')
