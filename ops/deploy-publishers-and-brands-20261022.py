#!/usr/bin/env python3
import os
import sys
import subprocess
import hashlib
import time

FILES_TO_DEPLOY = [
    # Theme templates & logic
    "public_html/wp-content/themes/akibara-gp/template-parts/brands/grid.php",
    "public_html/wp-content/themes/akibara-gp/template-parts/brands/home-carousel.php",
    "public_html/wp-content/themes/akibara-gp/template-parts/brands/sprite.php",
    "public_html/wp-content/themes/akibara-gp/inc/woocommerce.php",
    # CSS & Minified CSS
    "public_html/wp-content/themes/akibara-gp/assets/css/brands.css",
    "public_html/wp-content/themes/akibara-gp/assets/css/brands.min.css",
    "public_html/wp-content/themes/akibara-gp/assets/css/brands-home.css",
    "public_html/wp-content/themes/akibara-gp/assets/css/brands-home.min.css",
    # Business plugin BrandRegion (badges + slug mapping)
    "public_html/wp-content/plugins/akibara-business/src/Modules/BrandsDirectory/BrandRegion.php",
    # Publisher SVGs (matrix & fallbacks)
    "public_html/wp-content/themes/akibara-gp/assets/images/publishers/ivrea-argentina.svg",
    "public_html/wp-content/themes/akibara-gp/assets/images/publishers/ivrea-espana.svg",
    "public_html/wp-content/themes/akibara-gp/assets/images/publishers/ivrea.svg",
    "public_html/wp-content/themes/akibara-gp/assets/images/publishers/panini.svg",
    "public_html/wp-content/themes/akibara-gp/assets/images/publishers/distrito-manga.svg",
    # Editorial SVGs
    "public_html/wp-content/themes/akibara-gp/assets/img/editoriales/ivrea-argentina.svg",
    "public_html/wp-content/themes/akibara-gp/assets/img/editoriales/ivrea-espana.svg",
    "public_html/wp-content/themes/akibara-gp/assets/img/editoriales/panini.svg",
    "public_html/wp-content/themes/akibara-gp/assets/img/editoriales/panini-argentina.svg",
    "public_html/wp-content/themes/akibara-gp/assets/img/editoriales/panini-espana.svg",
    "public_html/wp-content/themes/akibara-gp/assets/img/editoriales/distrito-manga.svg",
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
    backup_dir = os.path.join(LOCAL_BACKUP_BASE, f"publishers_brands_{timestamp}")
    os.makedirs(backup_dir, exist_ok=True)
    print(f"=== DEPLOY PUBLISHERS, AUTHENTIC COLORS & BRAND IDENTITY ===")
    print(f"Local backup directory: {backup_dir}\n")

    # Step 1: Pre-deployment backup of remote files
    print("1. Downloading pre-deployment backups from production...")
    for rel_path in FILES_TO_DEPLOY:
        remote_rel = rel_path.replace("public_html/", "")
        remote_path = f"{REMOTE_BASE}/{remote_rel}"
        safe_name = remote_rel.replace("/", "__")
        local_backup_path = os.path.join(backup_dir, safe_name)
        
        cmd = ["ssh", SSH_HOST, f"cat {remote_path}"]
        res = subprocess.run(cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        if res.returncode == 0 and len(res.stdout) > 0:
            with open(local_backup_path, "wb") as f:
                f.write(res.stdout)
            print(f"  [BACKUP OK] {remote_rel} ({len(res.stdout)} bytes)")
        else:
            print(f"  [NEW FILE ON REMOTE] {remote_rel}")

    # Step 2: Ensure directories exist and deploy atomically
    print("\n2. Deploying files atomically with SHA-256 verification...")
    for rel_path in FILES_TO_DEPLOY:
        local_path = rel_path
        if not os.path.exists(local_path):
            print(f"  [ERROR] Local file does not exist: {local_path}")
            sys.exit(1)
        
        local_hash = sha256_file(local_path)
        remote_rel = rel_path.replace("public_html/", "")
        remote_path = f"{REMOTE_BASE}/{remote_rel}"
        remote_dir = os.path.dirname(remote_path)

        # Ensure directory exists
        subprocess.run(["ssh", SSH_HOST, f"mkdir -p {remote_dir}"], check=True)

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

    # Step 3: Touch files to prevent 1980 mtime issue
    print("\n3. Re-stamping remote file modification times...")
    remote_files_str = " ".join([f"{REMOTE_BASE}/{f.replace('public_html/', '')}" for f in FILES_TO_DEPLOY])
    subprocess.run(["ssh", SSH_HOST, f"touch {remote_files_str}"], check=True)
    print("  [TOUCH OK] Remote timestamps re-stamped.")

    # Step 4: Verify zero temporary files
    print("\n4. Verifying zero remote temporary/backup files on production...")
    chk_tmp = ["ssh", SSH_HOST, f"find {REMOTE_BASE}/wp-content/themes/akibara-gp {REMOTE_BASE}/wp-content/plugins/akibara-business/src/Modules/BrandsDirectory -name '*.bak' -o -name '*.tmp' -o -name '*~' 2>/dev/null"]
    res = subprocess.run(chk_tmp, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
    if res.stdout.strip():
        print(f"  [TEMP FILES FOUND] Deleting: {res.stdout.strip()}")
        subprocess.run(["ssh", SSH_HOST, f"rm -f {res.stdout.strip().replace(chr(10), ' ')}"])
    else:
        print("  [CLEAN] 0 remote temporary files found.")

    # Step 5: Bump brand cache generation & purge transients
    print("\n5. Invalidating brand transients and cache generations...")
    cmd_bump = [
        "ssh", SSH_HOST,
        f"wp --path={REMOTE_BASE} eval 'update_option(\"akb_brands_grid_gen\", time()); delete_transient(\"akb_brands_home_items\"); echo \"Transients invalidated OK\n\";'"
    ]
    res_bump = subprocess.run(cmd_bump, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
    print(f"  [TRANSIENTS] {res_bump.stdout.strip() or res_bump.stderr.strip()}")

    # Step 6: Purge LiteSpeed & Cloudflare
    print("\n6. Purging LiteSpeed and Cloudflare caches...")
    purge_cmd = [
        "ssh", SSH_HOST,
        f"wp --path={REMOTE_BASE} litespeed-purge all"
    ]
    res_lsc = subprocess.run(purge_cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
    print(f"  [LITESPEED] {res_lsc.stdout.strip() or res_lsc.stderr.strip()}")

    cf_script = "tools/akibara-releases/rewards-qa/purge-cloudflare.mjs"
    if os.path.exists(cf_script):
        res_cf = subprocess.run(["node", cf_script], stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
        print(f"  [CLOUDFLARE] {res_cf.stdout.strip() or res_cf.stderr.strip()}")

    print("\n=== DEPLOYMENT COMPLETED SUCCESSFULLY ===")

if __name__ == "__main__":
    main()
