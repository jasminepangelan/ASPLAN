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
        print("Connected.")
        
        print("Reading SQL file...")
        with open(SQL_FILE, 'r', encoding='utf-8') as f:
            content = f.read()
            
        print("Extracting INSERT statements...")
        # Find all INSERT statements
        # A simple split by ';' won't work perfectly if strings contain ';', 
        # but for standard phpMyAdmin dumps, INSERT statements are usually on their own lines.
        # Let's use a regex to find all INSERT INTO statements
        inserts = re.findall(r"(INSERT INTO `?[a-zA-Z0-9_]+`?.*?);", content, re.DOTALL | re.IGNORECASE)
        
        print(f"Found {len(inserts)} INSERT statements. Executing...")
        count = 0
        success = 0
        for stmt in inserts:
            # Change to INSERT IGNORE INTO
            stmt = re.sub(r"(?i)^INSERT INTO", "INSERT IGNORE INTO", stmt)
            count += 1
            try:
                cursor.execute(stmt)
                success += 1
            except Exception as e:
                print(f"Error on stmt {count}: {e}")
                
            if count % 10 == 0:
                print(f"Executed {count}/{len(inserts)} statements... (Success: {success})")
                
        connection.commit()
        cursor.close()
        connection.close()
        print(f"Done! Successfully executed {success}/{len(inserts)} INSERT statements.")
    except Exception as e:
        print(f"Error: {e}")

if __name__ == "__main__":
    run_import()
