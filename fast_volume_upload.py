import os
import subprocess

STAGE_DIR = r"c:\Users\Stephen Tiozon\Documents\GitHub\ASPLAN\exports\uploads_stage"
VOLUME_ID = "4506ee3d-d120-4116-9c75-36051ea7d302"

files = os.listdir(STAGE_DIR)
for f in files:
    local_path = os.path.join(STAGE_DIR, f)
    remote_path = f"/uploads/{f}"
    cmd = [
        "powershell", "-Command",
        f'railway volume files -v {VOLUME_ID} upload "{local_path}" "{remote_path}" --overwrite'
    ]
    print(f"Uploading {f}...")
    try:
        proc = subprocess.run(
            cmd,
            input=b"\n\n\n\n",
            capture_output=True,
            timeout=30
        )
        if proc.returncode != 0:
            print("Failed:", proc.stderr.decode('utf-8', errors='replace'))
    except Exception as e:
        print("Error:", e)
        
print("All files uploaded successfully!")
