#!/usr/bin/env python3
"""Create a deterministic, installable theme ZIP using only the Python standard library."""

import argparse
from datetime import datetime, timezone
import hashlib
import os
from pathlib import Path
import re
import stat
import sys
import tempfile
import zipfile

SLUG = "ai-skills-hub"
DEFAULT_EPOCH = 315532800  # 1980-01-01 UTC, the earliest ZIP timestamp.
EXCLUDED_DIRS = {
    "node_modules", "vendor", "uploads", "cache", "caches", "tmp", "temp",
    "logs", "tests", "coverage", "backup", "backups", "release", "releases",
    "secrets", "credentials", "private", "keys",
}
EXCLUDED_NAMES = {
    "wp-config.php", "auth.json", "credentials.json", "secrets.json",
    "package.json", "package-lock.json", "composer.json", "composer.lock",
    "id_rsa", "id_ed25519", "sha256sums",
}
ALLOWED_SUFFIXES = {
    ".php", ".css", ".js", ".svg", ".png", ".jpg", ".jpeg", ".gif",
    ".webp", ".ico", ".woff", ".woff2", ".ttf", ".otf", ".eot",
    ".po", ".mo", ".pot", ".json", ".xml", ".txt", ".md",
}
REQUIRED = (
    "style.css", "index.php", "functions.php", "inc/core/bootstrap.php",
    "assets/dist/app.css", "assets/dist/app.js",
)


def safe_name(relative: Path) -> bool:
    """Reject hidden, credential/backup and dependency paths before reading bytes."""
    if any(part.startswith(".") or part.lower() in EXCLUDED_DIRS for part in relative.parts):
        return False
    name = relative.name.lower()
    if name in EXCLUDED_NAMES or name.endswith("~"):
        return False
    if re.search(r"(?:^|[._-])(?:env|secret|secrets|credential|credentials|password|private-key)(?:[._-]|$)", name):
        return False
    return name == "license" or relative.suffix.lower() in ALLOWED_SUFFIXES


