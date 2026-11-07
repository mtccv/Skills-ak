#!/usr/bin/env python3
"""Temporary synthetic QA encryption only. Existing MU isolation still prevents real mail/API."""
import base64
import os
import pathlib
import secrets
import subprocess
import sys

if sys.argv[1:] not in (["install"], ["remove"]):
    raise SystemExit("Use install or remove for the two known isolated QA containers")
env = os.environ.copy()
for key in ("DOCKER_HOST", "DOCKER_CONTEXT", "DOCKER_TLS", "DOCKER_TLS_VERIFY", "DOCKER_CERT_PATH"):
    env.pop(key, None)
prefix = ["docker", "--host=unix:///var/run/docker.sock"]
private = pathlib.Path("/tmp/p14qa")
private.mkdir(exist_ok=True, mode=0o700)
source = private / "phase14-local-mail.php"
target = "/var/www/html/wp-content/mu-plugins/phase14-local-mail.php"
if sys.argv[1] == "install":
    if not source.exists():
        key = base64.b64encode(secrets.token_bytes(32)).decode()
        content = "<?php\n// Synthetic encryption for isolated Phase 14 QA only; SMTP remains intercepted.\n"
        content += "if (!defined('AISH_ACCOUNT_MAIL_ENABLED')) define('AISH_ACCOUNT_MAIL_ENABLED',true);\n"
        content += "if (!defined('AISH_ACCOUNT_MAIL_KEY_VERSION')) define('AISH_ACCOUNT_MAIL_KEY_VERSION','p14qa');\n"
        content += "if (!defined('AISH_ACCOUNT_MAIL_KEYS')) define('AISH_ACCOUNT_MAIL_KEYS',['p14qa'=>'" + key + "']);\n"
        source.write_text(content)
        source.chmod(0o600)
    for container in ("p11qa-wp83", "p12qa-wp84"):
        subprocess.run(prefix + ["cp", str(source), container + ":" + target], check=True, env=env, capture_output=True)
        subprocess.run(prefix + ["exec", container, "chmod", "0644", target], check=True, env=env, capture_output=True)
    print("Installed private synthetic mail crypto configuration in both isolated QA runtimes; no keys printed; external transport still intercepted.")
else:
    for container in ("p11qa-wp83", "p12qa-wp84"):
        subprocess.run(prefix + ["exec", container, "rm", "-f", target], check=True, env=env, capture_output=True)
    print("Removed only the Phase14 temporary MU mail configuration; retained WordPress data, queues and original isolation plugin.")
