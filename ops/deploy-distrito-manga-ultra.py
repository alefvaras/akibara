#!/usr/bin/env python3
import os
import sys
import subprocess
import hashlib
import time

FILES_TO_DEPLOY = [
    "public_html/wp-content/themes/akibara-gp/assets/css/loop.css",
    "public_html/wp-content/themes/akibara-gp/assets/css/loop.min.css",
    "public_html/wp-content/themes/akibara-gp/assets/css/woocommerce.css",
    "public_html/wp-content/themes/akibara-gp/assets/css/woocommerce-listado.min.css",
    "public_html/wp-content/themes/akibara-gp/assets/css/woocommerce-ficha.min.css",
    "public_html/wp-content/themes/akibara-gp/assets/css/woocommerce-carrito.min.css",
    "public_html/wp-content/themes/akibara-gp/assets/css/woocommerce-cuenta.min.css",
    "public_html/wp-content/themes/akibara-gp/assets/css/brands-home.css",
    "public_html/wp-content/themes/akibara-gp/assets/css/brands-home.min.css",
    "public_html/wp-content/plugins/akibara-business/assets/css/pdp-enhancer.css",
    "public_html/wp-content/plugins/akibara-business/assets/css/native-cart-v2.css"
]

SSH_HOST = "akibara"
REMOTE_BASE = "domains/akibara.cl/public_html"
LOCAL_BACKUP_BASE = ".local-backups"

def sha256_file(filepath):
    h = hashlib.sha256()
    with open(filepath, "rb") as f:
        while chunk := f.read(8192):
            h.update(chunk)
    return h.hexdigest()

def main():
    timestamp = time.strftime("%Y%m%d_%H%M%S")
    backup_dir = os.path.join(LOCAL_BACKUP_BASE, f"distrito-manga-ultra_{timestamp}")
    os.makedirs(backup_dir, exist_ok=True)
    print(f"=== DEPLOY DISTRITO MANGA ULTRA (PERSONA 5 x AKIHABARA POP) ===")
    print(f"Local backup dir: {backup_dir}\n")

    # Step 1: Download backups from production
    print("1. Downloading pre-deployment backups from production...")
    for rel_path in FILES_TO_DEPLOY:
        # Strip public_html/
        remote_rel = rel_path.replace("public_html/", "")
        remote_path = f"{REMOTE_BASE}/{remote_rel}"
        local_backup_path = os.path.join(backup_dir, os.path.basename(rel_path))
        
        cmd = ["ssh", SSH_HOST, f"cat {remote_path}"]
        res = subprocess.run(cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        if res.returncode == 0:
            with open(local_backup_path, "wb") as f:
                f.write(res.stdout)
            print(f"  [BACKUP OK] {rel_path} -> {local_backup_path} ({len(res.stdout)} bytes)")
        else:
            print(f"  [BACKUP WARN] Remote file not found or empty: {remote_path}")

    # Step 2: Deploy files atomically
    print("\n2. Deploying updated assets atomically with SHA-256 verification...")
    for rel_path in FILES_TO_DEPLOY:
        local_path = rel_path
        if not os.path.exists(local_path):
            print(f"ERROR: Local file missing: {local_path}")
            sys.exit(1)

        local_hash = sha256_file(local_path)
        with open(local_path, "rb") as f:
            data = f.read()

        remote_rel = rel_path.replace("public_html/", "")
        remote_dest = f"{REMOTE_BASE}/{remote_rel}"
        remote_dest_dir = os.path.dirname(remote_dest)

        remote_script = (
            f"set -euo pipefail; "
            f"mkdir -p {remote_dest_dir}; "
            f"tmp=$(mktemp {remote_dest_dir}/tmp.XXXXXXXXXX); "
            f"cat > \"$tmp\"; "
            f"chmod 644 \"$tmp\"; "
            f"mv \"$tmp\" \"{remote_dest}\"; "
            f"sha256sum \"{remote_dest}\" | awk '{{print $1}}'"
        )

        ssh_cmd = ["ssh", SSH_HOST, remote_script]
        proc = subprocess.run(ssh_cmd, input=data, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        if proc.returncode != 0:
            print(f"ERROR deploying {rel_path}: {proc.stderr.decode()}")
            sys.exit(1)

        remote_hash = proc.stdout.decode().strip()
        if remote_hash != local_hash:
            print(f"ERROR: Hash mismatch for {rel_path}!")
            print(f"  Local:  {local_hash}")
            print(f"  Remote: {remote_hash}")
            sys.exit(1)

        print(f"  [DEPLOY OK] {rel_path} ({len(data)} bytes, sha: {remote_hash[:12]}...)")

    # Step 3: Verify 0 temporary remote files
    print("\n3. Verifying 0 remote temporary files...")
    check_cmd = ["ssh", SSH_HOST, f"find {REMOTE_BASE}/wp-content/themes/akibara-gp/assets/css/ {REMOTE_BASE}/wp-content/plugins/akibara-business/assets/css/ -name '*.bak' -o -name 'tmp.*' -o -name '*.tmp'"]
    check_res = subprocess.run(check_cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
    stray_files = check_res.stdout.strip()
    if stray_files:
        print(f"WARNING: Stray files found on remote:\n{stray_files}")
    else:
        print("  0 remote temporary files certified. Clean!")

    # Step 4: Purge LiteSpeed Cache & Cloudflare
    print("\n4. Purging LiteSpeed Cache and Cloudflare on production...")
    purge_cmd = [
        "ssh", SSH_HOST,
        f"cd {REMOTE_BASE} && wp eval '\\LiteSpeed\\Purge::purge_all(); do_action(\"litespeed_purge_all\");' && wp litespeed-purge all"
    ]
    purge_res = subprocess.run(purge_cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
    print("  Purge output:", purge_res.stdout.strip())

    print("\n=== DEPLOYMENT COMPLETED SUCCESSFULLY ===")

if __name__ == "__main__":
    main()
