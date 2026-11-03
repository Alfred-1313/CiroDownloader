"""Render the actual shell UI with explicitly simulated status; no host changes."""
import html
import os
from pathlib import Path
import re
import shutil
import subprocess

root = Path(__file__).resolve().parents[1]
bash = r'C:\Program Files\Git\bin\bash.exe' if os.name == 'nt' else shutil.which('bash')
fixture = '''source ./manager.sh
docker_ready() { return 0; }
timeout() { shift; "$@"; }
docker() { printf 'bot\\nworker\\n'; }
systemctl() { return 0; }
ui_init
render_menu
ui_prompt 'Choose [0-12]: '
'''
environment = dict(os.environ, TERM='xterm-256color', CLICOLOR_FORCE='1', CIRO_ASCII='0', COLUMNS='80')
environment.pop('NO_COLOR', None)
output = subprocess.check_output([bash, '-c', fixture], cwd=root, env=environment).decode('utf-8')
palette = {'0': '#c7d2e2', '1;97': '#f8fafc', '1;36': '#67e8f9', '0;37': '#c7d2e2',
           '1;32': '#4ade80', '1;31': '#f87171', '1;33': '#facc15', '38;5;39': '#38bdf8',
           '38;5;24': '#256287', '38;5;245': '#94a3b8'}
fragments = []; color = palette['0']; cursor = 0
for match in re.finditer(r'\x1b\[([0-9;]*)m', output):
    fragments.append('<span style="color:' + color + '">' + html.escape(output[cursor:match.start()]) + '</span>')
    color = palette.get(match[1] or '0', palette['0']); cursor = match.end()
fragments.append('<span style="color:' + color + '">' + html.escape(output[cursor:]) + '</span>')
document = '''<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width">
<title>Ciro Downloader — terminal preview</title><style>
body{margin:0;padding:40px 20px;background:#090f19;color:#c7d2e2;font:14px system-ui,sans-serif}
main{max-width:840px;margin:auto}p{color:#94a3b8;line-height:1.6}h1{font-size:20px;color:#f8fafc}
.terminal{background:#0d1624;border:1px solid #263a52;border-radius:12px;overflow:auto;box-shadow:0 20px 70px #0005}
.chrome{padding:14px 20px;border-bottom:1px solid #263a52;color:#94a3b8;font-size:12px}
pre{margin:0;padding:10px 20px 26px;line-height:1.55;font:14px/1.55 Consolas,"Cascadia Code",monospace}
</style><main><h1>Ciro Downloader · manager 3.0</h1><p>Actual shell layout. Service indicators are simulated for this preview.</p>
<div class="terminal"><div class="chrome">root@server · CiroDownloader</div><pre>''' + ''.join(fragments) + '</pre></div></main></html>'
destination = root / 'docs/manager-preview.html'
destination.write_text(document, encoding='utf-8')
print(destination)
