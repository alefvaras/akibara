#!/usr/bin/env python3
"""
Deploy Propuesta 1: Look Editorial Blanco & Coherencia Manga to Production (akibara.cl).
Deploys only the exact 12 scoped files touched by Propuesta 1.
Atomic replacement with mktemp -> sha256 verify -> mv -f -> cleanup check -> purge LiteSpeed.
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
    'wp-content/themes/akibara-gp/assets/css/loop.css',
    'wp-content/themes/akibara-gp/assets/css/loop.min.css',
    'wp-content/themes/akibara-gp/assets/css/woocommerce.css',
    'wp-content/themes/akibara-gp/assets/css/woocommerce-listado.min.css',
    'wp-content/themes/akibara-gp/assets/css/woocommerce-ficha.min.css',
    'wp-content/themes/akibara-gp/assets/css/woocommerce-carrito.min.css',
    'wp-content/themes/akibara-gp/assets/css/woocommerce-cuenta.min.css',
    # Plugin files
    'wp-content/plugins/akibara-business/assets/css/native-cart-v2.css',
    'wp-content/plugins/akibara-business/assets/css/quick-view.css',
    'wp-content/plugins/akibara-business/assets/css/pdp-enhancer.css',
    'wp-content/plugins/akibara-business/assets/css/series-bundles.css',
    'wp-content/plugins/akibara-business/assets/css/free-shipping-progress.css',
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
tmp=$(mktemp "$dir/deploy.tmp.XXXXXX")
trap 'rm -f "$tmp"' EXIT
cat > "$tmp"
remote_sha=$(sha256sum "$tmp" | cut -d' ' -f1)
if [ "$remote_sha" != "{expected_sha}" ]; then
    echo "SHA256 mismatch for {rel_path}: expected {expected_sha}, got $remote_sha" >&2
    exit 1
fi
mv -f "$tmp" {quoted_rel}
chmod 644 {quoted_rel}
trap - EXIT
"""
    run_remote(remote_script, input_data=data)
    
    # 2. Verify deployed file hash
    verify_cmd = f"cd ~/{REMOTE_ROOT} && sha256sum {quoted_rel} | cut -d' ' -f1"
    res = run_remote(verify_cmd)
    live_sha = res.stdout.decode().strip()
    if live_sha != expected_sha:
        raise RuntimeError(f"Post-deploy verification failed for {rel_path}: live={live_sha} != expected={expected_sha}")
    print(f"  ✓ {rel_path} verified on remote")

def purge_litespeed():
    print("\nPurging LiteSpeed cache on production...")
    cmd = f"cd ~/{REMOTE_ROOT} && wp litespeed-purge all"
    try:
        res = run_remote(cmd)
        print("LiteSpeed purged:", res.stdout.decode().strip())
    except Exception as e:
        print("LiteSpeed CLI purge returned note:", e)

def verify_no_residuals():
    print("\nVerifying zero residual files (.bak, .tmp) on remote...")
    cmd = f"cd ~/{REMOTE_ROOT} && find wp-content/themes/akibara-gp wp-content/plugins/akibara-business -name '*.bak' -o -name '*.tmp*' 2>/dev/null"
    res = run_remote(cmd)
    out = res.stdout.decode().strip()
    if out:
        raise RuntimeError(f"Found residual files on remote!\n{out}")
    print("  ✓ Zero residual files found on production.")

def main():
    print("=== Deploying Propuesta 1: Look Editorial Blanco to Production ===")
    for f in FILES_TO_DEPLOY:
        deploy_file(f)
    
    purge_litespeed()
    verify_no_residuals()
    print("\n=== All 12 files successfully deployed and verified! ===")

if __name__ == '__main__':
    main()
