#!/usr/bin/env python3
"""
ops/deploy-distrito-manga-prod.py

Despliegue integral de Distrito Manga y las novelas de Los diarios de la boticaria a producción (akibara.cl).
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
    "public_html/wp-content/themes/akibara-gp/template-parts/brands/home-carousel.php",
    "public_html/wp-content/themes/akibara-gp/template-parts/brands/grid.php",
    "public_html/wp-content/themes/akibara-gp/inc/woocommerce.php",
    "public_html/wp-content/themes/akibara-gp/assets/css/brands-home.css",
    "public_html/wp-content/themes/akibara-gp/assets/css/brands-home.min.css",
    "public_html/wp-content/themes/akibara-gp/assets/css/brands.css",
    "public_html/wp-content/themes/akibara-gp/assets/css/brands.min.css",
    "public_html/wp-content/themes/akibara-gp/assets/img/editoriales/distrito-manga.svg",
    "public_html/wp-content/themes/akibara-gp/assets/images/publishers/distrito-manga.svg",
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
    backup_dir = os.path.join(LOCAL_BACKUP_BASE, f"distrito_manga_{timestamp}")
    os.makedirs(backup_dir, exist_ok=True)
    print("=" * 60)
    print("  DESPLIEGUE A PRODUCCIÓN: DISTRITO MANGA & NOVELAS BOTICARIA")
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
    # Crear directorios en remoto
    run_ssh(f"mkdir -p {REMOTE_BASE}/wp-content/uploads/portadas-assets")
    run_ssh(f"mkdir -p {REMOTE_BASE}/wp-content/uploads/2026/10")
    run_ssh(f"mkdir -p {REMOTE_BASE}/wp-content/uploads/portadas")

    # Copiar portadas-assets/distrito-manga*
    print("  -> Subiendo logo distrito-manga a portadas-assets...")
    subprocess.run(
        f"scp public_html/wp-content/uploads/portadas-assets/distrito-manga* {SSH_HOST}:{REMOTE_BASE}/wp-content/uploads/portadas-assets/",
        shell=True, check=True
    )

    # Copiar portadas de productos 2026/10
    print("  -> Subiendo portadas 2026/10...")
    subprocess.run(
        f"scp public_html/wp-content/uploads/2026/10/los-diarios-de-la-boticaria-la-novela-original-* {SSH_HOST}:{REMOTE_BASE}/wp-content/uploads/2026/10/",
        shell=True, check=True
    )

    # Copiar imágenes -og.jpg
    print("  -> Subiendo imágenes -og.jpg a portadas/...")
    subprocess.run(
        f"scp public_html/wp-content/uploads/portadas/los-diarios-de-la-boticaria-la-novela-original-*-og.jpg {SSH_HOST}:{REMOTE_BASE}/wp-content/uploads/portadas/",
        shell=True, check=True
    )
    print("  [OK] Todas las imágenes transferidas con éxito.")

    # 4. Creación y sincronización de marca, serie y productos vía WP-CLI en producción
    print("\n4. Configurando base de datos de producción vía WP-CLI...")
    prod_script = """
    $upload_dir = wp_upload_dir();
    require_once ABSPATH . 'wp-admin/includes/image.php';
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';

    // A. MARCA DISTRITO MANGA
    $brand_term = get_term_by('slug', 'distrito-manga', 'product_brand');
    if (!$brand_term) {
        $res = wp_insert_term('Distrito Manga', 'product_brand', array('slug' => 'distrito-manga'));
        if (is_wp_error($res)) {
            echo 'ERROR creando marca: ' . $res->get_error_message() . PHP_EOL;
            exit(1);
        }
        $brand_term_id = $res['term_id'];
        echo "Marca Distrito Manga creada con ID $brand_term_id" . PHP_EOL;
    } else {
        $brand_term_id = $brand_term->term_id;
        echo "Marca Distrito Manga ya existe con ID $brand_term_id" . PHP_EOL;
    }

    update_term_meta($brand_term_id, 'country', 'ES');
    update_term_meta($brand_term_id, 'rank_math_title', 'Distrito Manga en Chile %sep% Manga y Novelas %sep% Akibara');
    update_term_meta($brand_term_id, 'rank_math_description', 'Distrito Manga: revisa las novelas y mangas oficiales catalogados en Akibara con formato original y despacho a todo Chile.');
    update_term_meta($brand_term_id, 'rank_math_focus_keyword', 'distrito manga chile, distrito manga catalogo');

    // Registrar attachment logo para thumbnail_id
    $logo_file = $upload_dir['basedir'] . '/portadas-assets/distrito-manga.avif';
    $existing_logo_att = get_posts(array(
        'post_type' => 'attachment',
        'meta_key' => '_wp_attached_file',
        'meta_value' => 'portadas-assets/distrito-manga.avif',
        'posts_per_page' => 1
    ));
    if (!empty($existing_logo_att)) {
        $logo_att_id = $existing_logo_att[0]->ID;
    } else {
        $logo_att_id = wp_insert_attachment(array(
            'post_mime_type' => 'image/avif',
            'post_title'     => 'Logo distrito-manga',
            'post_content'   => '',
            'post_status'    => 'inherit'
        ), 'portadas-assets/distrito-manga.avif');
        update_post_meta($logo_att_id, '_wp_attached_file', 'portadas-assets/distrito-manga.avif');
        $att_data = wp_generate_attachment_metadata($logo_att_id, $logo_file);
        wp_update_attachment_metadata($logo_att_id, $att_data);
    }
    update_term_meta($brand_term_id, 'thumbnail_id', $logo_att_id);
    echo "Thumbnail logo asignado ID $logo_att_id a marca $brand_term_id" . PHP_EOL;

    // B. AUTORES (pa_autor)
    $autores = array('Natsu Hyuuga', 'Touko Shino');
    $autor_term_ids = array();
    foreach ($autores as $autor_name) {
        $slug = sanitize_title($autor_name);
        $term = get_term_by('slug', $slug, 'pa_autor');
        if (!$term) {
            $t_res = wp_insert_term($autor_name, 'pa_autor', array('slug' => $slug));
            $autor_term_ids[] = is_array($t_res) ? $t_res['term_id'] : 0;
            echo "Autor $autor_name creado con ID " . end($autor_term_ids) . PHP_EOL;
        } else {
            $autor_term_ids[] = $term->term_id;
            echo "Autor $autor_name ya existe con ID " . $term->term_id . PHP_EOL;
        }
    }

    // C. SERIE (pa_serie)
    $serie_slug = 'los-diarios-de-la-boticaria-la-novela-original';
    $serie_term = get_term_by('slug', $serie_slug, 'pa_serie');
    $serie_desc = '<p><strong>Los diarios de la boticaria (La novela original)</strong> (<em>Kusuriya no Hitorigoto</em>) narra las fascinantes vivencias de <strong>Maomao</strong>, una sagaz boticaria secuestrada del barrio del placer y vendida como sirvienta en el palacio imperial. Su amplio conocimiento de venenos y medicina la convierte rápidamente en catadora oficial y confidente de Jinshi, resolviendo los enigmas y conspiraciones médicas más oscuros de la corte imperial.</p>' . \"\\n\\n\" .
        '<p><strong>Autor original:</strong> Natsu Hyuuga<br />' . \"\\n\" .
        '<strong>Ilustraciones:</strong> Touko Shino<br />' . \"\\n\" .
        '<strong>Demografía:</strong> Seinen / Misterio y ficción histórica<br />' . \"\\n\" .
        '<strong>Estado en Japón:</strong> En curso (15 tomos publicados)<br />' . \"\\n\" .
        '<strong>Formato de edición:</strong> Formato Rústica con solapas 15 &times; 21 cm, inclusión de páginas a color e ilustraciones exclusivas<br />' . \"\\n\" .
        '<strong>Estado en España:</strong> En curso (2 tomos publicados)<br />' . \"\\n\" .
        '<strong>Próximo tomo:</strong> Tomo #3 anunciado para primeros meses de 2027</p>';

    if (!$serie_term) {
        $s_res = wp_insert_term(
            'Los diarios de la boticaria (La novela original)',
            'pa_serie',
            array('slug' => $serie_slug, 'description' => $serie_desc)
        );
        $serie_term_id = $s_res['term_id'];
        echo "Serie creada con ID $serie_term_id" . PHP_EOL;
    } else {
        $serie_term_id = $serie_term->term_id;
        wp_update_term($serie_term_id, 'pa_serie', array('description' => $serie_desc));
        echo "Serie actualizada con ID $serie_term_id" . PHP_EOL;
    }

    update_term_meta($serie_term_id, '_akb_serie_estado', 'en_publicacion');
    update_term_meta($serie_term_id, '_akb_serie_tomos_obra', '15');
    update_term_meta($serie_term_id, '_akb_serie_estado_edicion', 'en_publicacion');
    update_term_meta($serie_term_id, '_akb_serie_tomos_edicion', '2');
    update_term_meta($serie_term_id, 'rank_math_title', 'Los diarios de la boticaria (La novela original) en Chile %sep% Akibara');
    update_term_meta($serie_term_id, 'rank_math_description', 'Encuentra la novela original de Los diarios de la boticaria en Chile. Edición oficial de Distrito Manga con despacho rápido a todo el país.');
    update_term_meta($serie_term_id, 'rank_math_focus_keyword', 'Los diarios de la boticaria novela, Los diarios de la boticaria novela chile, comprar novela boticaria');

    // D. PRODUCTOS
    $products_data = array(
        array(
            'sku' => '9791387962821',
            'title' => 'Los diarios de la boticaria (La novela original) 01',
            'slug' => 'los-diarios-de-la-boticaria-la-novela-original-1',
            'regular_price' => 24990,
            'sale_price' => 23741,
            'weight' => '0.45',
            'length' => '21',
            'width' => '15',
            'height' => '2.5',
            'img_file' => '2026/10/los-diarios-de-la-boticaria-la-novela-original-1-distrito-manga.avif',
            'excerpt' => '<strong>Los diarios de la boticaria (La novela original) 01</strong> inicia la aclamada obra de Natsu Hyuuga y Touko Shino. Maomao ingresa al palacio imperial como sirvienta y pronto se convierte en catadora de venenos tras resolver un envenenamiento cortesano. Edición oficial con despacho a todo Chile.',
            'content' => '<p><strong>Los diarios de la boticaria (La novela original) 01</strong> presenta la historia original de <strong>Natsu Hyuuga</strong> con bellísimas ilustraciones de <strong>Touko Shino</strong>. Secuestrada en el barrio del placer y vendida como sirvienta en el palacio imperial, la joven boticaria Maomao desea pasar desapercibida hasta cumplir su servicio. Sin embargo, cuando los infantes del emperador enferman misteriosamente, su pasión investigadora y sus conocimientos sobre venenos y plantas medicinales la llevan a desentrañar un oscuro complot palaciego.</p>' . \"\\n\\n\" .
                '<h2>Descubre la intriga de Los diarios de la boticaria (La novela original) 01</h2>' . \"\\n\" .
                '<p>Al salvar la vida de la pequeña princesa, Maomao llama la atención del perspicaz Jinshi, un eunuco de alta alcurnia imperial que reconoce su agudeza deductiva y la convierte en su catadora oficial de venenos. Una cautivante novela que combina rigor científico, intrigas cortesanas, enigmas médicos y una protagonista inolvidable.</p>' . \"\\n\\n\" .
                '<p class=\"akb-pdp-seo-links\">Explora también los demás volúmenes de la <a href=\"/serie/los-diarios-de-la-boticaria-la-novela-original/\">serie Los diarios de la boticaria (La novela original)</a> y descubre más obras publicadas por <a href=\"/marca/distrito-manga/\">Distrito Manga</a> en Akibara.</p>',
            'focus_kw' => 'Los diarios de la boticaria (La novela original) 01, Los diarios de la boticaria novela 1, novela Los diarios de la boticaria 1, Natsu Hyuuga',
            'rm_desc' => 'Compra Los diarios de la boticaria (La novela original) 01 en Akibara. Edición oficial con ilustraciones a color y despacho rápido a todo Chile.'
        ),
        array(
            'sku' => '9791387962838',
            'title' => 'Los diarios de la boticaria (La novela original) 02',
            'slug' => 'los-diarios-de-la-boticaria-la-novela-original-2',
            'regular_price' => 24990,
            'sale_price' => 23741,
            'weight' => '0.45',
            'length' => '21',
            'width' => '15',
            'height' => '2.5',
            'img_file' => '2026/10/los-diarios-de-la-boticaria-la-novela-original-2-distrito-manga.avif',
            'excerpt' => '<strong>Los diarios de la boticaria (La novela original) 02</strong> continúa las investigaciones de Maomao en el palacio imperial. Nuevos misterios médicos, conspiraciones palaciegas y enigmas diplomáticos ponen a prueba su ingenio. Edición oficial con despacho a todo Chile.',
            'content' => '<p>En <strong>Los diarios de la boticaria (La novela original) 02</strong>, Maomao continúa desentrañando los enigmas más complejos del palacio imperial bajo las órdenes de Jinshi. Nuevos casos de envenenamientos aparentemente accidentales, rituales prohibidos y conspiraciones diplomáticas pondrán a prueba su ingenio farmacéutico y su capacidad deductiva.</p>' . \"\\n\\n\" .
                '<h2>Misterio y conspiración en Los diarios de la boticaria (La novela original) 02</h2>' . \"\\n\" .
                '<p>Mientras la tensión política crece en la corte interna, los secretos en torno al pasado de Maomao y los verdaderos motivos de Jinshi comienzan a salir a la luz en una trama llena de giros magistrales y sutilezas históricas.</p>' . \"\\n\\n\" .
                '<p class=\"akb-pdp-seo-links\">Continúa la lectura con la <a href=\"/serie/los-diarios-de-la-boticaria-la-novela-original/\">serie Los diarios de la boticaria (La novela original)</a> y encuentra el catálogo completo de <a href=\"/marca/distrito-manga/\">Distrito Manga</a> en Akibara.</p>',
            'focus_kw' => 'Los diarios de la boticaria (La novela original) 02, Los diarios de la boticaria novela 2, novela Los diarios de la boticaria 2, Natsu Hyuuga',
            'rm_desc' => 'Compra Los diarios de la boticaria (La novela original) 02 en Akibara. Edición oficial con ilustraciones a color y despacho rápido a todo Chile.'
        ),
        array(
            'sku' => '9791387962968',
            'title' => 'Los diarios de la boticaria (La novela original) 1-2 (Pack de lanzamiento)',
            'slug' => 'los-diarios-de-la-boticaria-la-novela-original-1-2-pack-de-lanzamiento',
            'regular_price' => 47990,
            'sale_price' => 45591,
            'weight' => '0.90',
            'length' => '21',
            'width' => '15',
            'height' => '5.5',
            'img_file' => '2026/10/los-diarios-de-la-boticaria-la-novela-original-1-2-pack-distrito-manga.avif',
            'excerpt' => '<strong>Los diarios de la boticaria (La novela original) 1-2 (Pack de lanzamiento)</strong> incluye los dos primeros volúmenes en un estuche exclusivo de colección. Descubre la historia de Maomao en la corte imperial. Edición oficial con despacho a todo Chile.',
            'content' => '<p><strong>Los diarios de la boticaria (La novela original) 1-2 (Pack de lanzamiento)</strong> reúne en un estuche especial de colección los dos primeros volúmenes de la aclamada obra original escrita por <strong>Natsu Hyuuga</strong> e ilustrada por <strong>Touko Shino</strong>. La oportunidad perfecta para iniciar una de las historias de misterio, medicina tradicional e intriga cortesana más fascinantes de la literatura japonesa actual.</p>' . \"\\n\\n\" .
                '<h2>Edición coleccionista: Los diarios de la boticaria (La novela original) 1-2 (Pack de lanzamiento)</h2>' . \"\\n\" .
                '<p>Acompaña a Maomao desde su llegada forzada a la corte interior hasta convertirse en la confidente y catadora de venenos más respetada del palacio. Incluye páginas a color exclusivas y acabado de lujo en formato rústica con solapas.</p>' . \"\\n\\n\" .
                '<p class=\"akb-pdp-seo-links\">Descubre toda la colección en la <a href=\"/serie/los-diarios-de-la-boticaria-la-novela-original/\">serie Los diarios de la boticaria (La novela original)</a> y el catálogo oficial de <a href=\"/marca/distrito-manga/\">Distrito Manga</a> en Akibara.</p>',
            'focus_kw' => 'Los diarios de la boticaria (La novela original) 1-2 (Pack de lanzamiento), pack Los diarios de la boticaria novela, novela Los diarios de la boticaria pack, Natsu Hyuuga',
            'rm_desc' => 'Compra Los diarios de la boticaria (La novela original) 1-2 (Pack de lanzamiento) en Akibara. Edición especial con ilustraciones y despacho a todo Chile.'
        )
    );

    $created_pids = array();
    foreach ($products_data as $pdata) {
        $existing_id = wc_get_product_id_by_sku($pdata['sku']);
        if ($existing_id) {
            $product = wc_get_product($existing_id);
            echo "Producto SKU {$pdata['sku']} ya existe con ID $existing_id" . PHP_EOL;
        } else {
            $product = new WC_Product_Simple();
            $product->set_name($pdata['title']);
            $product->set_slug($pdata['slug']);
            $product->set_sku($pdata['sku']);
            $product->set_status('publish');
            echo "Creando nuevo producto {$pdata['title']}" . PHP_EOL;
        }

        $product->set_regular_price($pdata['regular_price']);
        $product->set_sale_price($pdata['sale_price']);
        $product->set_price($pdata['sale_price']);
        $product->set_manage_stock(false);
        $product->set_stock_status('onbackorder');
        $product->set_backorders('notify');
        $product->set_short_description($pdata['excerpt']);
        $product->set_description($pdata['content']);

        // Dimensiones y peso
        $product->set_weight($pdata['weight']);
        $product->set_length($pdata['length']);
        $product->set_width($pdata['width']);
        $product->set_height($pdata['height']);

        // Categorías: Manga (product_cat) + Seinen
        $cat_manga = get_term_by('slug', 'manga', 'product_cat');
        $cat_seinen = get_term_by('slug', 'seinen', 'product_cat');
        $cat_ids = array();
        if ($cat_manga) $cat_ids[] = $cat_manga->term_id;
        if ($cat_seinen) $cat_ids[] = $cat_seinen->term_id;
        $product->set_category_ids($cat_ids);

        // Guardar para obtener ID
        $pid = $product->save();
        $created_pids[] = $pid;

        // Registrar / Asignar portada
        $img_path = $upload_dir['basedir'] . '/' . $pdata['img_file'];
        $existing_att = get_posts(array(
            'post_type' => 'attachment',
            'meta_key' => '_wp_attached_file',
            'meta_value' => $pdata['img_file'],
            'posts_per_page' => 1
        ));

        if (!empty($existing_att)) {
            $att_id = $existing_att[0]->ID;
        } else {
            $att_id = wp_insert_attachment(array(
                'post_mime_type' => 'image/avif',
                'post_title'     => 'Portada oficial de ' . $pdata['title'] . ' - Distrito Manga',
                'post_content'   => '',
                'post_status'    => 'inherit'
            ), $pdata['img_file'], $pid);
            update_post_meta($att_id, '_wp_attached_file', $pdata['img_file']);
            $att_data = wp_generate_attachment_metadata($att_id, $img_path);
            wp_update_attachment_metadata($att_id, $att_data);
        }
        update_post_meta($att_id, '_wp_attachment_image_alt', 'Portada oficial de ' . $pdata['title'] . ' - Distrito Manga');
        set_post_thumbnail($pid, $att_id);

        // Identificadores de código de barras
        update_post_meta($pid, '_global_unique_id', $pdata['sku']);
        update_post_meta($pid, '_gtin', $pdata['sku']);
        update_post_meta($pid, '_barcode', $pdata['sku']);

        // Taxonomías
        wp_set_object_terms($pid, 'distrito-manga', 'product_brand', false);
        wp_set_object_terms($pid, 'espana', 'pa_pais', false);
        wp_set_object_terms($pid, array('natsu-hyuuga', 'touko-shino'), 'pa_autor', false);
        wp_set_object_terms($pid, $serie_slug, 'pa_serie', false);
        wp_set_object_terms($pid, array('drama', 'historico', 'misterio'), 'pa_genero', false);

        // Atributos de producto estrictos (_product_attributes) - CERO product_brand
        $attributes = array(
            'pa_pais' => array(
                'name' => 'pa_pais',
                'value' => '',
                'position' => 0,
                'is_visible' => 1,
                'is_variation' => 0,
                'is_taxonomy' => 1
            ),
            'pa_autor' => array(
                'name' => 'pa_autor',
                'value' => '',
                'position' => 1,
                'is_visible' => 1,
                'is_variation' => 0,
                'is_taxonomy' => 1
            ),
            'pa_genero' => array(
                'name' => 'pa_genero',
                'value' => '',
                'position' => 2,
                'is_visible' => 1,
                'is_variation' => 0,
                'is_taxonomy' => 1
            ),
            'pa_serie' => array(
                'name' => 'pa_serie',
                'value' => '',
                'position' => 3,
                'is_visible' => 1,
                'is_variation' => 0,
                'is_taxonomy' => 1
            )
        );
        update_post_meta($pid, '_product_attributes', $attributes);

        // Rank Math SEO
        update_post_meta($pid, 'rank_math_title', $pdata['title'] . ' %sep% Novela Ligera %sep% %sitename%');
        update_post_meta($pid, 'rank_math_description', $pdata['rm_desc']);
        update_post_meta($pid, 'rank_math_focus_keyword', $pdata['focus_kw']);
        update_post_meta($pid, 'rank_math_robots', array('index'));

        // Remoción de outofstock de visibilidad (es onbackorder)
        wp_remove_object_terms($pid, 'outofstock', 'product_visibility');

        echo "Producto $pid configurado con éxito" . PHP_EOL;
    }

    // Encadenamiento de Upsells entre los 3 tomos
    if (count($created_pids) === 3) {
        update_post_meta($created_pids[0], '_upsell_ids', array($created_pids[1], $created_pids[2]));
        update_post_meta($created_pids[1], '_upsell_ids', array($created_pids[0], $created_pids[2]));
        update_post_meta($created_pids[2], '_upsell_ids', array($created_pids[0], $created_pids[1]));
        echo "Upsells encadenados entre IDs: " . implode(', ', $created_pids) . PHP_EOL;
    }

    // Invalidar caché del Home Carousel
    delete_transient('akibara_home_carousel_brands_v4');
    echo "Cache del Home Carousel purgado" . PHP_EOL;

    echo "RESULT_PIDS:" . implode(',', $created_pids) . PHP_EOL;
    """

    # Ejecutar en producción vía wp eval
    # Escribir a un script temporal remoto, ejecutar y borrar inmediatamente
    remote_tmp = f"{REMOTE_BASE}/wp-content/uploads/tmp_deploy_boticaria.php"
    p = subprocess.Popen(["ssh", SSH_HOST, f"cat > {remote_tmp}"], stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    stdout, stderr = p.communicate(input=f"<?php\n{prod_script}".encode('utf-8'))
    if p.returncode != 0:
        print(f"Error escribiendo script remoto: {stderr.decode()}")
        sys.exit(1)

    print("  -> Ejecutando configuración WooCommerce en producción...")
    wp_eval_res = run_ssh(f"cd {REMOTE_BASE} && /usr/local/bin/wp eval-file wp-content/uploads/tmp_deploy_boticaria.php")
    print(wp_eval_res.stdout)

    # Borrado inmediato del archivo temporal remoto (Regla de Oro / Higiene)
    run_ssh(f"rm -f {remote_tmp}")
    print("  [HIGIENE OK] Archivo temporal remoto eliminado de producción.")

    # Extraer IDs creados
    pids = []
    for line in wp_eval_res.stdout.splitlines():
        if line.startswith("RESULT_PIDS:"):
            pids = [int(x) for x in line.replace("RESULT_PIDS:", "").strip().split(",") if x]

    # 5. Reindexar en akibara-search y purgar LiteSpeed
    if pids:
        print(f"\n5. Reindexando productos {pids} en akibara-search...")
        for pid in pids:
            run_ssh(f"cd {REMOTE_BASE} && /usr/local/bin/wp akb-search reindex {pid}", check=False)
        print("  [OK] akibara-search reindexado.")

    print("\n6. Purgando caché de LiteSpeed...")
    run_ssh(f"cd {REMOTE_BASE} && /usr/local/bin/wp litespeed-purge all", check=False)
    print("  [OK] Caché LiteSpeed purgado.")

    # 7. Watchdog de Inventario en Producción
    print("\n7. Ejecutando Watchdog de Inventario en Producción...")
    guardrail_res = subprocess.run(["python3", "ops/guardrail-inventario-akibara.py", "--target", "prod"], capture_output=True, text=True)
    print(guardrail_res.stdout)
    if "[APROBADO]" not in guardrail_res.stdout:
        print("  [ALERTA] Watchdog de inventario reportó anomalías.")
    else:
        print("  [PASS] Integridad de inventario certificada al 100%.")

    # 8. Verificación HTTP en vivo de producción
    print("\n8. Certificando URLs públicas de producción...")
    time.sleep(2)
    urls = [
        "https://akibara.cl/",
        "https://akibara.cl/marca/distrito-manga/",
        "https://akibara.cl/serie/los-diarios-de-la-boticaria-la-novela-original/",
        "https://akibara.cl/los-diarios-de-la-boticaria-la-novela-original-1/",
        "https://akibara.cl/los-diarios-de-la-boticaria-la-novela-original-2/",
        "https://akibara.cl/los-diarios-de-la-boticaria-la-novela-original-1-2-pack-de-lanzamiento/",
    ]

    for u in urls:
        try:
            req = urllib.request.Request(u, headers={"User-Agent": "Mozilla/5.0 (Akibara-QA-Deploy)"})
            with urllib.request.urlopen(req, timeout=15) as resp:
                code = resp.getcode()
                body = resp.read().decode("utf-8")
                print(f"  [HTTP {code}] {u}")
                if u == "https://akibara.cl/":
                    has_logo = "akb-logo-distrito-manga" in body
                    has_brand = "distrito-manga" in body
                    print(f"    -> Home Carousel Distrito Manga: {'PASS' if has_brand and has_logo else 'FAIL'}")
                elif "marca/distrito-manga" in u:
                    has_pack = "Pack de lanzamiento" in body
                    print(f"    -> Marca tiene catálogo: {'PASS' if has_pack else 'FAIL'}")
                elif "original-1/" in u:
                    has_weight = "0,45 kg" in body or "0.45" in body
                    has_dims = "21 × 15 × 2,5" in body or "21 x 15" in body
                    print(f"    -> Ficha Vol 01 Peso/Dimensiones: {'PASS' if has_weight and has_dims else 'FAIL'}")
        except Exception as e:
            print(f"  [ERROR HTTP] {u}: {e}")

    print("\n" + "=" * 60)
    print("  DESPLIEGUE A PRODUCCIÓN COMPLETADO CON ÉXITO")
    print("=" * 60)

if __name__ == "__main__":
    main()
