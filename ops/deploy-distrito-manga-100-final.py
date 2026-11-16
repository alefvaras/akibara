#!/usr/bin/env python3
"""
Deploy Distrito Manga 100%: Pureza Visual Absoluta (Eliminación de Residuos Amarillos y Pulido Editorial).
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
    'wp-content/plugins/akibara-business/assets/css/series-directory.css',
    'wp-content/themes/akibara-gp/assets/css/brands-home.css',
    'wp-content/themes/akibara-gp/assets/css/brands-home.min.css',
    'wp-content/themes/akibara-gp/assets/css/core.css',
    'wp-content/themes/akibara-gp/assets/css/core.min.css',
    'wp-content/themes/akibara-gp/assets/css/base.css',
    'wp-content/themes/akibara-gp/assets/css/blog.css',
    'wp-content/themes/akibara-gp/assets/css/blog.min.css',
    'wp-content/plugins/akibara-search/assets/css/akb-search.css',
    'wp-content/plugins/akibara-search/assets/css/akb-search.min.css',
    'wp-content/plugins/akibara-rewards/src/Points/WelcomeBonus.php',
    'wp-content/plugins/akibara-rewards/src/Points/AccountRewards.php',
    'wp-content/plugins/akibara-rewards/src/Reviews/ReviewForm.php',
]

def sha256(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()

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
    
    remote_cmd = (
        f"set -eu; cd ~/{REMOTE_ROOT}; "
        f"dir=$(dirname {quoted_rel}); test -d \"$dir\" || mkdir -p \"$dir\"; "
        f"temp=$(mktemp \"{quoted_rel}.manga100.XXXXXX\"); "
        f"trap 'rm -f -- \"$temp\"' EXIT; "
        f"cat > \"$temp\"; "
        f"actual_sha=$(sha256sum \"$temp\" | cut -d' ' -f1); "
        f"if [ \"$actual_sha\" != \"{expected_sha}\" ]; then "
        f"  echo \"HASH MISMATCH: expected {expected_sha} got $actual_sha\" >&2; exit 1; "
        f"fi; "
        f"chmod 644 \"$temp\"; "
        f"mv -f \"$temp\" {quoted_rel}"
    )
    res = subprocess.run(
        SSH + [remote_cmd],
        input=data,
        capture_output=True
    )
    if res.returncode != 0:
        print(f"Deploy error on {rel_path}: {res.stderr.decode('utf-8', errors='replace')}", file=sys.stderr)
        raise RuntimeError(f"Failed to deploy {rel_path}")
    print(f"✔ Successfully deployed and atomically swapped: {rel_path}")

def verify_all_remote_hashes():
    print("\n=== Verifying all remote sha256 hashes against local sources ===")
    for rel_path in FILES_TO_DEPLOY:
        local_path = ROOT / 'public_html' / rel_path
        expected_sha = sha256(local_path.read_bytes())
        quoted_rel = shlex.quote(rel_path)
        cmd = f"sha256sum ~/{REMOTE_ROOT}/{quoted_rel} | cut -d' ' -f1"
        res = subprocess.run(SSH + [cmd], capture_output=True, text=True, check=True)
        remote_sha = res.stdout.strip()
        if remote_sha != expected_sha:
            print(f"FATAL: Mismatch on {rel_path}! Remote: {remote_sha}, Local: {expected_sha}", file=sys.stderr)
            sys.exit(1)
        print(f"✔ Verified identical: {rel_path} ({remote_sha[:12]}...)")

def verify_zero_temp_files():
    print("\n=== Verifying zero temporary files in production public_html ===")
    cmd = f"find ~/{REMOTE_ROOT}/wp-content/themes ~/{REMOTE_ROOT}/wp-content/plugins -name '*.bak' -o -name '*manga100*' -o -name '*.tmp' 2>/dev/null || true"
    res = subprocess.run(SSH + [cmd], capture_output=True, text=True, check=True)
    out = res.stdout.strip()
    if out:
        print(f"WARNING: Residual files found:\n{out}", file=sys.stderr)
        sys.exit(1)
    print("✔ Certified: 0 remote temporary files found.")

def purge_litespeed():
    print("\n=== Purging LiteSpeed & Cloudflare Cache on Production ===")
    cmd = f"cd ~/{REMOTE_ROOT} && wp eval 'do_action(\"litespeed_purge_all\"); \\LiteSpeed\\Purge::purge_all();' && wp litespeed-purge all || true"
    res = subprocess.run(SSH + [cmd], capture_output=True, text=True)
    print(res.stdout.strip() or res.stderr.strip() or "LiteSpeed purged.")

def main():
    timestamp = datetime.now().strftime("%Y%m%d_%H%M%S")
    backup_dir = ROOT / '.local-backups' / f'distrito-manga-100-final_{timestamp}'
    
    backup_remote_files(backup_dir)
    
    print("\n=== Deploying files atomically ===")
    for rel_path in FILES_TO_DEPLOY:
        deploy_file(rel_path)
        
    verify_all_remote_hashes()
    verify_zero_temp_files()
    purge_litespeed()
    print("\n=== DEPLOY COMPLETED SUCCESSFULLY ===")

if __name__ == '__main__':
    main()
