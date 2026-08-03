import mysql.connector, sys, io
sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding="utf-8", errors="replace")

# Check zealous-beauty (source)
conn = mysql.connector.connect(
    host='hayabusa.proxy.rlwy.net', port=58143,
    user='root', password='PIlezyGzBauvijKewcPUtNqUtETTNcfP',
    database='railway', connect_timeout=15
)
c = conn.cursor()
c.execute("SELECT student_number, picture FROM student_info WHERE picture IS NOT NULL AND picture != '' ORDER BY student_number")
rows = c.fetchall()
print(f"=== zealous-beauty: students with picture set ({len(rows)} total) ===")
anon = 0
real = 0
for snum, pic in rows:
    if pic == 'pix/anonymous.jpg' or pic == 'pix/generic_user.svg':
        anon += 1
    else:
        real += 1
        print(f"  {snum}: {pic}")
print(f"\nAnonymous/default: {anon}")
print(f"Actual uploaded:   {real}")
c.close()
conn.close()
