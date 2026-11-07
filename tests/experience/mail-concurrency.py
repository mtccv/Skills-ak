#!/usr/bin/env python3
"""Real process barriers and MySQL locks; selected owned rows, intercepted transport only."""
import json,os,pathlib,secrets,subprocess,sys,time
version=sys.argv[1] if len(sys.argv)>1 else '83'
if version not in ['83','84']:raise SystemExit('isolated QA runtime only')
container,php=('p11qa-wp83','php')if version=='83'else('p12qa-wp84','php84')
env=os.environ.copy()
for k in ['DOCKER_HOST','DOCKER_CONTEXT','DOCKER_TLS','DOCKER_TLS_VERIFY','DOCKER_CERT_PATH']:env.pop(k,None)
docker=['docker','--host=unix:///var/run/docker.sock'];command=docker+['exec','-i','-e','AISH_PHASE14_QA=1',container,php,'/repo/tests/experience/mail-race-worker.php'];cases=[];processes=[]
def begin(mode,**args):
 p=subprocess.Popen(command,env=env,stdin=subprocess.PIPE,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True);p.stdin.write(json.dumps(dict(mode=mode,**args)));p.stdin.close();processes.append(p);return p
def finish(p,allow_busy=False):
 data=p.stdout.read();p.wait(timeout=15)
 if p.returncode and not(allow_busy and json.loads(data).get('error')=='busy'):raise RuntimeError('Worker failed with bounded response '+data[:200])
 return json.loads(data)
def call(mode,**args):return finish(begin(mode,**args))
def case(name,fn):
 try:fn();cases.append(dict(id=name,status='passed'))
 except Exception as e:cases.append(dict(id=name,status='failed',reason=str(e)[:250]))
barrier=secrets.token_hex(16)
def release():subprocess.run(docker+['exec',container,'touch','/tmp/aish-p14-mail-release-'+barrier],env=env,check=True,capture_output=True)
try:
 call('enable')
 def duplicate_consent():
  assert call('consent_setup')=={'created':True}
  left=begin('request');right=begin('request');results=[finish(left,True),finish(right,True)]
  assert any(r.get('state')=='pending' for r in results),'At least one real request commits pending consent'
  assert all(r.get('state')=='pending'or r.get('error')=='busy'for r in results),'Contender safely deduplicates or reports bounded contention'
  replay=call('request');assert replay==dict(state='pending',consent_rows=1,confirmations=1,outbox=1),'Exact retry leaves one consent/verification/outbox row'
 case('two_real_concurrent_optins_and_retry_share_one_pending_consent',duplicate_consent)
 def races():
  sender=begin('send',which='a',barrier=barrier);assert json.loads(sender.stdout.readline())=={'stage':'transport_started'},'First real worker reached intercepted transport under user/policy locks'
  duplicate=call('send',which='a');assert duplicate['mock_mail_calls']==0 and duplicate['row']['status']=='sending','Second independent process cannot dispatch same claimed row'
  other=call('send',which='b');assert other['mock_mail_calls']==0 and other['row']['status']=='retry' and int(other['row']['has_payload'])==1,'Policy-lock contention preserves unsent payload for retry'
  cancellation=begin('cancel',which='a');assert json.loads(cancellation.stdout.readline())=={'stage':'cancel_started'};time.sleep(.15);assert cancellation.poll() is None,'Cancellation waits for current owned send lock'
  release();sent=finish(sender);cancelled=finish(cancellation)
  assert sent['row']['status']=='accepted'and sent['mock_mail_calls']==1,'Only in-flight send accepted before completed cancellation'
  assert cancelled['cancelled']and cancelled['state']=='unsubscribed','Cancellation completes after serialized in-flight attempt'
  repeat=call('send',which='a');assert repeat['mock_mail_calls']==0 and repeat['state']=='unsubscribed','No later send after completed unsubscribe'
  retried=call('send',which='b');assert retried['row']['status']=='accepted'and retried['mock_mail_calls']==1,'Safely deferred other recipient retries exactly once'
  replay=call('send',which='b');assert replay['mock_mail_calls']==0,'Accepted row is never dispatched again'
 case('real_two_worker_policy_contention_deduplication_and_cancel_send_serialization',races)
finally:
 release()
 for p in processes:
  if p.poll()is None:
   try:p.wait(timeout=10)
   except subprocess.TimeoutExpired:p.terminate()
 try:call('restore')
 except Exception as e:cases.append(dict(id='restore',status='failed',reason=str(e)[:150]))
report=dict(kind='Real separate PHP/MySQL connections and deterministic transport barrier; constrained synthetic outbox rows; wp_mail acceptance Mock, not SMTP or delivery',php_runtime=version,cases=cases,passed=sum(c['status']=='passed'for c in cases),failed=sum(c['status']!='passed'for c in cases))
(pathlib.Path(__file__).parent/'results'/f'mail-concurrency-php{version}.json').write_text(json.dumps(report,indent=2)+'\n');print(json.dumps(report));raise SystemExit(bool(report['failed']))
