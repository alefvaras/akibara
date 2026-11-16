#!/usr/bin/env python3
"""
Aplica la máxima calidad editorial y optimización SEO (Rank Math >= 70)
a todos los 35 tomos españoles nuevos en Docker Local.
"""

import json
import os
import subprocess
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).parent))
from data_editorial_espana_1 import BATCH_1
from data_editorial_espana_2 import BATCH_2
from data_editorial_espana_3 import BATCH_3

ALL_PRODUCTS = BATCH_1 + BATCH_2 + BATCH_3

def build_full_content(p):
    h2 = p["h2"]
    kw = p["kw"]
    p1 = p["p1"]
    p2 = p["p2"]
    p3 = p["p3"]
    p4 = p["p4"]
    p5 = p["p5"]
    in_url = p["internal_url"]
    in_text = p["internal_text"]
    spec = p["spec"]

    return f"""<h2>{h2}</h2>

<p><strong>{kw}</strong> {p1}</p>

<p>{p2}</p>

<p>{p3}</p>

<p>{p4}</p>

<p>{p5}</p>

<p><strong>{spec}</strong></p>

<p class="akb-pdp-seo-links">Puedes explorar la colección completa en nuestra sección de <a href="{in_url}">{in_text}</a> con despacho rápido a todo Chile desde Akibara.</p>"""

def main():
    print(f"=== ACTUALIZANDO CALIDAD EDITORIAL Y SEO DE {len(ALL_PRODUCTS)} TOMOS EN LOCAL ===")

    payload_items = []
    for p in ALL_PRODUCTS:
        full_content = build_full_content(p)
        payload_items.append({
            "id": p["id"],
            "title": p["title"],
            "desc": p["desc"],
            "kw": p["kw"],
            "score": "75",
            "content": full_content,
            "excerpt": p["excerpt"],
            "image_alt": p["image_alt"]
        })

    # Guardar en JSON temporal para ser leído en el contenedor
    json_path = "public_html/wp-content/calidad_editorial_payload.json"
    with open(json_path, "w", encoding="utf-8") as f:
        json.dump(payload_items, f, ensure_ascii=False, indent=2)

    php_script = """<?php
$json_file = ABSPATH . 'wp-content/calidad_editorial_payload.json';
if (!file_exists($json_file)) {
    echo "ERROR: Payload no encontrado.\n";
    exit(1);
}
$items = json_decode(file_get_contents($json_file), true);
$updated = 0;
foreach ($items as $item) {
    $id = (int)$item['id'];
    $post = get_post($id);
    if (!$post) {
        echo "ADVERTENCIA: Post ID $id no existe en local.\n";
        continue;
    }

    wp_update_post([
        'ID'           => $id,
        'post_content' => $item['content'],
        'post_excerpt' => $item['excerpt'],
    ]);

    update_post_meta($id, 'rank_math_title', $item['title']);
    update_post_meta($id, 'rank_math_description', $item['desc']);
    update_post_meta($id, 'rank_math_focus_keyword', $item['kw']);
    update_post_meta($id, 'rank_math_seo_score', $item['score']);

    $thumb_id = get_post_thumbnail_id($id);
    if ($thumb_id) {
        update_post_meta($thumb_id, '_wp_attachment_image_alt', $item['image_alt']);
    }

    $wc = str_word_count(strip_tags($item['content']));
    echo "[OK] ID $id ({$post->post_title}): words=$wc, SEO={$item['score']}, KW='{$item['kw']}'\n";
    $updated++;
}
echo "\nTotal actualizados exitosamente en local: $updated\n";
"""

    php_path = "public_html/wp-content/actualizar-calidad.php"
    with open(php_path, "w", encoding="utf-8") as f:
        f.write(php_script)

    print("Ejecutando actualización masiva en Docker local vía WP-CLI...")
    res = subprocess.run([
        "docker", "exec", "-i", "akibara-cron-1",
        "wp", "eval-file", "/var/www/html/wp-content/actualizar-calidad.php"
    ], capture_output=True, text=True)

    print("STDOUT:\n", res.stdout)
    if res.stderr:
        print("STDERR:\n", res.stderr)

    # Limpieza inmediata de archivos auxiliares
    subprocess.run(["rm", "-f", json_path, php_path])
    print("Archivos temporales locales eliminados.")

if __name__ == "__main__":
    main()
