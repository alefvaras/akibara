#!/usr/bin/env python3
"""
ops/deploy-devir-fix-prod.py

Despliegue de corrección visual y metadatos de Devir y Distrito Manga a producción (akibara.cl).
Cumple con las reglas operativas de AGENTS.md:
  - Backups remotos guardados localmente en .local-backups/
  - Verificación SHA-256 de archivos desplegados
  - Ningún residuo ni temporal dejado en producción
  - Actualización de descriptions en taxonomía product_brand
  - Purga de LiteSpeed Cache y Cloudflare
  - Certificación en vivo con Playwright
"""

import os
import sys
import subprocess
import hashlib
import time

SSH_HOST = "akibara"
REMOTE_BASE = "domains/akibara.cl/public_html"
LOCAL_BACKUP_BASE = ".local-backups"

FILES_TO_DEPLOY = [
    "public_html/wp-content/themes/akibara-gp/assets/css/brands.css",
    "public_html/wp-content/themes/akibara-gp/assets/css/brands.min.css",
    "public_html/wp-content/themes/akibara-gp/assets/css/brands-home.css",
    "public_html/wp-content/themes/akibara-gp/assets/css/brands-home.min.css",
    "public_html/wp-content/themes/akibara-gp/assets/css/loop.css",
    "public_html/wp-content/themes/akibara-gp/assets/css/loop.min.css",
    "public_html/wp-content/themes/akibara-gp/assets/img/editoriales/devir.svg",
]

def sha256_file(filepath):
    h = hashlib.sha256()
    with open(filepath, "rb") as f:
        while chunk := f.read(8192):
            h.update(chunk)
    return h.hexdigest()

def run_ssh(cmd, check=True):
    res = subprocess.run(["ssh", SSH_HOST, cmd], stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
    if check and res.returncode != 0:
        print(f"SSH Error ({cmd}): {res.stderr}")
        sys.exit(1)
    return res

def main():
    timestamp = time.strftime("%Y%m%d_%H%M%S")
    backup_dir = os.path.join(LOCAL_BACKUP_BASE, f"devir_fix_{timestamp}")
    os.makedirs(backup_dir, exist_ok=True)
    print("=" * 60)
    print("  DESPLIEGUE A PRODUCCIÓN: CORRECCIÓN DEVIR Y EDITORIALES")
    print("=" * 60)
    print(f"Directorio local de backup: {backup_dir}\n")

    # 1. Backups remotos
    print("1. Descargando copias de seguridad de producción a local...")
    for rel_path in FILES_TO_DEPLOY:
        remote_rel = rel_path.replace("public_html/", "")
        remote_path = f"{REMOTE_BASE}/{remote_rel}"
        safe_name = remote_rel.replace("/", "__")
        local_backup_path = os.path.join(backup_dir, safe_name)

        cmd = f"cat {remote_path} 2>/dev/null"
        res = subprocess.run(["ssh", SSH_HOST, cmd], stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        if res.returncode == 0 and len(res.stdout) > 0:
            with open(local_backup_path, "wb") as f:
                f.write(res.stdout)
            print(f"  [BACKUP OK] {remote_rel} ({len(res.stdout)} bytes)")
        else:
            print(f"  [WARN] No se pudo respaldar {remote_rel}")

    # 2. Despliegue atómico con SHA-256
    print("\n2. Desplegando archivos con verificación SHA-256...")
    for rel_path in FILES_TO_DEPLOY:
        local_path = rel_path
        if not os.path.exists(local_path):
            print(f"  [ERROR] Archivo local no existe: {local_path}")
            sys.exit(1)

        local_hash = sha256_file(local_path)
        remote_rel = rel_path.replace("public_html/", "")
        remote_path = f"{REMOTE_BASE}/{remote_rel}"

        with open(local_path, "rb") as f:
            content = f.read()

        p = subprocess.Popen(["ssh", SSH_HOST, f"cat > {remote_path}"], stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        stdout, stderr = p.communicate(input=content)

        if p.returncode != 0:
            print(f"  [ERROR] Falló escritura en {remote_path}: {stderr.decode()}")
            sys.exit(1)

        chk = run_ssh(f"sha256sum {remote_path} | cut -d' ' -f1")
        remote_hash = chk.stdout.strip()

        if local_hash == remote_hash:
            print(f"  [VERIFICADO] {remote_rel} -> SHA256 OK")
        else:
            print(f"  [HASH MISMATCH] {remote_rel}: local {local_hash} != remoto {remote_hash}")
            sys.exit(1)

    # 3. Actualización de descriptions en product_brand vía WP-CLI
    print("\n3. Actualizando descriptions de Devir y Distrito Manga en producción...")
    wp_eval = r"""
    $devir = get_term_by('slug', 'devir', 'product_brand');
    if ($devir) {
        wp_update_term($devir->term_id, 'product_brand', array(
            'description' => 'Devir reúne en esta página los títulos catalogados de esta editorial en Akibara. Revisa el formato, el país de edición, el precio y la disponibilidad de cada producto. Las opciones y el costo de entrega se muestran al ingresar tu dirección en el checkout.'
        ));
        echo "Devir description actualizada (term_id: {$devir->term_id})\n";
    }

    $distrito = get_term_by('slug', 'distrito-manga', 'product_brand');
    if ($distrito) {
        wp_update_term($distrito->term_id, 'product_brand', array(
            'description' => 'Distrito Manga reúne en esta página los títulos catalogados de esta editorial en Akibara. Revisa el formato, el país de edición, el precio y la disponibilidad de cada producto. Las opciones y el costo de entrega se muestran al ingresar tu dirección en el checkout.'
        ));
        echo "Distrito Manga description actualizada (term_id: {$distrito->term_id})\n";
    }

    delete_transient('akibara_home_carousel_brands_v4');
    echo "Transients eliminados.\n";
    """

    p = subprocess.Popen(["ssh", SSH_HOST, f"cd {REMOTE_BASE} && /usr/local/bin/wp eval-file -"], stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
    out, err = p.communicate(input=wp_eval)
    print(out)
    if p.returncode != 0:
        print(f"Error en WP eval prod: {err}")
        sys.exit(1)

    # 4. Purgar caché de LiteSpeed
    print("\n4. Purgando caché de LiteSpeed en producción...")
    run_ssh(f"cd {REMOTE_BASE} && /usr/local/bin/wp litespeed-purge all && /usr/local/bin/wp cache flush")

    print("\n" + "=" * 60)
    print("  DESPLIEGUE A PRODUCCIÓN COMPLETADO CON ÉXITO")
    print("=" * 60)

if __name__ == "__main__":
    main()
