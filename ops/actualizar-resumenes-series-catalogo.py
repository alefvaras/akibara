#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Script maestro de actualización de resúmenes de series para Akibara.
Aplica los 126 resúmenes editoriales condensados (120-170 palabras),
erradica 100% referencias a Norma Editorial y asegura consistencia
editorial en el espejo local y en producción.
"""

import json
import os
import subprocess
import sys
from pathlib import Path

# Asegurar que el directorio raíz del proyecto está en PYTHONPATH
BASE_DIR = Path(__file__).resolve().parent.parent
if str(BASE_DIR) not in sys.path:
    sys.path.insert(0, str(BASE_DIR))

from ops.resumenes_series_norma import SERIES_NORMA
from ops.resumenes_series_shonen_top import SERIES_SHONEN_TOP
from ops.resumenes_series_seinen_classics import SERIES_SEINEN_CLASSICS
from ops.resumenes_series_milkyway_arechi_ovni import SERIES_MILKYWAY_ARECHI_OVNI
from ops.resumenes_series_rest import SERIES_REST
from ops.validar_resumen_serie import validate_summary


def get_all_summaries() -> Dict[str, str]:
    all_dict = {}
    for d in [
        SERIES_NORMA,
        SERIES_SHONEN_TOP,
        SERIES_SEINEN_CLASSICS,
        SERIES_MILKYWAY_ARECHI_OVNI,
        SERIES_REST,
    ]:
        all_dict.update(d)
    return all_dict


def validate_all(summaries: Dict[str, str], prod_data_path: str = "/tmp/series_prod.json") -> bool:
    with open(prod_data_path, "r", encoding="utf-8") as f:
        prod_list = json.load(f)
    prod = {s["slug"]: s for s in prod_list}

    all_valid = True
    print(f"Validando {len(summaries)} resúmenes editoriales contra catálogo...")
    for slug, html in summaries.items():
        if slug not in prod:
            print(f"  [ERROR] Slug '{slug}' no existe en el catálogo de producción.")
            all_valid = False
            continue
        s = prod[slug]
        errors, wc = validate_summary(slug, s["name"], s["brands"], html)
        if errors:
            print(f"  [ERROR] {slug} ({wc} palabras): {errors}")
            all_valid = False

    if all_valid:
        print(f"Todos los {len(summaries)} resúmenes pasaron la validación con éxito (100% OK).\n")
    return all_valid


def generate_php_update_script(summaries: Dict[str, str]) -> str:
    """Genera el script PHP que ejecutará wp_update_term para cada slug."""
    json_payload = json.dumps(summaries, ensure_ascii=False)
    encoded_json = json.dumps(json_payload)
    php_code = f"""<?php
$summaries = json_decode({encoded_json}, true);
if (!is_array($summaries)) {{
    echo "ERROR: Falló decodificación de JSON.\\n";
    exit(1);
}}

echo "Iniciando actualización de " . count($summaries) . " descripciones de series...\\n";

$updated = 0;
$skipped = 0;
$not_found = 0;

foreach ($summaries as $slug => $new_desc) {{
    $term = get_term_by('slug', $slug, 'pa_serie');
    if (!$term) {{
        echo "  [AVISO] Término '" . $slug . "' no existe en pa_serie.\\n";
        $not_found++;
        continue;
    }}

    $current_desc = trim((string)$term->description);
    $clean_new = trim((string)$new_desc);

    if ($current_desc === $clean_new) {{
        $skipped++;
        continue;
    }}

    $res = wp_update_term($term->term_id, 'pa_serie', [
        'description' => $clean_new
    ]);

    if (is_wp_error($res)) {{
        echo "  [ERROR] Falló actualización de " . $slug . ": " . $res->get_error_message() . "\\n";
    }} else {{
        $updated++;
    }}
}}

echo "\\n=== RESULTADO DE ACTUALIZACIÓN ===\\n";
echo "  Actualizados: $updated\\n";
echo "  Sin cambios (idénticos): $skipped\\n";
echo "  No encontrados: $not_found\\n";

// Limpiar transients de facts y cache de series
delete_transient('akb_series_directory_cache');
if (function_exists('wp_cache_flush')) {{
    wp_cache_flush();
}}
"""
    return php_code


def update_local(php_script: str):
    print("=== [1/2] APLICANDO EN ESPEJO LOCAL (Docker akibara-cron-1) ===")
    cmd = ["docker", "exec", "-i", "akibara-cron-1", "wp", "eval-file", "-", "--allow-root"]
    proc = subprocess.run(cmd, input=php_script, capture_output=True, text=True)
    print(proc.stdout)
    if proc.stderr:
        print("STDERR:", proc.stderr)
    if proc.returncode != 0:
        raise RuntimeError(f"Falló la ejecución en Docker local (código {proc.returncode})")


def update_prod(php_script: str):
    print("=== [2/2] APLICANDO EN PRODUCCIÓN (akibara.cl vía SSH stdin) ===")
    cmd = ["ssh", "akibara", "cd domains/akibara.cl/public_html && wp eval-file -"]
    proc = subprocess.run(cmd, input=php_script, capture_output=True, text=True)
    print(proc.stdout)
    if proc.stderr:
        print("STDERR:", proc.stderr)
    if proc.returncode != 0:
        raise RuntimeError(f"Falló la ejecución en producción (código {proc.returncode})")

    print("\nPurgando LiteSpeed Cache en Producción...")
    purge_proc = subprocess.run(
        ["ssh", "akibara", "cd domains/akibara.cl/public_html && wp litespeed-purge all"],
        capture_output=True,
        text=True,
    )
    print(purge_proc.stdout)


if __name__ == "__main__":
    target = sys.argv[1].lower() if len(sys.argv) > 1 else "local"
    summaries = get_all_summaries()

    if not validate_all(summaries):
        print("Deteniendo ejecución por errores de validación.")
        sys.exit(1)

    php_script = generate_php_update_script(summaries)

    if target in ("local", "all"):
        update_local(php_script)

    if target in ("prod", "all"):
        update_prod(php_script)

    print("\nProceso finalizado con éxito.")
