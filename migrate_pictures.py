#!/usr/bin/env python3
"""
Step 1: Download the 45 uploaded profile pictures from asplan.site (zealous-beauty)
Step 2: Upload them to fearless-wonder's Railway volume via `railway volume files upload`

The database already has the correct paths (e.g. uploads/6a6ce4...jpg).
We just need the physical files on fearless-wonder's volume.
"""

import mysql.connector
import os
import sys
import io
import subprocess
import urllib.request
import urllib.error

if sys.platform == "win32":
    sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding="utf-8", errors="replace")

# ─── Source DB (zealous-beauty) ───────────────────────────────────────────────
SRC_HOST     = "hayabusa.proxy.rlwy.net"
SRC_PORT     = 58143
SRC_USER     = "root"
SRC_PASSWORD = "PIlezyGzBauvijKewcPUtNqUtETTNcfP"
SRC_DATABASE = "railway"

ASPLAN_SITE  = "https://asplan.site"

# Local staging folder to hold downloads before uploading to volume
SCRIPT_DIR   = os.path.dirname(os.path.abspath(__file__))
STAGE_DIR    = os.path.join(SCRIPT_DIR, "exports", "uploads_stage")
os.makedirs(STAGE_DIR, exist_ok=True)

print("=" * 65)
print("  ASPLAN — Migrate Profile Pictures to fearless-wonder")
print("=" * 65)

# ─── Step 1: Get all real uploaded picture paths from zealous-beauty ──────────
print("\n[1/3] Fetching uploaded picture paths from zealous-beauty DB...")
conn = mysql.connector.connect(
    host=SRC_HOST, port=SRC_PORT, user=SRC_USER,
    password=SRC_PASSWORD, database=SRC_DATABASE,
    connect_timeout=15
)
c = conn.cursor()
c.execute("""
    SELECT student_number, picture
    FROM student_info
    WHERE picture IS NOT NULL
      AND picture != ''
      AND picture NOT LIKE 'pix/%'
      AND picture NOT LIKE 'http%'
    ORDER BY student_number
""")
rows = c.fetchall()
c.close()
conn.close()

print(f"  Found {len(rows)} students with uploaded profile pictures:")
for snum, path in rows:
    print(f"  ├─ {snum}: {path}")

if not rows:
    print("  No uploaded pictures found. Exiting.")
    sys.exit(0)

# ─── Step 2: Download each file from asplan.site ─────────────────────────────
print(f"\n[2/3] Downloading files from {ASPLAN_SITE}...")
downloaded = []
failed = []

for snum, db_path in rows:
    # db_path is like: uploads/6a6ce48d...jpg
    url = f"{ASPLAN_SITE}/{db_path}"
    filename = os.path.basename(db_path)
    local_path = os.path.join(STAGE_DIR, filename)

    # Skip if already downloaded
    if os.path.exists(local_path) and os.path.getsize(local_path) > 0:
        print(f"  ├─ (cached) {filename}")
        downloaded.append((db_path, local_path))
        continue

    try:
        req = urllib.request.Request(url, headers={"User-Agent": "ASPLAN-Export/1.0"})
        with urllib.request.urlopen(req, timeout=30) as resp, open(local_path, "wb") as f:
            data = resp.read()
            f.write(data)
        size_kb = len(data) / 1024
        print(f"  ├─ OK ({size_kb:.1f} KB)  {filename}")
        downloaded.append((db_path, local_path))
    except urllib.error.HTTPError as e:
        print(f"  ├─ HTTP {e.code}: {filename}  [{snum}]")
        failed.append((snum, db_path, str(e)))
    except Exception as e:
        print(f"  ├─ ERR: {filename}  [{snum}] — {e}")
        failed.append((snum, db_path, str(e)))

print(f"\n  Downloaded: {len(downloaded)}  |  Failed: {len(failed)}")

# ─── Step 3: Upload to fearless-wonder volume via Railway CLI ─────────────────
print(f"\n[3/3] Uploading to fearless-wonder Railway volume...")
print("  Using: railway volume files upload <local> <remote>")
print()

if not downloaded:
    print("  Nothing to upload.")
    sys.exit(0)

upload_ok  = []
upload_err = []

for db_path, local_path in downloaded:
    # Remote path on the volume should mirror the db_path
    # e.g. local: uploads_stage/6a6ce4...jpg -> remote: /uploads/6a6ce4...jpg
    remote_path = "/" + db_path.replace("\\", "/")  # /uploads/filename.jpg
    filename = os.path.basename(local_path)

    cmd = [
        "railway", "volume", "files", "upload",
        local_path,
        remote_path,
        "--overwrite",
        "--json"
    ]
    print(f"  ├─ Uploading: {filename} → {remote_path} ...", end=" ", flush=True)
    try:
        result = subprocess.run(
            cmd,
            capture_output=True, text=True, timeout=60,
            cwd=SCRIPT_DIR
        )
        if result.returncode == 0:
            print("OK")
            upload_ok.append(remote_path)
        else:
            err = (result.stderr or result.stdout).strip()[:120]
            print(f"FAILED\n      {err}")
            upload_err.append((remote_path, err))
    except subprocess.TimeoutExpired:
        print("TIMEOUT")
        upload_err.append((remote_path, "timeout"))
    except Exception as e:
        print(f"ERR: {e}")
        upload_err.append((remote_path, str(e)))

# ─── Summary ──────────────────────────────────────────────────────────────────
print("\n" + "=" * 65)
print("  PICTURE MIGRATION SUMMARY")
print("=" * 65)
print(f"  Pictures in DB        : {len(rows)}")
print(f"  Downloaded from site  : {len(downloaded)}")
print(f"  Failed downloads      : {len(failed)}")
print(f"  Uploaded to volume    : {len(upload_ok)}")
print(f"  Failed uploads        : {len(upload_err)}")
print(f"  Staged files folder   : {STAGE_DIR}")
print("=" * 65)

if failed:
    print("\n  Download failures:")
    for snum, path, err in failed:
        print(f"    {snum}: {path} — {err}")

if upload_err:
    print("\n  Upload failures:")
    for path, err in upload_err:
        print(f"    {path} — {err}")
