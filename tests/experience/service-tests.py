#!/usr/bin/env python3
"""Execute selected real PHP integration suites, writing only structured nonsecret evidence."""
import json
import os
from pathlib import Path
import subprocess
import sys

version=sys.argv[1] if len(sys.argv)>1 else '83'
if version not in ['83','84']: raise SystemExit('isolated QA runtime only')
suites=sys.argv[2:] or ['onboarding','help','installation','subscriptions','campaigns']
if any(x not in ['onboarding','help','help-regression','installation','subscriptions','campaigns','campaign-regression','new-resource-mail','mail-public-regression','subscription-retention','guards'] for x in suites): raise SystemExit('unknown suite')
root=Path(__file__).resolve().parents[2];env=os.environ.copy()
for key in ['DOCKER_HOST','DOCKER_CONTEXT','DOCKER_TLS','DOCKER_TLS_VERIFY','DOCKER_CERT_PATH']:env.pop(key,None)
container,php=('p11qa-wp83','php') if version=='83' else ('p12qa-wp84','php84')
failures=0
for suite in suites:
 result=subprocess.run(['docker','--host=unix:///var/run/docker.sock','exec','-e','AISH_PHASE14_QA=1',container,php,f'/repo/tests/experience/{suite}.php'],env=env,capture_output=True,text=True,timeout=120)
 try: report=json.loads(result.stdout)
 except Exception:
  private=Path('/tmp/p14qa');private.mkdir(exist_ok=True,mode=0o700);log=private/f'{suite}-php{version}.log';log.write_text(result.stdout+'\n'+result.stderr);log.chmod(0o600);print(json.dumps({'suite':suite,'runtime':version,'harness_error':'Non-JSON response; private local diagnostic '+str(log)}),flush=True);failures+=1;continue
 (root/'tests/experience/results'/f'{suite}-php{version}.json').write_text(json.dumps(report,indent=2,ensure_ascii=False)+'\n')
 print(json.dumps({'suite':suite,'runtime':version,'passed':report['passed'],'failed':report['failed'],'assertions':report.get('assertions'), 'failures':[c for c in report.get('cases',[]) if c['status']!='passed']}),flush=True)
 failures+=int(result.returncode!=0 or report['failed']!=0)
raise SystemExit(bool(failures))
