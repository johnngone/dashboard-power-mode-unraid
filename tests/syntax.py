import subprocess
from pathlib import Path
import tempfile
import xml.etree.ElementTree as ET

root = Path(__file__).resolve().parents[1]
for directory in ('src', 'tests'):
    for path in sorted((root / directory).rglob('*')):
        if path.is_file() and (path.suffix in ('.php', '.page') or path.name == 'power-mode'):
            subprocess.run(['php', '-d', 'short_open_tag=1', '-l', str(path)], check=True)
plugin = ET.parse(root / 'plugin/dashboard-power-mode.plg').getroot()
for entry in plugin.findall('FILE'):
    if entry.get('Run') == '/bin/bash':
        with tempfile.TemporaryFile() as script:
            script.write(entry.findtext('INLINE').encode())
            script.seek(0)
            subprocess.run(['bash', '-n'], stdin=script, check=True)
print('PASS: PHP and installer shell syntax')
