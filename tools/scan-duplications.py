import os, re
from collections import defaultdict

def scan_duplications():
    php_files = []
    for root, _, files in os.walk("public_html/wp-content/plugins"):
        if "vendor" in root or "tests" in root:
            continue
        for f in files:
            if f.endswith(".php"):
                php_files.append(os.path.join(root, f))
    for root, _, files in os.walk("public_html/wp-content/themes/akibara-gp"):
        if "vendor" in root or "tests" in root:
            continue
        for f in files:
            if f.endswith(".php"):
                php_files.append(os.path.join(root, f))

    # 1. Search for RUT validation across files
    rut_files = []
    for p in php_files:
        with open(p, "r", encoding="utf-8", errors="ignore") as fp:
            c = fp.read()
        if re.search(r"function\s+(?:clean|format|validate|validar)_?rut\b", c, re.I) or re.search(r"class\s+.*Rut", c):
            rut_files.append(p)
    print("--- 1. RUT Validation / Utilities ---")
    for rf in rut_files:
        print("  •", rf)

    # 2. Search for Free shipping threshold ($60.000)
    shipping_thresh_files = []
    for p in php_files:
        with open(p, "r", encoding="utf-8", errors="ignore") as fp:
            lines = fp.readlines()
        for idx, line in enumerate(lines):
            if "60000" in line or "60.000" in line or "FREE_SHIPPING" in line or "akb_envio_gratis" in line:
                if "tests" not in p:
                    shipping_thresh_files.append((p, idx + 1, line.strip()))
    print(f"\n--- 2. Free Shipping Threshold Matches ({len(shipping_thresh_files)}) ---")
    for p, l, text in shipping_thresh_files[:10]:
        print(f"  • {p}:{l} -> {text[:80]}")

    # 3. Search for Phone validation / normalization
    phone_files = []
    for p in php_files:
        with open(p, "r", encoding="utf-8", errors="ignore") as fp:
            c = fp.read()
        if re.search(r"function\s+.*phone\b", c, re.I) or "ChileanPhone" in c:
            phone_files.append(p)
    print(f"\n--- 3. Phone Validation Matches ({len(phone_files)}) ---")
    for pf in phone_files:
        print("  •", pf)

    # 4. Search for Stock rank calculation (0 = instock, 1 = outofstock)
    stock_rank_files = []
    for p in php_files:
        with open(p, "r", encoding="utf-8", errors="ignore") as fp:
            lines = fp.readlines()
        for idx, line in enumerate(lines):
            if "stock_rank" in line:
                stock_rank_files.append((p, idx + 1, line.strip()))
    print(f"\n--- 4. Stock Rank (0/1) Logic Matches ({len(stock_rank_files)}) ---")
    for p, l, text in stock_rank_files[:10]:
        print(f"  • {p}:{l} -> {text[:80]}")

scan_duplications()
