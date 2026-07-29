import re
import mysql.connector

HOST = "hayabusa.proxy.rlwy.net"
PORT = 58143
USER = "root"
PASSWORD = "PIlezyGzBauvijKewcPUtNqUtETTNcfP"
DATABASE = "railway"
SQL_FILE = "C:/Users/Stephen Tiozon/Documents/GitHub/ASPLAN/osas_db_07_28_26_part2.sql"

def run_import():
    try:
        print("Connecting to DB...")
        connection = mysql.connector.connect(
            host=HOST,
            port=PORT,
            user=USER,
            password=PASSWORD,
            database=DATABASE
        )
        cursor = connection.cursor()
        print("Connected. Disabling FK checks...")
        cursor.execute("SET FOREIGN_KEY_CHECKS=0;")
        
        print("Reading SQL file...")
        with open(SQL_FILE, 'r', encoding='utf-8') as f:
            content = f.read()
            
        print("Splitting statements...")
        # Split on semicolon followed by newline
        stmts = re.split(r';\r?\n', content)
        
        print(f"Found {len(stmts)} statements. Executing...")
        count = 0
        success = 0
        
        for stmt in stmts:
            stmt = stmt.strip()
            if not stmt:
                continue
                
            # Skip ALTER TABLE which adds constraints/indexes that already exist
            if stmt.upper().startswith("ALTER TABLE"):
                continue
                
            # Change INSERT INTO to INSERT IGNORE INTO
            if stmt.upper().startswith("INSERT INTO"):
                # Use a case-insensitive replacement for the first occurrence
                stmt = re.sub(r"(?i)^INSERT INTO", "INSERT IGNORE INTO", stmt, count=1)
                
            count += 1
            try:
                cursor.execute(stmt)
                success += 1
            except Exception as e:
                print(f"Error on stmt {count} ({stmt[:50]}...): {e}")
                
            if count % 10 == 0:
                print(f"Processed {count} statements... (Success: {success})")
                
        connection.commit()
        cursor.execute("SET FOREIGN_KEY_CHECKS=1;")
        connection.commit()
        
        cursor.close()
        connection.close()
        print(f"Done! Successfully executed {success}/{count} statements.")
    except Exception as e:
        print(f"Error: {e}")

if __name__ == "__main__":
    run_import()
