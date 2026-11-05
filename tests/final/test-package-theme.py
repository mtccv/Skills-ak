#!/usr/bin/env python3
"""Packaging contracts against temporary synthetic trees; does not create a release."""

import hashlib
import importlib.util
import os
from pathlib import Path
import stat
import sys
import tempfile
import unittest
import zipfile

SCRIPT = Path(__file__).resolve().parents[2] / "scripts" / "package-theme.py"
# Keep importing the standalone tool from creating untracked repository caches.
sys.dont_write_bytecode = True
SPEC = importlib.util.spec_from_file_location("aish_packager", SCRIPT)
packager = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(packager)


class PackageThemeTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix="aish-package-test-")
        self.root = Path(self.temp.name)
        self.theme = self.root / "wp-content" / "themes" / "ai-skills-hub"
        self.theme.mkdir(parents=True)
        self.write("style.css", "/*\nTheme Name: AI Skills Hub\nVersion: 0.7.1\n*/\n")
        self.write("index.php", "<?php // QA fixture\n")
        self.write("functions.php", "<?php // QA fixture\n")
        self.write("inc/core/bootstrap.php", "<?php const VERSION = '0.7.1';\n")
        self.write("assets/dist/app.css", ".aish-container{display:block}.text-4xl{font-size:2rem}\n")
        self.write("assets/dist/app.js", "console.log('synthetic test');\n")
        self.write("assets/src/app.js", "// Editable source is retained.\n")
        (self.root / "LICENSE").write_text("Synthetic license fixture.\n", encoding="utf-8")

    def tearDown(self):
        self.temp.cleanup()

    def write(self, name, content):
        target = self.theme / name
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_text(content, encoding="utf-8")
        return target

    def test_reproducible_bytes_and_sha_ignore_source_modes_and_mtimes(self):
        first = packager.package(self.root, self.root / "first")
        for path in self.theme.rglob("*"):
            os.utime(path, (1800000001, 1800000001))
            if path.is_file():
                path.chmod(0o600)
        second = packager.package(self.root, self.root / "second")
        self.assertEqual(first["sha256"], second["sha256"])
        self.assertEqual(Path(first["archive"]).read_bytes(), Path(second["archive"]).read_bytes())
        self.assertEqual(first["sha256"], hashlib.sha256(Path(first["archive"]).read_bytes()).hexdigest())
        self.assertEqual((self.root / "first" / "SHA256SUMS").read_text(), first["sha256"] + "  ai-skills-hub-0.7.1.zip\n")
        self.assertEqual(stat.S_IMODE(Path(first["archive"]).stat().st_mode), 0o644)

    def test_single_root_resources_license_and_unix_permissions(self):
        result = packager.package(self.root, self.root / "release")
        with zipfile.ZipFile(result["archive"]) as archive:
            self.assertIsNone(archive.testzip())
            names = archive.namelist()
            self.assertEqual(names, sorted(names))
            self.assertEqual(archive.read("ai-skills-hub/LICENSE"), (self.root / "LICENSE").read_bytes())
            self.assertIn("ai-skills-hub/assets/dist/app.css", names)
            self.assertIn("ai-skills-hub/assets/src/app.js", names)
            for item in archive.infolist():
                self.assertTrue(item.filename.startswith("ai-skills-hub/"))
                self.assertNotIn("..", Path(item.filename).parts)
                self.assertEqual(item.create_system, 3)
                self.assertEqual(stat.S_IMODE(item.external_attr >> 16), 0o755 if item.is_dir() else 0o644)
                self.assertEqual(item.date_time, (1980, 1, 1, 0, 0, 0))

    def test_sensitive_dependency_cache_backup_and_unrelated_files_excluded(self):
        excluded = (
            ".env", ".env.production", ".git/config", "wp-config.php", "auth.json",
            "credentials.json", "secret-settings.php", "secrets/config.php", "keys/auth.txt",
            "node_modules/library/index.js", "vendor/autoload.php", "cache/page.html",
            "tests/probe.php", "backups/index.php", "debug.log", "dump.sql", "database.sqlite",
            "private.pem", "package-lock.json", "assets/dist/app.js.map", "style.css.bak",
        )
        for name in excluded:
            self.write(name, "do-not-package-test-marker")
        (self.root / "config.php").write_text("unrelated root file", encoding="utf-8")
        result = packager.package(self.root, self.root / "release")
        with zipfile.ZipFile(result["archive"]) as archive:
            self.assertEqual(result["files"], 8)
            for item in archive.infolist():
                self.assertNotIn(b"do-not-package-test-marker", archive.read(item))
            self.assertNotIn("ai-skills-hub/config.php", archive.namelist())

    def test_symlink_cannot_include_outside_file(self):
        outside = self.root / "outside-secret.php"
        outside.write_text("outside fixture", encoding="utf-8")
        (self.theme / "extra.php").symlink_to(outside)
        with self.assertRaisesRegex(ValueError, "regular"):
            packager.package(self.root, self.root / "release")
        self.assertFalse((self.root / "release").exists())

    def test_symlink_directory_is_not_followed(self):
        (self.theme / "external").symlink_to(self.root, target_is_directory=True)
        with self.assertRaisesRegex(ValueError, "symlinks"):
            packager.collect(self.root)

    def test_missing_assets_version_mismatch_and_unsafe_version_fail_closed(self):
        (self.theme / "assets/dist/app.js").unlink()
        with self.assertRaisesRegex(ValueError, "Missing or empty"):
            packager.collect(self.root)
        self.write("assets/dist/app.js", "fixture")
        self.write("inc/core/bootstrap.php", "<?php const VERSION = '0.7.0';")
        with self.assertRaisesRegex(ValueError, "versions must match"):
            packager.collect(self.root)
        self.write("style.css", "Version: ../../unsafe\n")
        with self.assertRaisesRegex(ValueError, "safe semantic"):
            packager.collect(self.root)

    def test_fixed_epoch_is_bounded_and_changes_explicit_metadata_only(self):
        epoch = 1762300801
        result = packager.package(self.root, self.root / "release", epoch)
        with zipfile.ZipFile(result["archive"]) as archive:
            self.assertTrue(all(item.date_time == packager.zip_time(epoch) for item in archive.infolist()))
            self.assertEqual(archive.read("ai-skills-hub/style.css"), (self.theme / "style.css").read_bytes())
        for value in ("not-a-date", "1.5", -1, 999999999999):
            with self.subTest(value=value), self.assertRaises(ValueError):
                packager.zip_time(value)

    def test_license_and_output_symlinks_are_rejected(self):
        license_path = self.root / "LICENSE"
        original = license_path.read_bytes()
        license_path.unlink()
        license_path.symlink_to(self.theme / "style.css")
        with self.assertRaisesRegex(ValueError, "LICENSE"):
            packager.collect(self.root)
        license_path.unlink()
        license_path.write_bytes(original)
        (self.root / "release").symlink_to(self.theme, target_is_directory=True)
        with self.assertRaisesRegex(ValueError, "release directory"):
            packager.package(self.root, self.root / "release")


if __name__ == "__main__":
    unittest.main(verbosity=2)
