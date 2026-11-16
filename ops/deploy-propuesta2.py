#!/usr/bin/env python3
"""
Deploy Propuesta 2: Distrito Manga & Cómics to Production (akibara.cl).
Deploys only the exact 16 scoped files touched by Propuesta 2.
Atomic replacement with mktemp -> sha256 verify -> mv -f -> cleanup check.
"""
import hashlib
import json
import shlex
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SSH = ['ssh', '-o', 'BatchMode=yes', '-o', 'StrictHostKeyChecking=yes',
       '-o', 'ConnectTimeout=15', 'akibara']
REMOTE_ROOT = 'domains/akibara.cl/public_html'

FILES_TO_DEPLOY = [
    # Theme files
    'wp-content/themes/akibara-gp/inc/title-parser.php',
    'wp-content/themes/akibara-gp/template-parts/woocommerce/sticky-atc.php',
    'wp-content/themes/akibara-gp/assets/css/woocommerce.css',
    'wp-content/themes/akibara-gp/assets/css/home.css',
    'wp-content/themes/akibara-gp/assets/css/home.min.css',
    'wp-content/themes/akibara-gp/assets/css/woocommerce-listado.min.css',
    'wp-content/themes/akibara-gp/assets/css/woocommerce-ficha.min.css',
    'wp-content/themes/akibara-gp/assets/css/woocommerce-carrito.min.css',
    'wp-content/themes/akibara-gp/assets/css/woocommerce-cuenta.min.css',
    # Plugin files
    'wp-content/plugins/akibara-business/src/Modules/PdpEnhancer/PdpEnhancer.php',
    'wp-content/plugins/akibara-business/assets/css/pdp-enhancer.css',
    'wp-content/plugins/akibara-business/assets/js/native-cart.js',
    'wp-content/plugins/akibara-business/assets/css/native-cart-v2.css',
    'wp-content/plugins/akibara-business/templates/myaccount/collection.php',
    'wp-content/plugins/akibara-business/assets/css/account-collection.css',
]

def sha256(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()

def run_remote(cmd: str, input_data: bytes = None) -> subprocess.CompletedProcess:
    return subprocess.run(SSH + [cmd], input=input_data, capture_output=True, check=True)

def deploy_file(rel_path: str):
    local_path = ROOT / 'public_html' / rel_path
    if not local_path.is_file():
        raise FileNotFoundError(f"Local file not found: {local_path}")
    
    data = local_path.read_bytes()
    expected_sha = sha256(data)
    quoted_rel = shlex.quote(rel_path)
    
    print(f"Deploying {rel_path} (sha256: {expected_sha[:12]}...)...")
    
    # 1. Upload via mktemp next to target, verify hash, atomic replace, touch, verify cleanup
    remote_script = f"""set -eu
cd ~/{REMOTE_ROOT}
dir=$(dirname {quoted_rel})
test -d "$dir" || mkdir -p "$dir"

temp=$(mktemp "{quoted_rel}.prop2.XXXXXX")
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
leftovers=$(ls {quoted_rel}.prop2.* 2>/dev/null || true)
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
    print(f"=== Starting scoped deploy of Propuesta 2 ({len(FILES_TO_DEPLOY)} files) ===")
    
    # Pre-flight check
    for f in FILES_TO_DEPLOY:
        p = ROOT / 'public_html' / f
        if not p.is_file():
            sys.exit(f"Error: {p} does not exist.")
    
    # Deploy files
    for f in FILES_TO_DEPLOY:
        deploy_file(f)
    print("All files transferred and verified atomically!")
    
    # Post deploy maintenance
    print("\n=== Running post-deploy cache flush & warming ===")
    print("1. Flushing rewrite rules...")
    run_remote(f"cd ~/{REMOTE_ROOT} && /usr/local/bin/wp rewrite flush --hard")
    
    print("2. Probing sitemap...")
    probe_res = subprocess.run(
        ["curl", "-s", "-o", "/dev/null", "-w", "%{http_code}", "https://akibara.cl/sitemap_index.xml"],
        capture_output=True, text=True
    )
    print(f"Sitemap HTTP status: {probe_res.stdout.strip()}")
    
    print("3. Warming discovery cron...")
    run_remote(f"cd ~/{REMOTE_ROOT} && /usr/local/bin/wp cron event run akibara_discovery_warming || true")
    
    print("4. Purging LiteSpeed cache...")
    run_remote(f"cd ~/{REMOTE_ROOT} && /usr/local/bin/wp eval 'do_action(\"litespeed_purge_all\");' 2>/dev/null || true")
    
    print("\n=== Verifying no temporary files left in production ===")
    check_temp = run_remote(f"cd ~/{REMOTE_ROOT} && find wp-content/themes wp-content/plugins -name '*.prop2.*' -o -name '*.bak' 2>/dev/null || true")
    temp_output = check_temp.stdout.decode().strip()
    if temp_output:
        print(f"WARNING: Temporary files found: {temp_output}")
    else:
        print("0 temporary files, 0 .bak files in production. Clean!")

    print("\n=== Propuesta 2 deployed successfully! ===")

if __name__ == '__main__':
    main()
