#!/usr/bin/env python3
"""
Upload staged profile pictures to fearless-wonder's Railway volume.
Fixes:
  - Uses PowerShell to invoke railway.ps1 (since it's an npm .ps1 wrapper)
  - Targets the correct volume by ID
  - Uploads the /uploads/ directory in one shot
"""

import os
import sys
import io
import subprocess

if sys.platform == "win32":
    sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding="utf-8", errors="replace")

SCRIPT_DIR = os.path.dirname(os.path.abspath(__file__))
STAGE_DIR  = os.path.join(SCRIPT_DIR, "exports", "uploads_stage")

# ─── Check staged files ────────────────────────────────────────────────────────
files = [f for f in os.listdir(STAGE_DIR) if os.path.isfile(os.path.join(STAGE_DIR, f))]
print("=" * 65)
print("  Upload profile pictures → fearless-wonder volume")
print("=" * 65)
print(f"  Stage folder : {STAGE_DIR}")
print(f"  Files ready  : {len(files)}")
for f in files:
    size_kb = os.path.getsize(os.path.join(STAGE_DIR, f)) / 1024
    print(f"  ├─ {f}  ({size_kb:.1f} KB)")

if not files:
    print("  No files to upload. Run migrate_pictures.py first.")
    sys.exit(0)

# ─── Get volume ID ────────────────────────────────────────────────────────────
print()
print("  Fetching volume list from Railway...")

result = subprocess.run(
    ["powershell", "-Command", "railway volume list --json"],
    capture_output=True, text=True, timeout=30, cwd=SCRIPT_DIR
)
print(result.stdout.strip())

# ─── Upload entire uploads_stage/ directory to /uploads/ on the volume ────────
# railway volume files -v <VOLUME_ID> upload <local_dir> <remote_dir> --overwrite
print()
print("  Uploading uploads_stage/ → /uploads/ on the volume...")
print("  (Railway CLI will prompt to select volume if --volume is not set)")
print()

# Upload the whole directory at once (faster than file-by-file)
cmd = [
    "powershell", "-Command",
    f'railway volume files upload "{STAGE_DIR}" /uploads --overwrite --json'
]

result = subprocess.run(
    cmd,
    capture_output=False,   # Show output live
    timeout=300,
    cwd=SCRIPT_DIR
)

if result.returncode == 0:
    print("\n  ✓ Upload complete!")
else:
    print(f"\n  ✗ Upload failed with exit code {result.returncode}")
    print("  Try running manually:")
    print(f'    railway service romantic-optimism')
    print(f'    railway volume files upload "{STAGE_DIR}" /uploads --overwrite')
