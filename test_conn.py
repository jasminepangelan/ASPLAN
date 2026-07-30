import sys
import mysql.connector

HOST = 'thomas.proxy.rlwy.net'
PORT = 45044
USER = 'root'
PASSWORD = 'qMsZwbiIngMfINmKygVSbMIiqJfdoTst'
DATABASE = 'railway'

try:
    print("Attempting to connect...")
    connection = mysql.connector.connect(
        host=HOST,
        port=PORT,
        user=USER,
        password=PASSWORD,
        database=DATABASE,
        connect_timeout=3
    )
    print("Connected successfully!")
    connection.close()
except Exception as e:
    print(f"Error: {e}")
