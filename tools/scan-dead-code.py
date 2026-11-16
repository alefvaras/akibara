import os, re
from collections import defaultdict

TARGET_DIRS = [
    "public_html/wp-content/plugins/akibara-business",
    "public_html/wp-content/plugins/akibara-rewards",
    "public_html/wp-content/plugins/akibara-search",
    "public_html/wp-content/plugins/akibara-cart-checkout-blocks",
    "public_html/wp-content/themes/akibara-gp",
    "public_html/wp-content/mu-plugins"
]

def scan_dead_code():
    php_files = {}
    class_defs = {}
    class_usages = defaultdict(int)
    private_methods = {}
    
    # 1. Parse all proprietary files
    for base in TARGET_DIRS:
        for root, _, files in os.walk(base):
            if "vendor" in root or "tests" in root or "node_modules" in root:
                continue
            for f in files:
                if f.endswith(".php"):
                    p = os.path.join(root, f)
                    with open(p, "r", encoding="utf-8", errors="ignore") as fp:
                        content = fp.read()
                    php_files[p] = content
                    
                    # Classes
                    classes = re.findall(r"(?:final\s+|abstract\s+)?class\s+([a-zA-Z0-9_]+)", content)
                    for c in classes:
                        class_defs[c] = p
                    
                    # Private methods
                    privates = re.findall(r"private\s+(?:static\s+)?function\s+([a-zA-Z0-9_]+)", content)
                    if privates:
                        private_methods[p] = privates

    print(f"Proprietary classes found: {len(class_defs)}")
    print(f"Files with private methods: {len(private_methods)}")

    # 2. Check class usage across all proprietary files
    for p, content in php_files.items():
        for c in class_defs:
            if p != class_defs[c]:
                if re.search(r"\b" + re.escape(c) + r"\b", content):
                    class_usages[c] += 1

    unused_classes = [c for c, p in class_defs.items() if class_usages[c] == 0]
    print(f"\n--- POTENTIALLY UNUSED / UNREFERENCED PROPRIETARY CLASSES ({len(unused_classes)}) ---")
    for c in sorted(unused_classes):
        print(f"  • {c:<35} in {class_defs[c]}")

    # 3. Check private methods called within their own file
    unused_privates = []
    for p, methods in private_methods.items():
        content = php_files[p]
        for m in methods:
            # find calls to m in content
            # exclude the definition: private function m
            def_pattern = r"private\s+(?:static\s+)?function\s+" + re.escape(m) + r"\b"
            content_without_def = re.sub(def_pattern, "", content)
            
            call_matches = len(re.findall(r"->" + re.escape(m) + r"\b", content_without_def)) + \
                           len(re.findall(r"::" + re.escape(m) + r"\b", content_without_def)) + \
                           len(re.findall(r"['\"]" + re.escape(m) + r"['\"]", content_without_def))
            if call_matches == 0:
                unused_privates.append((m, p))

    print(f"\n--- UNUSED PRIVATE METHODS ({len(unused_privates)}) ---")
    for m, p in unused_privates:
        print(f"  • {m}() in {p}")

scan_dead_code()
