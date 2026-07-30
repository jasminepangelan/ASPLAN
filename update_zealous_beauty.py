import mysql.connector

HOST = 'hayabusa.proxy.rlwy.net'
PORT = 58143
USER = 'root'
PASSWORD = 'PIlezyGzBauvijKewcPUtNqUtETTNcfP'
DATABASE = 'railway'

try:
    print("Attempting to connect to zealous-beauty...")
    connection = mysql.connector.connect(
        host=HOST,
        port=PORT,
        user=USER,
        password=PASSWORD,
        database=DATABASE,
        connect_timeout=10
    )
    cursor = connection.cursor()
    print("Connected successfully!")
    
    # Check rows to update
    cursor.execute("SELECT COUNT(*) FROM student_checklists WHERE final_grade = 'No Grade'")
    count = cursor.fetchone()[0]
    print(f"Found {count} rows with final_grade = 'No Grade'")
    
    if count > 0:
        cursor.execute("UPDATE student_checklists SET evaluator_remarks = 'Pending' WHERE final_grade = 'No Grade'")
        connection.commit()
        print(f"Updated {cursor.rowcount} rows.")
    
    cursor.close()
    connection.close()
except Exception as e:
    print(f"Error: {e}")
