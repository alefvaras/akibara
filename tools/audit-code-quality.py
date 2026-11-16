import os, re

def analyze_php_files(dirs):
    results = []
    for d in dirs:
        for root, _, files in os.walk(d):
            for f in files:
                if f.endswith(".php") and "tests" not in root and "vendor" not in root:
                    p = os.path.join(root, f)
                    with open(p, "r", encoding="utf-8", errors="ignore") as fp:
                        lines = fp.readlines()
                    line_count = len(lines)
                    content = "".join(lines)
                    classes = re.findall(r"class\s+([a-zA-Z0-9_]+)", content)
                    methods = re.findall(r"(?:public|protected|private)\s+function\s+([a-zA-Z0-9_]+)", content)
                    class_name = classes[0] if classes else os.path.basename(f)
                    results.append({
                        "path": p,
                        "class": class_name,
                        "lines": line_count,
                        "methods": len(methods),
                        "method_names": methods
                    })
    results.sort(key=lambda x: x["lines"], reverse=True)
    return results

dirs = [
    "public_html/wp-content/plugins/akibara-business/src",
    "public_html/wp-content/plugins/akibara-rewards/src",
    "public_html/wp-content/plugins/akibara-search/src",
    "public_html/wp-content/plugins/akibara-cart-checkout-blocks/src",
    "public_html/wp-content/themes/akibara-gp"
]

res = analyze_php_files(dirs)
print(f"Total PHP files analyzed: {len(res)}\n")
print(f"{'Lines':>6} | {'Methods':>7} | {'Class':<35} | Path")
print("-" * 100)
for r in res[:30]:
    c_name = r['class'][:35]
    print(f"{r['lines']:6d} | {r['methods']:7d} | {c_name:<35} | {r['path']}")
