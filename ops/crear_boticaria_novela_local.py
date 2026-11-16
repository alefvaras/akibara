#!/usr/bin/env python3
"""Creación e integración de Los diarios de la boticaria (La novela original) en Local.

Pasos:
1. Procesa las 3 portadas en Akibara Image Studio (trim, upscale x4, min 800px, AVIF target_bpp 0.116).
2. Genera los derivados JPG de portadas (-og.jpg).
3. Crea la marca 'Distrito Manga' (product_brand) si no existe.
4. Crea la serie 'Los diarios de la boticaria (La novela original)' con metadatos de Regla 17.
5. Crea el autor 'Touko Shino' en pa_autor si no existe.
6. Registra los archivos adjuntos en WordPress y genera sus metadatos responsive completos.
7. Crea los 3 productos en WooCommerce con todos los atributos estructurados, extractos y SEO Rank Math.
8. Encadena ventas cruzadas / upsells.
"""

from __future__ import annotations

import json
import os
import subprocess
import time
import urllib.request
from pathlib import Path
from PIL import Image

STUDIO_URL = "http://127.0.0.1:8420/api/process"
STUDIO_OUT_DIR = Path("/Users/alefvaras/Documents/akibara-image-studio/out")
LOCAL_UPLOADS = Path("/Users/alefvaras/Documents/akibara/public_html/wp-content/uploads/2026/10")
PORTADAS_DIR = Path("/Users/alefvaras/Documents/akibara/public_html/wp-content/uploads/portadas")
LOCAL_UPLOADS.mkdir(parents=True, exist_ok=True)
PORTADAS_DIR.mkdir(parents=True, exist_ok=True)

