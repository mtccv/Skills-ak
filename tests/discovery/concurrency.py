"""Real independent PHP processes against isolated MySQL; no production access."""
import concurrent.futures,json,os,secrets,subprocess
from pathlib import Path
repo=Path(__file__).resolve().parents[2]
env=os.environ.copy()
for key in ['DOCKER_HOST','DOCKER_CONTEXT','DOCKER_TLS','DOCKER_TLS_VERIFY','DOCKER_CERT_PATH']: env.pop(key,None)
cmd=['docker','--host=unix:///var/run/docker.sock','exec','-i','-e','AISH_PHASE11_QA=1','p11qa-wp83','php','/repo/tests/discovery/race-worker.php']
f=json.loads(Path('/tmp/p11qa/browser-fixture.json').read_text())
def worker(payload):
 r=subprocess.run(cmd,input=json.dumps(payload),capture_output=True,text=True,env=env,check=True)
 return json.loads(r.stdout)
view={'mode':'view','skill':f['skills']['alpha'],'observer':secrets.token_hex(32)}
with concurrent.futures.ThreadPoolExecutor(max_workers=8) as pool:
 views=list(pool.map(worker,[dict(view,locale='en_US' if n%2 else 'zh_CN') for n in range(8)]))
c=f['collection'];payload={'status':'published','sort_order':1,'skills':[f['skills']['alpha']],'translations':{'en_US':{'title':c['title'],'slug':c['slug'],'description':c['description'],'seo_title':c['seo_title'],'seo_description':c['seo_description']},'zh_CN':{'title':'并发测试专题','slug':c['slug'],'description':'保留原有双语关联。'}}}
# Newly created fixture collection's initial revision is 1; browser has not edited it.
change={'mode':'collection','id':c['id'],'revision':1,'payload':payload}
with concurrent.futures.ThreadPoolExecutor(max_workers=4) as pool:
 editors=list(pool.map(worker,[change]*4))
checks={'eight_parallel_views_one_record':sum(x['state']=='recorded' for x in views)==1,'seven_crosslanguage_retries_deduplicated':sum(x['state']=='already_counted' for x in views)==7,'four_parallel_editorial_edits_one_commit':sum(x['state']=='saved' for x in editors)==1,'three_stale_editors_rejected':sum(x['state']=='rejected' for x in editors)==3}
result={'kind':'Real MySQL concurrent independent PHP8.3 worker processes; direct private persistence method used only by CLI-only QA harness','view_workers':views,'editor_workers':editors,'checks':checks,'passed':sum(checks.values()),'failed':len(checks)-sum(checks.values())}
(repo/'tests/discovery/results/concurrency-php83.json').write_text(json.dumps(result,indent=2)+'\n')
print(json.dumps({'passed':result['passed'],'failed':result['failed']}))
raise SystemExit(1 if result['failed'] else 0)
