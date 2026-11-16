#!/usr/bin/env python3
"""
Aplica la normalización crítica en el servidor de Producción de Akibara.
Actualiza The Walking Dead 05, 06, 07, Invencible 04, y Tokyo Ghoul 01, 07, 09, 10
con sus descripciones canónicas, limpias de H3 artificiales, sin enlaces externos,
y con enlazado interno SEO estandarizado.
"""

import base64
import json
import subprocess
import sys
from normalizar_fichas_critico import CLEAN_DESCRIPTIONS

PROD_MAP = {
    "The Walking Dead 05": 28438,
    "The Walking Dead 06": 28440,
    "The Walking Dead 07": 28442,
    "Invencible 04": 28444,
    "Tokyo Ghoul 01": 28125,
    "Tokyo Ghoul 07": 28139,
    "Tokyo Ghoul 09": 28143,
    "Tokyo Ghoul 10": 28145
}

def main():
    print("=== APLICANDO NORMALIZACIÓN CRÍTICA EN PRODUCCIÓN ===")

    payload = []
    for name, prod_id in PROD_MAP.items():
        if name in CLEAN_DESCRIPTIONS:
            item = CLEAN_DESCRIPTIONS[name]
            payload.append({
                "id": prod_id,
                "name": name,
                "content": item["content"],
                "excerpt": item["excerpt"]
            })

    b64_payload = base64.b64encode(json.dumps(payload, ensure_ascii=False).encode("utf-8")).decode("ascii")

    php_script = f"""<?php
require_once "/home/u888022333/domains/akibara.cl/public_html/wp-load.php";

$items = json_decode(base64_decode('{b64_payload}'), true);
$updated = 0;
foreach ($items as $item) {{
    $id = (int)$item['id'];
    $post = get_post($id);
    if ($post) {{
        wp_update_post([
            'ID' => $id,
            'post_content' => $item['content'],
            'post_excerpt' => $item['excerpt']
        ]);
        echo "[OK Prod] ID $id (" . $post->post_title . ") actualizado con contenido canonico limpio.\\n";
        $updated++;
    }} else {{
        echo "[ERR Prod] No se encontro ID $id (" . $item['name'] . ")\\n";
    }}
}}
echo "Total actualizados en produccion: $updated\\n";
"""

    # Ejecutar via stdin por SSH para no dejar archivos temporales en produccion
    proc = subprocess.run(
        ["ssh", "akibara", "php"],
        input=php_script,
        text=True,
        capture_output=True
    )

    print("Salida STDOUT:\n", proc.stdout)
    if proc.stderr:
        print("Salida STDERR:\n", proc.stderr)

if __name__ == "__main__":
    main()
