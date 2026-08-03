#!/usr/bin/env python3
"""
ASPLAN Full Data Export Script
================================
Exports all data from:
  1. Railway MySQL database (hayabusa.proxy.rlwy.net:58143)
  2. asplan.site uploaded files (via HTTP listing)

Output files:
  - exports/railway_db_<timestamp>.sql   — full SQL dump (structure + data)
  - exports/railway_db_<timestamp>_data_only.sql — data-only dump
  - exports/tables_summary_<timestamp>.csv — row counts per table
  - exports/pix_export/ — downloaded profile picture files from asplan.site
"""

import mysql.connector
import os
import sys
import csv
import datetime
import urllib.request
import urllib.error
import json
import re

# Force UTF-8 output on Windows to support checkmark/box-drawing characters
if sys.platform == "win32":
    import io
    sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding="utf-8", errors="replace")

# ─── Connection Config ────────────────────────────────────────────────────────
HOST     = "hayabusa.proxy.rlwy.net"
PORT     = 58143
USER     = "root"
PASSWORD = "PIlezyGzBauvijKewcPUtNqUtETTNcfP"
DATABASE = "railway"

ASPLAN_BASE_URL = "https://asplan.site"

# ─── Output Directory ─────────────────────────────────────────────────────────
SCRIPT_DIR  = os.path.dirname(os.path.abspath(__file__))
EXPORT_DIR  = os.path.join(SCRIPT_DIR, "exports")
TIMESTAMP   = datetime.datetime.now().strftime("%Y_%m_%d_%H%M%S")
SQL_FILE    = os.path.join(EXPORT_DIR, f"railway_db_{TIMESTAMP}.sql")
DATA_FILE   = os.path.join(EXPORT_DIR, f"railway_db_{TIMESTAMP}_data_only.sql")
SUMMARY_CSV = os.path.join(EXPORT_DIR, f"tables_summary_{TIMESTAMP}.csv")
PIX_DIR     = os.path.join(EXPORT_DIR, f"pix_export_{TIMESTAMP}")

os.makedirs(EXPORT_DIR, exist_ok=True)
os.makedirs(PIX_DIR, exist_ok=True)


# ─── Helpers ──────────────────────────────────────────────────────────────────

def escape_value(val):
    """Escape a Python value for safe MySQL INSERT output."""
    if val is None:
        return "NULL"
    if isinstance(val, (int, float)):
        return str(val)
    if isinstance(val, (bytes, bytearray)):
        hex_str = val.hex()
        return f"0x{hex_str}"
    if isinstance(val, (datetime.datetime, datetime.date, datetime.time)):
        return f"'{val}'"
    s = str(val)
    s = s.replace("\\", "\\\\")
    s = s.replace("'", "\\'")
    s = s.replace("\0", "\\0")
    s = s.replace("\n", "\\n")
    s = s.replace("\r", "\\r")
    s = s.replace("\x1a", "\\Z")
    return f"'{s}'"


def get_create_table(cursor, table):
    """Return the CREATE TABLE statement for the given table."""
    cursor.execute(f"SHOW CREATE TABLE `{table}`")
    row = cursor.fetchone()
    return row[1]  # index 1 = Create Table


def dump_table_data(cursor, table, batch_size=500):
    """Yield INSERT statements for all rows in a table, in batches."""
    cursor.execute(f"SELECT * FROM `{table}`")
    columns = [desc[0] for desc in cursor.description]
    col_list = ", ".join(f"`{c}`" for c in columns)

    rows = cursor.fetchmany(batch_size)
    while rows:
        values_list = []
        for row in rows:
            values = ", ".join(escape_value(v) for v in row)
            values_list.append(f"({values})")
        yield f"INSERT INTO `{table}` ({col_list}) VALUES\n" + ",\n".join(values_list) + ";\n"
        rows = cursor.fetchmany(batch_size)


# ─── Main Export ──────────────────────────────────────────────────────────────

