import os
from urllib.parse import urlparse

import mysql.connector

PROGRAM_CODE = "BSCS"
CANONICAL_PROGRAM_LABEL = "Bachelor of Science in Computer Science"
SOURCE_YEAR = 2018
TARGET_YEAR = 2023


def connect():
    url = os.environ.get("MYSQL_PUBLIC_URL") or os.environ.get("MYSQL_URL") or os.environ.get("DATABASE_URL")
    if not url:
        raise SystemExit("Missing MYSQL_PUBLIC_URL/MYSQL_URL/DATABASE_URL")

    parsed = urlparse(url)
    if parsed.scheme not in ("mysql", "mariadb"):
        raise SystemExit(f"Unsupported DB scheme: {parsed.scheme}")

    return mysql.connector.connect(
        host=parsed.hostname,
        port=parsed.port or 3306,
        user=parsed.username,
        password=parsed.password,
        database=(parsed.path or "").lstrip("/") or None,
        autocommit=False,
    )


def fetch_one_int(cur, sql, params):
    cur.execute(sql, params)
    row = cur.fetchone()
    if not row:
        return 0

    if isinstance(row, dict):
        value = next(iter(row.values()), None)
    else:
        value = row[0]

    return int(value) if value is not None else 0


def table_exists(cur, name: str) -> bool:
    cur.execute("SHOW TABLES LIKE %s", (name,))
    return cur.fetchone() is not None


def ensure_program_curriculum_years(cur):
    cur.execute(
        """
        CREATE TABLE IF NOT EXISTS program_curriculum_years (
            id INT AUTO_INCREMENT PRIMARY KEY,
            program VARCHAR(64) NOT NULL,
            curriculum_year CHAR(4) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_program_year (program, curriculum_year)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        """
    )


def ensure_curriculum_courses(cur):
    cur.execute(
        """
        CREATE TABLE IF NOT EXISTS curriculum_courses (
            id INT(11) NOT NULL AUTO_INCREMENT,
            curriculum_year INT(4) NOT NULL,
            program VARCHAR(255) NOT NULL,
            year_level VARCHAR(50) NOT NULL,
            semester VARCHAR(50) NOT NULL,
            course_code VARCHAR(20) NOT NULL,
            course_title VARCHAR(255) NOT NULL,
            credit_units_lec INT(2) DEFAULT 0,
            credit_units_lab INT(2) DEFAULT 0,
            lect_hrs_lec INT(2) DEFAULT 0,
            lect_hrs_lab INT(2) DEFAULT 0,
            pre_requisite VARCHAR(255) DEFAULT 'NONE',
            PRIMARY KEY (id),
            KEY curriculum_year (curriculum_year),
            KEY program (program),
            KEY course_code (course_code)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        """
    )