ITEMS = [
    {
        "key": "vol1",
        "slug": "los-diarios-de-la-boticaria-la-novela-original-1-distrito-manga",
        "product_slug": "los-diarios-de-la-boticaria-la-novela-original-1",
        "title": "Los diarios de la boticaria (La novela original) 1",
        "sku": "9791387962821",
        "reg_price": 24990,
        "sale_price": 23741,
        "raw_path": "/tmp/boticaria_novela/vol1_raw.jpg",
        "alt": "Portada oficial de Los diarios de la boticaria (La novela original) 1 - Distrito Manga",
        "caption": "Portada del volumen 1 de Los diarios de la boticaria (La novela original), Distrito Manga.",
        "desc": "Fotografía y portada oficial en alta resolución de Los diarios de la boticaria (La novela original) volumen 1 de Natsu Hyuuga y Touko Shino, publicada por Distrito Manga. Disponible en Akibara con despacho a todo Chile.",
        "excerpt": "<strong>Los diarios de la boticaria (La novela original) 1</strong>: Tras ser secuestrada y vendida al palacio imperial, Maomao oculta su prodigioso talento farmacéutico hasta que un enigma mortal entre los herederos del trono exige su intervención. Edición oficial con despacho a todo Chile.",
        "content": """<p><strong>Los diarios de la boticaria (La novela original) 1</strong> presenta la historia original de <strong>Natsu Hyuuga</strong> con bellísimas ilustraciones de <strong>Touko Shino</strong>. Secuestrada en el barrio del placer y vendida como sirvienta en el palacio imperial, la joven boticaria Maomao desea pasar desapercibida hasta cumplir su servicio. Sin embargo, cuando los infantes del emperador enferman misteriosamente, su pasión investigadora y sus conocimientos sobre venenos y plantas medicinales la llevan a desentrañar un oscuro complot palaciego.</p>

<h2>Descubre la intriga de Los diarios de la boticaria (La novela original) 1</h2>
<p>Al salvar la vida de la pequeña princesa, Maomao llama la atención del perspicaz Jinshi, un eunuco de alta alcurnia imperial que reconoce su agudeza deductiva y la convierte en su catadora oficial de venenos. Una cautivante novela que combina rigor científico, intrigas cortesanas, enigmas médicos y una protagonista inolvidable.</p>

<p class="akb-pdp-seo-links">Explora también los demás volúmenes de la <a href="/serie/los-diarios-de-la-boticaria-la-novela-original/">serie Los diarios de la boticaria (La novela original)</a> y descubre más obras publicadas por <a href="/marca/distrito-manga/">Distrito Manga</a> en Akibara.</p>""",
        "seo_keyword": "Los diarios de la boticaria (La novela original) 1, Los diarios de la boticaria novela 1, novela Los diarios de la boticaria 1, Natsu Hyuuga",
        "seo_title": "Los diarios de la boticaria (La novela original) 1 %sep% Novela Ligera %sep% Akibara",
        "seo_desc": "Compra Los diarios de la boticaria (La novela original) 1 en Akibara. Edición oficial con ilustraciones a color y despacho rápido a todo Chile.",
    },
    {
        "key": "vol2",
        "slug": "los-diarios-de-la-boticaria-la-novela-original-2-distrito-manga",
        "product_slug": "los-diarios-de-la-boticaria-la-novela-original-2",
        "title": "Los diarios de la boticaria (La novela original) 2",
        "sku": "9791387962838",
        "reg_price": 24990,
        "sale_price": 23741,
        "raw_path": "/tmp/boticaria_novela/vol2_raw.jpg",
        "alt": "Portada oficial de Los diarios de la boticaria (La novela original) 2 - Distrito Manga",
        "caption": "Portada del volumen 2 de Los diarios de la boticaria (La novela original), Distrito Manga.",
        "desc": "Fotografía y portada oficial en alta resolución de Los diarios de la boticaria (La novela original) volumen 2 de Natsu Hyuuga y Touko Shino, publicada por Distrito Manga. Disponible en Akibara con despacho a todo Chile.",
        "excerpt": "<strong>Los diarios de la boticaria (La novela original) 2</strong>: Al servicio directo de la corte, Maomao debe resolver una intriga de envenenamiento y conspiración militar que sacude los cimientos del imperio. Novela ligera oficial con ilustraciones a color y despacho a todo Chile.",
        "content": """<p>En <strong>Los diarios de la boticaria (La novela original) 2</strong>, Maomao continúa desentrañando los enigmas más complejos del palacio imperial bajo las órdenes de Jinshi. Nuevos casos de envenenamientos aparentemente accidentales, rituales prohibidos y conspiraciones diplomáticas pondrán a prueba su ingenio farmacéutico y su capacidad deductiva.</p>

<h2>Misterio y conspiración en Los diarios de la boticaria (La novela original) 2</h2>
<p>Mientras la tensión política crece en la corte interna, los secretos en torno al pasado de Maomao y los verdaderos motivos de Jinshi comienzan a salir a la luz en una trama llena de giros magistrales y sutilezas históricas.</p>

<p class="akb-pdp-seo-links">Continúa la lectura con la <a href="/serie/los-diarios-de-la-boticaria-la-novela-original/">serie Los diarios de la boticaria (La novela original)</a> y encuentra el catálogo completo de <a href="/marca/distrito-manga/">Distrito Manga</a> en Akibara.</p>""",
        "seo_keyword": "Los diarios de la boticaria (La novela original) 2, Los diarios de la boticaria novela 2, novela Los diarios de la boticaria 2, Natsu Hyuuga",
        "seo_title": "Los diarios de la boticaria (La novela original) 2 %sep% Novela Ligera %sep% Akibara",
        "seo_desc": "Compra Los diarios de la boticaria (La novela original) 2 en Akibara. Edición oficial con ilustraciones a color y despacho rápido a todo Chile.",
    },
    {
        "key": "pack",
        "slug": "los-diarios-de-la-boticaria-la-novela-original-1-2-pack-distrito-manga",
        "product_slug": "los-diarios-de-la-boticaria-la-novela-original-1-2-pack-de-lanzamiento",
        "title": "Los diarios de la boticaria (La novela original) 1 + 2 (Pack de lanzamiento)",
        "sku": "9791387962968",
        "reg_price": 47990,
        "sale_price": 45591,
        "raw_path": "/tmp/boticaria_novela/pack_raw.jpg",
        "alt": "Portada oficial de Los diarios de la boticaria (La novela original) 1 + 2 Pack de lanzamiento - Distrito Manga",
        "caption": "Estuche del pack de lanzamiento con los volúmenes 1 y 2 de Los diarios de la boticaria (La novela original), Distrito Manga.",
        "desc": "Fotografía y portada oficial en alta resolución del pack de lanzamiento de Los diarios de la boticaria (La novela original) volúmenes 1 y 2 de Natsu Hyuuga y Touko Shino, publicado por Distrito Manga. Disponible en Akibara con despacho a todo Chile.",
        "excerpt": "<strong>Los diarios de la boticaria (La novela original) 1 + 2 (Pack de lanzamiento)</strong>: Estuche especial de lanzamiento que reúne los dos primeros volúmenes de la aclamada novela de Natsu Hyuuga y Touko Shino. Edición oficial de colección con despacho a todo Chile.",
        "content": """<p><strong>Los diarios de la boticaria (La novela original) 1 + 2 (Pack de lanzamiento)</strong> reúne en un estuche especial de colección los dos primeros volúmenes de la aclamada obra original escrita por <strong>Natsu Hyuuga</strong> e ilustrada por <strong>Touko Shino</strong>. La oportunidad perfecta para iniciar una de las historias de misterio, medicina tradicional e intriga cortesana más fascinantes de la literatura japonesa actual.</p>

<h2>Edición coleccionista: Los diarios de la boticaria (La novela original) 1 + 2 (Pack de lanzamiento)</h2>
<p>Acompaña a Maomao desde su llegada forzada a la corte interior hasta convertirse en la confidente y catadora de venenos más respetada del palacio. Incluye páginas a color exclusivas y acabado de lujo en formato rústica con solapas.</p>

<p class="akb-pdp-seo-links">Descubre toda la colección en la <a href="/serie/los-diarios-de-la-boticaria-la-novela-original/">serie Los diarios de la boticaria (La novela original)</a> y el catálogo oficial de <a href="/marca/distrito-manga/">Distrito Manga</a> en Akibara.</p>""",
        "seo_keyword": "Los diarios de la boticaria (La novela original) 1 + 2 (Pack de lanzamiento), pack Los diarios de la boticaria novela, novela Los diarios de la boticaria pack, Natsu Hyuuga",
        "seo_title": "Los diarios de la boticaria (La novela original) 1 + 2 (Pack de lanzamiento) %sep% Akibara",
        "seo_desc": "Consigue el pack de lanzamiento de Los diarios de la boticaria (La novela original) 1 y 2 en Akibara. Edición oficial de colección con despacho a todo Chile.",
    },
]


