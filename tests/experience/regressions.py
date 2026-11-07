#!/usr/bin/env python3
"""Selected pre-existing contracts on Phase 14; never overwrite historical evidence."""
import json
import os
import pathlib
import subprocess
import sys

root = pathlib.Path(__file__).resolve().parents[2]
version = sys.argv[1] if len(sys.argv) > 1 else "83"
if version not in ("83", "84"):
    raise SystemExit("isolated QA runtime only")
container, php = ("p11qa-wp83", "php") if version == "83" else ("p12qa-wp84", "php84")
env = os.environ.copy()
for key in ("DOCKER_HOST", "DOCKER_CONTEXT", "DOCKER_TLS", "DOCKER_TLS_VERIFY", "DOCKER_CERT_PATH"):
    env.pop(key, None)
suites = [("operations", 12, name) for name in ("users", "notifications-mail", "workbench-home", "analytics", "transactions")]
suites += [("commercial", 13, name) for name in ("sponsors", "enterprise", "entitlements-bundles", "privacy-transactions")]
failures = 0
for directory, phase, suite in suites:
    result = subprocess.run(["docker", "--host=unix:///var/run/docker.sock", "exec", "-e", f"AISH_PHASE{phase}_QA=1", container, php, f"/repo/tests/{directory}/{suite}.php"], env=env, capture_output=True, text=True, timeout=120)
    try:
        report = json.loads(result.stdout)
    except Exception:
        private = pathlib.Path("/tmp/p14qa")
        private.mkdir(exist_ok=True, mode=0o700)
        log = private / f"{directory}-{suite}-{version}-regression.log"
        log.write_text(result.stdout + "\n" + result.stderr)
        log.chmod(0o600)
        print("Harness failure; private local diagnostic:", str(log))
        failures += 1
        continue
    (root / "tests/experience/results" / f"regression-{directory}-{suite}-php{version}.json").write_text(json.dumps(report, indent=2, ensure_ascii=False) + "\n")
    print(version, directory, suite, report["passed"], report["failed"], flush=True)
    failures += int(result.returncode != 0 or report["failed"] != 0)
raise SystemExit(bool(failures))
