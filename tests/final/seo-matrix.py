#!/usr/bin/env python3
"""Run real disposable WordPress plugin transitions; never provisions or contacts a production site."""
import argparse
import json
import subprocess
import sys
from pathlib import Path

parser = argparse.ArgumentParser()
parser.add_argument('--controller', required=True, help='QA Docker controller providing exec/wp modes for the seo site')
parser.add_argument('--fixture', required=True)
parser.add_argument('--output-dir', required=True)
args = parser.parse_args()
out = Path(args.output_dir)
out.mkdir(parents=True, exist_ok=True)
checks = []
controller = [sys.executable, args.controller]
http = [sys.executable, str(Path(__file__).with_name('seo-http.py')), '--fixture', args.fixture]


def command(arguments):
    result = subprocess.run(arguments, text=True, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, timeout=90)
    if result.returncode:
        raise RuntimeError('QA command failed: ' + result.stdout[-1500:])
    return result.stdout


def control(action):
    output = command(controller + ['exec', 'seo', 'env', 'AISH_FINAL_QA=1', 'php', '/repo/tests/final/seo-control.php', action])
    record = json.loads(output)
    if not record.get('ok'):
        raise RuntimeError('QA control failed: ' + output)
    checks.append({'control': record})


def verify(plugin, state, crawl=False, label=None):
    output = out / ('seo-' + plugin + '-' + (label or state) + '.json')
    mode = plugin if state == 'final' else state
    result = subprocess.run(http + ['--mode', mode, '--output', str(output)] + (['--crawl'] if crawl else []), text=True, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, timeout=240)
    output.with_suffix('.log').write_text(result.stdout)
    if not output.exists():
        raise RuntimeError('HTTP suite did not produce evidence: ' + result.stdout[-1500:])
    evidence = json.loads(output.read_text())
    checks.append({'plugin_mode': plugin, 'state': state, 'counts': evidence['counts'], 'evidence': output.name})
    print(json.dumps(checks[-1]), flush=True)


# Each fixture transition uses the real management/WordPress API. No cache flush on state changes.
for plugin in ('yoast', 'rankmath', 'both', 'none'):
    control('site-public')
    control('default-zh')
    control('plugins-' + plugin)
    command(controller + ['wp', 'seo', 'rewrite', 'flush', '--hard'])
    verify(plugin, 'final', crawl=True)
    control('site-private')
    verify(plugin, 'private')
    control('site-public')
    control('pair-edit-withdraw')
    verify(plugin, 'withdrawn')
    control('pair-restore')
    verify(plugin, 'restored')
    control('pair-archive')
    verify(plugin, 'archived')
    control('pair-restore')
    verify(plugin, 'restored', label='restored-after-archive')
    control('default-en')
    verify(plugin, 'default-en')
    control('default-zh')
summary = {'suite': 'real-seo-plugin-transitions', 'transitions': checks, 'failed': sum(item.get('counts', {}).get('failed', 0) for item in checks), 'http_requests': sum(item.get('counts', {}).get('http_requests', 0) for item in checks), 'assertions': sum(item.get('counts', {}).get('cases', 0) for item in checks)}
(out / 'seo-matrix.json').write_text(json.dumps(summary, ensure_ascii=False, indent=2) + '\n')
print(json.dumps({k: v for k, v in summary.items() if k != 'transitions'}), flush=True)
sys.exit(1 if summary['failed'] else 0)