def export_database():
    print("=" * 65)
    print("  ASPLAN — Railway MySQL Full Export")
    print("=" * 65)
    print(f"  Host     : {HOST}:{PORT}")
    print(f"  Database : {DATABASE}")
    print(f"  Timestamp: {TIMESTAMP}")
    print(f"  Output   : {EXPORT_DIR}")
    print("=" * 65)

    print("\n[1/4] Connecting to Railway MySQL...")
    try:
        conn = mysql.connector.connect(
            host=HOST,
            port=PORT,
            user=USER,
            password=PASSWORD,
            database=DATABASE,
            connect_timeout=20,
            charset="utf8mb4",
        )
    except Exception as e:
        print(f"  ✗ Connection failed: {e}")
        sys.exit(1)

    cursor = conn.cursor()
    print("  ✓ Connected successfully!")

    # ── Get list of tables ────────────────────────────────────────────────────
    cursor.execute("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")
    tables = [row[0] for row in cursor.fetchall()]

    cursor.execute("SHOW FULL TABLES WHERE Table_type = 'VIEW'")
    views = [row[0] for row in cursor.fetchall()]

    print(f"  ✓ Found {len(tables)} tables, {len(views)} views")

    # ── Row count summary ─────────────────────────────────────────────────────
    print("\n[2/4] Counting rows per table...")
    summary = []
    for table in tables:
        cursor.execute(f"SELECT COUNT(*) FROM `{table}`")
        count = cursor.fetchone()[0]
        summary.append({"table": table, "rows": count})
        print(f"  ├─ {table}: {count:,} rows")

    with open(SUMMARY_CSV, "w", newline="", encoding="utf-8") as f:
        writer = csv.DictWriter(f, fieldnames=["table", "rows"])
        writer.writeheader()
        writer.writerows(summary)
    print(f"\n  ✓ Summary saved → {SUMMARY_CSV}")

    # ── Full SQL Dump (structure + data) ──────────────────────────────────────
    print("\n[3/4] Exporting full SQL dump (structure + data)...")

    header = f"""-- ============================================================
-- ASPLAN Railway Database Full Export
-- Host    : {HOST}:{PORT}
-- Database: {DATABASE}
-- Exported: {datetime.datetime.now().strftime('%Y-%m-%d %H:%M:%S')}
-- ============================================================

SET FOREIGN_KEY_CHECKS=0;
SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';
SET NAMES utf8mb4;
SET CHARACTER SET utf8mb4;

"""

    with open(SQL_FILE, "w", encoding="utf-8") as full_f, \
         open(DATA_FILE, "w", encoding="utf-8") as data_f:

        full_f.write(header)
        data_f.write(header.replace("Full Export", "Data-Only Export"))

        # --- Tables ---
        for table in tables:
            print(f"  ├─ Dumping table: {table} ...", end=" ", flush=True)

            # Structure
            full_f.write(f"\n-- ─── Table: {table} ────────────────────────────\n")
            full_f.write(f"DROP TABLE IF EXISTS `{table}`;\n")
            create_stmt = get_create_table(cursor, table)
            full_f.write(create_stmt + ";\n\n")

            # Data (both files)
            data_f.write(f"\n-- ─── Table: {table} ────────────────────────────\n")
            full_f.write(f"-- Data for `{table}`\n")

            row_count = 0
            for insert_stmt in dump_table_data(cursor, table):
                full_f.write(insert_stmt + "\n")
                data_f.write(insert_stmt + "\n")
                # Count rows by counting VALUE groups (approximate)
                row_count += insert_stmt.count("\n(") + (1 if insert_stmt.startswith("INSERT") else 0)

            print(f"✓")

        # --- Views ---
        if views:
            full_f.write("\n-- ─── Views ────────────────────────────────────\n")
            for view in views:
                print(f"  ├─ Dumping view: {view} ...", end=" ", flush=True)
                cursor.execute(f"SHOW CREATE VIEW `{view}`")
                row = cursor.fetchone()
                full_f.write(f"\nDROP VIEW IF EXISTS `{view}`;\n")
                full_f.write(row[1] + ";\n")
                print("✓")

        # Footer
        footer = "\n\nSET FOREIGN_KEY_CHECKS=1;\n\n-- Export complete.\n"
        full_f.write(footer)
        data_f.write(footer)

    full_size = os.path.getsize(SQL_FILE) / (1024 * 1024)
    data_size = os.path.getsize(DATA_FILE) / (1024 * 1024)
    print(f"\n  ✓ Full dump  → {SQL_FILE}  ({full_size:.2f} MB)")
    print(f"  ✓ Data-only  → {DATA_FILE}  ({data_size:.2f} MB)")

    cursor.close()
    conn.close()

    return summary


