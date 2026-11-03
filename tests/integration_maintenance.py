"""Actual Compose-volume backup/restore on an isolated CI runner; no Telegram calls."""
import importlib.util
from pathlib import Path
import subprocess
import tempfile

root = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('maintenance', root / 'scripts/maintenance.py')
maintenance = importlib.util.module_from_spec(spec); spec.loader.exec_module(maintenance)


def command(*args):
    return subprocess.check_output(args, cwd=root, stderr=subprocess.STDOUT).decode().strip()


if subprocess.run(['docker', 'volume', 'inspect', 'ciro-downloader_data'], capture_output=True).returncode == 0:
    raise RuntimeError('Integration test requires an unused Ciro Downloader volume.')
previous = (root / '.env').read_bytes() if (root / '.env').exists() else None
try:
    (root / '.env').write_text('BOT_TOKEN=123:FAKE_CI_TOKEN\nADMIN_IDS=42\nSTORAGE_DIR=/app/storage\n', encoding='utf-8')
    command('docker', 'tag', 'ciro-downloader:ci', 'ciro-downloader:local')
    command('docker', 'compose', 'run', '--rm', '--no-deps', '-T', 'worker', 'php', '-r',
            'require "src/Core.php"; $s=new Zero\\Store("/app/storage"); $s->set("fixture","original"); file_put_contents("/app/storage/youtube-cookies.txt","FAKE_COOKIE"); chmod("/app/storage/youtube-cookies.txt",0600);')
    with tempfile.TemporaryDirectory(prefix='ciro-backup-integration-') as folder:
        app = maintenance.Maintenance(root, Path(folder))
        archive = app.backup()
        command('docker', 'compose', 'run', '--rm', '--no-deps', '-T', 'worker', 'php', '-r',
                'require "src/Core.php"; $s=new Zero\\Store("/app/storage"); $s->set("fixture","changed");')
        recovery = app.restore(archive)
        result = command('docker', 'compose', 'run', '--rm', '--no-deps', '-T', 'worker', 'php', '-r',
                'require "src/Core.php"; $s=new Zero\\Store("/app/storage"); echo $s->get("fixture")."|".file_get_contents("/app/storage/youtube-cookies.txt")."|".fileowner("/app/storage/zero.sqlite");')
        assert result == 'original|FAKE_COOKIE|10001', result
        assert recovery.is_file()
        assert app.running() == []
        app.configure('ADMIN_IDS', '42,99')
        assert maintenance.environment((root / '.env').read_text())['ADMIN_IDS'] == '42,99'
        print('PASS actual Docker volume backup/restore preserves SQLite, cookies, runtime ownership and stopped services')
finally:
    subprocess.run(['docker', 'compose', 'down', '--volumes', '--remove-orphans'], cwd=root, capture_output=True)
    if previous is None:
        (root / '.env').unlink(missing_ok=True)
    else:
        (root / '.env').write_bytes(previous)
