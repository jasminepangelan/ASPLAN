import os
from urllib.parse import urlparse

import mysql.connector


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
    )


def table_exists(cur, name: str) -> bool:
    cur.execute("SHOW TABLES LIKE %s", (name,))
    return cur.fetchone() is not None


def main():
    conn = connect()
    cur = conn.cursor()

    print("== Curriculum tables ==")
    for t in ["program_curriculum_years", "cvsucarmona_courses", "curriculum_courses"]:
        print(f"- {t}: {'YES' if table_exists(cur, t) else 'NO'}")

    print("\n== program_curriculum_years (BSCS) ==")
    if table_exists(cur, "program_curriculum_years"):
        cur.execute(
            "SELECT curriculum_year FROM program_curriculum_years WHERE program = %s ORDER BY curriculum_year",
            ("BSCS",),
        )
        years = [row[0] for row in cur.fetchall()]
        print(years if years else "<none>")
    else:
        print("<table missing>")

    print("\n== cvsucarmona_courses distinct years (BSCS) ==")
    if table_exists(cur, "cvsucarmona_courses"):
        cur.execute(
            """
            SELECT DISTINCT TRIM(SUBSTRING_INDEX(curriculumyear_coursecode, '_', 1)) AS y
            FROM cvsucarmona_courses
            WHERE FIND_IN_SET(%s, REPLACE(programs, ', ', ',')) > 0
              AND curriculumyear_coursecode LIKE '____%'
            ORDER BY y
            """,
            ("BSCS",),
        )
        years = [row[0] for row in cur.fetchall()]
        print(years if years else "<none>")
    else:
        print("<table missing>")

    print("\n== curriculum_courses distinct years (BSCS canonical label) ==")
    canonical_label = "Bachelor of Science in Computer Science"
    if table_exists(cur, "curriculum_courses"):
        cur.execute(
            """
            SELECT DISTINCT curriculum_year
            FROM curriculum_courses
            WHERE UPPER(TRIM(program)) = UPPER(%s)
            ORDER BY curriculum_year
            """,
            (canonical_label,),
        )
        years = [row[0] for row in cur.fetchall()]
        print(years if years else "<none>")

        cur.execute(
            """
            SELECT curriculum_year, COUNT(*)
            FROM curriculum_courses
            WHERE UPPER(TRIM(program)) = UPPER(%s)
            GROUP BY curriculum_year
            ORDER BY curriculum_year
            """,
            (canonical_label,),
        )
        rows = cur.fetchall()
        if rows:
            print("Counts:")
            for y, c in rows:
                print(f"  - {y}: {c}")
    else:
        print("<table missing>")

    cur.close()
    conn.close()


if __name__ == "__main__":
    main()
