#!/usr/bin/env python3
"""Real local wp-cron.php HTTP trigger; all external HTTP is isolated by a guarded MU fixture."""
import json, os, pathlib, subprocess, sys, time, urllib.request

ROOT = pathlib.Path(__file__).resolve().parents[2]
QA = pathlib.Path('/tmp/aish-final-qa')
TARGET = QA / 'mu-plugins' / 'aish-qa-sources-http-cron.php'
FIXTURE = ROOT / 'tests/final/sources-http-cron-mu.php'
ENV = os.environ.copy()
for key in ('DOCKER_HOST', 'DOCKER_CONTEXT', 'DOCKER_TLS', 'DOCKER_TLS_VERIFY', 'DOCKER_CERT_PATH'):
    ENV.pop(key, None)
COMMAND = ['docker', '--host=unix:///var/run/docker.sock', 'exec', '-i', 'aishqa-sources', 'php', '/repo/tests/final/sources-http-cron.php']


def cli(mode):
    result = subprocess.run(COMMAND + [mode], env=ENV, capture_output=True, text=True, timeout=30)
    if result.returncode or result.stderr:
        raise RuntimeError('QA CLI failed in ' + mode + ' (credentials and raw diagnostics omitted).')
    return json.loads(result.stdout)


def trigger():
    # Explicit loopback address: no external service or authentication is contacted.
    with urllib.request.urlopen('http://127.0.0.1:18085/wp-cron.php?doing_wp_cron', timeout=15) as response:
        return {'status': response.status, 'body_bytes': len(response.read())}


cases = []
report = {'suite': 'sources-real-http-wp-cron', 'network': 'Real localhost wp-cron.php HTTP; all external HTTP Mock/blocked; AI paused; no live GitHub/Provider credentials.', 'cases': cases}
installed = False
setup_started = False


def check(name, value):
    cases.append({'name': name, 'status': 'passed' if value else 'failed'})
    if not value:
        raise AssertionError(name)


try:
    if TARGET.exists():
        raise RuntimeError('A prior temporary MU fixture exists; inspect it before running.')
    TARGET.write_bytes(FIXTURE.read_bytes())
    TARGET.chmod(0o644)
    installed = True
    setup_started = True
    setup = cli('setup')
    report.update({'run': setup['run'], 'environment': setup['environment']})
    check('both_due_events_registered_once', all(len(events) == 1 and events[0]['timestamp'] < time.time() for events in setup['events'].values()))
    first_http = trigger()
    check('real_wp_cron_http_200_empty_response', first_http == {'status': 200, 'body_bytes': 0})
    deadline = time.monotonic() + 10
    while True:
        first = cli('snapshot')
        if all(first['counts'].get(hook) == 1 for hook in ('aish_import_tick', 'aish_ai_tick')):
            break
        if time.monotonic() > deadline:
            raise AssertionError('Real Cron callbacks did not finish within the observation window.')
        time.sleep(0.1)
    report['first_http'] = first_http
    report['first_observation'] = first
    check('production_callbacks_present_in_real_http_cron_chain', all(first['counts']['contexts'][hook] == {'doing_cron': True, 'sapi': 'apache2handler', 'user_id': 0, 'production_callback_priority': 10, 'wp_cron_request': True} for hook in ('aish_import_tick', 'aish_ai_tick')))
    check('importer_advances_one_real_database_step', first['job']['status'] == 'queued' and first['job']['stage'] == 'commit' and first['job']['cursor'] == 0 and first['repository']['github_identity_bound'])
    check('queued_checkpoint_and_repository_state_consistent', first['repository']['sync_status'] == 'queued' and first['job']['active_key_retained'] and first['job']['lease_released'] and first['repository']['last_sync_commit'] == '')
    # WordPress may schedule its own update checks when Cron is initially empty.
    # The fixture refuses those requests too; rejected core checks are not egress.
    check('one_mock_metadata_request_without_extra_business_http', first['counts'].get('mock_github_requests') == 1 and set(first['counts'].get('blocked_hosts', {})).issubset({'api.wordpress.org', 'downloads.wordpress.org', 's.w.org'}))
    check('ai_pause_and_existing_attempt_history_preserved', first['ai_paused'] and first['ai_attempt_count_unchanged'])
    check('single_future_recurring_event_per_queue', all(len(events) == 1 and events[0]['interval'] == 60 and events[0]['timestamp'] > time.time() for events in first['events'].values()))
    second_http = trigger()
    second = cli('snapshot')
    report['second_http'] = second_http
    report['second_observation'] = second
    check('repeated_http_does_not_repeat_not_due_callbacks', second_http == {'status': 200, 'body_bytes': 0} and second['counts'] == first['counts'])
    check('repeated_http_preserves_queue_and_schedule', second['job'] == first['job'] and second['events'] == first['events'] and second['repository'] == first['repository'])
except Exception as error:
    report['error'] = str(error) if isinstance(error, (AssertionError, RuntimeError)) else type(error).__name__
finally:
    if setup_started:
        try:
            cleanup = cli('cleanup')
            report['cleanup'] = cleanup
            cases.append({'name': 'configuration_restored_fixture_history_retained', 'status': 'passed' if cleanup == {'restored': True, 'fixture_history_retained': True} else 'failed'})
        except Exception:
            cases.append({'name': 'configuration_restored_fixture_history_retained', 'status': 'failed'})
    if installed and TARGET.exists() and TARGET.read_bytes() == FIXTURE.read_bytes():
        TARGET.unlink()
    report['case_count'] = len(cases)
    report['assertions'] = len(cases)
    report['failed'] = sum(case['status'] == 'failed' for case in cases) + (1 if report.get('error') and not any(case['status'] == 'failed' for case in cases) else 0)
    report['generated_at'] = time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime())
    report['boundaries'] = ['This verifies actual HTTP wp-cron.php dispatch, not wall-clock schedule precision, low-traffic scheduling or load capacity.', 'AI callback remains paused; no Provider request is issued.', 'The Importer only performs one mocked metadata step; this does not verify live GitHub API.', 'Original Cron/AI-pause/source-sync settings are restored; only this fixture repository is archived and its queued task/history retained.']
    print(json.dumps(report, indent=2))
    sys.exit(1 if report['failed'] else 0)
