import os
import sys
from pathlib import Path

import mysql.connector


def split_sql(sql_text):
    statements = []
    current = []
    quote = None
    escape = False
    line_comment = False
    block_comment = False
    i = 0

    while i < len(sql_text):
        ch = sql_text[i]
        nxt = sql_text[i + 1] if i + 1 < len(sql_text) else ""

        if line_comment:
            current.append(ch)
            if ch == "\n":
                line_comment = False
            i += 1
            continue

        if block_comment:
            current.append(ch)
            if ch == "*" and nxt == "/":
                current.append(nxt)
                block_comment = False
                i += 2
            else:
                i += 1
            continue

        if quote:
            current.append(ch)
            if escape:
                escape = False
            elif ch == "\\":
                escape = True
            elif ch == quote:
                quote = None
            i += 1
            continue

        if ch in ("'", '"', "`"):
            quote = ch
            current.append(ch)
            i += 1
            continue

        if ch == "-" and nxt == "-" and (i + 2 == len(sql_text) or sql_text[i + 2].isspace()):
            line_comment = True
            current.append(ch)
            current.append(nxt)
            i += 2
            continue

        if ch == "#":
            line_comment = True
            current.append(ch)
            i += 1
            continue

        if ch == "/" and nxt == "*":
            block_comment = True
            current.append(ch)
            current.append(nxt)
            i += 2
            continue

        if ch == ";":
            statement = "".join(current).strip()
            if statement:
                statements.append(statement)
            current = []
            i += 1
            continue

        current.append(ch)
        i += 1

    statement = "".join(current).strip()
    if statement:
        statements.append(statement)
    return statements


def main():
    if len(sys.argv) != 2:
        print("Usage: python scripts/import_mysql_dump.py path/to/dump.sql", file=sys.stderr)
        return 2

    dump_path = Path(sys.argv[1])
    sql_text = dump_path.read_text(encoding="utf-8")
    statements = split_sql(sql_text)

    config = {
        "host": os.environ["MYSQL_HOST"],
        "port": int(os.environ["MYSQL_PORT"]),
        "user": os.environ["MYSQL_USER"],
        "password": os.environ["MYSQL_PASSWORD"],
        "database": os.environ["MYSQL_DATABASE"],
        "connection_timeout": 20,
        "autocommit": True,
        "use_pure": True,
    }

    print(f"Connecting to {config['host']}:{config['port']}/{config['database']}...")
    conn = mysql.connector.connect(**config)
    cursor = conn.cursor()

    try:
        for index, statement in enumerate(statements, 1):
            cursor.execute(statement)
            if index % 25 == 0 or index == len(statements):
                print(f"Executed {index}/{len(statements)} statements")
    finally:
        cursor.close()
        conn.close()

    print("Import complete.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
