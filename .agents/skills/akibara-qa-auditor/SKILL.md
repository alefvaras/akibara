---
name: akibara-qa-auditor
description: Specialized QA and adversarial audit skill for Akibara (akibara.cl). Uses Playwright, curl, DOM inspection, and WP-CLI to rigorously audit catalog products, series, checkout, SEO, and visual fidelity across local Docker and live production.
---

# Akibara QA Auditor Skill

Specialized quality assurance and adversarial verification protocol for the Akibara e-commerce platform.

## When to Use This Skill
- Auditing any new product, volume, or series publication.
- Verifying catalog synchronization, price rules, and stock status.
- Validating visual fidelity, responsive layouts, and SEO compliance.
- Performing independent QA certifications before closing any development task.

## Mandatory Operating Protocol (AGENTS.md)

### 1. Dual Environment Testing (Local First, Then Production)
1. **Local Docker:** Audit on `http://localhost:8080` first. Discover issues, test edge cases, verify zero errors.
2. **Production:** Audit on `https://akibara.cl` after deployment. Lo verde en local no basta: verify real CDN, cache, and public HTML.

### 2. Catalog Invariants Audit Checklist
- [ ] **No Norma Editorial:** Exclusively Ivrea, Panini, Milky Way, Planeta Cómic, Arechi, Distrito Manga.
- [ ] **Zero External Editorial Links:** All series/brand links must be canonical internal `/serie/<slug>/` and `/marca/<slug>/`.
- [ ] **Commercial Identifiers:** `_global_unique_id` stores a unique, Mod-10 valid 13-digit GTIN (`ISBN-13` or `EAN-13`) on individual published editions, never duplicated across products and never containing `-1` suffixes (`_sku` holds internal/ERP or custom bundle codes with empty `_global_unique_id` when no manufacturer barcode exists).
- [ ] **Cover Images:** High-resolution original source, AVIF/WebP, responsive `sizes="auto, (max-width: 350px) 100vw, 350px"`, descriptive alt text.
- [ ] **Structured Specs Grid:** Formatted within `.akb-pdp-specs-grid`.
- [ ] **Price Strikethrough:** Strikethrough using pseudo-element `del::after { top: 50%; height: 1.5px; }`.
- [ ] **Upsells & Recommendations:** Upsells chained (`wp akibara:upsells-fill`), "También te recomendamos" and "Productos relacionados" active.
- [ ] **Native WooCommerce Stock & Pricing (Rule 8):** Stock status and `_sale_price` are governed natively by WooCommerce without forced automated 5% discounts or code overrides.
- [ ] **Product Attributes Hygiene:** `product_brand` is NOT in `_product_attributes`. Only `pa_pais`, `pa_autor`, `pa_genero`, `pa_serie`.
- [ ] **Mandatory Genres:** `pa_genero` taxonomy assigned and in attributes.
- [ ] **Categories & Demography:** `product_cat` has both main category (Manga/Comics) and demography (Shonen/Seinen/Shojo/etc.).
- [ ] **Author Normalization:** Standard Latin characters without macrons (`pa_autor`).
- [ ] **Rank Math SEO:** Focus keyword matches product title, title starts with focus keyword, score >= 70, description 140-155 characters.
- [ ] **Short Description:** `post_excerpt` starts with `<strong>[Title]</strong>`, 160-300 characters.
- [ ] **Reprint / Out of Stock:** Reprints marked strictly `outofstock` with restock notification.
- [ ] **Double Check Serie vs PDP:** Attribute consistency between `/serie/<slug>/` and PDP.
- [ ] **Comprehensive Editorial Attributes (Rule 17):**
  - Original work status & volumes published in Japan (e.g., "En curso – 21 tomos publicados").
  - Local edition status & volumes published.
  - Format equivalence (e.g., "Tomo doble 2 en 1", B6, color pages).
  - Next announced volume and launch window.
  - Separate Author (story/novel) and Artist (drawing).
  - Canonical `wp_termmeta` keys (`_akb_serie_estado`, `_akb_serie_tomos_obra`, `_akb_serie_estado_edicion`, `_akb_serie_tomos_edicion`) rendered dynamically by `SeriesFacts`, with ZERO static bullet-point counts inside `pa_serie` description (Rule 17.8).
- [ ] **Zero Residual Files:** No `.bak`, temporary scripts, or deployment artifacts left in production.

## Audit Output Format
Every audit must output:
1. **Target URLs and IDs audited.**
2. **Commands & tests executed.**
3. **Itemized Checklist Findings with PASS/FAIL.**
4. **Final Certification Verdict: APROBADO (PASS) or RECHAZADO (FAIL).**
