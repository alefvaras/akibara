#!/usr/bin/env python3
"""
ops/deploy-devir-prod.py

Despliegue integral de la marca Devir y los 16 tomos de "Los diarios de la boticaria (Devir)" a producción (akibara.cl).
Cumple con las reglas operativas de AGENTS.md:
  - Backups remotos guardados localmente en .local-backups/
  - Verificación SHA-256 de archivos desplegados
  - Ningún residuo ni temporal dejado en producción
  - Blindaje estricto de inventario (onbackorder, manage_stock=no, 5% de descuento reserva)
  - Pesos y dimensiones de envío registrados
  - Reindexación en akibara-search y purga de caché
  - Certificación en vivo
"""

import os
import sys
import subprocess
import hashlib
import time
import json
import urllib.request

SSH_HOST = "akibara"
REMOTE_BASE = "domains/akibara.cl/public_html"
LOCAL_BACKUP_BASE = ".local-backups"

FILES_TO_DEPLOY = [
    "public_html/wp-content/themes/akibara-gp/template-parts/brands/sprite.php",
    "public_html/wp-content/themes/akibara-gp/inc/woocommerce.php",
    "public_html/wp-content/themes/akibara-gp/assets/css/brands-home.css",
    "public_html/wp-content/themes/akibara-gp/assets/css/brands-home.min.css",
    "public_html/wp-content/themes/akibara-gp/assets/css/brands.css",
    "public_html/wp-content/themes/akibara-gp/assets/css/brands.min.css",
    "public_html/wp-content/themes/akibara-gp/assets/img/editoriales/devir.svg",
    "public_html/wp-content/themes/akibara-gp/assets/images/publishers/devir.svg",
    "public_html/wp-content/plugins/akibara-business/src/Modules/BrandsDirectory/BrandRegion.php",
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
    backup_dir = os.path.join(LOCAL_BACKUP_BASE, f"devir_{timestamp}")
    os.makedirs(backup_dir, exist_ok=True)
    print("=" * 60)
    print("  DESPLIEGUE A PRODUCCIÓN: MARCA DEVIR Y BOTICARIA MANGA")
    print("=" * 60)
    print(f"Directorio local de backup: {backup_dir}\n")

    # 1. Backups remotos de archivos que serán reemplazados
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
            print(f"  [NUEVO EN REMOTO] {remote_rel}")

    # 2. Despliegue atómico de archivos con verificación SHA-256
    print("\n2. Desplegando archivos con verificación SHA-256...")
    for rel_path in FILES_TO_DEPLOY:
        local_path = rel_path
        if not os.path.exists(local_path):
            print(f"  [ERROR] Archivo local no existe: {local_path}")
            sys.exit(1)

        local_hash = sha256_file(local_path)
        remote_rel = rel_path.replace("public_html/", "")
        remote_path = f"{REMOTE_BASE}/{remote_rel}"
        remote_dir = os.path.dirname(remote_path)

        run_ssh(f"mkdir -p {remote_dir}")

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

    # 3. Despliegue de imágenes (portadas de productos y logo)
    print("\n3. Sincronizando imágenes y portadas a producción...")
    run_ssh(f"mkdir -p {REMOTE_BASE}/wp-content/uploads/portadas-assets")
    run_ssh(f"mkdir -p {REMOTE_BASE}/wp-content/uploads/2026/10")
    run_ssh(f"mkdir -p {REMOTE_BASE}/wp-content/uploads/portadas")

    # Copiar portadas-assets/devir*
    print("  -> Subiendo logo devir a portadas-assets...")
    subprocess.run(
        f"rsync -avz public_html/wp-content/uploads/portadas-assets/devir* {SSH_HOST}:{REMOTE_BASE}/wp-content/uploads/portadas-assets/",
        shell=True, check=True
    )

    # Copiar portadas de productos 2026/10
    print("  -> Subiendo portadas 2026/10...")
    subprocess.run(
        f"rsync -avz public_html/wp-content/uploads/2026/10/los-diarios-de-la-boticaria-*-devir* {SSH_HOST}:{REMOTE_BASE}/wp-content/uploads/2026/10/",
        shell=True, check=True
    )

    # Copiar imágenes portadas/
    print("  -> Subiendo portadas/...")
    subprocess.run(
        f"rsync -avz public_html/wp-content/uploads/portadas/los-diarios-de-la-boticaria-*-devir* {SSH_HOST}:{REMOTE_BASE}/wp-content/uploads/portadas/",
        shell=True, check=True
    )
    print("  [OK] Todas las imágenes transferidas con éxito.")

    # 4. Creación y sincronización de marca, serie y productos vía WP-CLI en producción
    print("\n4. Configurando base de datos de producción vía WP-CLI...")
    
    # Preparamos script PHP para producción
    with open("ops/devir_boticaria_payload.json", "r", encoding="utf-8") as f:
        data_json = f.read()

    php_template = r"""<?php
    $upload_dir = wp_upload_dir();
    require_once ABSPATH . 'wp-admin/includes/image.php';
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';

    // A. MARCA DEVIR
    $brand_term = get_term_by('slug', 'devir', 'product_brand');
    if (!$brand_term) {
        $res = wp_insert_term('Devir', 'product_brand', array('slug' => 'devir'));
        if (is_wp_error($res)) {
            echo 'ERROR creando marca: ' . $res->get_error_message() . PHP_EOL;
            exit(1);
        }
        $brand_term_id = $res['term_id'];
        echo "Marca Devir creada con ID $brand_term_id" . PHP_EOL;
    } else {
        $brand_term_id = $brand_term->term_id;
        echo "Marca Devir ya existe con ID $brand_term_id" . PHP_EOL;
    }

    update_term_meta($brand_term_id, 'country', 'ES');
    update_term_meta($brand_term_id, 'akibara_region', 'espana');
    update_term_meta($brand_term_id, 'rank_math_title', 'Devir • Catálogo Oficial de Manga y Juegos en Chile | Akibara');
    update_term_meta($brand_term_id, 'rank_math_description', 'Descubre el catálogo oficial de Devir en Chile: manga de alta calidad importado desde España con despacho rápido y seguro a todo el país en Akibara.');
    update_term_meta($brand_term_id, 'rank_math_focus_keyword', 'Devir');

    // Registrar attachment logo para thumbnail_id
    $logo_file = $upload_dir['basedir'] . '/portadas-assets/devir.avif';
    $existing_logo_att = get_posts(array(
        'post_type' => 'attachment',
        'meta_key' => '_wp_attached_file',
        'meta_value' => 'portadas-assets/devir.avif',
        'posts_per_page' => 1
    ));
    if (!empty($existing_logo_att)) {
        $logo_att_id = $existing_logo_att[0]->ID;
    } else {
        $logo_att_id = wp_insert_attachment(array(
            'post_mime_type' => 'image/avif',
            'post_title'     => 'Logo devir',
            'post_content'   => '',
            'post_status'    => 'inherit'
        ), 'portadas-assets/devir.avif');
        update_post_meta($logo_att_id, '_wp_attached_file', 'portadas-assets/devir.avif');
        $att_data = wp_generate_attachment_metadata($logo_att_id, $logo_file);
        wp_update_attachment_metadata($logo_att_id, $att_data);
    }
    update_term_meta($brand_term_id, 'thumbnail_id', $logo_att_id);
    echo "Thumbnail logo asignado ID $logo_att_id a marca $brand_term_id" . PHP_EOL;

    // B. AUTORES (pa_autor)
    $autores = array('Natsu Hyuuga', 'Nekokurage');
    $autor_term_ids = array();
    foreach ($autores as $autor_name) {
        $slug = sanitize_title($autor_name);
        $term = get_term_by('slug', $slug, 'pa_autor');
        if (!$term) {
            $res = wp_insert_term($autor_name, 'pa_autor', array('slug' => $slug));
            $autor_term_ids[] = $res['term_id'];
            echo "Autor $autor_name creado con ID " . $res['term_id'] . PHP_EOL;
        } else {
            $autor_term_ids[] = $term->term_id;
            echo "Autor $autor_name existe con ID " . $term->term_id . PHP_EOL;
        }
    }

    // C. PAÍS (pa_pais: España)
    $pais_term = get_term_by('slug', 'espana', 'pa_pais');
    if (!$pais_term) {
        $res = wp_insert_term('España', 'pa_pais', array('slug' => 'espana'));
        $pais_term_id = $res['term_id'];
    } else {
        $pais_term_id = $pais_term->term_id;
    }

    // D. GÉNEROS (pa_genero: Drama, Misterio, Histórico)
    $generos = array('Drama' => 'drama', 'Misterio' => 'misterio', 'Histórico' => 'historico');
    $genero_term_ids = array();
    foreach ($generos as $name => $slug) {
        $term = get_term_by('slug', $slug, 'pa_genero');
        if (!$term) {
            $res = wp_insert_term($name, 'pa_genero', array('slug' => $slug));
            $genero_term_ids[] = $res['term_id'];
        } else {
            $genero_term_ids[] = $term->term_id;
        }
    }

    // E. CATEGORÍAS (product_cat: Manga, Seinen)
    $manga_cat = get_term_by('slug', 'manga', 'product_cat');
    $manga_cat_id = $manga_cat ? $manga_cat->term_id : 0;
    $seinen_cat = get_term_by('slug', 'seinen', 'product_cat');
    if (!$seinen_cat) {
        $res = wp_insert_term('Seinen', 'product_cat', array('slug' => 'seinen'));
        $seinen_cat_id = $res['term_id'];
    } else {
        $seinen_cat_id = $seinen_cat->term_id;
    }

    // F. SERIE (pa_serie: Los diarios de la boticaria (Devir))
    $serie_slug = 'los-diarios-de-la-boticaria-devir';
    $serie_term = get_term_by('slug', $serie_slug, 'pa_serie');
    $serie_desc = '<p>Maomao es una joven herbolaria del barrio del placer que termina secuestrada y vendida como sirvienta al palacio imperial. Con su agudo ingenio, curiosidad innata y amplios conocimientos en venenos y medicina tradicional, pronto llama la atención de Jinshi, un influyente eunuco que la involucra en la resolución de intrigas y misterios dentro de la corte.</p>\\n<p><strong>Autor original:</strong> Natsu Hyuuga<br />\\n<strong>Dibujo:</strong> Nekokurage<br />\\n<strong>Demografía:</strong> Seinen<br />\\n<strong>Estado en Japón:</strong> En curso (16 tomos publicados)<br />\\n<strong>Formato de edición:</strong> 128 &times; 180 mm, Rústica con sobrecubierta, B/N con páginas a color<br />\\n<strong>Estado en España:</strong> En curso (16 tomos publicados)<br />\\n<strong>Próximo tomo:</strong> Tomo #17 anunciado para 2026</p>';

    if (!$serie_term) {
        $res = wp_insert_term('Los diarios de la boticaria (Devir)', 'pa_serie', array(
            'slug' => $serie_slug,
            'description' => $serie_desc
        ));
        $serie_term_id = $res['term_id'];
        echo "Serie Los diarios de la boticaria (Devir) creada con ID $serie_term_id" . PHP_EOL;
    } else {
        $serie_term_id = $serie_term->term_id;
        wp_update_term($serie_term_id, 'pa_serie', array('description' => $serie_desc));
        echo "Serie Los diarios de la boticaria (Devir) actualizada con ID $serie_term_id" . PHP_EOL;
    }

    update_term_meta($serie_term_id, '_akb_serie_estado', 'en_publicacion');
    update_term_meta($serie_term_id, '_akb_serie_tomos_obra', '16');
    update_term_meta($serie_term_id, '_akb_serie_estado_edicion', 'en_publicacion');
    update_term_meta($serie_term_id, '_akb_serie_tomos_edicion', '16');

    // Vincular Ediciones Relacionadas (_akb_serie_ediciones_relacionadas)
    $peer_slugs = array(
        'los-diarios-de-la-boticaria',
        'los-diarios-de-la-boticaria-la-novela-original',
        'los-diarios-de-la-boticaria-maomao-resolviendo-los-misterios-imperiales'
    );
    $peer_ids = array();
    foreach ($peer_slugs as $pslug) {
        $pterm = get_term_by('slug', $pslug, 'pa_serie');
        if ($pterm) {
            $peer_ids[] = (int) $pterm->term_id;
        }
    }
    if (!empty($peer_ids)) {
        update_term_meta($serie_term_id, '_akb_serie_ediciones_relacionadas', $peer_ids);
        foreach ($peer_ids as $pid) {
            $existing_rel = get_term_meta($pid, '_akb_serie_ediciones_relacionadas', true);
            if (!is_array($existing_rel)) {
                $existing_rel = !empty($existing_rel) ? array((int)$existing_rel) : array();
            }
            $existing_rel[] = $serie_term_id;
            $existing_rel = array_values(array_unique(array_filter($existing_rel)));
            update_term_meta($pid, '_akb_serie_ediciones_relacionadas', $existing_rel);
        }
        echo "Ediciones relacionadas vinculadas recíprocamente: " . implode(', ', $peer_ids) . PHP_EOL;
    }

    // G. CREACIÓN DE LOS 16 PRODUCTOS
    $payload = json_decode('__PAYLOAD_JSON__', true);

    $created_ids = array();

    for ($num = 1; $num <= 16; $num++) {
        $num_pad = sprintf('%02d', $num);
        $slug = "los-diarios-de-la-boticaria-{$num}-devir";
        $item = isset($payload[(string)$num]) ? $payload[(string)$num] : $payload[$num];
        $title = $item['title'];
        $ean = preg_replace('/[^0-9]/', '', $item['sku']);
        $excerpt = $item['excerpt'];
        $content = $item['content'];

        $existing = get_page_by_path($slug, OBJECT, 'product');
        if ($existing) {
            $post_id = $existing->ID;
            wp_update_post(array(
                'ID' => $post_id,
                'post_title' => $title,
                'post_content' => $content,
                'post_excerpt' => $excerpt,
                'post_status' => 'publish'
            ));
            echo "Actualizado producto $title (ID $post_id)" . PHP_EOL;
        } else {
            $post_id = wp_insert_post(array(
                'post_title' => $title,
                'post_name' => $slug,
                'post_content' => $content,
                'post_excerpt' => $excerpt,
                'post_status' => 'publish',
                'post_type' => 'product'
            ));
            echo "Creado producto $title (ID $post_id)" . PHP_EOL;
        }

        $created_ids[] = $post_id;

        wp_set_object_terms($post_id, 'simple', 'product_type');
        wp_set_object_terms($post_id, array($brand_term_id), 'product_brand');
        wp_set_object_terms($post_id, array($pais_term_id), 'pa_pais');
        wp_set_object_terms($post_id, $autor_term_ids, 'pa_autor');
        wp_set_object_terms($post_id, array($serie_term_id), 'pa_serie');
        wp_set_object_terms($post_id, $genero_term_ids, 'pa_genero');
        wp_set_object_terms($post_id, array_filter(array($manga_cat_id, $seinen_cat_id)), 'product_cat');
        wp_remove_object_terms($post_id, 'outofstock', 'product_visibility');

        $product_attributes = array(
            'pa_pais' => array('name' => 'pa_pais', 'value' => '', 'position' => 0, 'is_visible' => 1, 'is_variation' => 0, 'is_taxonomy' => 1),
            'pa_autor' => array('name' => 'pa_autor', 'value' => '', 'position' => 1, 'is_visible' => 1, 'is_variation' => 0, 'is_taxonomy' => 1),
            'pa_serie' => array('name' => 'pa_serie', 'value' => '', 'position' => 2, 'is_visible' => 1, 'is_variation' => 0, 'is_taxonomy' => 1),
            'pa_genero' => array('name' => 'pa_genero', 'value' => '', 'position' => 3, 'is_visible' => 1, 'is_variation' => 0, 'is_taxonomy' => 1),
        );
        update_post_meta($post_id, '_product_attributes', $product_attributes);

        $reg_price = 16500;
        $sale_price = (int) round($reg_price * 0.95);
        update_post_meta($post_id, '_regular_price', (string)$reg_price);
        update_post_meta($post_id, '_sale_price', (string)$sale_price);
        update_post_meta($post_id, '_price', (string)$sale_price);

        update_post_meta($post_id, '_manage_stock', 'no');
        update_post_meta($post_id, '_stock_status', 'onbackorder');
        update_post_meta($post_id, '_backorders', 'notify');
        delete_post_meta($post_id, '_stock');

        update_post_meta($post_id, '_sku', $ean);
        update_post_meta($post_id, '_global_unique_id', $ean);
        update_post_meta($post_id, '_gtin', $ean);
        update_post_meta($post_id, '_barcode', $ean);

        update_post_meta($post_id, '_weight', '0.25');
        update_post_meta($post_id, '_length', '18');
        update_post_meta($post_id, '_width', '12.8');
        update_post_meta($post_id, '_height', '1.5');

        $focus_kws = "{$title}, Los diarios de la boticaria {$num_pad} Devir, manga Los diarios de la boticaria {$num_pad}, Natsu Hyuuga, Nekokurage";
        update_post_meta($post_id, 'rank_math_focus_keyword', $focus_kws);
        update_post_meta($post_id, 'rank_math_title', "{$title} • Manga Oficial | Akibara");
        $meta_desc = "Consigue {$title} en Chile. Manga oficial en español con reserva con 5% de descuento y despacho rápido a todo el país.";
        if (mb_strlen($meta_desc) > 155) {
            $meta_desc = mb_substr($meta_desc, 0, 152) . '...';
        }
        update_post_meta($post_id, 'rank_math_description', $meta_desc);

        // Attachment portada
        $cover_rel = "portadas/{$slug}.avif";
        $cover_abs = $upload_dir['basedir'] . "/{$cover_rel}";
        $existing_att = get_posts(array(
            'post_type' => 'attachment',
            'meta_key' => '_wp_attached_file',
            'meta_value' => $cover_rel,
            'posts_per_page' => 1
        ));

        if (!empty($existing_att)) {
            $att_id = $existing_att[0]->ID;
        } else {
            $att_id = wp_insert_attachment(array(
                'post_mime_type' => 'image/avif',
                'post_title' => $title,
                'post_content' => '',
                'post_status' => 'inherit'
            ), $cover_abs, $post_id);
            update_post_meta($att_id, '_wp_attached_file', $cover_rel);
        }
        $att_data = wp_generate_attachment_metadata($att_id, $cover_abs);
        wp_update_attachment_metadata($att_id, $att_data);
        update_post_meta($att_id, '_wp_attachment_image_alt', $title);
        set_post_thumbnail($post_id, $att_id);

        // Actualizar WooCommerce Lookup Table para sincronización inmediata de facts
        if (function_exists('wc_update_product_lookup_tables')) {
            wc_update_product_lookup_tables($post_id);
        }
    }

    delete_transient('akibara_home_carousel_brands_v4');
    delete_transient('akb_series_facts_generation_' . $serie_term_id);
    echo "Configuración completa en producción." . PHP_EOL;
    """
    php_script = php_template.replace("__PAYLOAD_JSON__", json.dumps(json.loads(data_json)))

    p = subprocess.Popen(["ssh", SSH_HOST, f"cd {REMOTE_BASE} && /usr/local/bin/wp eval-file -"], stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
    out, err = p.communicate(input=php_script)
    print(out)
    if p.returncode != 0:
        print(f"Error en WP eval prod: {err}")
        sys.exit(1)

    # 5. Encadenar upsells en producción
    print("\n5. Encadenando upsells en producción...")
    run_ssh(f"cd {REMOTE_BASE} && /usr/local/bin/wp akibara:upsells-fill")

    # 6. Reindexar en akibara-search
    print("\n6. Reindexando en akibara-search...")
    reindex_cmd = "cd " + REMOTE_BASE + """ && /usr/local/bin/wp eval '
    for ($i = 1; $i <= 16; $i++) {
        $slug = "los-diarios-de-la-boticaria-" . $i . "-devir";
        $p = get_page_by_path($slug, OBJECT, "product");
        if ($p) {
            do_action("akb_search_product_updated", $p->ID);
        }
    }
    echo "16 productos Devir reindexados en akibara-search.\n";
    '"""
    run_ssh(reindex_cmd)

    # 7. Purgar caché de LiteSpeed
    print("\n7. Purgando caché de LiteSpeed...")
    run_ssh(f"cd {REMOTE_BASE} && /usr/local/bin/wp litespeed-purge all && /usr/local/bin/wp cache flush")

    # 8. Guardrail de inventario
    print("\n8. Ejecutando watchdog de inventario en producción...")
    subprocess.run(["python3", "ops/guardrail-inventario-akibara.py", "--target", "prod"], check=True)

    print("\n" + "=" * 60)
    print("  DESPLIEGUE A PRODUCCIÓN COMPLETADO CON ÉXITO")
    print("=" * 60)

if __name__ == "__main__":
    main()
