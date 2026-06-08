#!/usr/bin/env python3
"""Build a distributable ZIP for the mod_exelearning Moodle plugin.

Cross-platform packager invoked by ``make package`` (see Makefile). It mirrors
the previous ``rsync --exclude-from=.distignore`` + ``zip`` pipeline but needs
nothing beyond the Python standard library, so it works on Linux, macOS and
Windows (Git Bash / cmd) alike.

Usage::

    python scripts/package.py <RELEASE> [<PLUGIN_NAME>]

The archive contains every tracked file (minus the ``.distignore`` patterns)
under a single top-level folder named ``exelearning`` -- the Moodle install
directory for component ``mod_exelearning``. The output file is
``<PLUGIN_NAME>-<RELEASE>.zip`` in the current working directory.

``version.php`` is expected to already carry the release/version values; the
Makefile rewrites it with ``sed`` before calling this script and restores the
dev sentinels afterwards.
"""

import fnmatch
import os
import sys
import zipfile

# Moodle install folder for component mod_exelearning (NOT "exeweb").
INSTALL_DIR = "exelearning"
DISTIGNORE = ".distignore"


def load_patterns(root):
    """Return the list of .distignore patterns (trailing '/' stripped)."""
    patterns = []
    path = os.path.join(root, DISTIGNORE)
    if not os.path.isfile(path):
        return patterns
    with open(path, encoding="utf-8") as handle:
        for raw in handle:
            line = raw.strip()
            if not line or line.startswith("#"):
                continue
            patterns.append(line.rstrip("/"))
    return patterns


def is_ignored(rel_posix, patterns):
    """True if any path component of rel_posix matches any .distignore pattern.

    This unified component-wise match covers every pattern style currently in
    .distignore: exact names (``.git``, ``Makefile``), globs (``.aider*``,
    ``mod_exelearning-*.zip``) and directory names (``vendor/``, ``scripts/``).
    """
    parts = rel_posix.split("/")
    for pattern in patterns:
        for part in parts:
            if fnmatch.fnmatch(part, pattern):
                return True
    return False


def collect_files(root, patterns):
    """Yield (absolute_path, posix_relpath) for every file to ship."""
    for dirpath, dirnames, filenames in os.walk(root):
        rel_dir = os.path.relpath(dirpath, root)
        rel_dir = "" if rel_dir == "." else rel_dir.replace(os.sep, "/")
        # Prune ignored directories so we don't descend into them.
        dirnames[:] = [
            d for d in dirnames
            if not is_ignored(f"{rel_dir}/{d}".lstrip("/"), patterns)
        ]
        for name in filenames:
            rel = f"{rel_dir}/{name}".lstrip("/")
            if is_ignored(rel, patterns):
                continue
            yield os.path.join(dirpath, name), rel


def main(argv):
    if len(argv) < 2 or not argv[1].strip():
        sys.stderr.write(
            "Error: RELEASE not specified. "
            "Usage: python scripts/package.py <RELEASE> [<PLUGIN_NAME>]\n"
        )
        return 1

    # Defensively trim whitespace, CR and a stray trailing dot (see issue #22).
    release = argv[1].strip().rstrip(".")
    plugin_name = argv[2].strip() if len(argv) > 2 and argv[2].strip() else "mod_exelearning"

    root = os.getcwd()
    patterns = load_patterns(root)
    output = os.path.join(root, f"{plugin_name}-{release}.zip")

    if os.path.exists(output):
        os.remove(output)

    count = 0
    with zipfile.ZipFile(output, "w", zipfile.ZIP_DEFLATED) as zf:
        for abs_path, rel in collect_files(root, patterns):
            zf.write(abs_path, f"{INSTALL_DIR}/{rel}")
            count += 1

    if count == 0:
        os.remove(output)
        sys.stderr.write("Error: no files matched for packaging.\n")
        return 1

    print(f"Created {os.path.basename(output)} ({count} files under {INSTALL_DIR}/)")
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv))
