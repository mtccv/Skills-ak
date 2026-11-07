#!/usr/bin/env python3
"""Rerun existing Phase12 PHP contracts on Phase13; never overwrite historical evidence."""
import json,os,pathlib,subprocess,sys
root=pathlib.Path(__file__).resolve().parents[2];env=os.environ.copy()
for k in ['DOCKER_HOST','DOCKER_CONTEXT','DOCKER_TLS','DOCKER_TLS_VERIFY','DOCKER_CERT_PATH']:env.pop(k,None)
v=sys.argv[1] if len(sys.argv)>1 else '83';assert v in ['83','84'];c,p=('p11qa-wp83','php') if v=='83' else ('p12qa-wp84','php84')
for suite in ['users','notifications-mail','workbench-home','analytics','transactions']:
 r=subprocess.run(['docker','--host=unix:///var/run/docker.sock','exec','-e','AISH_PHASE12_QA=1',c,p,'/repo/tests/operations/'+suite+'.php'],env=env,capture_output=True,text=True)
 try:data=json.loads(r.stdout)
 except Exception:
  private=pathlib.Path('/tmp/p13qa');private.mkdir(exist_ok=True,mode=0o700);log=private/f'{suite}-{v}-regression.log';log.write_text(r.stdout+'\n'+r.stderr);log.chmod(0o600);print('Harness failure; private diagnostic',str(log));sys.exit(2)
 (root/'tests/commercial/results'/f'regression-{suite}-php{v}.json').write_text(json.dumps(data,indent=2,ensure_ascii=False)+'\n');print(v,suite,data['passed'],data['failed'])
 if r.returncode:sys.exit(r.returncode)
