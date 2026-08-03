import urllib.request, json, sys, io
sys.stdout = io.TextIOWrapper(sys.stdout.buffer, encoding="utf-8", errors="replace")

TOKEN = "VWPy3C2MMz-1ybjQuc6C9We4Nd0y60I3-MSB74k1vpk"
PROJECT_ID = "f8f15d38-5c33-4f1a-9b1a-862fa20e4eed"
ENV_ID     = "78026611-1184-4fd4-b35b-6570662970d2"

# Service IDs known from earlier
SERVICES = {
    "ASPLAN":            "1f89a858-c5b3-4830-9730-892f689c631d",
    "romantic-optimism": "91c63f62-466f-46e4-93a1-81ee106bdd04",
}

def gql(query, variables=None):
    payload = json.dumps({"query": query, "variables": variables or {}}).encode()
    req = urllib.request.Request(
        "https://backboard.railway.com/graphql/v2",
        data=payload,
        headers={
            "Content-Type": "application/json",
            "Authorization": f"Bearer {TOKEN}",
        }
    )
    with urllib.request.urlopen(req, timeout=15) as resp:
        data = json.loads(resp.read().decode())
    if "errors" in data:
        raise Exception(json.dumps(data["errors"]))
    return data["data"]

# Query the project for volumes via serviceInstance
query = """
query($serviceId: String!, $environmentId: String!) {
  serviceInstance(serviceId: $serviceId, environmentId: $environmentId) {
    id
    serviceId
    volumes {
      id
      name
      mountPath
      sizeMB
      currentSizeMB
      createdAt
    }
  }
}
"""

print("=== Volume info per service ===\n")
for name, sid in SERVICES.items():
    print(f"--- {name} (service ID: {sid}) ---")
    try:
        data = gql(query, {"serviceId": sid, "environmentId": ENV_ID})
        si = data.get("serviceInstance")
        if not si:
            print("  No service instance found")
            continue
        vols = si.get("volumes", [])
        if not vols:
            print("  No volumes attached")
        for v in vols:
            print(f"  Volume ID  : {v['id']}")
            print(f"  Name       : {v['name']}")
            print(f"  Mount path : {v['mountPath']}")
            print(f"  Size       : {v['currentSizeMB']:.2f} / {v['sizeMB']} MB")
    except Exception as e:
        # Try alternate schema
        print(f"  Error: {e}")
    print()
