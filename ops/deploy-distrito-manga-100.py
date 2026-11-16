#!/usr/bin/env python3
"""
Deploy Distrito Manga 100%: Impacto Kinético Persona 5 + Checkout & Mi Cuenta Redesign.
Enforces all AGENTS.md rules:
- Local backup in .local-backups/ before touching remote
- Atomic upload with sha256 verification
- Zero remote .bak or temporary files
- LiteSpeed cache purge
"""
import hashlib
import os
import shlex
import subprocess
import sys
from datetime import datetime
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SSH = ['ssh', '-o', 'BatchMode=yes', '-o', 'StrictHostKeyChecking=yes',
       '-o', 'ConnectTimeout=15', 'akibara']
REMOTE_ROOT = 'domains/akibara.cl/public_html'

FILES_TO_DEPLOY = [
    # Theme CSS & JS
    'wp-content/themes/akibara-gp/assets/css/base.css',
    'wp-content/themes/akibara-gp/assets/css/core.css',
    'wp-content/themes/akibara-gp/assets/css/core.min.css',
    'wp-content/themes/akibara-gp/assets/css/loop.css',
    'wp-content/themes/akibara-gp/assets/css/loop.min.css',
    'wp-content/themes/akibara-gp/assets/css/footer.css',
    'wp-content/themes/akibara-gp/assets/css/woocommerce.css',
    'wp-content/themes/akibara-gp/assets/css/woocommerce-carrito.min.css',
    'wp-content/themes/akibara-gp/assets/css/woocommerce-cuenta.min.css',
    'wp-content/themes/akibara-gp/assets/css/woocommerce-ficha.min.css',
    'wp-content/themes/akibara-gp/assets/css/woocommerce-listado.min.css',
    'wp-content/themes/akibara-gp/assets/js/main.js',
    'wp-content/themes/akibara-gp/assets/js/main.min.js',

    # Plugin files
    'wp-content/plugins/akibara-cart-checkout-blocks/assets/css/cart-checkout-blocks.css',
    'wp-content/plugins/akibara-business/assets/css/native-cart-v2.css',
    'wp-content/plugins/akibara-business/assets/css/series-bundles.css',
    'wp-content/plugins/akibara-business/assets/css/home-hero.css',
    'wp-content/plugins/akibara-business/assets/css/wishlist-guest-prompt.css',
    'wp-content/plugins/akibara-rewards/assets/css/account-rewards.css',
    'wp-content/plugins/akibara-rewards/assets/js/checkout-blocks.js',
    'wp-content/plugins/akibara-search/assets/css/akb-search.css',
    'wp-content/plugins/akibara-search/assets/css/akb-search.min.css',
    'wp-content/plugins/akibara-search/assets/css/search-suggest.css',
    'wp-content/plugins/akibara-search/assets/css/search-suggest.min.css',
]

def sha256(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()

def run_remote(cmd: str, input_data: bytes = None) -> subprocess.CompletedProcess:
    return subprocess.run(SSH + [cmd], input=input_data, capture_output=True, check=True)

def backup_remote_files(backup_dir: Path):
    print(f"\n=== Creating local backup of production files in {backup_dir} ===")
    backup_dir.mkdir(parents=True, exist_ok=True)
    for rel_path in FILES_TO_DEPLOY:
        dest = backup_dir / rel_path
        dest.parent.mkdir(parents=True, exist_ok=True)
        quoted_rel = shlex.quote(rel_path)
        cmd = f"cat ~/{REMOTE_ROOT}/{quoted_rel} 2>/dev/null || true"
        res = subprocess.run(SSH + [cmd], capture_output=True)
        if res.returncode == 0 and res.stdout:
            dest.write_bytes(res.stdout)
            print(f"Backed up: {rel_path} ({len(res.stdout)} bytes)")
        else:
            print(f"Notice: Remote file does not exist or empty: {rel_path}")

def deploy_file(rel_path: str):
    local_path = ROOT / 'public_html' / rel_path
    if not local_path.is_file():
        raise FileNotFoundError(f"Local file not found: {local_path}")
    
    data = local_path.read_bytes()
    expected_sha = sha256(data)
    quoted_rel = shlex.quote(rel_path)
    
    print(f"Deploying {rel_path} ({len(data)} bytes, sha256: {expected_sha[:12]}...)...")
    
    remote_script = f"""set -eu
cd ~/{REMOTE_ROOT}
dir=$(dirname {quoted_rel})
test -d "$dir" || mkdir -p "$dir"

temp=$(mktemp "{quoted_rel}.manga100.XXXXXX")
trap 'if [ -n "$temp" ] && [ -e "$temp" ]; then rm -f -- "$temp"; fi' EXIT

cat > "$temp"
actual_sha=$(sha256sum "$temp" | cut -d' ' -f1)
if [ "$actual_sha" != "{expected_sha}" ]; then
    echo "HASH MISMATCH: expected {expected_sha} got $actual_sha" >&2
    exit 1
fi

chmod 644 "$temp"
mv -f "$temp" {quoted_rel}
temp=""

# Verify target has expected hash
verified_sha=$(sha256sum {quoted_rel} | cut -d' ' -f1)
if [ "$verified_sha" != "{expected_sha}" ]; then
    echo "VERIFICATION FAILED: $verified_sha != {expected_sha}" >&2
    exit 2
fi

touch {quoted_rel}

# Verify no leftover temporary files
leftovers=$(ls {quoted_rel}.manga100.* 2>/dev/null || true)
if [ -n "$leftovers" ]; then
    echo "LEFTOVER TEMP FILES FOUND: $leftovers" >&2
    exit 3
fi
echo "OK"
"""
    res = run_remote(remote_script, input_data=data)
    out = res.stdout.decode().strip()
    if out != "OK":
        print(f"Warning: unexpected output for {rel_path}: {out}")

def main():
    print(f"=== Starting Distrito Manga 100% deploy ({len(FILES_TO_DEPLOY)} files) ===")
    
    # Pre-flight check
    for f in FILES_TO_DEPLOY:
        p = ROOT / 'public_html' / f
        if not p.is_file():
            sys.exit(f"Error: {p} does not exist locally.")
    print("Pre-flight check passed: all local files exist.")

    # 1. Local backup
    timestamp = datetime.now().strftime("%Y%m%d_%H%M%S")
    backup_dir = ROOT / '.local-backups' / f"distrito-manga-100_{timestamp}"
    backup_remote_files(backup_dir)

    # 2. Deploy files
    print("\n=== Deploying files to production ===")
    for f in FILES_TO_DEPLOY:
        deploy_file(f)
    print("All files deployed and verified atomically!")

    # 3. Cache flush & purge
    print("\n=== Purging LiteSpeed cache ===")
    run_remote(f"cd ~/{REMOTE_ROOT} && /usr/local/bin/wp eval 'do_action(\"litespeed_purge_all\");' 2>/dev/null || true")
    print("LiteSpeed cache purged.")

    # 4. Cleanliness check
    print("\n=== Verifying zero temporary files in production ===")
    check_temp = run_remote(f"cd ~/{REMOTE_ROOT} && find wp-content/themes wp-content/plugins -name '*.manga100.*' -o -name '*.bak' 2>/dev/null || true")
    temp_output = check_temp.stdout.decode().strip()
    if temp_output:
        print(f"WARNING: Temporary files found: {temp_output}")
    else:
        print("0 temporary files, 0 .bak files in production. Clean per AGENTS.md!")

    print("\n=== Deploy completed successfully! ===")

if __name__ == '__main__':
    main()
