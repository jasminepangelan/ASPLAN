#!/usr/bin/env python3
"""
Import SQL file to Railway MySQL database
"""

import sys
import os

# Try importing mysql connector
try:
    import mysql.connector
except ImportError:
    print("Error: mysql-connector-python is not installed.")
    print("Installing...")
    os.system("pip install mysql-connector-python")
    import mysql.connector

def import_sql_file(host, port, user, password, database, sql_file):
    """Import SQL file to MySQL database"""
    
    try:
        # Connect to MySQL
        connection = mysql.connector.connect(
            host=host,
            port=port,
            user=user,
            password=password,
            database=database,
            autocommit=False
        )
        
        cursor = connection.cursor()
        print(f"✓ Connected to {host}:{port}")
        
        # Read SQL file
        with open(sql_file, 'r', encoding='utf-8') as f:
            sql_content = f.read()
        
        print(f"✓ Loaded SQL file ({len(sql_content)} bytes)")
        
        # Split SQL statements and execute
        statements = [s.strip() for s in sql_content.split(';') if s.strip()]
        print(f"✓ Found {len(statements)} SQL statements")
        
        executed = 0
        for i, statement in enumerate(statements):
            try:
                if statement:
                    cursor.execute(statement)
                    executed += 1
                    
                    # Print progress
                    if (executed % 10) == 0:
                        print(f"  Executed {executed}/{len(statements)} statements...")
            except Exception as e:
                print(f"Error executing statement {i+1}: {str(e)}")
                # Continue with next statement
                pass
        
        # Commit changes
        connection.commit()
        cursor.close()
        connection.close()
        
        print(f"✓ Import completed successfully!")
        print(f"  Total statements executed: {executed}")
        
    except Exception as e:
        print(f"✗ Error: {str(e)}")
        sys.exit(1)

if __name__ == "__main__":
    # Railway database credentials
    HOST = "thomas.proxy.rlwy.net"
    PORT = 45044
    USER = "root"
    PASSWORD = "qMsZwbiIngMfINmKygVSbMIiqJfdoTst"
    DATABASE = "railway"
    SQL_FILE = os.path.join(os.path.dirname(__file__), "backups", "railway-production-2026-07-05.sql")
    
    print("=" * 60)
    print("Railway MySQL Database Importer")
    print("=" * 60)
    print(f"Host: {HOST}:{PORT}")
    print(f"User: {USER}")
    print(f"Database: {DATABASE}")
    print(f"SQL File: {SQL_FILE}")
    print("=" * 60)
    
    if not os.path.exists(SQL_FILE):
        print(f"✗ SQL file not found: {SQL_FILE}")
        sys.exit(1)
    
    import_sql_file(HOST, PORT, USER, PASSWORD, DATABASE, SQL_FILE)
