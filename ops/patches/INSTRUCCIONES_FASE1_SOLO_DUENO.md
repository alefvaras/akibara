# Instrucciones `[SÓLO DUEÑO]` — Fase 1 (Candado anti-supresiones, Hooks WC, Auditor pre-deploy y Protección GitHub)

> Ningún agente modificó los 27 archivos sellados del repositorio (`tools/verify-devsecops-seal.py` sigue en `PASS`). Este documento contiene el parche unificado generado y verificado en árbol staging limpio (`ops/patches/fase1-owner-seal.patch`) más los comandos exactos para que el dueño ejecute el sellado y la protección en GitHub.

---

## 1. Aplicar el parche unificado de Fase 1 (`P1-2` + `hooks.json` WC + `CODEOWNERS`)

El parche [`ops/patches/fase1-owner-seal.patch`](fase1-owner-seal.patch) integra en un solo cambio:
- **`P1-5` (`ops/patches/fresh-auditor-deploy-gate.patch`)**: integra `tools/qa-fresh-auditor-gate.py` + verificación de recibo en `ops/akibara-deploy-runtime.sh`, `tools/guardrail-ci.sh` (`test-fresh-auditor-gate.py`) y `tools/test-deploy-runtime.mjs`.
- **`P1-2` (`guardrail-suppressions.php` y `pre-commit`)**:
  - `tools/guardrail-ci.sh`: añade `php tools/test-guardrail-suppressions.php` en `[1/7]` y `php tools/guardrail-suppressions.php --base="$suppr_base" --r5=changed` en `[3/7]` (hasta que Fase 2 elimine las 711 supresiones inútiles legadas; luego se sube a `--r5=all`).
  - `.githooks/pre-commit`: reemplaza `guard-private-data.py --revision HEAD 2>/dev/null || true` por `python3 "${repo_root}/tools/guard-private-data.py" --staged` (sin `|| true` ni `2>/dev/null`, sin romper commits locales por historial antiguo en `HEAD`) y añade `php "${repo_root}/tools/guardrail-suppressions.php" --staged --r5="$r5_flag"`.
  - `.github/workflows/runtime-quality.yml`: añade `fetch-depth: 0` y `AKB_BASE_REF` en PRs para que `guardrail-suppressions.php` compare contra la rama base real.
  - `.github/CODEOWNERS`: exige aprobación de `@alefvaras` para `.github/`, `.githooks/`, `tools/`, `ops/`, configuraciones de analizadores y auditor pre-deploy.
- **Hooks WooCommerce reales en `tools/psalm/woocommerce/hooks.json` y `tools/test-woocommerce-hook-metadata.mjs`**: registra 9 hooks verificados por AST contra `dependencies.lock.json` (`woocommerce_order_status_changed`, `woocommerce_thankyou` con alias `woocommerce_thankyou_bacs`, `woocommerce_view_order`, `woocommerce_email_before_order_table`, `woocommerce_email_after_order_table`, `woocommerce_order_details_after_order_table`, `woocommerce_package_rates`, `woocommerce_order_item_get_formatted_meta_data`, `woocommerce_cart_shipping_method_full_label`).
- **Candado ampliado (`tools/verify-devsecops-seal.py` y `tools/test-devsecops-seal.py`)**: amplía `SEALED_HASHES` de 27 a 50 archivos con sus SHA-256 ya calculados y agrega verificación semántica para `guardrail-suppressions.php` y `qa-fresh-auditor-gate.py`.

### Comandos para ejecutar en macOS (como dueño):

