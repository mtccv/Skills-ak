#!/usr/bin/env python3
"""Fresh PHP processes race on existing QA databases; only synthetic fixtures/configs."""
import concurrent.futures,json,os,pathlib,subprocess,sys,time
version=sys.argv[1] if len(sys.argv)>1 else '83'
if version not in ('83','84'):raise SystemExit('QA runtime only')
container,binary=('p11qa-wp83','php') if version=='83' else ('p12qa-wp84','php84')
env={k:v for k,v in os.environ.items() if k not in ('DOCKER_HOST','DOCKER_CONTEXT','DOCKER_TLS','DOCKER_TLS_VERIFY','DOCKER_CERT_PATH')}
def run(data):
 result=subprocess.run(['docker','--host=unix:///var/run/docker.sock','exec','-i','-e','AISH_PHASE13_QA=1',container,binary,'/repo/tests/commercial/race-worker.php'],input=json.dumps(data),capture_output=True,text=True,env=env,timeout=30)
 if result.returncode:raise RuntimeError('QA worker failed without exposing output')
 return json.loads(result.stdout)
checks={};outcomes={};baseline=run({'mode':'prepare'})
try:
 for mode in ['sponsor','enterprise','bundle','receive']:
  at=time.time()+2;revision=baseline.get(mode+'_revision',0)
  with concurrent.futures.ThreadPoolExecutor(max_workers=4) as pool:rows=list(pool.map(run,[{'mode':mode,'at':at,'revision':revision,'writer':i}for i in range(4)]))
  outcomes[mode]={'initial':rows.copy()}
  # The reused native Inbox deliberately uses GET_LOCK(...,0): contention is
  # retryable busy, not a duplicate or an implied successful write.
  for i,row in enumerate(rows):
   if not row.get('ok') and row.get('reason')=='busy':rows[i]=run({'mode':mode,'at':0,'revision':revision,'writer':i})
  outcomes[mode]['after_busy_retry']=rows
  if any(not x.get('ok') and x.get('reason') not in ('conflict','revision_conflict') for x in rows):print(json.dumps({'diagnostic_operation':mode,'outcomes':rows}))
  if mode=='receive':checks['concurrent_receipt_one_identity']=all(x.get('ok')for x in rows) and len({x.get('id')for x in rows})==1
  else:
   checks[mode+'_one_writer']=sum(bool(x.get('ok')) for x in rows)==1
   checks[mode+'_stale_writers_rejected']=all(x.get('ok')or x.get('reason')in('conflict','revision_conflict')for x in rows)
 state=run({'mode':'inspect'})
 checks['one_private_inquiry']=state['inquiries']==state['private_inquiries']==1
 checks['one_revision_each']=all(state[k+'_revision']==baseline[k+'_revision']+1 for k in ['sponsor','enterprise','bundle'])
 checks['four_committed_audit_events']=state['audit_delta']==4
finally:run({'mode':'restore'})
report={'kind':'Real parallel fresh PHP workers and MySQL row/advisory locks; four processes per operation. Native Inbox contention returns busy; each busy request retried once after parallel workers complete. Synthetic settings/Inbox only.','php_runtime':version,'checks':checks,'passed':sum(checks.values()),'failed':len(checks)-sum(checks.values()),'workers_per_operation':4,'initial_busy_responses':{k:sum(x.get('reason')=='busy' for x in v['initial'])for k,v in outcomes.items()}}
target=pathlib.Path(__file__).parent/'results'/f'concurrency-php{version}.json';target.write_text(json.dumps(report,indent=2)+'\n')
print(json.dumps(report));sys.exit(1 if report['failed'] else 0)
