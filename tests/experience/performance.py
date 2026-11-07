#!/usr/bin/env python3
"""Run bounded read-only service samples in an already synchronized local QA container."""
import argparse
import json
import os
from pathlib import Path
import subprocess

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--runtime', choices=('83', '84'), required=True)
parser.add_argument('--container-script', required=True, help='Existing synchronized path to performance.php in the container')
args = parser.parse_args()
if args.container_script != '/repo/tests/experience/performance.php' and not (args.container_script.startswith('/tmp/') and args.container_script.endswith('/performance.php')):
    parser.error('Use the mounted repository test file or an already synchronized temporary QA test path.')
target = {'83': ('p11qa-wp83', 'php'), '84': ('p12qa-wp84', 'php84')}[args.runtime]
env = dict(os.environ)
for key in ('DOCKER_HOST', 'DOCKER_CONTEXT', 'DOCKER_TLS', 'DOCKER_TLS_VERIFY', 'DOCKER_CERT_PATH'):
    env.pop(key, None)
scopes = ('help_list', 'help_detail', 'home_catalog_preamble', 'skill_detail', 'account_subscription', 'account_onboarding', 'guard_post_metadata')
results = []
for scope in scopes:
    for locale in ('zh_CN', 'en_US'):
        command = ['docker', '--host=unix:///var/run/docker.sock', 'exec', '-e', 'AISH_PHASE14_QA=1', target[0], target[1], args.container_script, scope, locale]
        completed = subprocess.run(command, env=env, capture_output=True, text=True, timeout=60)
        if completed.returncode:
            # Do not persist unexpected raw PHP diagnostics or database parameters.
            raise RuntimeError(f'Read-only measurement {args.runtime}/{scope}/{locale} failed with exit {completed.returncode}; review locally without publishing secrets.')
        results.append(json.loads(completed.stdout))
output = Path(__file__).parent / 'results' / f'performance-php{args.runtime}.json'
output.write_text(json.dumps({'kind': 'Limited actual service measurements, not a production benchmark', 'processes': len(results), 'samples_per_process': 2, 'measurements': results}, indent=2) + '\n')
print(f'{output}: {len(results)} independent PHP processes; no writes or external services requested.')
