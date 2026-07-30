import sys
import mysql.connector

HOST = 'thomas.proxy.rlwy.net'
PORT = 45044
USER = 'root'
PASSWORD = 'qMsZwbiIngMfINmKygVSbMIiqJfdoTst'
DATABASE = 'railway'

try:
    connection = mysql.connector.connect(
        host=HOST,
        port=PORT,
        user=USER,
        password=PASSWORD,
        database=DATABASE,
        autocommit=True
    )
    cursor = connection.cursor()
    print('Connected to Railway database.')
    
    cursor.execute("SELECT COUNT(*) FROM student_checklists WHERE final_grade = 'No Grade'")
    count = cursor.fetchone()[0]
    print(f'Found {count} rows with final_grade = "No Grade"')
    
    if count > 0:
        cursor.execute("UPDATE student_checklists SET evaluator_remarks = 'Pending' WHERE final_grade = 'No Grade'")
        print(f'Updated {cursor.rowcount} rows.')
        
    cursor.close()
    connection.close()
except Exception as e:
    print(f'Error: {e}')
