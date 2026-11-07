#!/usr/bin/env python3
"""Real independent PHP/MySQL workers; no mock database, external API or credentials."""
import concurrent.futures
import json
import os
import pathlib
import subprocess
import sys

version = sys.argv[1] if len(sys.argv) > 1 else "83"
if version not in ("83", "84"):
    raise SystemExit("isolated QA runtime only")
container, php = ("p11qa-wp83", "php") if version == "83" else ("p12qa-wp84", "php84")
env = os.environ.copy()
for key in ("DOCKER_HOST", "DOCKER_CONTEXT", "DOCKER_TLS", "DOCKER_TLS_VERIFY", "DOCKER_CERT_PATH"):
    env.pop(key, None)
command = ["docker", "--host=unix:///var/run/docker.sock", "exec", "-i", "-e", "AISH_PHASE14_QA=1", container, php, "/repo/tests/experience/concurrency-worker.php"]
cases = []

def call(mode, **data):
    result = subprocess.run(command, input=json.dumps(dict(mode=mode, **data)), text=True, capture_output=True, env=env, timeout=30)
    try:
        payload = json.loads(result.stdout)
    except ValueError:
        raise RuntimeError("Worker returned non-JSON output; inspect local runtime diagnostics, never expose credentials") from None
    return result.returncode, payload

def check(name, fn):
    try:
        fn()
        cases.append(dict(id=name, status="passed"))
    except Exception as error:
        cases.append(dict(id=name, status="failed", reason=str(error)[:250]))

def onboarding():
    assert call("setup") == (0, {"created": True})
    steps = ["welcome", "registration", "verified", "search", "favorite", "review", "submission", "account"]
    with concurrent.futures.ThreadPoolExecutor(max_workers=8) as pool:
        results = list(pool.map(lambda step: call("complete", step=step), steps))
    for code, result in results:
        assert code == 0, "All bounded concurrent writes must complete: " + str(result)
    with concurrent.futures.ThreadPoolExecutor(max_workers=8) as pool:
        repeats = list(pool.map(lambda _: call("complete", step="welcome"), range(8)))
    assert all(code == 0 for code, _ in repeats), "Replay writes complete without storage error"
    code, state = call("state")
    assert code == 0 and state == dict(state=dict(version=1, completed=steps), rows=1, verified=True), "Concurrent merge preserves all eight flags in exactly one row and never changes verification"

def imports():
    with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
        results = list(pool.map(lambda _: call("import"), range(2)))
    assert any(code == 0 for code, _ in results), "At least one repeated import completes"
    assert all((code == 0 and result == dict(created=0, existing=42)) or (code == 1 and result.get("error") == "busy") for code, result in results), "Concurrent native import either reuses 42 rows or returns bounded busy"
    assert call("import") == (0, dict(created=0, existing=42)), "Safe retry creates no duplicate content"

check("eight_parallel_onboarding_merges_and_replays_one_private_state", onboarding)
check("concurrent_repeat_native_help_import_is_idempotent_and_retryable", imports)
report = dict(kind="Real separate PHP/MySQL processes against retained isolated WordPress; actual advisory-lock contention, no database Mock", php_runtime=version, cases=cases, passed=sum(c["status"] == "passed" for c in cases), failed=sum(c["status"] != "passed" for c in cases))
out = pathlib.Path(__file__).parent / "results" / f"concurrency-php{version}.json"
out.write_text(json.dumps(report, indent=2) + "\n")
print(json.dumps(report))
raise SystemExit(bool(report["failed"]))
