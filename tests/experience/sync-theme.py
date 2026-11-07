#!/usr/bin/env python3
"""Record the exact source tree copied into both retained QA mounts; never touch DB/credentials."""
from pathlib import Path
from datetime import datetime, timezone
import hashlib
import json
import subprocess
import sys

root=Path(__file__).resolve().parents[2]
subprocess.run([sys.executable,str(root/'tests/operations/sync-theme.py')],cwd=root,check=True)
source=root/'wp-content/themes/ai-skills-hub'
rows={str(p.relative_to(source)):hashlib.sha256(p.read_bytes()).hexdigest() for p in sorted(source.rglob('*')) if p.is_file()}
checks={}
for version,base in [('83',Path('/tmp/p11qa/theme')),('84',Path('/tmp/p12qa/theme'))]:
    checks[version]=all((base/name).is_file() and hashlib.sha256((base/name).read_bytes()).hexdigest()==digest for name,digest in rows.items())
record={'timestamp_utc':datetime.now(timezone.utc).isoformat(),'baseline_commit':subprocess.check_output(['git','rev-parse','HEAD'],cwd=root,text=True).strip(),'theme_source_files':len(rows),'source_tree_sha256':hashlib.sha256(json.dumps(rows,sort_keys=True).encode()).hexdigest(),'runtime_matches':checks,'purpose':' '.join(sys.argv[1:]) or 'Targeted Phase14 candidate regression'}
path=root/'tests/experience/results/source-syncs.json'
data=json.loads(path.read_text()) if path.exists() else []
data.append(record);path.write_text(json.dumps(data,indent=2)+'\n')
print(json.dumps(record))
raise SystemExit(not all(checks.values()))
