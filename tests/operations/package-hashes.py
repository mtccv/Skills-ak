#!/usr/bin/env python3
"""Compare every deployable source byte, and the separately packaged repository LICENSE."""
import hashlib,json,pathlib,zipfile
p=pathlib.Path(__file__).resolve().parents[2];z=p/'release/ai-skills-hub-0.12.0.zip';checks={'repository':True,'qa83':True,'qa84':True,'packaged_license':True};files=0
with zipfile.ZipFile(z) as archive:
 for entry in archive.infolist():
  if entry.is_dir():continue
  name=entry.filename.removeprefix('ai-skills-hub/');expected=hashlib.sha256(archive.read(entry)).hexdigest();files+=1
  if name=='LICENSE':checks['packaged_license']=hashlib.sha256((p/'LICENSE').read_bytes()).hexdigest()==expected;continue
  for label,base in [('repository',p/'wp-content/themes/ai-skills-hub'),('qa83',pathlib.Path('/tmp/p11qa/theme')),('qa84',pathlib.Path('/tmp/p12qa/theme'))]:
   file=base/name;checks[label]=checks[label] and file.is_file() and hashlib.sha256(file.read_bytes()).hexdigest()==expected
out={'kind':'Byte-for-byte all executable/theme assets in repository and both real runtime mounts against final development ZIP; LICENSE added from repository root by packager','package_sha256':hashlib.sha256(z.read_bytes()).hexdigest(),'package_files':files,'runtime_theme_files':files-1,'checks':checks,'passed':sum(checks.values()),'failed':len(checks)-sum(checks.values())};(p/'tests/operations/results/runtime-package-hashes.json').write_text(json.dumps(out,indent=2)+'\n');print(json.dumps(out));raise SystemExit(bool(out['failed']))
