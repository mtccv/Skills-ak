#!/usr/bin/env python3
"""HTTP smoke against the fixed disposable ZIP QA site; never save response bodies."""
import argparse
from datetime import datetime, timezone
import json
from pathlib import Path
import re
import urllib.error
import urllib.parse
import urllib.request

BASE = "http://127.0.0.1:18090"


class LocalRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, request, fp, code, message, headers, newurl):
        if urllib.parse.urlsplit(newurl).netloc != "127.0.0.1:18090":
            raise RuntimeError("QA redirect left the dedicated package site.")
        return super().redirect_request(request, fp, code, message, headers, newurl)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("stage", choices=["baseline", "upgraded", "reinstalled", "reactivated"])
    parser.add_argument("--output-dir", type=Path, default=Path("/tmp/aish-final-qa"))
    args = parser.parse_args()
    opener = urllib.request.build_opener(LocalRedirect())
    checks = []
    cases = [
        ("/zh/", 200, ""), ("/en/", 200, ""), ("/en/skills/", 200, ""),
        ("/zh/skills/1/package-retained/", 200, "ZIP 人工中文保留"),
        ("/en/skills/1/package-retained/", 200, "ZIP retained human English"),
        ("/en/skills/2/package-private-draft/", 404, ""),
        ("/aish-sitemap.xml", 200, ""), ("/robots.txt", 200, ""),
        ("/wp-content/themes/ai-skills-hub/assets/dist/app.css", 200, ".aish-container"),
        ("/wp-content/themes/ai-skills-hub/assets/dist/app.js", 200, ""),
    ]
    for path, expected, marker in cases:
        request = urllib.request.Request(BASE + path, headers={"User-Agent": "AI-Skills-Hub-Final-QA"})
        try:
            response = opener.open(request, timeout=20)
        except urllib.error.HTTPError as error:
            response = error
        with response:
            body = response.read(2_000_000).decode("utf-8", errors="replace")
            content_type = response.headers.get("Content-Type", "")
            # Alpine's static JavaScript contains a legitimate "Warning:" string.
            # Diagnose PHP errors in assets without misclassifying library messages.
            pattern = r"(?i)(fatal error|uncaught (?:error|exception)|warning:|deprecated:|notice:)"
            if "javascript" in content_type or "text/css" in content_type:
                pattern = r"(?i)(<b>(?:Fatal error|Warning|Deprecated|Notice)</b>:|PHP (?:Fatal error|Warning|Deprecated|Notice):)"
            diagnostics = bool(re.search(pattern, body))
            leaked = any(value in body for value in ["ZIP_PRIVATE_DRAFT_SENTINEL", "Private QA human-edited generation draft", "Private QA generation draft", "This is a database fixture, not an executed Skill."])
            checks.append({"path": path, "status": response.code, "expected_status": expected, "content_type": response.headers.get("Content-Type"), "php_diagnostics": diagnostics, "private_fixture_leaked": leaked, "required_marker_present": not marker or marker in body, "passed": response.code == expected and not diagnostics and not leaked and (not marker or marker in body)})
    result = {"suite": "installed_zip_http", "stage": args.stage, "kind": "real_http", "time": datetime.now(timezone.utc).isoformat(), "base": BASE, "checks": checks, "passed": sum(row["passed"] for row in checks), "failed": sum(not row["passed"] for row in checks), "limits": ["Availability, curated markers, private fixture exclusion and asset delivery; not a replacement for full browser/SEO acceptance."]}
    args.output_dir.mkdir(parents=True, exist_ok=True)
    (args.output_dir / ("package-http-" + args.stage + ".json")).write_text(json.dumps(result, indent=2, ensure_ascii=False) + "\n")
    print(json.dumps({"stage": args.stage, "passed": result["passed"], "failed": result["failed"]}))
    return 1 if result["failed"] else 0


if __name__ == "__main__":
    raise SystemExit(main())
