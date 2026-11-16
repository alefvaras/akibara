import os, re

TARGET_DIRS = [
    "public_html/wp-content/plugins/akibara-business/src",
    "public_html/wp-content/plugins/akibara-rewards/src",
    "public_html/wp-content/plugins/akibara-search/src",
    "public_html/wp-content/plugins/akibara-cart-checkout-blocks/src",
    "public_html/wp-content/themes/akibara-gp"
]

DEPRECATED_PATTERNS = [
    (r"\$product->get_post_data\b", "$product->get_post_data() (WC deprecated)"),
    (r"\$order->get_total_discount\b", "$order->get_total_discount() (WC deprecated)"),
    (r"\bcreate_function\b", "create_function() (PHP 7.2+ deprecated)"),
    (r"\beach\s*\(", "each() (PHP 7.2+ deprecated)"),
    (r"\bwp_get_referer\s*\(\s*\)", "wp_get_referer() without safety"),
    (r"\b_manage_stock\b", "_manage_stock direct postmeta reference"),
    (r"\b_stock_status\b", "_stock_status direct postmeta reference"),
    (r"\bonbackorder\b", "onbackorder reference"),
]

def scan_legacy():
    findings = []
    for d in TARGET_DIRS:
        for root, _, files in os.walk(d):
            for f in files:
                if f.endswith(".php"):
                    p = os.path.join(root, f)
                    with open(p, "r", encoding="utf-8", errors="ignore") as fp:
                        lines = fp.readlines()
                    for idx, line in enumerate(lines):
                        for pattern, name in DEPRECATED_PATTERNS:
                            if re.search(pattern, line):
                                findings.append({
                                    "file": p,
                                    "line": idx + 1,
                                    "text": line.strip(),
                                    "pattern": name
                                })
    return findings

findings = scan_legacy()
print(f"Total legacy pattern matches found: {len(findings)}\n")
by_pattern = {}
for f in findings:
    by_pattern.setdefault(f["pattern"], []).append(f)

for pat, items in by_pattern.items():
    print(f"[{pat}]: {len(items)} matches")
    for it in items[:5]:
        print(f"   {it['file']}:{it['line']} -> {it['text'][:80]}")
    if len(items) > 5:
        print(f"   ... and {len(items) - 5} more\n")
    else:
        print()