def process_with_studio(raw_path: str, filename_slug: str) -> Path:
    """Envía la imagen a Akibara Image Studio con preset Auto."""
    boundary = "----WebKitFormBoundaryAkibaraImageStudioAuto"
    with open(raw_path, "rb") as f:
        file_bytes = f.read()

    fields = {
        "trim": "true",
        "upscale": "true",
        "resize": "true",
        "max_width": "800",
        "max_height": "0",
        "required_min_width": "800",
        "out_format": "avif",
        "target_bpp": "0.116",
        "quality": "92",
    }

    body = bytearray()
    for k, v in fields.items():
        body.extend(f"--{boundary}\r\nContent-Disposition: form-data; name=\"{k}\"\r\n\r\n{v}\r\n".encode("utf-8"))

    body.extend(f"--{boundary}\r\nContent-Disposition: form-data; name=\"file\"; filename=\"{filename_slug}.jpg\"\r\nContent-Type: image/jpeg\r\n\r\n".encode("utf-8"))
    body.extend(file_bytes)
    body.extend(f"\r\n--{boundary}--\r\n".encode("utf-8"))

    req = urllib.request.Request(
        STUDIO_URL,
        data=bytes(body),
        headers={
            "Content-Type": f"multipart/form-data; boundary={boundary}",
            "User-Agent": "AkibaraImageStudioClient/1.0",
        },
    )

    with urllib.request.urlopen(req) as resp:
        res = json.loads(resp.read().decode("utf-8"))
        if not res.get("ok"):
            raise RuntimeError(f"Error procesando {filename_slug}: {res.get('error')}")

        job_id = res["id"]
        out_file = STUDIO_OUT_DIR / job_id / f"{filename_slug}.avif"
        if not out_file.exists():
            found = list((STUDIO_OUT_DIR / job_id).glob("*.avif"))
            if found:
                out_file = found[0]
            else:
                raise FileNotFoundError(f"No se encontró archivo generado para {filename_slug}")

        return out_file


