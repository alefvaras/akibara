#!/usr/bin/env python3
"""
Aplica la normalización crítica en el entorno Docker Local de Akibara.
"""

import json
import subprocess
import sys
from normalizar_fichas_critico import CLEAN_DESCRIPTIONS

LOCAL_MAP = {
    "The Walking Dead 05": 32783,
    "The Walking Dead 06": 32785,
    "The Walking Dead 07": 32787,
    "Invencible 04": 32781
}

def main():
    print("=== APLICANDO NORMALIZACIÓN CRÍTICA EN LOCAL DOCKER ===")

    payload = []
    for name, local_id in LOCAL_MAP.items():
        if name in CLEAN_DESCRIPTIONS:
            item = CLEAN_DESCRIPTIONS[name]
            payload.append({
                "id": local_id,
                "name": name,
                "content": item["content"],
                "excerpt": item["excerpt"]
            })

    json_path = "public_html/wp-content/normalizar_payload.json"
    with open(json_path, "w", encoding="utf-8") as f:
        json.dump(payload, f, ensure_ascii=False, indent=2)

    php_code = """
require_once '/var/www/html/wp-load.php';
$items = json_decode(file_get_contents('/var/www/html/wp-content/normalizar_payload.json'), true);
$updated = 0;
foreach ($items as $item) {
    $id = (int)$item['id'];
    $post = get_post($id);
    if (!$post) {
        $found = get_posts(['post_type' => 'product', 's' => $item['name'], 'posts_per_page' => 1]);
        if ($found) {
            $id = $found[0]->ID;
            $post = $found[0];
        }
    }
    if ($post) {
        wp_update_post([
            'ID' => $id,
            'post_content' => $item['content'],
            'post_excerpt' => $item['excerpt']
        ]);
        echo "[OK Local] ID $id ({$post->post_title}) actualizado con contenido canonico limpio.\n";
        $updated++;
    } else {
        echo "[ERR Local] No se encontro {$item['name']}\n";
    }
}
echo "Total especificos actualizados en local: $updated\n";
"""

    res = subprocess.run([
        "docker", "exec", "-i", "akibara-wordpress-1", "php", "-r", php_code
    ], capture_output=True, text=True)
    print("Salida específicos local:\n", res.stdout)
    if res.stderr:
        print("Error específicos local:\n", res.stderr)

    subprocess.run(["rm", "-f", json_path])
    print("Fichas específicas aplicadas con éxito.")

if __name__ == "__main__":
    main()
