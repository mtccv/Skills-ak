#!/usr/bin/env python3
"""Phase 13 local PHP lint/isolated regressions; not WordPress integration."""
import json, os, pathlib, re, subprocess, sys
ROOT = pathlib.Path(__file__).resolve().parents[2]
OUT = ROOT / 'tests/commercial/results'
OUT.mkdir(exist_ok=True)
env = dict(os.environ)
for key in ('DOCKER_HOST','DOCKER_CONTEXT','DOCKER_TLS','DOCKER_TLS_VERIFY','DOCKER_CERT_PATH'):
    env.pop(key, None)
DOCKER = ['docker','--host=unix:///var/run/docker.sock','exec']
summary = {'kind':'Static PHP syntax and isolated/Mock contracts, not real WordPress/MySQL/API tests','php':{}}
for runtime, container, php in [('83','p11qa-wp83','php'),('84','p12qa-wp84','php84')]:
    def execute(args):
        return subprocess.run(DOCKER + ['-w','/repo',container,php] + args, env=env, text=True, stdout=subprocess.PIPE, stderr=subprocess.STDOUT)
    version = execute(['-r','echo PHP_VERSION;']).stdout
    suites=[]; log=[]; lint_log=[]; failed=0; counts=0
    files = sorted((ROOT/'wp-content/themes/ai-skills-hub').rglob('*.php')) + sorted((ROOT/'tests').rglob('*.php'))
    for path in files:
        result=execute(['-l',str(path.relative_to(ROOT))]); lint_log.append(result.stdout)
        if result.returncode: failed+=1
    for path in sorted((ROOT/'tests/basic').glob('*.php')):
        result=execute([str(path.relative_to(ROOT))]); log.extend(['TEST '+str(path.relative_to(ROOT))+'\n',result.stdout])
        checks=sum(int(x) for x in re.findall(r'(?:PASS:\s*|:\s*)(\d+)\s+(?=[A-Za-z])',result.stdout))
        if not checks:
            try: checks=int(json.loads(result.stdout).get('checks',0))
            except (ValueError,TypeError): checks=0
        counts+=checks; failed+=bool(result.returncode)
        suites.append({'file':str(path.relative_to(ROOT)),'assertion_executions':checks,'exit_code':result.returncode})
        if result.returncode: print(runtime,path.name,result.stdout[-2500:])
    (OUT/f'lint-php{runtime}.txt').write_text(''.join(lint_log))
    (OUT/f'isolated-php{runtime}.txt').write_text(''.join(log))
    summary['php'][runtime]={'version':version.strip(),'scripts':len(suites),'assertion_executions':counts,'linted_files':len(files),'failed':failed,'suites':suites}
    print(json.dumps({runtime:{k:v for k,v in summary['php'][runtime].items() if k!='suites'}}))
(OUT/'static-summary.json').write_text(json.dumps(summary,indent=2)+'\n')
sys.exit(any(x['failed'] for x in summary['php'].values()))
