import re
import mysql.connector
import sys

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
            
        print("Replacing INSERT INTO with REPLACE INTO to update existing data...")
        # Use REPLACE INTO so that existing records (by Primary/Unique key) are updated/overwritten!
        content = re.sub(r"(?im)^INSERT INTO ", "REPLACE INTO ", content)
        
        print("Splitting statements...")
        stmts = re.split(r';\r?\n', content)
        
        print(f"Found {len(stmts)} statements. Executing...")
        count = 0
        success = 0
        
        for stmt in stmts:
            stmt = stmt.strip()
            if not stmt:
                continue
                
            # Skip ALTER TABLE which adds constraints/indexes that already exist
            # Skip CREATE TABLE as they already exist and we just want to update data
            if stmt.upper().startswith("ALTER TABLE") or stmt.upper().startswith("CREATE TABLE"):
                continue
                
            count += 1
            try:
                cursor.execute(stmt)
                success += 1
            except Exception as e:
                # We expect some failures if a table really doesn't exist, but we will print errors
                print(f"Error on stmt {count}: {e}")
                
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