def main():
    conn = connect()
    cur = conn.cursor(dictionary=True)

    required = ["cvsucarmona_courses"]
    for t in required:
        if not table_exists(cur, t):
            raise SystemExit(f"Missing required table: {t}")

    ensure_program_curriculum_years(cur)
    ensure_curriculum_courses(cur)

    # Safety checks: do not overwrite an existing 2023 curriculum for BSCS.
    existing_target_curriculum = fetch_one_int(
        cur,
        """
        SELECT COUNT(*)
        FROM curriculum_courses
        WHERE curriculum_year = %s AND UPPER(TRIM(program)) = UPPER(%s)
        """,
        (TARGET_YEAR, CANONICAL_PROGRAM_LABEL),
    )
    existing_target_legacy = fetch_one_int(
        cur,
        """
        SELECT COUNT(*)
        FROM cvsucarmona_courses
        WHERE curriculumyear_coursecode LIKE %s
          AND FIND_IN_SET(%s, REPLACE(programs, ', ', ',')) > 0
        """,
        (f"{TARGET_YEAR}_%", PROGRAM_CODE),
    )

    if existing_target_curriculum > 0 or existing_target_legacy > 0:
        print(
            f"Abort: BSCS already has {existing_target_curriculum} curriculum_courses rows and "
            f"{existing_target_legacy} legacy rows for {TARGET_YEAR}."
        )
        cur.close()
        conn.close()
        return

    # Load source rows from curriculum_courses (canonical source for study-plan generation).
    cur.execute(
        """
        SELECT year_level, semester, course_code, course_title,
               credit_units_lec, credit_units_lab, lect_hrs_lec, lect_hrs_lab, pre_requisite
        FROM curriculum_courses
        WHERE curriculum_year = %s AND UPPER(TRIM(program)) = UPPER(%s)
        ORDER BY id
        """,
        (SOURCE_YEAR, CANONICAL_PROGRAM_LABEL),
    )
    source_rows = cur.fetchall()

    if not source_rows:
        raise SystemExit(
            f"No source curriculum rows found in curriculum_courses for {CANONICAL_PROGRAM_LABEL} ({SOURCE_YEAR})."
        )

    # Transactional insert.
    conn.start_transaction()
    try:
        # Register the new year for the program.
        cur.execute(
            """
            INSERT INTO program_curriculum_years (program, curriculum_year)
            VALUES (%s, %s)
            ON DUPLICATE KEY UPDATE updated_at = CURRENT_TIMESTAMP
            """,
            (PROGRAM_CODE, str(TARGET_YEAR)),
        )

        # Insert curriculum_courses rows.
        insert_curriculum = (
            """
            INSERT INTO curriculum_courses (
                curriculum_year, program, year_level, semester, course_code, course_title,
                credit_units_lec, credit_units_lab, lect_hrs_lec, lect_hrs_lab, pre_requisite
            ) VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
            """
        )

        curriculum_inserted = 0
        for r in source_rows:
            cur.execute(
                insert_curriculum,
                (
                    TARGET_YEAR,
                    CANONICAL_PROGRAM_LABEL,
                    (r.get("year_level") or "").strip(),
                    (r.get("semester") or "").strip(),
                    (r.get("course_code") or "").strip(),
                    (r.get("course_title") or "").strip(),
                    int(r.get("credit_units_lec") or 0),
                    int(r.get("credit_units_lab") or 0),
                    int(r.get("lect_hrs_lec") or 0),
                    int(r.get("lect_hrs_lab") or 0),
                    (r.get("pre_requisite") or "NONE").strip() or "NONE",
                ),
            )
            curriculum_inserted += 1

        # Insert legacy rows for UI editing/view.
        # We create keys like "2023_COSC 50" and assign them to BSCS only.
        insert_legacy = (
            """
            INSERT INTO cvsucarmona_courses (
                curriculumyear_coursecode, programs, course_title, year_level, semester,
                credit_units_lec, credit_units_lab, lect_hrs_lec, lect_hrs_lab, pre_requisite
            ) VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
            """
        )

        legacy_inserted = 0
        for r in source_rows:
            course_code = (r.get("course_code") or "").strip()
            if not course_code:
                continue
            legacy_key = f"{TARGET_YEAR}_{course_code}"
            try:
                cur.execute(
                    insert_legacy,
                    (
                        legacy_key,
                        PROGRAM_CODE,
                        (r.get("course_title") or "").strip(),
                        (r.get("year_level") or "").strip(),
                        (r.get("semester") or "").strip(),
                        int(r.get("credit_units_lec") or 0),
                        int(r.get("credit_units_lab") or 0),
                        int(r.get("lect_hrs_lec") or 0),
                        int(r.get("lect_hrs_lab") or 0),
                        (r.get("pre_requisite") or "NONE").strip() or "NONE",
                    ),
                )
                legacy_inserted += 1
            except mysql.connector.IntegrityError as e:
                # If the row exists, only add BSCS to the program list when safe.
                cur.execute(
                    "SELECT programs FROM cvsucarmona_courses WHERE curriculumyear_coursecode = %s LIMIT 1",
                    (legacy_key,),
                )
                existing = cur.fetchone()
                if not existing:
                    raise

                programs_raw = (existing.get("programs") or "")
                programs = [p.strip() for p in programs_raw.split(",") if p.strip()]
                if PROGRAM_CODE in programs:
                    continue

                programs.append(PROGRAM_CODE)
                new_programs = ", ".join(sorted(set(programs)))
                cur.execute(
                    "UPDATE cvsucarmona_courses SET programs = %s WHERE curriculumyear_coursecode = %s",
                    (new_programs, legacy_key),
                )
                # Do not count this as an insert.

        conn.commit()

        # Post-commit verification counts.
        verified_curriculum = fetch_one_int(
            cur,
            """
            SELECT COUNT(*)
            FROM curriculum_courses
            WHERE curriculum_year = %s AND UPPER(TRIM(program)) = UPPER(%s)
            """,
            (TARGET_YEAR, CANONICAL_PROGRAM_LABEL),
        )
        verified_legacy = fetch_one_int(
            cur,
            """
            SELECT COUNT(*)
            FROM cvsucarmona_courses
            WHERE curriculumyear_coursecode LIKE %s
              AND FIND_IN_SET(%s, REPLACE(programs, ', ', ',')) > 0
            """,
            (f"{TARGET_YEAR}_%", PROGRAM_CODE),
        )

        print("Created new curriculum:")
        print(f"- Program: {PROGRAM_CODE} ({CANONICAL_PROGRAM_LABEL})")
        print(f"- Source year: {SOURCE_YEAR}")
        print(f"- Target year: {TARGET_YEAR} (Sample)")
        print(f"- Inserted curriculum_courses rows: {curriculum_inserted} (verified {verified_curriculum})")
        print(f"- Inserted cvsucarmona_courses rows: {legacy_inserted} (verified {verified_legacy})")

    except Exception:
        conn.rollback()
        raise
    finally:
        cur.close()
        conn.close()


if __name__ == "__main__":
    main()
