import os, subprocess
ssh_dir = os.path.expanduser("~/.ssh")
os.makedirs(ssh_dir, exist_ok=True)
key_path = os.path.join(ssh_dir, "id_rsa")
if not os.path.exists(key_path):
    subprocess.run(["ssh-keygen", "-t", "rsa", "-b", "4096", "-f", key_path, "-q", "-N", ""])
    print("Generated")
else:
    print("Exists")
