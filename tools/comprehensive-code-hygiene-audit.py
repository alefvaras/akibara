import os, re, glob
from collections import defaultdict

print("=================================================================")
print(" AUDITORÍA EXHAUSTIVA DE CÓDIGO MUERTO Y DUPLICADO (TODO EL SITIO)")
print("=================================================================")

# 1. Recolectar todos los archivos CSS
css_files = []
for root, dirs, files in os.walk("public_html/wp-content"):
    if "cache" in root or "vendor" in root or "node_modules" in root:
        continue
    for f in files:
        if f.endswith(".css") and not f.endswith(".min.css"):
            css_files.append(os.path.join(root, f))

print(f"\n1. Analizando {len(css_files)} archivos CSS fuente...")

# Reglas duplicadas en el mismo archivo
total_duplicates_found = 0
duplicate_report = []

for css_file in sorted(css_files):
    with open(css_file, "r", encoding="utf-8", errors="ignore") as f:
        content = f.read()

    # Quitar comentarios
    no_comments = re.sub(r"/\*.*?\*/", "", content, flags=re.DOTALL)

    # Buscar bloques de reglas
    # Considerar queries @media vs reglas globales
    parts = re.split(r"(@media[^{]+\{)", no_comments)
    current_media = "global"
    
    rules_by_media = defaultdict(lambda: defaultdict(list))
    
    for i, part in enumerate(parts):
        if part.startswith("@media"):
            current_media = part.strip()
            continue
        
        # Extraer reglas dentro de este bloque
        rule_matches = re.findall(r"([^{}]+)\{([^{}]+)\}", part)
        for sel_str, body in rule_matches:
            sel_str = sel_str.strip()
            if not sel_str or sel_str.startswith("@") or "keyframes" in sel_str:
                continue
            for sel in sel_str.split(","):
                sel = " ".join(sel.strip().split())
                if sel and not sel.startswith("@") and sel not in ["0%", "100%", "from", "to", "0%, 100%"]:
                    rules_by_media[current_media][sel].append(body.strip())
        
        # Reset media if not in media header
        if not part.startswith("@media"):
            current_media = "global"

    # Chequear duplicados en el mismo media/scope
    for media, sel_dict in rules_by_media.items():
        for sel, bodies in sel_dict.items():
            if len(bodies) > 1:
                # Comprobar si los cuerpos son redundantes o sobreescrituras en el mismo scope
                total_duplicates_found += 1
                duplicate_report.append({
                    "file": css_file,
                    "media": media,
                    "selector": sel,
                    "occurrences": len(bodies)
                })

print(f"Total de selectores duplicados en el mismo scope: {total_duplicates_found}")
if duplicate_report:
    for d in duplicate_report[:20]:
        print(f"  [{d['file']}] {d['selector']} ({d['occurrences']} veces en {d['media']})")
    if len(duplicate_report) > 20:
        print(f"  ... y {len(duplicate_report) - 20} más.")

# 2. Análisis de Clases Huérfanas (Dead CSS) en componentes propios
print("\n2. Analizando Clases Huérfanas (Dead CSS)...")
# Indexar todo el código PHP y JS para búsqueda rápida
repo_code = ""
for root, dirs, files in os.walk("public_html/wp-content"):
    if "cache" in root or "vendor" in root or "node_modules" in root or "uploads" in root:
        continue
    for f in files:
        if f.endswith((".php", ".js")):
            with open(os.path.join(root, f), "r", encoding="utf-8", errors="ignore") as code_f:
                repo_code += code_f.read() + "\n"

# También incluir templates del tema
for root, dirs, files in os.walk("public_html/wp-content/themes/akibara-gp"):
    for f in files:
        if f.endswith((".php", ".json")):
            with open(os.path.join(root, f), "r", encoding="utf-8", errors="ignore") as code_f:
                repo_code += code_f.read() + "\n"

# Buscar clases de Akibara en archivos clave de business y tema
akb_classes_checked = 0
dead_classes = []

target_css_list = [
    "public_html/wp-content/plugins/akibara-business/assets/css/series-bundles.css",
    "public_html/wp-content/plugins/akibara-business/assets/css/pdp-enhancer.css",
    "public_html/wp-content/plugins/akibara-business/assets/css/free-shipping-progress.css",
    "public_html/wp-content/plugins/akibara-business/assets/css/native-cart-v2.css",
    "public_html/wp-content/plugins/akibara-business/assets/css/account-collection.css",
    "public_html/wp-content/plugins/akibara-business/assets/css/welcome-popup.css",
    "public_html/wp-content/plugins/akibara-business/assets/css/collection-badges.css",
    "public_html/wp-content/plugins/akibara-business/assets/css/wishlist.css",
    "public_html/wp-content/themes/akibara-gp/assets/css/header.css",
    "public_html/wp-content/themes/akibara-gp/assets/css/footer.css",
    "public_html/wp-content/themes/akibara-gp/assets/css/trust.css",
]

for target_file in target_css_list:
    if not os.path.exists(target_file):
        continue
    with open(target_file, "r", encoding="utf-8", errors="ignore") as f:
        file_css = f.read()
    
    classes = set(re.findall(r"\.([a-zA-Z0-9_-]+)", file_css))
    for cls in classes:
        # Solo auditar clases que comienzan con prefijo del proyecto akb- o de componentes dedicados
        if cls.startswith(("akb-", "aki-", "trust-", "single-")):
            akb_classes_checked += 1
            # Buscar en repo_code si la clase aparece como string o clase
            # Ignorar si se encuentra en repo_code
            if cls not in repo_code:
                dead_classes.append((target_file, cls))

print(f"Total clases prefijadas auditadas: {akb_classes_checked}")
print(f"Clases potencialmente muertas encontradas: {len(dead_classes)}")
for tf, dc in dead_classes:
    print(f"  - [{os.path.basename(tf)}] .{dc}")

# 3. Auditoría de JavaScript (console.log residuales y syntax errors)
print("\n3. Analizando JavaScript (Higiene y Logs Residuales)...")
js_files = []
for root, dirs, files in os.walk("public_html/wp-content"):
    if "cache" in root or "vendor" in root or "node_modules" in root:
        continue
    for f in files:
        if f.endswith(".js") and not f.endswith(".min.js"):
            if "akibara-business" in root or "akibara-gp" in root:
                js_files.append(os.path.join(root, f))

console_logs = []
for js_file in sorted(js_files):
    with open(js_file, "r", encoding="utf-8", errors="ignore") as f:
        for idx, line in enumerate(f, 1):
            if re.search(r"\bconsole\.(log|debug|warn|error)\b", line):
                # Ignorar si es manejo legítimo de error catch
                if "console.error" in line and "catch" in line:
                    continue
                console_logs.append((js_file, idx, line.strip()))

print(f"Total archivos JS analizados: {len(js_files)}")
print(f"Console logs detectados: {len(console_logs)}")
for jf, lnum, ltxt in console_logs:
    print(f"  [{os.path.basename(jf)}:L{lnum}] {ltxt[:80]}")

