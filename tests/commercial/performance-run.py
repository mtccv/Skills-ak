#!/usr/bin/env python3
"""Run samples sequentially in separate PHP processes; never print credentials or raw SQL."""
import argparse,json,os,pathlib,subprocess
p=argparse.ArgumentParser();p.add_argument('phase',choices=['before','after']);p.add_argument('--samples',type=int,default=5);a=p.parse_args()
if not 1<=a.samples<=10:raise SystemExit('Samples must be bounded')
root=pathlib.Path(__file__).resolve().parents[2];results=[]
env={k:v for k,v in os.environ.items() if k not in ['DOCKER_HOST','DOCKER_CONTEXT','DOCKER_TLS','DOCKER_TLS_VERIFY','DOCKER_CERT_PATH']}
for label,container,php in [('php83','p11qa-wp83','php'),('php84','p12qa-wp84','php84')]:
 for scope in ['admin','overview_only','reviews_only']:
  samples=[]
  for sample in range(a.samples):
   cmd=['docker','--host=unix:///var/run/docker.sock','exec','-e','AISH_PHASE13_QA=1','-e','AISH_BENCHMARK_PHASE='+a.phase,container,php,'/repo/tests/commercial/performance-dashboard.php',scope]
   proc=subprocess.run(cmd,env=env,text=True,capture_output=True,timeout=90)
   if proc.returncode or proc.stderr:raise SystemExit(proc.stderr or 'Dashboard benchmark failed')
   samples.append(json.loads(proc.stdout))
  path=root/'tests/commercial/results'/f'performance-{a.phase}-{label}-{scope}.json';path.write_text(json.dumps(samples,ensure_ascii=False,indent=2)+'\n')
  results.append({'runtime':label,'scope':scope,'samples':len(samples),'queries':[x['measurement']['sql_queries'] for x in samples],'elapsed_ms':[x['measurement']['elapsed_ms'] for x in samples]})
print(json.dumps(results,indent=2))
