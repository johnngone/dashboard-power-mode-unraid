"""Check the bytes Unraid will install, including update checksums."""
import hashlib
from pathlib import Path
import xml.etree.ElementTree as ET

root = Path(__file__).resolve().parents[1]
plugin = ET.parse(root / 'plugin/dashboard-power-mode.plg').getroot()
files = [entry for entry in plugin.findall('FILE') if entry.get('Name')]
assert files
for entry in files:
    installed = entry.findtext('INLINE').strip().encode() + b'\n'
    expected = root / 'src' / entry.get('Name').split('/dashboard-power-mode/', 1)[1]
    assert installed == expected.read_bytes(), expected
    assert hashlib.sha256(installed).hexdigest() == entry.findtext('SHA256'), expected
assert plugin.get('min') == '7.0.0'
assert not plugin.findall('.//URL'), 'Plugin must boot without network downloads'
assert not any('tips.and.tweaks' in entry.get('Name', '') for entry in files)
assert {entry.get('Method') for entry in plugin.findall('FILE') if entry.get('Run')} == {'install', 'remove'}
print(f'PASS: {len(files)} embedded payloads, checksums, and install/remove lifecycle')
