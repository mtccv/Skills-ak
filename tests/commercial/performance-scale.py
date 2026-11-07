#!/usr/bin/env python3
"""Read immutable Phase 12 Dashboard from Git, rename its class only, compare on rollback fixtures."""
import hashlib,json,os,pathlib,subprocess,tempfile
root=pathlib.Path(__file__).resolve().parents[2]
commit='46d63dbfd256ae38c579f65608ee46876cf545f0'
source=subprocess.check_output(['git','show',commit+':wp-content/themes/ai-skills-hub/inc/core/Operations/Dashboard.php'],cwd=root)
if source.count(b'final class Dashboard {')!=1:raise SystemExit('Unexpected baseline source')
variant=source.replace(b'final class Dashboard {',b'final class BenchmarkDashboardBaseline {')
env={k:v for k,v in os.environ.items() if k not in ['DOCKER_HOST','DOCKER_CONTEXT','DOCKER_TLS','DOCKER_TLS_VERIFY','DOCKER_CERT_PATH']};summary=[]
with tempfile.TemporaryDirectory(prefix='aish-perf-') as temporary:
 baseline=pathlib.Path(temporary)/'DashboardBaseline.php';baseline.write_bytes(variant)
 for runtime,container,php in [('php83','p11qa-wp83','php'),('php84','p12qa-wp84','php84')]:
  subprocess.run(['docker','--host=unix:///var/run/docker.sock','cp',str(baseline),container+':/tmp/aish-dashboard-baseline.php'],env=env,check=True,capture_output=True)
  for scale in [0,100,1000]:
   samples=[]
   for order in ['before_first','after_first','before_first']:
    cmd=['docker','--host=unix:///var/run/docker.sock','exec','-e','AISH_PHASE13_QA=1','-e','AISH_BASELINE_SHA256='+hashlib.sha256(source).hexdigest(),container,php,'/repo/tests/commercial/performance-scale.php',str(scale),order]
    p=subprocess.run(cmd,env=env,text=True,capture_output=True,timeout=90)
    if p.returncode or p.stderr:raise SystemExit(p.stderr or 'Scaled comparison failed')
    samples.append(json.loads(p.stdout))
   path=root/'tests/commercial/results'/f'performance-scale-{runtime}-{scale}.json';path.write_text(json.dumps(samples,ensure_ascii=False,indent=2)+'\n')
   summary.append({'runtime':runtime,'scale':scale,'metrics_equal':all(s['identical_metrics'] for s in samples),'before_queries':[s['measurements']['before']['sql_queries'] for s in samples],'after_queries':[s['measurements']['after']['sql_queries'] for s in samples],'before_ms':[s['measurements']['before']['elapsed_ms'] for s in samples],'after_ms':[s['measurements']['after']['elapsed_ms'] for s in samples]})
print(json.dumps(summary,indent=2))
