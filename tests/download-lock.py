"""Verify a slow download does not hold its session's directory lock."""
import fcntl
from pathlib import Path
import shutil
import subprocess
import tempfile

ROOT = Path(__file__).resolve().parents[1]
with tempfile.TemporaryDirectory(prefix='khaifile-download-') as temp:
    directory = Path(temp)
    payload = b'download-fixture-' * 65536
    (directory / 'source.pdf').write_bytes(payload)
    code = '''require $argv[1];
$ownerLock=fopen($argv[2].'/.lock','c');flock($ownerLock,LOCK_EX);
kf_send($argv[2].'/source.pdf','fixture.pdf');'''
    process = subprocess.Popen([shutil.which('php'), '-r', code, str(ROOT / 'api/lib.php'), str(directory)],
                               stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    try:
        first = process.stdout.read(1)
        assert first == payload[:1], 'Download must start'
        assert process.poll() is None, 'Pipe must still be holding a slow download'
        with (directory / '.lock').open('a') as lock:
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
            print('PASS Slow download releases session lock before streaming', flush=True)
            # Unlink the open source while streaming; it must remain downloadable.
            (directory / 'source.pdf').unlink()
        remaining = process.stdout.read()
        errors = process.stderr.read()
        process.wait(timeout=10)
        assert process.returncode == 0 and not errors and first + remaining == payload
        print('PASS Deletion does not interrupt an already opened download', flush=True)
    finally:
        if process.poll() is None:
            process.kill()
            process.wait()
