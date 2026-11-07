#!/usr/bin/env python3
"""Sync source into existing isolated theme mounts only; credentials and volumes untouched."""
from pathlib import Path
import shutil
source=Path(__file__).resolve().parents[2]/'wp-content/themes/ai-skills-hub'
for target in [Path('/tmp/p11qa/theme'),Path('/tmp/p12qa/theme')]:
 if not target.is_dir():raise SystemExit('Provision QA theme mount first')
 shutil.copytree(source,target,dirs_exist_ok=True)
 # Workspace source is owner-only; Apache's www-data must read deployable theme assets.
 for p in [target,*target.rglob('*')]:
  if p.is_symlink():raise SystemExit('Unexpected QA theme symlink')
  p.chmod(0o755 if p.is_dir() else 0o644)
print('Synced deployable theme files only into existing QA mounts.')
