import os, re
from collections import defaultdict

print("=================================================================")
print(" AUDITORÍA EXHAUSTIVA DE CÓDIGO PROPIO AKIBARA (THEME + PLUGINS)")
print("=================================================================")

TARGET_DIRS = [
    "public_html/wp-content/themes/akibara-gp",
    "public_html/wp-content/plugins/akibara-business",
    "public_html/wp-content/plugins/akibara-rewards",
    "public_html/wp-content/plugins/akibara-search",
    "public_html/wp-content/plugins/akibara-cart-checkout-blocks",
    "public_html/wp-content/mu-plugins"
]

EXCLUDE_DIRS = ["vendor", "node_modules", "cache", "litespeed", "build", "dist"]

# 1. Recolectar archivos CSS fuente (excluyendo .min.css)
css_files = []
js_files = []
php_files = []

for base in TARGET_DIRS:
    for root, dirs, files in os.walk(base):
        # Excluir carpetas no deseadas
        dirs[:] = [d for d in dirs if d not in EXCLUDE_DIRS]
        for f in files:
            full = os.path.join(root, f)
            if f.endswith(".css") and not f.endswith(".min.css") and not f.endswith("-min.css"):
                css_files.append(full)
            elif f.endswith(".js") and not f.endswith(".min.js"):
                js_files.append(full)
            elif f.endswith(".php"):
                php_files.append(full)

print(f"Archivos encontrados para auditar:")
print(f"  - CSS fuente: {len(css_files)}")
print(f"  - JS fuente: {len(js_files)}")
print(f"  - PHP backend/templates: {len(php_files)}")

# -------------------------------------------------------------
# PARTE 1: SELECTORES Y REGLAS DUPLICADAS EN EL MISMO ARCHIVO
# -------------------------------------------------------------
print("\n--- 1. Escaneando selectores duplicados en CSS propio ---")
duplicates_found = []

for css_file in sorted(css_files):
    with open(css_file, "r", encoding="utf-8", errors="ignore") as f:
        content = f.read()

    # Quitar comentarios
    no_comments = re.sub(r"/\*.*?\*/", "", content, flags=re.DOTALL)

    # Dividir fuera de @media (global) y dentro de @media
    # Parseo simple de bloques
    rules_global = []
    media_blocks = defaultdict(list)
    
    # Extraer @media bloques completos
    pos = 0
    while True:
        m = re.search(r"@media[^{]+\{", no_comments[pos:])
        if not m:
            break
        media_start = pos + m.start()
        header = m.group(0)
        # encontrar cierre de este @media por balance de llaves
        brace_count = 1
        i = pos + m.end()
        while i < len(no_comments) and brace_count > 0:
            if no_comments[i] == '{': brace_count += 1
            elif no_comments[i] == '}': brace_count -= 1
            i += 1
        media_body = no_comments[pos + m.end(): i - 1]
        media_blocks[header.strip()].append(media_body)
        pos = i

    # Todo lo que no es @media es global
    outside_media = re.sub(r"@media[^{]+\{(?:[^{}]*\{[^{}]*\})*[^{}]*\}", "", no_comments, flags=re.DOTALL)
    
    # Buscar reglas en outside_media
    for s, b in re.findall(r"([^{}]+)\{([^{}]+)\}", outside_media):
        s_clean = s.strip()
        if not s_clean.startswith("@") and "keyframes" not in s_clean:
            for sel in s_clean.split(","):
                sel = " ".join(sel.strip().split())
                if sel and sel not in ["0%", "100%", "from", "to", "0%, 100%"]:
                    rules_global.append(sel)

    # Contar ocurrencias globales
    counts = defaultdict(int)
    for r in rules_global:
        counts[r] += 1
    
    for sel, c in counts.items():
        if c > 1:
            duplicates_found.append((css_file, "global", sel, c))

    # Chequear duplicados dentro de cada @media
    for m_head, m_bodies in media_blocks.items():
        media_rules = []
        for mb in m_bodies:
            for s, b in re.findall(r"([^{}]+)\{([^{}]+)\}", mb):
                s_clean = s.strip()
                if not s_clean.startswith("@") and "keyframes" not in s_clean:
                    for sel in s_clean.split(","):
                        sel = " ".join(sel.strip().split())
                        if sel and sel not in ["0%", "100%", "from", "to", "0%, 100%"]:
                            media_rules.append(sel)
        m_counts = defaultdict(int)
        for r in media_rules:
            m_counts[r] += 1
        for sel, c in m_counts.items():
            if c > 1:
                duplicates_found.append((css_file, m_head[:30], sel, c))

print(f"Total selectores duplicados encontrados: {len(duplicates_found)}")
for cf, scope, sel, c in duplicates_found:
    rel_cf = os.path.relpath(cf, "public_html/wp-content")
    print(f"  - [{rel_cf}] ({scope}): '{sel}' repetido {c} veces")

# -------------------------------------------------------------
# PARTE 2: AUDITORÍA DE CONSOLE.LOGS EN JS
# -------------------------------------------------------------
print("\n--- 2. Escaneando console.log / debug en JS propio ---")
logs_found = []
for jf in sorted(js_files):
    with open(jf, "r", encoding="utf-8", errors="ignore") as f:
        for idx, line in enumerate(f, 1):
            if re.search(r"\bconsole\.(log|debug|warn|info)\b", line):
                rel_jf = os.path.relpath(jf, "public_html/wp-content")
                logs_found.append((rel_jf, idx, line.strip()))

print(f"Total logs encontrados: {len(logs_found)}")
for rj, idx, line in logs_found:
    print(f"  - [{rj}:L{idx}] {line[:75]}")

# -------------------------------------------------------------
# PARTE 3: CLASES CSS POTENCIALMENTE HUÉRFANAS (DEAD CSS)
# -------------------------------------------------------------
print("\n--- 3. Analizando clases CSS potencialmente huérfanas ---")
# Cargar todo el código del repositorio en memoria
all_markup = ""
for pf in php_files + js_files:
    with open(pf, "r", encoding="utf-8", errors="ignore") as f:
        all_markup += f.read() + "\n"

# Para los módulos principales, auditar sus clases prefijadas (.akb-)
dead_classes = []
for cf in sorted(css_files):
    rel_cf = os.path.relpath(cf, "public_html/wp-content")
    with open(cf, "r", encoding="utf-8", errors="ignore") as f:
        content = f.read()
    
    classes = set(re.findall(r"\.(akb-[a-zA-Z0-9_-]+)", content))
    for c in classes:
        # Si la clase no se encuentra como palabra en todo el código markup
        # (usamos regex con límite de palabra o comillas)
        pattern = re.compile(r"['\"\s`]" + re.escape(c) + r"['\"\s`]")
        if not pattern.search(all_markup) and c not in all_markup:
            dead_classes.append((rel_cf, c))

print(f"Clases .akb-* sin referencia en ningún archivo PHP ni JS: {len(dead_classes)}")
for rcf, dc in dead_classes[:30]:
    print(f"  - [{rcf}] .{dc}")
if len(dead_classes) > 30:
    print(f"  ... y {len(dead_classes) - 30} más.")

