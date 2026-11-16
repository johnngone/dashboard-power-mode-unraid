#!/usr/bin/env python3
"""Build a network-independent Unraid plugin from the editable sources."""
import argparse
import hashlib
from pathlib import Path
import xml.etree.ElementTree as ET

ROOT = Path(__file__).resolve().parent
NAME = 'dashboard-power-mode'
VERSION = '2026.11.15.7'
TARGET = f'/usr/local/emhttp/plugins/{NAME}'
URL = f'https://raw.githubusercontent.com/johnngone/{NAME}-unraid/main/plugin/{NAME}.plg'


def inline(parent, content, **attributes):
    entry = ET.SubElement(parent, 'FILE', attributes)
    ET.SubElement(entry, 'INLINE').text = content
    return entry


def build():
    plugin = ET.Element('PLUGIN', {
        'name': NAME, 'author': 'johnngone', 'version': VERSION, 'min': '7.0.0',
        'pluginURL': URL, 'support': f'https://github.com/johnngone/{NAME}-unraid/issues', 'icon': 'fa-bolt',
    })
    ET.SubElement(plugin, 'CHANGES').text = (
        'Place adjustment controls below the existing Processor display.\n'
        'Add sampled GHz readings alongside each CPU load bar.\n'
        'Hardware detection, persistent settings, and Tips and Tweaks conflict detection.\n'
    )
    # pathlib orders paths case-insensitively on Windows. Use explicit POSIX
    # names so Windows and Linux produce exactly the same installer bytes.
    for path in sorted((ROOT / 'src').rglob('*'), key=lambda item: item.relative_to(ROOT / 'src').as_posix()):
        if not path.is_file():
            continue
        content = path.read_bytes()
        if b'\r' in content:
            raise ValueError(f'Expected LF line endings: {path}')
        relative = path.relative_to(ROOT / 'src').as_posix()
        entry = inline(plugin, content.decode('utf-8'), Name=f'{TARGET}/{relative}',
                       Mode='0755' if relative.startswith('scripts/') else '0644')
        # Unraid skips existing files unless a checksum changes; required for updates.
        ET.SubElement(entry, 'SHA256').text = hashlib.sha256(content.strip() + b'\n').hexdigest()
    inline(plugin, f'''#!/bin/bash
set -eu
mkdir -p /boot/config/plugins/{NAME}
chmod 0700 /boot/config/plugins/{NAME} || true
if [ -s /boot/config/plugins/{NAME}/settings.json ]; then
  if ! {TARGET}/scripts/power-mode restore >/dev/null; then
    logger -t {NAME} 'Saved CPU settings could not be restored; inspect the dashboard and syslog.'
  fi
fi
echo "Dashboard Power Mode installed. Open Dashboard to use the controls."
''', Run='/bin/bash', Method='install')
    inline(plugin, f'''#!/bin/bash
set -eu
rm -rf -- {TARGET}
echo "Dashboard Power Mode removed. Saved settings are retained; current CPU settings remain until changed or rebooted."
''', Run='/bin/bash', Method='remove')
    ET.indent(plugin, space='  ')
    # Keep text content byte-identical: indentation is added only around XML elements.
    return b'<?xml version="1.0" encoding="UTF-8"?>\n' + ET.tostring(plugin, encoding='utf-8') + b'\n'


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--check', action='store_true', help='Fail if the committed plugin is not reproducible')
    args = parser.parse_args()
    destination = ROOT / 'plugin' / f'{NAME}.plg'
    payload = build()
    ET.fromstring(payload)
    if args.check:
        if not destination.exists() or destination.read_bytes() != payload:
            raise SystemExit('Plugin is stale. Run python build.py and commit the result.')
        print('Plugin XML and reproducible build: OK')
    else:
        destination.parent.mkdir(exist_ok=True)
        destination.write_bytes(payload)
        print(f'Built {destination.name} ({len(payload):,} bytes)')
