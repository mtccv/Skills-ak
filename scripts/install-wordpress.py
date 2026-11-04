#!/usr/bin/env python3
"""Install the disposable development site without echoing its password."""

import argparse
import os
from pathlib import Path
import re
import secrets
import subprocess
import sys

root = Path(__file__).resolve().parent.parent
parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--password-file', type=Path, default=root / '.dev-admin-password')
args = parser.parse_args()
password_file = args.password_file.resolve()
if not password_file.exists():
    descriptor = os.open(password_file, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
    with os.fdopen(descriptor, 'w') as stream:
        stream.write(secrets.token_hex(24) + '\n')
    print('Generated a development admin password in the private password file.')
password = password_file.read_text().strip()
if not re.fullmatch(r'[0-9a-f]{48}', password):
    sys.exit('Password file must contain a generated 48-character lowercase hex value.')
result = subprocess.run(
    ['docker', 'compose', 'run', '--rm', '-T', 'cli', 'wp', 'core', 'install',
     '--url=http://localhost:8080', '--title=AI Skills Hub Dev',
     '--admin_user=cloud_admin', '--admin_email=dev@example.invalid',
     '--skip-email', '--prompt=admin_password'],
    cwd=root, input=password + '\n', text=True,
    stdout=subprocess.PIPE, stderr=subprocess.STDOUT, check=False,
)
print(result.stdout.replace(password, '[redacted]'), end='')
sys.exit(result.returncode)
