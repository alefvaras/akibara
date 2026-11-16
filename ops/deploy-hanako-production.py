#!/usr/bin/env python3
"""
Deploy Hanako-kun products and series to Akibara Production.
Follows all rules from AGENTS.md:
- Never leave backups or temp scripts on production
- Execute directly via ssh wp eval
- Strictly avoid image and attachment duplication
"""

import json
import subprocess
import sys

def main():
    with open('/tmp/hanako_full_export.json') as f:
        products = json.load(f)

    print(f"Loaded {len(products)} products from export.")

    # Convert products to PHP array code
    products_json = json.dumps(products, ensure_ascii=False)

    php_script = f'''
global $wpdb;

echo "=== STARTING PRODUCTION DEPLOYMENT OF HANAKO-KUN ===\\n";

// 1. Author
$author = get_term_by("slug", "aidairo", "pa_autor");
if (!$author) {{
    $res = wp_insert_term("AidaIro", "pa_autor", [
        "slug" => "aidairo",
        "description" => "Dúo creativo japonés formado por Iro (guionista) y Aida (ilustradora), creadores del manga de éxito internacional Hanako-kun, el fantasma del lavabo (Jibaku Shōnen Hanako-kun)."
    ]);
    $author_id = is_array($res) ? (int)$res["term_id"] : 0;
    echo "Created author AidaIro (ID: $author_id)\\n";
}} else {{
    $author_id = (int)$author->term_id;
    echo "Author AidaIro already exists (ID: $author_id)\\n";
}}

// 2. Series Definitions
$series_defs = [
    "hanako-kun" => [
        "name" => "Hanako-kun, el fantasma del lavabo",
        "desc" => "<p><strong>Hanako-kun, el fantasma del lavabo</strong> (<em>Jibaku Shōnen Hanako-kun</em>) es el aclamado manga de fantasía oscura, comedia sobrenatural y romance creado por el talentoso dúo <strong>AidaIro</strong> para la revista <em>Monthly GFantasy</em> de Square Enix. En los misteriosos pasillos de la Academia Kamome, la estudiante Nene Yashiro invoca al legendario Séptimo Misterio escolar esperando cumplir un deseo de amor, encontrándose con un travieso y enigmático chico fantasma. Tras quedar ligada espiritualmente a él por una maldición de sirena, Nene se convierte en su asistenta para mantener el equilibrio entre el mundo humano y la Orilla Lejana.</p>\\n\\n<p>En <strong>Akibara</strong> puedes comprar y encargar todos los tomos de <strong>Hanako-kun en español</strong> en su edición oficial de <strong>Editorial Ivrea España</strong>, presentada en formato <strong>B6</strong> con sobrecubierta y páginas a color. Disponible a pedido con importación directa desde España, despacho rápido en Santiago y envíos protegidos a todo Chile.</p>",
        "estado" => "en_publicacion",
        "estado_edicion" => "al_dia",
        "tomos_obra" => 26,
        "tomos_edicion" => 26,
        "rm_title" => "Manga Hanako-kun en Chile — Edición Ivrea España | Akibara",
        "rm_desc" => "Colección completa de Hanako-kun, el fantasma del lavabo (Ivrea España) en Chile. Tomos en español formato B6 a pedido con despacho rápido a todo el país.",
        "rm_kw" => "Hanako-kun"
    ],
    "hanako-kun-despues-de-clase" => [
        "name" => "Hanako-kun, después de clase",
        "desc" => "<p><strong>Hanako-kun, después de clase</strong> (<em>Hōkago Shōnen Hanako-kun</em>) es el encantador spin-off cómico oficial creado por el dúo <strong>AidaIro</strong> y publicado en <em>Pixiv Comic</em>. Esta miniserie cerrada de 2 tomos desvela el día a día más divertido y disparatado de los alumnos y apariciones de la Academia Kamome, acompañando a Hanako, Nene, Kou y los traviesos Mokke en juegos absurdos, fiestas de té sobrenaturales y enredos fuera del horario lectivo.</p>\\n\\n<p>En <strong>Akibara</strong> puedes encargar la serie completa de <strong>Hanako-kun, después de clase (Tomos 01 y 02)</strong> en la edición oficial de <strong>Editorial Ivrea España</strong>, en formato B6 con sobrecubierta y páginas a color, con envío rápido y seguro a todo Chile.</p>",
        "estado" => "completa",
        "estado_edicion" => "completa",
        "tomos_obra" => 2,
        "tomos_edicion" => 2,
        "rm_title" => "Manga Hanako-kun, después de clase en Chile — Ivrea España | Akibara",
        "rm_desc" => "Compra Hanako-kun, después de clase tomos 1 y 2 (Ivrea España) en Chile. El spin-off oficial de AidaIro a pedido con envíos a todo el país en Akibara.",
        "rm_kw" => "Hanako-kun, después de clase"
    ],
    "hanako-kun-aidairo-illustrations" => [
        "name" => "Hanako-kun: AidaIro Illustrations",
        "desc" => "<p><strong>Hanako-kun: AidaIro Illustrations</strong> (<em>Jibaku Shōnen Hanako-kun AidaIro Gashū</em>) es la lujosa colección de libros de arte oficiales que recopila el virtuoso trabajo ilustrado del dúo <strong>AidaIro</strong>. Con cerca de 200 piezas a todo color por volumen, abarca portadas, páginas a color de <em>Monthly GFantasy</em>, material promocional y bocetos conceptuales inéditos acompañados de comentarios de las autoras, destacando por su inconfundible estética gótica y victoriana.</p>\\n\\n<p>En <strong>Akibara</strong> puedes comprar y encargar los artbooks oficiales <strong>AidaIro Illustrations 01 y 02</strong> en su edición de lujo de <strong>Editorial Ivrea España</strong>, en formato <strong>B5</strong> con sobrecubierta metalizada y papel satinado premium, con envíos a todo Chile.</p>",
        "estado" => "en_publicacion",
        "estado_edicion" => "al_dia",
        "tomos_obra" => 2,
        "tomos_edicion" => 2,
        "rm_title" => "Artbook Hanako-kun: AidaIro Illustrations en Chile — Ivrea | Akibara",
        "rm_desc" => "Libros de arte oficiales de Hanako-kun por AidaIro (Ivrea España) en Chile. Edición de lujo B5 con ilustraciones a todo color y envío a todo el país.",
        "rm_kw" => "Hanako-kun: AidaIro Illustrations"
    ]
];

$series_ids = [];
foreach ($series_defs as $s_slug => $s_data) {{
    $existing = get_term_by("slug", $s_slug, "pa_serie");
    if (!$existing) {{
        $res = wp_insert_term($s_data["name"], "pa_serie", [
            "slug" => $s_slug,
            "description" => $s_data["desc"]
        ]);
        $s_id = is_array($res) ? (int)$res["term_id"] : 0;
        echo "Created serie {{$s_data['name']}} (ID: $s_id)\\n";
    }} else {{
        $s_id = (int)$existing->term_id;
        wp_update_term($s_id, "pa_serie", [
            "name" => $s_data["name"],
            "description" => $s_data["desc"]
        ]);
        echo "Updated serie {{$s_data['name']}} (ID: $s_id)\\n";
    }}
    $series_ids[$s_slug] = $s_id;

    // Series publication status metadata
    update_term_meta($s_id, "_akb_serie_estado", $s_data["estado"]);
    update_term_meta($s_id, "_akb_serie_estado_edicion", $s_data["estado_edicion"]);
    update_term_meta($s_id, "_akb_serie_tomos_obra", $s_data["tomos_obra"]);
    update_term_meta($s_id, "_akb_serie_tomos_edicion", $s_data["tomos_edicion"]);

    // Rank Math term meta
    update_term_meta($s_id, "rank_math_title", $s_data["rm_title"]);
    update_term_meta($s_id, "rank_math_description", $s_data["rm_desc"]);
    update_term_meta($s_id, "rank_math_focus_keyword", $s_data["rm_kw"]);
}}

// 3. Bidirectional related editions
$main_id = $series_ids["hanako-kun"];
$spin_id = $series_ids["hanako-kun-despues-de-clase"];
$art_id  = $series_ids["hanako-kun-aidairo-illustrations"];

update_term_meta($main_id, "_akb_serie_ediciones_relacionadas", [$spin_id, $art_id]);
update_term_meta($spin_id, "_akb_serie_ediciones_relacionadas", [$main_id, $art_id]);
update_term_meta($art_id,  "_akb_serie_ediciones_relacionadas", [$main_id, $spin_id]);
echo "Updated _akb_serie_ediciones_relacionadas for all 3 series\\n";

// 4. Products Import
$products_raw = '{products_json}';
$products = json_decode($products_raw, true);

$brand_term = get_term_by("slug", "ivrea-espana", "product_brand");
$brand_id = $brand_term ? (int)$brand_term->term_id : 362;

$pais_term = get_term_by("slug", "espana", "pa_pais");
$pais_id = $pais_term ? (int)$pais_term->term_id : 154;

$cat_manga = get_term_by("slug", "manga", "product_cat");
$cat_manga_id = $cat_manga ? (int)$cat_manga->term_id : 28;

$cat_shonen = get_term_by("slug", "shonen", "product_cat");
$cat_shonen_id = $cat_shonen ? (int)$cat_shonen->term_id : 51;

$cat_artbook = get_term_by("slug", "artbook", "product_cat");
$cat_artbook_id = $cat_artbook ? (int)$cat_artbook->term_id : 608;

$genre_cache = [];
foreach (["sobrenatural", "comedia", "misterio", "romance", "fantasia"] as $g_slug) {{
    $t = get_term_by("slug", $g_slug, "pa_genero");
    if ($t) $genre_cache[$g_slug] = (int)$t->term_id;
}}

require_once ABSPATH . "wp-admin/includes/image.php";

$count = 0;
foreach ($products as $p_data) {{
    $count++;
    $slug = $p_data["slug"];
    $title = $p_data["title"];
    $img_file = $p_data["image_file"];

    // 4.1 Attachment Deduplication Guard
    $attachment_id = 0;
    // Check by _wp_attached_file
    $existing_by_file = $wpdb->get_var($wpdb->prepare(
        "SELECT post_id FROM {{$wpdb->postmeta}} WHERE meta_key = '_wp_attached_file' AND (meta_value = %s OR meta_value = %s) LIMIT 1",
        "2026/10/" . $img_file,
        $img_file
    ));
    if ($existing_by_file) {{
        $attachment_id = (int)$existing_by_file;
    }} else {{
        // Check by title
        $existing_by_title = $wpdb->get_var($wpdb->prepare(
            "SELECT ID FROM {{$wpdb->posts}} WHERE post_type = 'attachment' AND post_title = %s LIMIT 1",
            $title
        ));
        if ($existing_by_title) {{
            $attachment_id = (int)$existing_by_title;
        }}
    }}

    if (!$attachment_id && $img_file) {{
        $target_path = ABSPATH . "wp-content/uploads/2026/10/" . $img_file;
        if (file_exists($target_path)) {{
            $attach_id = wp_insert_attachment([
                "post_mime_type" => "image/avif",
                "post_title"     => $title,
                "post_content"   => "",
                "post_status"    => "inherit"
            ], $target_path);
            if (!is_wp_error($attach_id) && $attach_id > 0) {{
                $attach_data = wp_generate_attachment_metadata($attach_id, $target_path);
                wp_update_attachment_metadata($attach_id, $attach_data);
                update_post_meta($attach_id, "_wp_attached_file", "2026/10/" . $img_file);
                update_post_meta($attach_id, "_wp_attachment_image_alt", $title . " - Akibara");
                $attachment_id = (int)$attach_id;
                echo "[$count] Created attachment for $title (ID: $attachment_id)\\n";
            }}
        }} else {{
            echo "[$count] WARNING: Image file $target_path not found!\\n";
        }}
    }} else {{
        echo "[$count] Reusing attachment ID $attachment_id for $title\\n";
    }}

    // 4.2 Product Post Creation / Update
    $existing_p = get_page_by_path($slug, OBJECT, "product");
    if ($existing_p) {{
        $product_id = (int)$existing_p->ID;
        wp_update_post([
            "ID"           => $product_id,
            "post_title"   => $title,
            "post_content" => $p_data["content"],
            "post_excerpt" => $p_data["excerpt"],
            "post_status"  => "publish"
        ]);
        echo "[$count] Updated existing product $title (ID: $product_id, status: publish)\\n";
    }} else {{
        $product_id = wp_insert_post([
            "post_title"   => $title,
            "post_name"    => $slug,
            "post_content" => $p_data["content"],
            "post_excerpt" => $p_data["excerpt"],
            "post_status"  => "publish",
            "post_type"    => "product"
        ]);
        if (is_wp_error($product_id) || $product_id < 1) {{
            echo "[$count] ERROR creating product $title\\n";
            continue;
        }}
        echo "[$count] Created product $title (ID: $product_id, status: publish)\\n";
    }}

    // 4.3 Meta fields
    update_post_meta($product_id, "_regular_price", $p_data["regular_price"]);
    if (!empty($p_data["sale_price"])) {{
        update_post_meta($product_id, "_sale_price", $p_data["sale_price"]);
        update_post_meta($product_id, "_price", $p_data["sale_price"]);
    }} else {{
        delete_post_meta($product_id, "_sale_price");
        update_post_meta($product_id, "_price", $p_data["regular_price"]);
    }}

    update_post_meta($product_id, "_sku", $p_data["sku"]);
    update_post_meta($product_id, "_global_unique_id", $p_data["global_unique_id"]);
    update_post_meta($product_id, "isbn", $p_data["isbn"]);

    update_post_meta($product_id, "_manage_stock", $p_data["manage_stock"]);
    update_post_meta($product_id, "_stock_status", $p_data["stock_status"]);
    update_post_meta($product_id, "_backorders", $p_data["backorders"]);
    update_post_meta($product_id, "_akb_eta_type", $p_data["eta_type"]);

    if ($attachment_id > 0) {{
        set_post_thumbnail($product_id, $attachment_id);
    }}
    // Prevent duplicate image in product gallery
    update_post_meta($product_id, "_product_image_gallery", "");

    // Rank Math product SEO
    update_post_meta($product_id, "rank_math_focus_keyword", $p_data["rank_math_focus_keyword"]);
    update_post_meta($product_id, "rank_math_description", $p_data["rank_math_description"]);
    update_post_meta($product_id, "rank_math_rich_snippet", "product");

    // 4.4 Categories & Taxonomies
    if (in_array("artbook", $p_data["cats"])) {{
        wp_set_object_terms($product_id, [$cat_artbook_id], "product_cat");
    }} else {{
        wp_set_object_terms($product_id, [$cat_manga_id, $cat_shonen_id], "product_cat");
    }}

    wp_set_object_terms($product_id, [$brand_id], "product_brand");
    wp_set_object_terms($product_id, [$pais_id], "pa_pais");
    wp_set_object_terms($product_id, [$author_id], "pa_autor");

    $s_term_id = $series_ids[$p_data["serie"]] ?? 0;
    if ($s_term_id > 0) {{
        wp_set_object_terms($product_id, [$s_term_id], "pa_serie");
    }}

    $product_genre_ids = [];
    foreach ($p_data["generos"] as $g_slug) {{
        if (isset($genre_cache[$g_slug])) {{
            $product_genre_ids[] = $genre_cache[$g_slug];
        }}
    }}
    if (!empty($product_genre_ids)) {{
        wp_set_object_terms($product_id, $product_genre_ids, "pa_genero");
    }}

    // Attributes definition
    $wc_attributes = [
        "pa_pais" => [
            "name"         => "pa_pais",
            "value"        => "",
            "position"     => 0,
            "is_visible"   => 1,
            "is_variation" => 0,
            "is_taxonomy"  => 1
        ],
        "pa_autor" => [
            "name"         => "pa_autor",
            "value"        => "",
            "position"     => 1,
            "is_visible"   => 1,
            "is_variation" => 0,
            "is_taxonomy"  => 1
        ],
        "pa_genero" => [
            "name"         => "pa_genero",
            "value"        => "",
            "position"     => 2,
            "is_visible"   => 1,
            "is_variation" => 0,
            "is_taxonomy"  => 1
        ],
        "pa_serie" => [
            "name"         => "pa_serie",
            "value"        => "",
            "position"     => 3,
            "is_visible"   => 1,
            "is_variation" => 0,
            "is_taxonomy"  => 1
        ]
    ];
    update_post_meta($product_id, "_product_attributes", $wc_attributes);

    // Update lookup table
    wc_update_product_lookup_tables($product_id);
    wc_delete_product_transients($product_id);
}}

// 5. Invalidate Series Transients & Purge
foreach ($series_ids as $s_id) {{
    delete_transient("akb_sf_" . $s_id);
}}

if (function_exists("do_action")) {{
    do_action("litespeed_purge_all");
}}

echo "=== DEPLOYMENT COMPLETED SUCCESSFULLY ===\\n";
'''

    full_php = '<?php\n' + php_script

    print("Running deployment on production via ssh wp eval-file -...")
    proc = subprocess.Popen(
        ['ssh', 'akibara', 'cd domains/akibara.cl/public_html && wp eval-file -'],
        stdin=subprocess.PIPE,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        text=True
    )
    stdout, stderr = proc.communicate(input=full_php)

    print("STDOUT:")
    print(stdout)
    if stderr:
        print("STDERR:")
        print(stderr)

    if proc.returncode != 0:
        print(f"FAILED with returncode {proc.returncode}")
        sys.exit(proc.returncode)

    print("SUCCESS!")

if __name__ == '__main__':
    main()
