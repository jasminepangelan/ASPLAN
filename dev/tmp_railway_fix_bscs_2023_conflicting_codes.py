import os
from dataclasses import dataclass
from typing import Dict, List, Tuple
from urllib.parse import urlparse

import mysql.connector


SUFFIXES = [" CS-IT", " CpE", " CPE", " IndT", " INDT", " CS", " IT"]


def normalize_display_course_code(code: str) -> str:
    value = (code or "").strip()
    if not value:
        return ""

    for suffix in SUFFIXES:
        if len(value) > len(suffix) and value.lower().endswith(suffix.lower()):
            return value[: -len(suffix)].strip()

    return value


def parse_mysql_url(url: str):
    parsed = urlparse(url)
    return {
        "host": parsed.hostname,
        "port": parsed.port or 3306,
        "user": parsed.username,
        "password": parsed.password,
        "database": (parsed.path or "").lstrip("/"),
    }


@dataclass
class CourseRow:
    key: str
    programs: str
    title: str
    year_level: str
    semester: str

    @property
    def raw_code(self) -> str:
        parts = self.key.split("_", 1)
        return parts[1].strip() if len(parts) == 2 else self.key.strip()

    @property
    def display_code(self) -> str:
        return normalize_display_course_code(self.raw_code)


def tokenize_programs(programs_csv: str) -> List[str]:
    return [t.strip() for t in (programs_csv or "").split(",") if t.strip()]


def programs_contains(programs_csv: str, program_code: str) -> bool:
    program_code = program_code.strip()
    return program_code in tokenize_programs(programs_csv)


def programs_remove(programs_csv: str, program_code: str) -> str:
    tokens = [t for t in tokenize_programs(programs_csv) if t != program_code]
    return ", ".join(tokens)


def main() -> int:
    program = os.environ.get("PROGRAM", "BSCS").strip()
    year = os.environ.get("YEAR", "2023").strip()
    apply = os.environ.get("APPLY", "0").strip() == "1"

    url = os.environ.get("MYSQL_PUBLIC_URL") or os.environ.get("MYSQL_URL")
    if not url:
        raise RuntimeError("Missing MYSQL_PUBLIC_URL/MYSQL_URL in environment")

    conn_cfg = parse_mysql_url(url)
    conn = mysql.connector.connect(**conn_cfg)
    conn.autocommit = False

    try:
        cur = conn.cursor(dictionary=True)

        # Pull all BSCS rows for the year.
        cur.execute(
            """
            SELECT curriculumyear_coursecode, programs, course_title, year_level, semester
            FROM cvsucarmona_courses
            WHERE curriculumyear_coursecode LIKE %s
            """,
            (f"{year}_%",),
        )

        rows: List[CourseRow] = []
        for r in cur.fetchall():
            key = str(r.get("curriculumyear_coursecode") or "")
            programs_csv = str(r.get("programs") or "")
            if not programs_contains(programs_csv.replace(", ", ","), program):
                # NOTE: programs are stored with comma+space in some rows; normalize check.
                if not programs_contains(programs_csv, program):
                    continue

            rows.append(
                CourseRow(
                    key=key,
                    programs=programs_csv,
                    title=str(r.get("course_title") or ""),
                    year_level=str(r.get("year_level") or ""),
                    semester=str(r.get("semester") or ""),
                )
            )

        by_display: Dict[str, List[CourseRow]] = {}
        for row in rows:
            dc = row.display_code.upper()
            if not dc:
                continue
            by_display.setdefault(dc, []).append(row)

        conflicts = {k: v for k, v in by_display.items() if len(v) > 1}

        print(f"Program={program} Year={year} APPLY={int(apply)}")
        print(f"Rows matching program/year: {len(rows)}")
        print(f"Conflicting display codes: {len(conflicts)}")

        if not conflicts:
            conn.rollback()
            return 0

        planned_updates: List[Tuple[str, str, str]] = []  # (key, old_programs, new_programs)

        # Decide which row to keep for each conflicting display code.
        for display_code, group in sorted(conflicts.items()):
            # Prefer the row whose raw code already matches the display code (no legacy suffix).
            keep: CourseRow | None = None
            for candidate in group:
                if candidate.raw_code.strip().upper() == display_code.strip().upper():
                    keep = candidate
                    break
            if keep is None:
                keep = sorted(group, key=lambda r: r.key)[0]

            print(f"\n== {display_code} ==")
            print(f"Keep: {keep.key} [{keep.year_level} | {keep.semester}] {keep.title}")

            for candidate in sorted(group, key=lambda r: r.key):
                print(f" - {candidate.key} [{candidate.year_level} | {candidate.semester}] programs={candidate.programs}")

            for candidate in group:
                if candidate.key == keep.key:
                    continue
                old_programs = candidate.programs
                new_programs = programs_remove(old_programs, program)
                if new_programs == old_programs:
                    continue
                planned_updates.append((candidate.key, old_programs, new_programs))

        print(f"\nPlanned program removals: {len(planned_updates)}")
        for key, old, new in planned_updates:
            print(f" - {key}: '{old}' -> '{new}'")

        if not apply:
            conn.rollback()
            print("\nDry-run only; no changes applied.")
            return 0

        # Apply updates.
        upd = conn.cursor()
        for key, _old, new in planned_updates:
            if new.strip() == "":
                upd.execute(
                    "DELETE FROM cvsucarmona_courses WHERE curriculumyear_coursecode = %s",
                    (key,),
                )
            else:
                upd.execute(
                    "UPDATE cvsucarmona_courses SET programs = %s WHERE curriculumyear_coursecode = %s",
                    (new, key),
                )

        conn.commit()
        print("\nApplied successfully.")
        return 0

    except Exception:
        conn.rollback()
        raise
    finally:
        try:
            conn.close()
        except Exception:
            pass


if __name__ == "__main__":
    raise SystemExit(main())
