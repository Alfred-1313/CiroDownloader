"""Render the actual shell UI with explicitly simulated status; no host changes."""
import html
import os
from pathlib import Path
import re
import shutil
import subprocess
import tempfile

root = Path(__file__).resolve().parents[1]
bash = r'C:\Program Files\Git\bin\bash.exe' if os.name == 'nt' else shutil.which('bash')
fixture = r'''source "$1/manager.sh"
cd "$2"
docker_ready() { return 0; }
timeout() { shift; "$@"; }
docker() {
  case "$*" in
    *"--status running"*) printf 'bot\n' ;;
    *) printf 'bot\nworker\n' ;;
  esac
}
systemctl() { return 1; }
ui_init
render_menu
ui_prompt 'Choose [0-12]: '
'''

def render(columns):
    environment = dict(os.environ, TERM='xterm-256color', CLICOLOR_FORCE='1', CIRO_ASCII='0', COLUMNS=str(columns))
    environment.pop('NO_COLOR', None)
    with tempfile.TemporaryDirectory(prefix='ciro-manager-preview-') as directory:
        sample = Path(directory)
        (sample / 'VERSION').write_bytes((root / 'VERSION').read_bytes())
        (sample / '.env').write_text('# Preview fixture only; no credentials.\n')
        return subprocess.check_output([bash, '-c', fixture, '_', root.as_posix(), sample.as_posix()], cwd=root, env=environment).decode('utf-8')

palette = {'0': '#c7d2e2', '1;97': '#f8fafc', '1;36': '#67e8f9', '0;37': '#c7d2e2',
           '1;32': '#4ade80', '1;31': '#f87171', '1;33': '#facc15', '38;5;39': '#38bdf8',
           '38;5;24': '#256287', '38;5;245': '#94a3b8'}
def ansi_html(output):
    fragments = []; color = palette['0']; cursor = 0
    for match in re.finditer(r'\x1b\[([0-9;]*)m', output):
        fragments.append('<span style="color:' + color + '">' + html.escape(output[cursor:match.start()]) + '</span>')
        color = palette.get(match[1] or '0', palette['0']); cursor = match.end()
    fragments.append('<span style="color:' + color + '">' + html.escape(output[cursor:]) + '</span>')
    return ''.join(fragments)

panels = []
for columns, label in [(80, 'Desktop SSH · 80 columns'), (32, 'Mobile SSH · 32 columns')]:
    panels.append('<section class="terminal"><h2>' + label + '</h2><pre>' + ansi_html(render(columns)) + '</pre></section>')
document = '''<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width">
<title>Ciro Downloader — terminal preview</title><style>
body{margin:0;padding:32px 16px;background:#090f19;color:#c7d2e2;font:14px system-ui,sans-serif}
main{max-width:1100px;margin:auto}p{color:#94a3b8;line-height:1.6}h1{font-size:22px;color:#f8fafc}
.previews{display:grid;grid-template-columns:minmax(0,1fr) 320px;gap:20px;align-items:start}
.terminal{background:#0d1624;border:1px solid #263a52;border-radius:12px;overflow:hidden;min-width:0}
h2{margin:0;padding:14px 16px;border-bottom:1px solid #263a52;color:#94a3b8;font-size:12px;font-weight:500}
pre{margin:0;padding:8px 8px 24px;overflow:auto;font:14px/1.55 Consolas,"Cascadia Code",monospace;tab-size:4}
@media(max-width:1000px){.previews{grid-template-columns:1fr}.terminal{width:fit-content;max-width:100%}}
</style><main><h1>Ciro Downloader · terminal manager 3.1</h1>
<p>Actual shell output at desktop and mobile widths. Status is simulated: bot running, worker stopped.</p>
<div class="previews">''' + ''.join(panels) + '</div></main></html>'
destination = root / 'docs/manager-preview.html'
destination.write_text(document, encoding='utf-8')
print(destination)
