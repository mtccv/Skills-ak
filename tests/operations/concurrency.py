#!/usr/bin/env python3
"""Independent PHP processes, actual MySQL advisory/row locks and CAS; synthetic QA only."""
import concurrent.futures,json,os,pathlib,subprocess,sys,time
v=sys.argv[1] if len(sys.argv)>1 else '83';assert v in ['83','84']
env=os.environ.copy()
for k in ['DOCKER_HOST','DOCKER_CONTEXT','DOCKER_TLS','DOCKER_TLS_VERIFY','DOCKER_CERT_PATH']:env.pop(k,None)
c,p=('p11qa-wp83','php') if v=='83' else ('p12qa-wp84','php84')
cmd=['docker','--host=unix:///var/run/docker.sock','exec','-i','-e','AISH_PHASE12_QA=1',c,p,'/repo/tests/operations/race-worker.php']
def call(mode,i=0):
 r=subprocess.run(cmd,input=json.dumps({'mode':mode,'worker':i}),capture_output=True,text=True,env=env,timeout=35)
 if r.returncode:raise RuntimeError('PHP QA worker failed')
 return json.loads(r.stdout)
assert call('setup')['ready'];runs={}
for mode in ['user','home','mail','search']:
 with concurrent.futures.ThreadPoolExecutor(max_workers=4) as ex:runs[mode]=list(ex.map(lambda i:call(mode,i),range(4)))
verify=call('verify');checks=verify['checks'];checks['user_all_identical_replays_safe']=all(r['success'] for r in runs['user']);checks['home_one_winner']=sum(r['success']for r in runs['home'])==1 and all(r['success'] or r.get('code')=='conflict' for r in runs['home']);checks['mail_one_schedule']=sum(r['success']for r in runs['mail'])==1 and all(r['success'] or r.get('code') in ['mail_not_retryable','mail_worker_busy'] for r in runs['mail']);checks['search_one_durable_winner']=sum(r.get('result')is True for r in runs['search'])==1 and all(r['success'] or r.get('code')=='measurement_busy' for r in runs['search'])
out={'kind':'Real four independent PHP processes per race against actual MySQL8; no Mock database. Mail retry-state fixture never sent.','php_runtime':v,'workers_per_race':4,'races':runs,'checks':checks,'passed':sum(checks.values()),'failed':len(checks)-sum(checks.values())}
p=pathlib.Path(__file__).parent/'results'/f'concurrency-php{v}.json';p.write_text(json.dumps(out,indent=2)+'\n');print(json.dumps({'passed':out['passed'],'failed':out['failed'],'checks':checks}));sys.exit(bool(out['failed']))