def zip_time(epoch: str | int | None = None) -> tuple[int, ...]:
    try:
        value = int(str(DEFAULT_EPOCH if epoch is None else epoch))
        instant = datetime.fromtimestamp(value, timezone.utc)
    except (ValueError, OverflowError, OSError) as error:
        raise ValueError("SOURCE_DATE_EPOCH must be a valid whole Unix timestamp.") from error
    if not 1980 <= instant.year <= 2107:
        raise ValueError("SOURCE_DATE_EPOCH must be within the ZIP date range 1980–2107.")
    return (instant.year, instant.month, instant.day, instant.hour, instant.minute, instant.second // 2 * 2)


def collect(repo_root: Path) -> tuple[str, dict[str, bytes]]:
    theme = repo_root / "wp-content" / "themes" / SLUG
    if not theme.is_dir() or theme.is_symlink():
        raise ValueError("Expected a real ai-skills-hub theme directory.")
    files: dict[str, bytes] = {}
    for directory, dirs, names in os.walk(theme, followlinks=False):
        parent = Path(directory)
        kept = []
        for name in sorted(dirs):
            candidate = parent / name
            if name.startswith(".") or name.lower() in EXCLUDED_DIRS:
                continue
            if candidate.is_symlink():
                raise ValueError("Theme symlinks are not accepted: " + candidate.relative_to(theme).as_posix())
            kept.append(name)
        dirs[:] = kept
        for name in sorted(names):
            path = parent / name
            relative = path.relative_to(theme)
            if not safe_name(relative):
                continue
            if "\\" in relative.as_posix() or any(part in ("", ".", "..") for part in relative.parts):
                raise ValueError("Invalid archive path.")
            if not stat.S_ISREG(path.lstat().st_mode):
                raise ValueError("Only regular theme files are accepted: " + relative.as_posix())
            files[relative.as_posix()] = path.read_bytes()
    for name in REQUIRED:
        if not files.get(name):
            raise ValueError("Missing or empty required theme file: " + name + "; run npm ci && npm run build first.")
    style = files["style.css"][:8192].decode("utf-8")
    match = re.search(r"^Version:\s*([0-9]+\.[0-9]+\.[0-9]+(?:-[A-Za-z0-9.-]+)?)\s*$", style, re.MULTILINE)
    if not match:
        raise ValueError("style.css must declare one safe semantic Version header.")
    version = match.group(1)
    core = files["inc/core/bootstrap.php"].decode("utf-8")
    core_match = re.search(r"\bconst\s+VERSION\s*=\s*(['\"])([^'\"]+)\1\s*;", core)
    if not core_match or core_match.group(2) != version:
        raise ValueError("Theme and Core versions must match before packaging.")
    css = files["assets/dist/app.css"]
    if b".aish-container" not in css or b".text-4xl" not in css:
        raise ValueError("Built CSS is missing the current design-system/template utilities.")
    license_path = repo_root / "LICENSE"
    if license_path.is_symlink() or not license_path.is_file():
        raise ValueError("A regular repository-root LICENSE is required.")
    license_bytes = license_path.read_bytes()
    if not license_bytes.strip():
        raise ValueError("Repository LICENSE cannot be empty.")
    if "LICENSE" in files and files["LICENSE"] != license_bytes:
        raise ValueError("Theme LICENSE conflicts with repository LICENSE.")
    files["LICENSE"] = license_bytes
    return version, files


def write_archive(destination: Path, files: dict[str, bytes], timestamp: tuple[int, ...]) -> None:
    directories = {SLUG + "/"}
    for name in files:
        for parent in Path(name).parents:
            if parent != Path("."):
                directories.add(SLUG + "/" + parent.as_posix() + "/")
    entries = {SLUG + "/" + name: data for name, data in files.items()}
    entries.update({name: b"" for name in directories})
    # ZIP_STORED avoids zlib-version-dependent output; the theme is small enough
    # that reproducibility is preferable to variable compression output.
    with zipfile.ZipFile(destination, "w", compression=zipfile.ZIP_STORED) as archive:
        for name, data in sorted(entries.items()):
            is_directory = name.endswith("/")
            info = zipfile.ZipInfo(name, timestamp)
            info.create_system = 3
            info.external_attr = ((stat.S_IFDIR | 0o755) if is_directory else (stat.S_IFREG | 0o644)) << 16
            if is_directory:
                info.external_attr |= 0x10
            info.compress_type = zipfile.ZIP_STORED
            archive.writestr(info, data)


def package(repo_root: Path, output_dir: Path, epoch: str | int | None = None) -> dict[str, str | int]:
    version, files = collect(repo_root)
    timestamp = zip_time(epoch)
    if output_dir.is_symlink():
        raise ValueError("The release directory must not be a symlink.")
    output_dir.mkdir(parents=True, exist_ok=True)
    archive_path = output_dir / (SLUG + "-" + version + ".zip")
    temp_path = None
    manifest_temp = None
    try:
        with tempfile.NamedTemporaryFile(dir=output_dir, prefix=".package-", delete=False) as stream:
            temp_path = Path(stream.name)
        write_archive(temp_path, files, timestamp)
        checksum = hashlib.sha256(temp_path.read_bytes()).hexdigest()
        os.chmod(temp_path, 0o644)
        os.replace(temp_path, archive_path)
        with tempfile.NamedTemporaryFile(mode="w", encoding="ascii", newline="\n", dir=output_dir, prefix=".checksum-", delete=False) as stream:
            manifest_temp = Path(stream.name)
            stream.write(checksum + "  " + archive_path.name + "\n")
        os.chmod(manifest_temp, 0o644)
        os.replace(manifest_temp, output_dir / "SHA256SUMS")
    finally:
        for candidate in (temp_path, manifest_temp):
            if candidate is not None:
                candidate.unlink(missing_ok=True)
    return {"version": version, "files": len(files), "archive": str(archive_path), "sha256": checksum, "bytes": archive_path.stat().st_size}


def main() -> int:
    root = Path(__file__).resolve().parent.parent
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--output-dir", type=Path, default=root / "release", help="Output directory (default: repository release/).")
    parser.add_argument("--check", action="store_true", help="Validate package inputs without creating a ZIP or checksum file.")
    args = parser.parse_args()
    try:
        epoch = os.getenv("SOURCE_DATE_EPOCH")
        if args.check:
            version, files = collect(root)
            zip_time(epoch)
            print(f"Package inputs valid: {SLUG} {version}; {len(files)} files. No archive created.")
        else:
            result = package(root, args.output_dir, epoch)
            print(f"{result['archive']} ({result['files']} files, {result['bytes']} bytes)")
            print(f"SHA256: {result['sha256']}")
        return 0
    except (OSError, UnicodeError, ValueError, zipfile.BadZipFile) as error:
        print("Packaging failed: " + str(error), file=sys.stderr)
        return 1


if __name__ == "__main__":
    sys.exit(main())