def export_asplan_site_volume():
    """
    Try to download uploaded files (profile pictures, etc.) from asplan.site.
    The app stores uploads in /pix/ and /uploads/ directories.
    We'll attempt to fetch known paths, and also try the /api/list-pix endpoint if present.
    """
    print("\n[4/4] Exporting asplan.site volume files (pix / uploads)...")

    downloaded = 0
    failed = 0

    # Try fetching the pix directory listing via a known API endpoint
    api_url = f"{ASPLAN_BASE_URL}/api/list_pix.php"
    pix_files = []

    try:
        print(f"  Trying API listing: {api_url}")
        req = urllib.request.Request(api_url, headers={"User-Agent": "ASPLAN-Export/1.0"})
        with urllib.request.urlopen(req, timeout=10) as resp:
            body = resp.read().decode("utf-8")
            data = json.loads(body)
            if isinstance(data, list):
                pix_files = data
                print(f"  ✓ API returned {len(pix_files)} file entries")
    except Exception as e:
        print(f"  ⚠ API listing not available: {e}")

    # Try the admin API endpoint
    if not pix_files:
        api_url2 = f"{ASPLAN_BASE_URL}/api/export_volume.php"
        try:
            req = urllib.request.Request(api_url2, headers={"User-Agent": "ASPLAN-Export/1.0"})
            with urllib.request.urlopen(req, timeout=10) as resp:
                body = resp.read().decode("utf-8")
                data = json.loads(body)
                if isinstance(data, list):
                    pix_files = data
        except Exception:
            pass

    # If no API, try to scrape the pix/ directory listing (Apache/Nginx auto-index)
    if not pix_files:
        for path in ["/pix/", "/uploads/"]:
            listing_url = f"{ASPLAN_BASE_URL}{path}"
            try:
                req = urllib.request.Request(listing_url, headers={"User-Agent": "ASPLAN-Export/1.0"})
                with urllib.request.urlopen(req, timeout=10) as resp:
                    html = resp.read().decode("utf-8", errors="replace")
                # Extract filenames from directory listing HTML
                matches = re.findall(r'href="([^"?#/][^"]*\.(jpg|jpeg|png|gif|webp|pdf|csv))"', html, re.IGNORECASE)
                for fname, _ in matches:
                    pix_files.append({"url": f"{ASPLAN_BASE_URL}{path}{fname}", "filename": fname})
                if matches:
                    print(f"  ✓ Directory listing found {len(matches)} files at {path}")
            except Exception as e:
                print(f"  ⚠ Could not list {listing_url}: {e}")

    # Download whatever we found
    if pix_files:
        print(f"\n  Downloading {len(pix_files)} files...")
        for entry in pix_files:
            if isinstance(entry, dict):
                file_url = entry.get("url", "")
                filename = entry.get("filename", os.path.basename(file_url))
            else:
                file_url = str(entry)
                filename = os.path.basename(file_url)

            if not file_url:
                continue

            out_path = os.path.join(PIX_DIR, filename)
            try:
                req = urllib.request.Request(file_url, headers={"User-Agent": "ASPLAN-Export/1.0"})
                with urllib.request.urlopen(req, timeout=30) as resp, open(out_path, "wb") as f:
                    f.write(resp.read())
                print(f"  ├─ ✓ {filename}")
                downloaded += 1
            except Exception as e:
                print(f"  ├─ ✗ {filename}: {e}")
                failed += 1
    else:
        print("  ⚠ No file listing could be obtained from asplan.site.")
        print("    The Railway volume is not directly accessible from outside the container.")
        print("    To export volume data, you need to use the Railway CLI:")
        print()
        print("    Option A — Railway CLI (recommended):")
        print("      railway login")
        print("      railway shell")
        print("      tar czf /tmp/pix_backup.tar.gz /var/www/html/pix/")
        print("      # Then download via Railway's volume interface or SCP")
        print()
        print("    Option B — Add a temporary export endpoint to the app:")
        print("      Create /api/export_volume.php that zips & streams the pix/ folder")

    print(f"\n  ✓ Volume export complete.")
    print(f"    Downloaded: {downloaded} files → {PIX_DIR}")
    if failed:
        print(f"    Failed    : {failed} files")

    return downloaded


# ─── Entry Point ──────────────────────────────────────────────────────────────

if __name__ == "__main__":
    start = datetime.datetime.now()

    summary = export_database()
    dl_count = export_asplan_site_volume()

    elapsed = (datetime.datetime.now() - start).total_seconds()

    print("\n" + "=" * 65)
    print("  EXPORT COMPLETE")
    print("=" * 65)
    total_rows = sum(r["rows"] for r in summary)
    print(f"  Tables exported : {len(summary)}")
    print(f"  Total rows      : {total_rows:,}")
    print(f"  Volume files    : {dl_count}")
    print(f"  Time elapsed    : {elapsed:.1f}s")
    print(f"  Output folder   : {EXPORT_DIR}")
    print("=" * 65)
    print("\nOutput files:")
    print(f"  SQL (full)      : {SQL_FILE}")
    print(f"  SQL (data-only) : {DATA_FILE}")
    print(f"  Table summary   : {SUMMARY_CSV}")
    if dl_count:
        print(f"  Pix files       : {PIX_DIR}")
    print()
