#!/usr/bin/env python3
"""Validate exact package QA artifacts and publish sanitized release acceptance evidence."""
import argparse
from datetime import datetime, timezone
import hashlib
import json
from pathlib import Path
import zipfile


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--artifact-dir", type=Path, default=Path("/tmp/aish-final-qa"))
    parser.add_argument("--expected-sha256", required=True)
    args = parser.parse_args()
    repo = Path(__file__).resolve().parents[2]
    checks = []

    def check(label, condition):
        checks.append({"id": label, "passed": bool(condition)})

    def read(name):
        return json.loads((args.artifact_dir / name).read_text())

    baseline = read("package-state-baseline.json")
    before = read("package-state-before-final.json")
    provenance = read("package-baseline/baseline-provenance.json")
    upgrade = read("package-upgrade.json")
    states = {stage: read("package-state-" + stage + ".json") for stage in ["upgraded", "reinstalled", "inactive", "reactivated"]}
    http = {stage: read("package-http-" + stage + ".json") for stage in ["baseline", "upgraded", "reinstalled", "reactivated"]}
    archive = repo / "release/ai-skills-hub-0.7.1.zip"
    checksum = hashlib.sha256(archive.read_bytes()).hexdigest()
    with zipfile.ZipFile(archive) as package:
        manifest = {name.removeprefix("ai-skills-hub/"): hashlib.sha256(package.read(name)).hexdigest() for name in package.namelist() if not name.endswith("/")}
    with zipfile.ZipFile(args.artifact_dir / "package-baseline/ai-skills-hub-0.7.0.zip") as package:
        baseline_manifest = {name.removeprefix("ai-skills-hub/"): hashlib.sha256(package.read(name)).hexdigest() for name in package.namelist() if not name.endswith("/")}
    check("exact_final_zip_sha256", checksum == args.expected_sha256 == upgrade["archive_sha256"])
    check("native_upgrade_from_070_to_071", upgrade["method"] == "Theme_Upgrader::upgrade" and upgrade["status"] == "passed" and upgrade["previous_version"] == "0.7.0" and upgrade["installed_version"] == "0.7.1")
    check("baseline_fourteen_nonempty_tables", len(baseline["tables"]) == 14 and all(row["rows"] > 0 for row in baseline["tables"].values()))
    check("baseline_exact_installed_zip", before["theme_version"] == "0.7.0" and before["core_version"] == "0.7.0" and before["theme_files"] == baseline_manifest)
    check("baseline_preserved_before_final", before["tables"] == baseline["tables"] and before["fixture"] == baseline["fixture"])
    for stage, state in states.items():
        check(stage + "_all_table_fingerprints_preserved", state["tables"] == baseline["tables"])
        check(stage + "_stable_identifiers_preserved", state["fixture"] == baseline["fixture"])
        check(stage + "_schema_retained", state["schema_version"] == "1.5.0")
        check(stage + "_exact_final_installed_files", state["theme_files"] == manifest and state["theme_version"] == "0.7.1")
        check(stage + "_no_external_http", state["outbound_http_requests"] == 0)
        if stage == "inactive":
            check("inactive_core_unloaded_and_cron_cleared", not state["core_loaded"] and state["core_version"] is None and not any(state["hooks"].values()))
        else:
            check(stage + "_core_and_cron_restored", state["core_loaded"] and state["core_version"] == "0.7.1" and all(state["hooks"].values()))
    for stage, row in http.items():
        check(stage + "_ten_real_http_checks", row["passed"] == 10 and row["failed"] == 0)
    result = {
        "suite": "real_zip_install_upgrade_lifecycle", "kind": "real_wordpress_mysql_filesystem_http", "verified_at": datetime.now(timezone.utc).isoformat(),
        "environment": {key: states["reactivated"][key] for key in ["php", "wordpress", "mysql", "database"]},
        "baseline_provenance": provenance,
        "final_archive": {"file": "release/ai-skills-hub-0.7.1.zip", "sha256": checksum, "bytes": archive.stat().st_size, "files": len(manifest)},
        "native_upgrade": upgrade, "fixture": baseline["fixture"], "baseline_table_fingerprints": baseline["tables"],
        "checks": checks, "passed": sum(row["passed"] for row in checks), "failed": sum(not row["passed"] for row in checks),
        "http": http,
        "harness_corrections": [
            "Static Alpine Warning string originally matched the diagnostic regex; asset diagnostic matching corrected and all HTTP stages rerun.",
            "Initial native upgrade remained at 0.7.0 because root-owned CLI installation selected a non-direct filesystem method. Only dedicated QA theme/scratch ownership was corrected; final runs use www-data and native direct selection without forcing the method.",
            "Native Theme_Upgrader::upgrade success contract is true, not the array returned by bulk/run; test assertion corrected before final acceptance.",
        ],
        "production_findings": [],
        "limits": ["Baseline 0.7.0 was reconstructed from the declared commit with current built assets; it is not a historical original distribution.", "Uses native WordPress Theme_Upgrader and WP-CLI; this is not the browser Upload Theme UI test.", "No GitHub/AI external API calls or unknown script execution. AI history records are explicit database-only fixtures.", "The fourteen-table schema remains 1.5.0 in this theme upgrade; historical schema migration cases are separately covered by the data suite."],
    }
    output = repo / "tests/final/results/package-results.json"
    output.write_text(json.dumps(result, indent=2, ensure_ascii=False) + "\n")
    (args.artifact_dir / "package-results.json").write_text(output.read_text())
    print(json.dumps({"passed": result["passed"], "failed": result["failed"], "final_sha256": checksum, "report": str(output)}))
    return 1 if result["failed"] else 0


if __name__ == "__main__":
    raise SystemExit(main())