def run_wp_php(php_code: str) -> str:
    cmd = ["docker", "exec", "-i", "akibara-cron-1", "wp", "eval-file", "-"]
    p = subprocess.run(cmd, input=php_code, text=True, capture_output=True)
    if p.returncode != 0:
        raise RuntimeError(f"Error WP CLI: {p.stderr}")
    return p.stdout


def main():
    print("=== INICIANDO PROCESO EN LOCAL ===")

    # 1. Image Studio Processing
    for item in ITEMS:
        slug = item["slug"]
        raw = item["raw_path"]
        avif_dest = LOCAL_UPLOADS / f"{slug}.avif"
        og_dest = PORTADAS_DIR / f"{slug}-og.jpg"

        print(f"-> Procesando imagen para {slug}...")
        if not avif_dest.exists() or avif_dest.stat().st_size < 10000:
            out_avif = process_with_studio(raw, slug)
            avif_dest.write_bytes(out_avif.read_bytes())
            print(f"   AVIF guardado: {avif_dest} ({avif_dest.stat().st_size} bytes)")
        else:
            print(f"   AVIF ya existe: {avif_dest}")

        # Generar versión JPG para redes / fallback
        if not og_dest.exists():
            im = Image.open(raw)
            if im.mode in ("RGBA", "P"):
                im = im.convert("RGB")
            # Redimensionar conservando proporción con lado mayor 800
            im.thumbnail((800, 1200), Image.Resampling.LANCZOS)
            im.save(og_dest, "JPEG", quality=90, optimize=True)
            print(f"   JPG OG guardado: {og_dest}")

    # 2. Ejecutar creación e integración en WordPress Local
    php_payload = {
        "items": ITEMS,
    }

    php_script = f"""<?php
    require_once ABSPATH . 'wp-admin/includes/image.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';

    $data = json_decode('{json.dumps(php_payload)}', true);

    // 1. Marca Distrito Manga
    $brand_slug = 'distrito-manga';
    $brand_term = get_term_by('slug', $brand_slug, 'product_brand');
    if (!$brand_term) {{
        $ins = wp_insert_term('Distrito Manga', 'product_brand', [
            'slug' => $brand_slug,
            'description' => 'Distrito Manga es el sello editorial oficial de Penguin Random House en España especializado en manga y novelas ligeras japonesas.'
        ]);
        if (is_wp_error($ins)) {{
            echo "Error creando marca: " . $ins->get_error_message() . "\\n";
            exit(1);
        }}
        $brand_id = $ins['term_id'];
        echo "Marca Distrito Manga creada con ID: $brand_id\\n";
    }} else {{
        $brand_id = $brand_term->term_id;
        echo "Marca Distrito Manga ya existe con ID: $brand_id\\n";
    }}

    update_term_meta($brand_id, 'country', 'ES');
    update_term_meta($brand_id, 'rank_math_title', 'Distrito Manga en Chile %sep% Manga y Novelas %sep% Akibara');
    update_term_meta($brand_id, 'rank_math_description', 'Distrito Manga: revisa las novelas y mangas oficiales catalogados en Akibara con formato original y despacho a todo Chile.');
    update_term_meta($brand_id, 'rank_math_focus_keyword', 'distrito manga chile, distrito manga catalogo');

    // 2. Autores: Natsu Hyuuga y Touko Shino
    $autor_natsu = get_term_by('slug', 'natsu-hyuuga', 'pa_autor');
    if (!$autor_natsu) {{
        $ins = wp_insert_term('Natsu Hyuuga', 'pa_autor', ['slug' => 'natsu-hyuuga']);
        $natsu_id = $ins['term_id'];
    }} else {{
        $natsu_id = $autor_natsu->term_id;
    }}

    $autor_touko = get_term_by('slug', 'touko-shino', 'pa_autor');
    if (!$autor_touko) {{
        $ins = wp_insert_term('Touko Shino', 'pa_autor', ['slug' => 'touko-shino']);
        $touko_id = $ins['term_id'];
        echo "Autor Touko Shino creado con ID: $touko_id\\n";
    }} else {{
        $touko_id = $autor_touko->term_id;
        echo "Autor Touko Shino ya existe con ID: $touko_id\\n";
    }}

    // 3. Serie Los diarios de la boticaria (La novela original)
    $serie_slug = 'los-diarios-de-la-boticaria-la-novela-original';
    $serie_name = 'Los diarios de la boticaria (La novela original)';
    $serie_desc = '<p>Maomao lleva una vida tranquila junto a su padre boticario en el barrio de placer, hasta que unos traficantes la secuestran y la venden como sirvienta en el palacio imperial. Decidida a pasar desapercibida hasta cumplir su contrato, intenta ocultar sus amplios conocimientos en venenos y medicina tradicional. Sin embargo, cuando los herederos del emperador caen víctimas de una misteriosa dolencia, la curiosidad y su instinto deductivo la empujan a intervenir, llamando la atención de Jinshi, un influyente e intrigante eunuco de la corte imperial. Descubre la historia original de Natsu Hyuuga que cautivó al mundo, en la edición oficial de novela ligera publicada por Distrito Manga con ilustraciones de Touko Shino y despacho a todo Chile.</p>\\n\\n<p><strong>Autor original:</strong> Natsu Hyuuga<br />\\n<strong>Ilustraciones:</strong> Touko Shino<br />\\n<strong>Demografía:</strong> Seinen / Misterio histórico<br />\\n<strong>Estado en Japón:</strong> En curso (15 volúmenes publicados)<br />\\n<strong>Formato de edición:</strong> Rústica con solapas (15 x 21 cm) con inclusión de páginas a color<br />\\n<strong>Estado en España:</strong> En curso (2 volúmenes publicados)<br />\\n<strong>Próximo tomo:</strong> Volumen #3 anunciado</p>';

    $serie_term = get_term_by('slug', $serie_slug, 'pa_serie');
    if (!$serie_term) {{
        $ins = wp_insert_term($serie_name, 'pa_serie', [
            'slug' => $serie_slug,
            'description' => $serie_desc
        ]);
        if (is_wp_error($ins)) {{
            echo "Error creando serie: " . $ins->get_error_message() . "\\n";
            exit(1);
        }}
        $serie_id = $ins['term_id'];
        echo "Serie creada con ID: $serie_id\\n";
    }} else {{
        $serie_id = $serie_term->term_id;
        wp_update_term($serie_id, 'pa_serie', [
            'name' => $serie_name,
            'description' => $serie_desc
        ]);
        echo "Serie actualizada con ID: $serie_id\\n";
    }}

    // Metadatos de serie estrictos (Regla 17)
    update_term_meta($serie_id, '_akb_serie_estado', 'en_publicacion');
    update_term_meta($serie_id, '_akb_serie_tomos_obra', '15');
    update_term_meta($serie_id, '_akb_serie_estado_edicion', 'en_publicacion');
    update_term_meta($serie_id, '_akb_serie_tomos_edicion', '2');

    // 4. Géneros y País
    $generos_slugs = ['misterio', 'drama', 'historico'];
    $generos_ids = [];
    foreach ($generos_slugs as $gs) {{
        $gt = get_term_by('slug', $gs, 'pa_genero');
        if ($gt) $generos_ids[] = (int) $gt->term_id;
    }}
    $pais_espana = get_term_by('slug', 'espana', 'pa_pais');
    $pais_id = $pais_espana ? (int) $pais_espana->term_id : 0;

    // Categorías: Manga (28) y Seinen (47)
    $cat_ids = [28, 47];

    $created_product_ids = [];

    // 5. Procesar productos
    foreach ($data['items'] as $item) {{
        $slug = $item['slug'];
        $pslug = $item['product_slug'];
        $title = $item['title'];
        $sku = $item['sku'];
        $reg = $item['reg_price'];
        $sale = $item['sale_price'];
        $excerpt = $item['excerpt'];
        $content = $item['content'];

        // Archivo AVIF
        $avif_path = '/var/www/html/wp-content/uploads/2026/10/' . $slug . '.avif';
        $avif_url = 'http://localhost:8080/wp-content/uploads/2026/10/' . $slug . '.avif';

        // Comprobar si existe el adjunto
        global $wpdb;
        $att_id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT ID FROM $wpdb->posts WHERE post_type = 'attachment' AND guid LIKE %s LIMIT 1",
            '%' . $slug . '.avif'
        ));

        if (!$att_id) {{
            $attachment = [
                'post_mime_type' => 'image/avif',
                'post_title'     => $item['title'] . ' - Distrito Manga',
                'post_name'      => $slug,
                'post_content'   => $item['desc'],
                'post_excerpt'   => $item['caption'],
                'post_status'    => 'inherit',
                'guid'           => $avif_url,
            ];
            $att_id = wp_insert_attachment($attachment, $avif_path);
            echo "Adjunto creado ID: $att_id para $slug\\n";
        }} else {{
            wp_update_post([
                'ID'           => $att_id,
                'post_title'   => $item['title'] . ' - Distrito Manga',
                'post_content' => $item['desc'],
                'post_excerpt' => $item['caption'],
            ]);
            echo "Adjunto existente ID: $att_id para $slug\\n";
        }}

        update_post_meta($att_id, '_wp_attached_file', '2026/10/' . $slug . '.avif');
        update_post_meta($att_id, '_wp_attachment_image_alt', $item['alt']);

        $meta = wp_generate_attachment_metadata($att_id, $avif_path);
        wp_update_attachment_metadata($att_id, $meta);

        // Comprobar si existe el producto
        $pid = wc_get_product_id_by_sku($sku);
        if (!$pid) {{
            $pid = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT ID FROM $wpdb->posts WHERE post_name = %s AND post_type = 'product' LIMIT 1",
                $pslug
            ));
        }}

        if (!$pid) {{
            $product = new WC_Product_Simple();
            $product->set_name($title);
            $product->set_slug($pslug);
            $product->set_status('publish');
            $pid = $product->save();
            echo "Producto creado con ID: $pid\\n";
        }} else {{
            $product = wc_get_product($pid);
            $product->set_name($title);
            $product->set_slug($pslug);
            $product->set_status('publish');
            $product->save();
            echo "Producto existente ID: $pid\\n";
        }}

        // Configuración de producto WooCommerce
        wp_update_post([
            'ID'           => $pid,
            'post_content' => $content,
            'post_excerpt' => $excerpt,
            'post_status'  => 'publish',
        ]);

        $created_product_ids[] = $pid;

        // Precios y Stock (Regla 8 y 18: onbackorder 5% reserva)
        update_post_meta($pid, '_sku', $sku);
        update_post_meta($pid, '_global_unique_id', $sku);
        update_post_meta($pid, '_regular_price', (string) $reg);
        update_post_meta($pid, '_sale_price', (string) $sale);
        update_post_meta($pid, '_price', (string) $sale);

        update_post_meta($pid, '_manage_stock', 'no');
        update_post_meta($pid, '_stock', null);
        update_post_meta($pid, '_stock_status', 'onbackorder');
        update_post_meta($pid, '_backorders', 'notify');

        // Imagen destacada
        set_post_thumbnail($pid, $att_id);

        // Taxonomías
        wp_set_object_terms($pid, [$brand_id], 'product_brand', false);
        wp_set_object_terms($pid, $cat_ids, 'product_cat', false);
        wp_set_object_terms($pid, [$pais_id], 'pa_pais', false);
        wp_set_object_terms($pid, [$natsu_id, $touko_id], 'pa_autor', false);
        wp_set_object_terms($pid, $generos_ids, 'pa_genero', false);
        wp_set_object_terms($pid, [$serie_id], 'pa_serie', false);

        // Quitar outofstock de visibilidad si estuviese
        wp_remove_object_terms($pid, 'outofstock', 'product_visibility');

        // Higiene _product_attributes (Regla 9: SOLO pa_pais, pa_autor, pa_genero, pa_serie)
        $attributes = [
            'pa_pais' => [
                'name'         => 'pa_pais',
                'value'        => '',
                'position'     => 0,
                'is_visible'   => 1,
                'is_variation' => 0,
                'is_taxonomy'  => 1,
            ],
            'pa_autor' => [
                'name'         => 'pa_autor',
                'value'        => '',
                'position'     => 1,
                'is_visible'   => 1,
                'is_variation' => 0,
                'is_taxonomy'  => 1,
            ],
            'pa_genero' => [
                'name'         => 'pa_genero',
                'value'        => '',
                'position'     => 2,
                'is_visible'   => 1,
                'is_variation' => 0,
                'is_taxonomy'  => 1,
            ],
            'pa_serie' => [
                'name'         => 'pa_serie',
                'value'        => '',
                'position'     => 3,
                'is_visible'   => 1,
                'is_variation' => 0,
                'is_taxonomy'  => 1,
            ],
        ];
        update_post_meta($pid, '_product_attributes', $attributes);

        // Rank Math SEO (Regla 13: focus keyword, titulo h2, longitud meta desc)
        update_post_meta($pid, 'rank_math_focus_keyword', $item['seo_keyword']);
        update_post_meta($pid, 'rank_math_title', $item['seo_title']);
        update_post_meta($pid, 'rank_math_description', $item['seo_desc']);
        update_post_meta($pid, 'rank_math_robots', ['index']);

        echo "Producto configurado con exito: $title (ID $pid)\\n";
    }}

    // Encadenamiento de Upsells cruzados entre ellos
    if (count($created_product_ids) === 3) {{
        // PID 0: Vol 1, PID 1: Vol 2, PID 2: Pack
        $p_vol1 = $created_product_ids[0];
        $p_vol2 = $created_product_ids[1];
        $p_pack = $created_product_ids[2];

        update_post_meta($p_vol1, '_upsell_ids', [$p_vol2, $p_pack]);
        update_post_meta($p_vol2, '_upsell_ids', [$p_vol1, $p_pack]);
        update_post_meta($p_pack, '_upsell_ids', [$p_vol1, $p_vol2]);
        echo "Upsells encadenados entre Vol 1 ($p_vol1), Vol 2 ($p_vol2) y Pack ($p_pack)\\n";
    }}
    ?>"""

    print("\n-> Ejecutando script de inserción en WordPress Local...")
    out = run_wp_php(php_script)
    print(out)
    print("=== FINALIZADO CON ÉXITO EN LOCAL ===")

if __name__ == "__main__":
    main()
