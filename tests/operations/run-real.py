#!/usr/bin/env python3
"""Run synthetic QA suites only in the pre-provisioned isolated local containers."""
import json, os, pathlib, subprocess, sys
root=pathlib.Path(__file__).resolve().parents[2]
env=os.environ.copy()
for key in ['DOCKER_HOST','DOCKER_CONTEXT','DOCKER_TLS','DOCKER_TLS_VERIFY','DOCKER_CERT_PATH']:env.pop(key,None)
version=sys.argv[1] if len(sys.argv)>1 else '83'
assert version in ['83','84']
container,php=('p11qa-wp83','php') if version=='83' else ('p12qa-wp84','php84')
for suite in (sys.argv[2:] or ['users','notifications-mail','workbench-home','analytics']):
 assert suite in ['users','notifications-mail','workbench-home','analytics','concurrency','performance']
 result=subprocess.run(['docker','--host=unix:///var/run/docker.sock','exec','-e','AISH_PHASE12_QA=1',container,php,'/repo/tests/operations/'+suite+'.php'],env=env,capture_output=True,text=True)
 try:data=json.loads(result.stdout)
 except Exception:
  private=pathlib.Path('/tmp/p12qa');private.mkdir(mode=0o700,exist_ok=True)
  log=private/f'{suite}-{version}-harness.log';log.write_text(result.stdout+'\n'+result.stderr);log.chmod(0o600)
  print(suite,'Harness failed before JSON; private diagnostic:',str(log));sys.exit(2)
 (root/'tests/operations/results'/f'{suite}-php{version}.json').write_text(json.dumps(data,indent=2,ensure_ascii=False)+'\n')
 print(version,suite,data['passed'],data['failed'])
 if result.returncode:sys.exit(result.returncode)
