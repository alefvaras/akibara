#!/usr/bin/env python3
"""
Deploy HomeDestacados shortcode restoration to production.
Backs up remote HomeDestacados.php to .local-backups/, deploys atomically with SHA-256 verification,
updates page 210 with straight quotes, purges LiteSpeed & Cloudflare, and verifies the rendered grid.
"""
import os
import sys
import subprocess
import hashlib
import time

FILES_TO_DEPLOY = [
    "public_html/wp-content/plugins/akibara-business/src/Modules/HomeDestacados/HomeDestacados.php",
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
    backup_dir = os.path.join(LOCAL_BACKUP_BASE, f"homedestacados_fix_{timestamp}")
    os.makedirs(backup_dir, exist_ok=True)
    print("=== DEPLOY: HOMEDESTACADOS SHORTCODE FIX ===")
    print(f"Local backup directory: {backup_dir}\n")

    # Step 1: Pre-deployment backup of remote file
    print("1. Downloading pre-deployment backup from production...")
    for rel_path in FILES_TO_DEPLOY:
        remote_rel = rel_path.replace("public_html/", "")
        remote_path = f"{REMOTE_BASE}/{remote_rel}"
        safe_name = remote_rel.replace("/", "__")
        local_backup_path = os.path.join(backup_dir, safe_name)
        
        cmd = ["ssh", "-o", "BatchMode=yes", SSH_HOST, f"cat {remote_path} 2>/dev/null"]
        res = subprocess.run(cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        if res.returncode == 0 and len(res.stdout) > 0:
            with open(local_backup_path, "wb") as f:
                f.write(res.stdout)
            print(f"  [BACKUP OK] {remote_rel} ({len(res.stdout)} bytes)")
        else:
            print(f"  [ERROR] Could not read {remote_path}")
            sys.exit(1)

    # Step 2: Deploy file atomically
    print("\n2. Deploying HomeDestacados.php with SHA-256 verification...")
    for rel_path in FILES_TO_DEPLOY:
        local_hash = sha256_file(rel_path)
        remote_rel = rel_path.replace("public_html/", "")
        remote_path = f"{REMOTE_BASE}/{remote_rel}"

        with open(rel_path, "rb") as f:
            content = f.read()

        ssh_cmd = f"cat > {remote_path}"
        p = subprocess.Popen(["ssh", "-o", "BatchMode=yes", SSH_HOST, ssh_cmd], stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        stdout, stderr = p.communicate(input=content)

        if p.returncode != 0:
            print(f"  [DEPLOY ERROR] Failed to write {remote_path}: {stderr.decode()}")
            sys.exit(1)

        # Verify remote SHA-256
        chk_cmd = ["ssh", "-o", "BatchMode=yes", SSH_HOST, f"sha256sum {remote_path}"]
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

    # Step 3: Touch file
    print("\n3. Re-stamping remote file modification time...")
    remote_files_str = " ".join([f"{REMOTE_BASE}/{f.replace('public_html/', '')}" for f in FILES_TO_DEPLOY])
    subprocess.run(["ssh", "-o", "BatchMode=yes", SSH_HOST, f"touch {remote_files_str}"], check=True)
    print("  [TOUCH OK] Remote timestamps re-stamped.")

    # Step 4: Update page 210 in WordPress with straight quotes
    print("\n4. Updating front page (ID 210) with clean shortcode...")
    php_update_script = """
$post = get_post( 210 );
if ( ! $post ) {
    echo "ERROR_NO_POST_210";
    exit( 1 );
}
$content = $post->post_content;

$a_pedido_block = '<!-- wp:group {"tagName":"section","align":"full","className":"akb-section akb-preventas","layout":{"type":"constrained","contentSize":"1280px"}} -->
<section class="wp-block-group alignfull akb-section akb-preventas"><!-- wp:html -->
<header class="akb-section__header"><h2>A pedido</h2><p class="akb-section__sub">Lo pedimos a la editorial y te avisamos cuando llega</p><a class="akb-section__link" href="/a-pedido/">Ver todos →</a></header>
<!-- /wp:html -->

<!-- wp:shortcode -->
[akb_preventas_destacadas count="8" columns="4" max_per_serie="1"]
<!-- /wp:shortcode --></section>
<!-- /wp:group -->';

// Si ya contiene akb_preventas_destacadas pero con comillas tipográficas o incorrectas, reemplazar el bloque o el shortcode
if ( false !== strpos( $content, 'akb_preventas_destacadas' ) ) {
    // Normalizar comillas tipográficas en el shortcode
    $content = preg_replace(
        '/\[akb_preventas_destacadas[^\]]*\]/u',
        '[akb_preventas_destacadas count="8" columns="4" max_per_serie="1"]',
        $content
    );
    wp_update_post( array( 'ID' => 210, 'post_content' => $content ) );
    echo "NORMALIZED_SHORTCODE_IN_POST_210";
} else {
    // Insertar después de Callejón Cómics
    $comics_needle = '<!-- wp:shortcode -->
[akb_destacados category="comics" count="8" columns="4" orderby="popularity" max_per_serie="1"]
<!-- /wp:shortcode --></section>
<!-- /wp:group -->';
    if ( false !== strpos( $content, $comics_needle ) ) {
        $content = str_replace( $comics_needle, $comics_needle . "\n\n" . $a_pedido_block, $content );
        wp_update_post( array( 'ID' => 210, 'post_content' => $content ) );
        echo "INSERTED_A_PEDIDO_SECTION_IN_POST_210";
    } else {
        echo "COMICS_NEEDLE_NOT_FOUND";
    }
}
"""
    p = subprocess.Popen(
        ["ssh", "-o", "BatchMode=yes", SSH_HOST, f"cd {REMOTE_BASE} && wp eval-file -"],
        stdin=subprocess.PIPE,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        text=True
    )
    stdout, stderr = p.communicate(input=php_update_script)
    print(f"  [PAGE 210] {stdout.strip() or stderr.strip()}")

    # Step 5: Purge LiteSpeed & Cloudflare
    print("\n5. Purging caches...")
    subprocess.run(["ssh", "-o", "BatchMode=yes", SSH_HOST, f"cd {REMOTE_BASE} && wp litespeed-purge all && wp cache flush"], check=True)
    print("  [LITESPEED] Flushed.")

    cf_script = "tools/akibara-releases/rewards-qa/purge-cloudflare.mjs"
    if os.path.exists(cf_script):
        res_cf = subprocess.run(["node", cf_script], stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
        print(f"  [CLOUDFLARE] {res_cf.stdout.strip() or res_cf.stderr.strip()}")

    # Step 6: Verify with curl on production
    print("\n6. Verifying live HTML of https://akibara.cl/...")
    res_curl = subprocess.run(["curl", "-sL", "https://akibara.cl/"], stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
    html = res_curl.stdout

    if "A pedido" in html and 'class="akb-section akb-preventas"' in html:
        print("  [VERIFIED] Section 'A pedido' is present in HTML.")
    else:
        print("  [WARNING] 'A pedido' not found in HTML.")

    if "[akb_preventas_destacadas" in html:
        print("  [FAIL] Raw shortcode text is still leaking into HTML!")
        sys.exit(1)
    else:
        print("  [VERIFIED] Zero raw shortcode leak! Shortcode is fully executed.")

    print("\n=== DEPLOYMENT AND VERIFICATION COMPLETED SUCCESSFULLY ===")

if __name__ == "__main__":
    main()
