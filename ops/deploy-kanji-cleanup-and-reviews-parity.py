#!/usr/bin/env python3
import os
import sys
import subprocess
import hashlib
import time

FILES_TO_DEPLOY = [
    "public_html/wp-content/themes/akibara-gp/template-parts/header/shell.php",
    "public_html/wp-content/themes/akibara-gp/assets/css/header.css",
    "public_html/wp-content/themes/akibara-gp/assets/css/core.css",
    "public_html/wp-content/themes/akibara-gp/assets/css/core.min.css",
    "public_html/wp-content/themes/akibara-gp/assets/css/loop.css",
    "public_html/wp-content/themes/akibara-gp/assets/css/loop.min.css",
    "public_html/wp-content/themes/akibara-gp/assets/css/woocommerce.css",
    "public_html/wp-content/themes/akibara-gp/assets/css/woocommerce-listado.min.css",
    "public_html/wp-content/themes/akibara-gp/assets/css/woocommerce-ficha.min.css",
    "public_html/wp-content/themes/akibara-gp/assets/css/woocommerce-carrito.min.css",
    "public_html/wp-content/themes/akibara-gp/assets/css/woocommerce-cuenta.min.css",
    "public_html/wp-content/plugins/akibara-rewards/src/Reviews/Carousel.php"
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
    backup_dir = os.path.join(LOCAL_BACKUP_BASE, f"kanji_cleanup_reviews_parity_{timestamp}")
    os.makedirs(backup_dir, exist_ok=True)
    print(f"=== DEPLOY KANJI CLEANUP + VOCES DEL DISTRITO EQUAL HEIGHT PARITY ===")
    print(f"Local backup dir: {backup_dir}\n")

    # Step 1: Download backups from production
    print("1. Downloading pre-deployment backups from production...")
    for rel_path in FILES_TO_DEPLOY:
        remote_rel = rel_path.replace("public_html/", "")
        remote_path = f"{REMOTE_BASE}/{remote_rel}"
        local_backup_path = os.path.join(backup_dir, rel_path.replace("/", "_"))
        
        cmd = ["ssh", SSH_HOST, f"cat {remote_path}"]
        res = subprocess.run(cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        if res.returncode == 0:
            with open(local_backup_path, "wb") as f:
                f.write(res.stdout)
            print(f"  [BACKUP OK] {rel_path} -> {local_backup_path} ({len(res.stdout)} bytes)")
        else:
            print(f"  [BACKUP WARN] Remote file not found or empty: {remote_path}")

    # Step 2: SCP local files to production
    print("\n2. Uploading files to production via SCP...")
    for rel_path in FILES_TO_DEPLOY:
        remote_rel = rel_path.replace("public_html/", "")
        remote_path = f"{REMOTE_BASE}/{remote_rel}"
        cmd = ["scp", rel_path, f"{SSH_HOST}:{remote_path}"]
        res = subprocess.run(cmd)
        if res.returncode != 0:
            print(f"  [ERROR] Failed to SCP {rel_path} to {remote_path}")
            sys.exit(1)
        print(f"  [SCP OK] {rel_path} -> {remote_path}")

    # Step 3: Verify SHA256 integrity
    print("\n3. Verifying SHA-256 integrity between local and production...")
    all_matched = True
    for rel_path in FILES_TO_DEPLOY:
        local_hash = sha256_file(rel_path)
        remote_rel = rel_path.replace("public_html/", "")
        remote_path = f"{REMOTE_BASE}/{remote_rel}"
        cmd = ["ssh", SSH_HOST, f"sha256sum {remote_path}"]
        res = subprocess.run(cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
        if res.returncode == 0:
            remote_hash = res.stdout.strip().split()[0]
            if local_hash == remote_hash:
                print(f"  [HASH MATCH] {rel_path}: {local_hash[:12]}...")
            else:
                print(f"  [HASH MISMATCH] {rel_path}!\n    Local:  {local_hash}\n    Remote: {remote_hash}")
                all_matched = False
        else:
            print(f"  [HASH ERROR] Could not compute remote hash for {remote_path}")
            all_matched = False

    if not all_matched:
        print("\n[CRITICAL ERROR] Hash verification failed on production!")
        sys.exit(1)

    # Step 4: Check for 0 remote temporaries
    print("\n4. Checking for zero remote temporaries (.bak, .tmp, ~)...")
    cmd = ["ssh", SSH_HOST, f"find {REMOTE_BASE}/wp-content/themes/akibara-gp {REMOTE_BASE}/wp-content/plugins/akibara-rewards -name '*.bak' -o -name '*.tmp' -o -name '*~'"]
    res = subprocess.run(cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
    temp_files = res.stdout.strip()
    if temp_files:
        print(f"  [WARN] Found remote temporary files:\n{temp_files}")
        sys.exit(1)
    else:
        print("  [CLEAN] 0 remote temporary files found.")

    # Step 5: Purge LiteSpeed Cache
    print("\n5. Purging LiteSpeed cache on production...")
    cmd = ["ssh", SSH_HOST, f"cd {REMOTE_BASE} && wp litespeed-purge all"]
    res = subprocess.run(cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
    print(f"  [LITESPEED] {res.stdout.strip()}")

    print("\n=== DEPLOYMENT COMPLETED SUCCESSFULLY ===")

if __name__ == "__main__":
    main()
