#!/usr/bin/env python3
"""
SQL File Importer - Import railway-production-2026-07-05.sql to MySQL
"""

import os
import sys
import re
from pathlib import Path

# Add the config to path
config_dir = Path(__file__).parent / 'config'

# Load environment variables from .env if it exists
env_file = Path(__file__).parent / '.env'
if env_file.exists():
    with open(env_file, 'r') as f:
        for line in f:
            line = line.strip()
            if line and not line.startswith('#'):
                if '=' in line:
                    key, value = line.split('=', 1)
                    key = key.strip()
                    value = value.strip().strip('"\'')
                    os.environ[key] = value

# Get database connection details from environment
def get_db_config():
    """Parse database configuration from environment variables"""
    
    # Try DATABASE_URL first
    db_url = os.environ.get('DATABASE_URL') or os.environ.get('MYSQL_URL') or os.environ.get('MYSQL_PUBLIC_URL')
    
    if db_url:
        # Parse URL: mysql://user:password@host:port/database
        match = re.match(r'mysql://([^:]+):([^@]+)@([^:/]+):(\d+)/(.+)', db_url)
        if match:
            return {
                'host': match.group(3),
                'user': match.group(1),
                'password': match.group(2),
                'database': match.group(5),
                'port': int(match.group(4))
            }
    
    # Fall back to individual environment variables
    return {
        'host': os.environ.get('DB_HOST') or os.environ.get('MYSQLHOST') or 'localhost',
        'user': os.environ.get('DB_USER') or os.environ.get('DB_USERNAME') or os.environ.get('MYSQLUSER') or 'root',
        'password': os.environ.get('DB_PASS') or os.environ.get('DB_PASSWORD') or os.environ.get('MYSQLPASSWORD') or '',
        'database': os.environ.get('DB_NAME') or os.environ.get('DB_DATABASE') or os.environ.get('MYSQLDATABASE') or 'osas_db',
        'port': int(os.environ.get('DB_PORT') or os.environ.get('MYSQLPORT') or 3306)
    }

def import_sql_file(sql_file_path, db_config):
    """Import SQL file to MySQL database"""
    
    try:
        import mysql.connector
    except ImportError:
        print("Error: mysql-connector-python is not installed")
        print("Installing mysql-connector-python...")
        os.system('pip install mysql-connector-python')
        import mysql.connector
    
    try:
        # Verify file exists
        if not os.path.exists(sql_file_path):
            print(f"Error: SQL file not found at {sql_file_path}")
            return False
        
        print(f"SQL File: {sql_file_path}")
        print(f"Database: {db_config['database']}")
        print(f"Host: {db_config['host']}")
        print(f"Port: {db_config['port']}")
        print()
        
        # Connect to database
        print("Connecting to database...")
        connection = mysql.connector.connect(
            host=db_config['host'],
            user=db_config['user'],
            password=db_config['password'],
            database=db_config['database'],
            port=db_config['port']
        )
        
        if connection.is_connected():
            print("✓ Connected successfully!")
        else:
            print("✗ Connection failed")
            return False
        
        cursor = connection.cursor()
        
        # Read SQL file
        print("\nReading SQL file...")
        with open(sql_file_path, 'r', encoding='utf-8') as f:
            sql_content = f.read()
        
        # Split statements - handle various delimiters
        statements = re.split(r';\s*(?=\n|$)', sql_content)
        
        executed = 0
        skipped = 0
        errors = []
        
        print("Executing statements...")
        for i, statement in enumerate(statements, 1):
            statement = statement.strip()
            
            # Skip empty statements and comments
            if not statement or statement.startswith('--') or statement.startswith('/*'):
                skipped += 1
                continue
            
            try:
                cursor.execute(statement)
                connection.commit()
                executed += 1
                print(".", end="", flush=True)
                
                if executed % 50 == 0:
                    print(f" [{executed}]")
            except Exception as e:
                errors.append({
                    'statement': statement[:100],
                    'error': str(e)
                })
                print("E", end="", flush=True)
        
        print(f"\n\nImport Summary:")
        print(f"  Statements executed: {executed}")
        print(f"  Statements skipped: {skipped}")
        
        if errors:
            print(f"\n  Errors encountered: {len(errors)}")
            for err in errors[:5]:  # Show first 5 errors
                print(f"    - {err['error'][:80]}")
                print(f"      Statement: {err['statement']}...")
        
        cursor.close()
        connection.close()
        
        print("\n✓ Import completed!")
        return True
        
    except Exception as e:
        print(f"✗ Error: {e}")
        return False

if __name__ == '__main__':
    sql_file = Path(__file__).parent / 'backups' / 'railway-production-2026-07-05.sql'
    db_config = get_db_config()
    
    success = import_sql_file(str(sql_file), db_config)
    sys.exit(0 if success else 1)
