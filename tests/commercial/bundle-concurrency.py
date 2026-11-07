#!/usr/bin/env python3
"""Focused repeated four-writer regression for the actual options lock-upgrade deadlock."""
import concurrent.futures,json,os,pathlib,subprocess,sys,time
v=sys.argv[1] if len(sys.argv)>1 else '83'
if v not in ('83','84'):raise SystemExit('QA runtime only')
c,p=('p11qa-wp83','php') if v=='83' else ('p12qa-wp84','php84')
env={k:val for k,val in os.environ.items() if k not in ('DOCKER_HOST','DOCKER_CONTEXT','DOCKER_TLS','DOCKER_TLS_VERIFY','DOCKER_CERT_PATH')}
def worker(data):
 r=subprocess.run(['docker','--host=unix:///var/run/docker.sock','exec','-i','-e','AISH_PHASE13_QA=1',c,p,'/repo/tests/commercial/race-worker.php'],input=json.dumps(data),text=True,capture_output=True,env=env,timeout=30)
 if r.returncode:raise RuntimeError('QA worker failed; raw output withheld')
 return json.loads(r.stdout)
checks={};rounds=[]
for index in range(5):
 initial=worker({'mode':'prepare'})
 try:
  at=time.time()+1.5
  with concurrent.futures.ThreadPoolExecutor(max_workers=4)as pool:rows=list(pool.map(worker,[{'mode':'bundle','at':at,'revision':initial['bundle_revision'],'writer':i}for i in range(4)]))
  state=worker({'mode':'inspect'});success=sum(bool(x.get('ok'))for x in rows);conflict=sum(x.get('reason')=='revision_conflict'for x in rows)
  checks[f'round_{index+1}_single_commit_three_conflicts']=success==1 and conflict==3
  checks[f'round_{index+1}_one_revision_one_audit']=state['bundle_revision']==initial['bundle_revision']+1 and state['audit_delta']==1
  rounds.append({'writers':4,'committed':success,'revision_conflicts':conflict,'other_failures':sum(not x.get('ok')and x.get('reason')!='revision_conflict'for x in rows)})
 finally:worker({'mode':'restore'})
result={'kind':'Actual MySQL repeated four-writer regression after exclusive no-op initialization upsert; five rounds, twenty independent PHP processes per runtime, no simulated locks.','php_runtime':v,'rounds':rounds,'checks':checks,'passed':sum(checks.values()),'failed':len(checks)-sum(checks.values())}
(pathlib.Path(__file__).parent/'results'/f'bundle-concurrency-php{v}.json').write_text(json.dumps(result,indent=2)+'\n');print(json.dumps(result));raise SystemExit(bool(result['failed']))