```bash
cd /Users/alefvaras/Documents/akibara

# 1. Desbloquear SOLO los archivos sellados que actualiza el parche
SEALED_TO_EDIT=(
  ops/akibara-deploy-runtime.sh
  tools/guardrail-ci.sh
  .githooks/pre-commit
  tools/psalm/woocommerce/hooks.json
  tools/test-devsecops-seal.py
  tools/verify-devsecops-seal.py
)
chflags nouchg "${SEALED_TO_EDIT[@]}"
chmod u+w "${SEALED_TO_EDIT[@]}"

# 2. Aplicar el parche unificado
git apply ops/patches/fase1-owner-seal.patch

# 3. Fijar permisos de solo lectura / ejecución y volver a sellar con chflags uchg los 50+2 archivos críticos
EXEC_SEALED=(
  .githooks/pre-commit
  .githooks/pre-push
  ops/akibara-deploy-runtime.sh
  tools/guardrail-ci.sh
  tools/akibara-releases/package-runtime.sh
  tools/test-devsecops-seal.py
)
READONLY_SEALED=(
  phpstan.neon.dist
  phpstan-bootstrap.php
  phpstan-dba-bootstrap.php
  psalm.xml
  tools/psalm/woocommerce/hooks.json
  phpcs.xml.dist
  tools/akibara-business/phpcs.xml.dist
  .eslintrc.js
  .eslintignore
  .stylelintrc.json
  .sqlfluff
  .sqlfluffignore
  .semgrep/wordpress-security.yml
  .semgrepignore
  rector.php
  knip.json
  purgecss.config.js
  package.json
  tools/guardrail-ast-hygiene.php
  ops/db-cleanup-brevo-welcome-coupon.sql
  tools/akibara-releases/verify-runtime-package.py
  tools/qa-playwright-full-suite.mjs
  tools/guardrail-suppressions.php
  tools/test-guardrail-suppressions.php
  tools/guard-private-data.py
  tools/test-private-data-guard.py
  tools/check-antigravity-policy.py
  tools/test-antigravity-policy.py
  tools/qa-fresh-auditor-gate.py
  tools/test-fresh-auditor-gate.py
  tools/akibara-predeploy-audit-loop.py
  .agents/skills/akibara-deploy-auditor/SKILL.md
  .agents/agents/it-deploy-auditor.md
  tools/sync-notebooklm.mjs
  .github/workflows/runtime-quality.yml
  .github/CODEOWNERS
  tools/test-runtime-delivery.mjs
  tools/test-deploy-runtime.mjs
  tools/test-guardrail-preflight.mjs
  tools/test-qa-playwright-full-suite.mjs
  tools/test-post-hygiene-verdict.mjs
  tools/test-muplugins-deploy.mjs
  tools/test-mail-health.mjs
  tools/test-woocommerce-hook-metadata.mjs
  tools/test-build-search-assets.mjs
  tools/verify-devsecops-seal.py
)
chmod 0555 "${EXEC_SEALED[@]}"
chmod 0444 "${READONLY_SEALED[@]}"
chflags uchg "${EXEC_SEALED[@]}" "${READONLY_SEALED[@]}"

# 4. Verificar que todo el candado y sus pruebas pasen en verde
python3 tools/verify-devsecops-seal.py
python3 tools/test-devsecops-seal.py
php tools/test-guardrail-suppressions.php
python3 tools/test-private-data-guard.py
node --test tools/test-woocommerce-hook-metadata.mjs tools/test-guardrail-preflight.mjs tools/test-deploy-runtime.mjs
```

---

## 2. Protección de rama `main` en GitHub (`P1-3`)

> Nota: confirma en **GitHub → Settings → Rules / Branches → Branch protection rules** (o ejecutando el `gh api` tras subir el primer PR con el nuevo workflow) que los dos status checks del matrix (`validate (8.1)` y `validate (8.5.12)`) existan en la lista exacta de checks del repositorio.

```bash
gh api \
  --method PUT \
  -H "Accept: application/vnd.github+json" \
  /repos/alefvaras/akibara/branches/main/protection \
  --input - <<'JSON'
{
  "required_status_checks": {
    "strict": true,
    "contexts": [
      "validate (8.1)",
      "validate (8.5.12)"
    ]
  },
  "enforce_admins": true,
  "required_pull_request_reviews": {
    "dismiss_stale_reviews": true,
    "require_code_owner_reviews": true,
    "required_approving_review_count": 1
  },
  "restrictions": null,
  "allow_force_pushes": false,
  "allow_deletions": false
}
JSON
```

Si también deseas probar que el pipeline rechaza el caso negativo tras aplicar el parche:
1. En una rama temporal agrega `// @phpstan-ignore complexity.functionLike (prueba negativa suficientemente larga para probar R4)` en un PHP del runtime y ejecuta `php tools/guardrail-suppressions.php --base=HEAD` → debe abortar con `[R4 Identificador vetado]`.
2. Descarta la rama temporal.
