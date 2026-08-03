#!/usr/bin/env python3
"""
Import ASPLAN Railway DB dump → fearless-wonder (thomas.proxy.rlwy.net:46753)
Uses the full SQL dump produced by export_railway_db.py
"""

import mysql.connector
import os
import sys
import re
import datetime

if sys.platform == "win32":
    import io
    sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding="utf-8", errors="replace")

# ─── Target DB (fearless-wonder) ─────────────────────────────────────────────
TARGET_HOST     = "thomas.proxy.rlwy.net"
TARGET_PORT     = 46753
TARGET_USER     = "root"
TARGET_PASSWORD = "RHbqPAeKhfyWnCkaTFWMOtFHIhqpkabc"
TARGET_DATABASE = "railway"

# ─── Find the latest full SQL dump in exports/ ───────────────────────────────
SCRIPT_DIR  = os.path.dirname(os.path.abspath(__file__))
EXPORTS_DIR = os.path.join(SCRIPT_DIR, "exports")

# Pick the most recent full dump (not data_only)
dump_files = sorted([
    f for f in os.listdir(EXPORTS_DIR)
    if f.startswith("railway_db_") and f.endswith(".sql") and "data_only" not in f
], reverse=True)

if not dump_files:
    print("✗ No SQL dump found in exports/ directory. Run export_railway_db.py first.")
    sys.exit(1)

SQL_FILE = os.path.join(EXPORTS_DIR, dump_files[0])
print("=" * 65)
print("  ASPLAN → Import to fearless-wonder")
print("=" * 65)
print(f"  Target   : {TARGET_HOST}:{TARGET_PORT}")
print(f"  Database : {TARGET_DATABASE}")
print(f"  SQL File : {dump_files[0]}")
print(f"  Size     : {os.path.getsize(SQL_FILE)/1024/1024:.2f} MB")
print("=" * 65)

# ─── Connect ─────────────────────────────────────────────────────────────────
print("\n[1/3] Connecting to fearless-wonder...")
try:
    conn = mysql.connector.connect(
        host=TARGET_HOST,
        port=TARGET_PORT,
        user=TARGET_USER,
        password=TARGET_PASSWORD,
        database=TARGET_DATABASE,
        connect_timeout=20,
        charset="utf8mb4",
        autocommit=False,
        allow_local_infile=True,
    )
    cursor = conn.cursor()
    print("  ✓ Connected!")
except Exception as e:
    print(f"  ✗ Connection failed: {e}")
    sys.exit(1)

# ─── Read SQL file ────────────────────────────────────────────────────────────
print("\n[2/3] Reading SQL dump...")
with open(SQL_FILE, "r", encoding="utf-8") as f:
    raw = f.read()
print(f"  ✓ Loaded {len(raw):,} bytes")

# ─── Split into individual statements ────────────────────────────────────────
# Strategy: split on ; followed by newline (handles multi-line values safely)
# Also handle delimiter changes if any
statements = []
current = []
for line in raw.splitlines():
    stripped = line.strip()
    # Skip pure comment lines and empty lines for speed, keep them in context
    if stripped.startswith("--") or stripped == "":
        continue
    current.append(line)
    # End of statement
    if stripped.endswith(";"):
        stmt = "\n".join(current).strip().rstrip(";")
        if stmt:
            statements.append(stmt)
        current = []

# Any trailing statement without semicolon
if current:
    stmt = "\n".join(current).strip()
    if stmt:
        statements.append(stmt)

print(f"  ✓ Parsed {len(statements):,} statements")

# ─── Execute ──────────────────────────────────────────────────────────────────
print("\n[3/3] Importing into fearless-wonder...")

# Session settings
cursor.execute("SET FOREIGN_KEY_CHECKS=0")
cursor.execute("SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO'")
cursor.execute("SET NAMES utf8mb4")
cursor.execute("SET CHARACTER SET utf8mb4")

total     = len(statements)
success   = 0
skipped   = 0
errors    = []
start     = datetime.datetime.now()
last_table = None

for i, stmt in enumerate(statements):
    upper = stmt.upper().lstrip()

    # Track which table we're on for progress display
    if upper.startswith("CREATE TABLE") or upper.startswith("DROP TABLE") or upper.startswith("INSERT INTO"):
        match = re.search(r'`([^`]+)`', stmt)
        if match:
            tname = match.group(1)
            if tname != last_table:
                if last_table is not None:
                    print(f"    ✓ {last_table}")
                print(f"  ├─ {tname} ...", end=" ", flush=True)
                last_table = tname

    try:
        cursor.execute(stmt)
        success += 1
    except mysql.connector.Error as e:
        err_code = e.errno
        # 1050 = Table already exists, 1060 = Duplicate column — safe to skip
        if err_code in (1050, 1060, 1061, 1091):
            skipped += 1
        else:
            errors.append((i + 1, err_code, str(e)[:120], stmt[:80]))
            if len(errors) <= 10:
                print(f"\n  ⚠ Stmt {i+1} (err {err_code}): {str(e)[:100]}")
    except Exception as e:
        errors.append((i + 1, 0, str(e)[:120], stmt[:80]))

    # Commit every 200 statements to avoid huge transactions
    if (i + 1) % 200 == 0:
        conn.commit()

if last_table:
    print(f"    ✓ {last_table}")

conn.commit()
cursor.execute("SET FOREIGN_KEY_CHECKS=1")
conn.commit()

elapsed = (datetime.datetime.now() - start).total_seconds()

# ─── Verify ───────────────────────────────────────────────────────────────────
print("\n  Verifying import...")
cursor.execute("SHOW TABLES")
imported_tables = [r[0] for r in cursor.fetchall()]
print(f"  ✓ Tables in fearless-wonder: {len(imported_tables)}")

total_rows = 0
for t in imported_tables:
    cursor.execute(f"SELECT COUNT(*) FROM `{t}`")
    cnt = cursor.fetchone()[0]
    total_rows += cnt
    if cnt > 0:
        print(f"    ├─ {t}: {cnt:,} rows")

cursor.close()
conn.close()

# ─── Summary ──────────────────────────────────────────────────────────────────
print("\n" + "=" * 65)
print("  IMPORT COMPLETE")
print("=" * 65)
print(f"  Statements total  : {total:,}")
print(f"  Executed OK       : {success:,}")
print(f"  Skipped (benign)  : {skipped:,}")
print(f"  Errors            : {len(errors)}")
print(f"  Tables imported   : {len(imported_tables)}")
print(f"  Total rows        : {total_rows:,}")
print(f"  Time elapsed      : {elapsed:.1f}s")
print("=" * 65)

if errors:
    print(f"\n  First {min(len(errors),10)} errors:")
    for idx, code, msg, preview in errors[:10]:
        print(f"    #{idx} [err {code}] {msg}")
        print(f"         SQL: {preview}...")
