#!/usr/bin/env python3
"""
Bulk-update local asset URLs with a deploy version query parameter.

Usage:
  python bump_asset_version.py --version 202605161430
  python bump_asset_version.py --root .
"""

from __future__ import annotations

import argparse
import datetime as dt
import re
from pathlib import Path
from urllib.parse import parse_qsl, urlencode, urlsplit, urlunsplit

ASSET_EXTENSIONS = {
    "css",
    "js",
    "mjs",
    "png",
    "jpg",
    "jpeg",
    "gif",
    "webp",
    "svg",
    "ico",
    "woff",
    "woff2",
    "ttf",
    "eot",
    "webmanifest",
    "json",
    "pdf",
}

VERSION_KEYS = {"v", "ver", "rev", "version", "hash"}
TARGET_FILE_EXTENSIONS = {".html", ".htm", ".php", ".css"}

ATTR_URL_PATTERN = re.compile(
    r"(?P<prefix>\b(?:href|src|poster|content)=['\"])(?P<url>[^'\"]+)(?P<suffix>['\"])",
    re.IGNORECASE,
)
SRCSET_PATTERN = re.compile(
    r"(?P<prefix>\bsrcset=['\"])(?P<value>[^'\"]+)(?P<suffix>['\"])",
    re.IGNORECASE,
)
CSS_URL_PATTERN = re.compile(
    r"url\((?P<quote>['\"]?)(?P<url>[^)'\"]+)(?P=quote)\)",
    re.IGNORECASE,
)


def has_asset_extension(url_path: str) -> bool:
    lowered = url_path.lower()
    return any(lowered.endswith(f".{ext}") for ext in ASSET_EXTENSIONS)


def is_local_asset_url(url: str) -> bool:
    candidate = url.strip()
    if not candidate:
        return False

    lowered = candidate.lower()
    if lowered.startswith(("#", "data:", "javascript:", "mailto:", "tel:", "blob:", "about:")):
        return False

    if candidate.startswith("//"):
        return False

    parsed = urlsplit(candidate)
    if parsed.scheme in {"http", "https"}:
        return False

    return has_asset_extension(parsed.path)


def with_version(url: str, version: str) -> str:
    parsed = urlsplit(url)
    pairs = parse_qsl(parsed.query, keep_blank_values=True)
    kept = [(k, v) for (k, v) in pairs if k.lower() not in VERSION_KEYS]
    kept.append(("v", version))
    query = urlencode(kept, doseq=True)
    return urlunsplit((parsed.scheme, parsed.netloc, parsed.path, query, parsed.fragment))


def version_srcset(value: str, version: str) -> str:
    chunks = [chunk.strip() for chunk in value.split(",") if chunk.strip()]
    updated_chunks = []

    for chunk in chunks:
        parts = chunk.split()
        if not parts:
            continue
        if is_local_asset_url(parts[0]):
            parts[0] = with_version(parts[0], version)
        updated_chunks.append(" ".join(parts))

    return ", ".join(updated_chunks)


def transform_text(text: str, version: str) -> tuple[str, int]:
    replacements = 0

    def replace_attr(match: re.Match[str]) -> str:
        nonlocal replacements
        url = match.group("url")
        if is_local_asset_url(url):
            replacements += 1
            url = with_version(url, version)
        return f"{match.group('prefix')}{url}{match.group('suffix')}"

    def replace_srcset(match: re.Match[str]) -> str:
        nonlocal replacements
        value = match.group("value")
        new_value = version_srcset(value, version)
        if new_value != value:
            replacements += 1
        return f"{match.group('prefix')}{new_value}{match.group('suffix')}"

    def replace_css_url(match: re.Match[str]) -> str:
        nonlocal replacements
        url = match.group("url")
        if is_local_asset_url(url):
            replacements += 1
            url = with_version(url, version)
        quote = match.group("quote")
        return f"url({quote}{url}{quote})"

    text = ATTR_URL_PATTERN.sub(replace_attr, text)
    text = SRCSET_PATTERN.sub(replace_srcset, text)
    text = CSS_URL_PATTERN.sub(replace_css_url, text)
    return text, replacements


def main() -> int:
    parser = argparse.ArgumentParser(description="Version local static asset URLs for cache busting.")
    parser.add_argument(
        "--root",
        type=Path,
        default=Path("."),
        help="Project root to scan (default: current directory).",
    )
    parser.add_argument(
        "--version",
        default=None,
        help="Version token to apply (default: current UTC timestamp YYYYMMDDHHMM).",
    )
    args = parser.parse_args()

    root = args.root.resolve()
    version = (args.version or dt.datetime.now(dt.UTC).strftime("%Y%m%d%H%M")).strip()
    if not version:
        raise SystemExit("Version cannot be empty.")

    files_changed = 0
    replacements = 0

    for path in root.rglob("*"):
        if not path.is_file():
            continue
        if path.suffix.lower() not in TARGET_FILE_EXTENSIONS:
            continue
        if ".git" in path.parts:
            continue

        original_bytes = path.read_bytes()
        try:
            original_text = original_bytes.decode("utf-8")
        except UnicodeDecodeError:
            continue

        updated_text, file_replacements = transform_text(original_text, version)
        if updated_text == original_text:
            continue

        path.write_bytes(updated_text.encode("utf-8"))
        files_changed += 1
        replacements += file_replacements

    print(f"version={version} files_changed={files_changed} replacements={replacements}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
