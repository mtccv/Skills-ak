#!/usr/bin/env python3
"""Reconstruct the declared Git baseline with current built assets for ZIP upgrade QA."""
import argparse
import hashlib
import importlib.util
import json
from pathlib import Path
import shutil
import subprocess
import tempfile

BASELINE = "d6401c2d6fb810605f5ba87524301ecc50418971"
THEME = "wp-content/themes/ai-skills-hub/"


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--output-dir", type=Path, default=Path("/tmp/aish-final-qa/package-baseline"))
    args = parser.parse_args()
    repo = Path(__file__).resolve().parents[2]
    paths = subprocess.check_output(["git", "ls-tree", "-r", "--name-only", BASELINE, "--", THEME, "LICENSE"], cwd=repo, text=True).splitlines()
    spec = importlib.util.spec_from_file_location("qa_theme_packager", repo / "scripts/package-theme.py")
    packager = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(packager)
    with tempfile.TemporaryDirectory(prefix="aish-package-baseline-") as temporary:
        root = Path(temporary)
        for relative in paths:
            if ".." in Path(relative).parts or Path(relative).is_absolute():
                raise ValueError("Unexpected baseline source path.")
            path = root / relative
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_bytes(subprocess.check_output(["git", "show", BASELINE + ":" + relative], cwd=repo))
        assets = {}
        for name in ["app.css", "app.js"]:
            relative = THEME + "assets/dist/" + name
            path = root / relative
            path.parent.mkdir(parents=True, exist_ok=True)
            shutil.copyfile(repo / relative, path)
            assets[relative] = hashlib.sha256(path.read_bytes()).hexdigest()
        sources = {relative: (root / relative).read_bytes() == (repo / relative).read_bytes() for relative in [THEME + "assets/src/app.css", THEME + "assets/src/app.js"]}
        if not all(sources.values()):
            raise ValueError("Asset source differs from the recorded baseline; a new explicit baseline build is needed.")
        result = packager.package(root, args.output_dir)
    result.update({"baseline_commit": BASELINE, "package_type": "QA baseline reconstruction, not a historical release artifact", "assets": "Current generated dist files included; baseline and working-tree app.css/app.js source match, Final QA build config may differ.", "asset_sha256": assets, "asset_source_equal_to_baseline": sources})
    (args.output_dir / "baseline-provenance.json").write_text(json.dumps(result, indent=2) + "\n")
    print(json.dumps(result, indent=2))


if __name__ == "__main__":
    main()
