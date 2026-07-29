import os
import sys
import mysql.connector

HOST = "thomas.proxy.rlwy.net"
PORT = 45044
USER = "root"
PASSWORD = "qMsZwbiIngMfINmKygVSbMIiqJfdoTst"
DATABASE = "railway"
SQL_FILE = "C:/Users/Stephen Tiozon/Documents/GitHub/ASPLAN/osas_db_07_28_26_part2_ignore.sql"

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
            sql_content = f.read()
        print(f"Loaded {len(sql_content)} bytes. Executing...")
        
        # We can split by ; manually or just use multi=True
        # However, some SQL dumps contain DELIMITER statements or similar which break multi=True.
        # But this is just phpMyAdmin dump.
        
        results = cursor.execute(sql_content, multi=True)
        count = 0
        for res in results:
            count += 1
            if count % 100 == 0:
                print(f"Executed {count} statements...")
                
        connection.commit()
        cursor.close()
        connection.close()
        print(f"Done! Executed {count} statements.")
    except Exception as e:
        print(f"Error: {e}")

if __name__ == "__main__":
    run_import()
