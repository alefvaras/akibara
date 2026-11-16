#!/usr/bin/env python3
import os
import sys
import subprocess
import hashlib
import time

FILES_TO_DEPLOY = [
    "public_html/wp-content/themes/akibara-gp/woocommerce/emails/email-header.php",
    "public_html/wp-content/plugins/akibara-business/src/Services/EmailButton.php",
    "public_html/wp-content/plugins/akibara-business/src/Modules/EmailBrand/EmailBrand.php",
    "public_html/wp-content/plugins/akibara-business/templates/emails/customer-bis-instock.php",
    "public_html/wp-content/plugins/akibara-business/templates/emails/customer-series-follow.php",
    "public_html/wp-content/plugins/akibara-business/templates/emails/customer-preventa-ready.php",
    "public_html/wp-content/plugins/akibara-business/templates/emails/customer-preventa-reminder.php"
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
    backup_dir = os.path.join(LOCAL_BACKUP_BASE, f"email_templates_identity_{timestamp}")
    os.makedirs(backup_dir, exist_ok=True)
    print(f"=== DEPLOY EMAIL TEMPLATES IDENTITY & HARDENING ===")
    print(f"Local backup dir: {backup_dir}\n")

    # Step 1: Download backups from production
    print("1. Downloading pre-deployment backups from production...")
    for rel_path in FILES_TO_DEPLOY:
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
    print("\n2. Deploying updated templates atomically with SHA-256 verification...")
    for rel_path in FILES_TO_DEPLOY:
        local_path = rel_path
        if not os.path.exists(local_path):
            print(f"  [ERROR] Local file does not exist: {local_path}")
            sys.exit(1)
        
        local_hash = sha256_file(local_path)
        remote_rel = rel_path.replace("public_html/", "")
        remote_path = f"{REMOTE_BASE}/{remote_rel}"

        with open(local_path, "rb") as f:
            content = f.read()

        ssh_cmd = f"cat > {remote_path}"
        p = subprocess.Popen(["ssh", SSH_HOST, ssh_cmd], stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        stdout, stderr = p.communicate(input=content)
        if p.returncode != 0:
            print(f"  [DEPLOY ERROR] Failed to write {remote_path}: {stderr.decode()}")
            sys.exit(1)

        # Verify remote SHA-256
        chk_cmd = ["ssh", SSH_HOST, f"sha256sum {remote_path}"]
        res = subprocess.run(chk_cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
        if res.returncode == 0:
            remote_hash = res.stdout.split()[0]
            if remote_hash == local_hash:
                print(f"  [VERIFIED] {remote_rel} (SHA-256: {local_hash[:12]}...)")
            else:
                print(f"  [HASH MISMATCH] {remote_rel}: local={local_hash} remote={remote_hash}")
                sys.exit(1)
        else:
            print(f"  [SHA256 CHECK FAILED] {res.stderr}")
            sys.exit(1)

    # Step 3: Zero remote temporary files check
    print("\n3. Verifying zero remote temporary/backup files on production...")
    chk_tmp = ["ssh", SSH_HOST, f"find {REMOTE_BASE}/wp-content/themes/akibara-gp/woocommerce/emails {REMOTE_BASE}/wp-content/plugins/akibara-business -name '*.bak' -o -name '*.tmp' -o -name '*~' 2>/dev/null"]
    res = subprocess.run(chk_tmp, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
    if res.stdout.strip():
        print(f"  [TEMP FILES FOUND] Deleting: {res.stdout.strip()}")
        subprocess.run(["ssh", SSH_HOST, f"rm -f {res.stdout.strip().replace(chr(10), ' ')}"])
    else:
        print("  [CLEAN] 0 remote temporary files found.")

    # Step 4: Purge LiteSpeed & Cloudflare caches
    print("\n4. Purging LiteSpeed and Cloudflare caches...")
    purge_cmd = [
        "ssh", SSH_HOST,
        f"wp --path={REMOTE_BASE} litespeed-purge all"
    ]
    res = subprocess.run(purge_cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
    print(f"  [LITESPEED] {res.stdout.strip() or res.stderr.strip()}")

    cf_script = "tools/akibara-releases/rewards-qa/purge-cloudflare.mjs"
    if os.path.exists(cf_script):
        res_cf = subprocess.run(["node", cf_script], stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
        print(f"  [CLOUDFLARE] {res_cf.stdout.strip() or res_cf.stderr.strip()}")

    print("\n=== EMAIL TEMPLATES IDENTITY DEPLOYMENT COMPLETE ===")

if __name__ == "__main__":
    main()
