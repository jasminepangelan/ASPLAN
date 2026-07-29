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
            
        print("Replacing INSERT INTO with INSERT IGNORE INTO...")
        # Replace globally. This is safe enough for a dump file.
        # We replace at the beginning of a line to avoid replacing inside strings.
        content = re.sub(r"(?im)^INSERT INTO ", "INSERT IGNORE INTO ", content)
        
        print("Splitting statements...")
        stmts = re.split(r';\r?\n', content)
        
        print(f"Found {len(stmts)} statements. Executing...")
        count = 0
        success = 0
        
        for stmt in stmts:
            stmt = stmt.strip()
            if not stmt:
                continue
                
            # We don't care about ALTER TABLE or CREATE TABLE failing, 
            # we just want to run the INSERT IGNORE INTO.
            # But let's skip ALTER TABLE to avoid noise.
            if "ALTER TABLE" in stmt.upper():
                continue
                
            count += 1
            try:
                cursor.execute(stmt)
                success += 1
            except Exception as e:
                # Suppress "Table already exists"
                if "already exists" not in str(e):
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
