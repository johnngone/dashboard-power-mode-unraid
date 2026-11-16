"""Check repository content and optionally reachable history for private data."""
import argparse
import re
import struct
import subprocess
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--history', action='store_true', help='Also inspect every reachable commit and its author emails')
args = parser.parse_args()
patterns = {
    'private IPv4 address': r'(?<![\d.])(?:192\.168\.\d{1,3}\.\d{1,3}|10\.\d{1,3}\.\d{1,3}\.\d{1,3}|172\.(?:1[6-9]|2\d|3[01])\.\d{1,3}\.\d{1,3})(?![\d.])',
    'Windows home directory': r'[A-Za-z]:[\\/](?:Users|Documents and Settings)[\\/][^\s\\/]+',
    'personal Unix home directory': r'/(?:Users|home)/(?!runner(?:/|\b))[^\s/]+',
    'email address': r'\b[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}\b',
    'private key': r'-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----',
    'hardware serial or MAC': r'\b(?:[0-9a-fA-F]{2}:){5}[0-9a-fA-F]{2}\b',
}


def git(*arguments):
    return subprocess.check_output(['git', *arguments], cwd=ROOT)


def check(path, data, label):
    if data.startswith(b'\x89PNG\r\n\x1a\n'):
        offset = 8
        while offset < len(data):
            size = struct.unpack('>I', data[offset:offset + 4])[0]
            kind = data[offset + 4:offset + 8]
            if kind in (b'tEXt', b'zTXt', b'iTXt', b'eXIf'):
                raise SystemExit(f'Image metadata requires review: {label}:{path}')
            offset += size + 12
        return
    text = data.decode('utf-8')
    for name, expression in patterns.items():
        for match in re.finditer(expression, text):
            if name == 'email address' and match.group().endswith('@users.noreply.github.com'):
                continue
            # Report locations and categories, never echo potentially private values into CI logs.
            raise SystemExit(f'Privacy review required ({name}): {label}:{path}:{text.count(chr(10), 0, match.start()) + 1}')


for entry in git('ls-files', '-z').decode().split('\0'):
    if entry:
        check(entry, (ROOT / entry).read_bytes(), 'working tree')
if args.history:
    for commit in git('rev-list', '--all').decode().splitlines():
        emails = git('show', '-s', '--format=%ae%n%ce', commit).decode().splitlines()
        if any(not email.endswith('@users.noreply.github.com') for email in emails):
            raise SystemExit(f'Non-private commit email requires review: {commit[:12]}')
        for entry in git('ls-tree', '-rz', '--name-only', commit).decode().split('\0'):
            if entry:
                check(entry, git('show', commit + ':' + entry), commit[:12])
print('PASS: privacy scan' + (' including reachable Git history' if args.history else ' of tracked files'))
