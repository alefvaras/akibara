---
name: akibara-release-promotion
description: Protocolo seguro de empaquetado, verificación local en espejo Docker y promoción por rieles sellados a producción (akibara.cl). Invocable únicamente por el usuario debido a sus efectos en producción.
disable-model-invocation: true
---

# Promoción Segura de Releases en Akibara (`/akibara-release-promotion`)

Ejecuta el flujo de entrega para el componente o riel indicado en `$ARGUMENTS` cumpliendo estrictamente la orden del dueño (2026-09-06) y `.agents/rules/entrega.md`:

1. **Pre-chequeo de Coordinación e Inventario**:
   - `bash ops/coordinacion.sh abiertos`
   - `python3 ops/guardrail-inventario-akibara.py --target local`
   - `node tools/akibara-releases/rewards-qa/qa-paridad-espejo.mjs`
2. **Build Determinista y Guardrails Locales**:
   - `npm run build`
   - `bash tools/guardrail-ci.sh --full`
2.5. **Auditoría Independiente en Contexto Fresco (`it-deploy-auditor`)**:
   - `python3 tools/akibara-predeploy-audit-loop.py --component <componente> --verify-cmd "bash tools/guardrail-ci.sh"`
   - Si el auditor con memoria limpia emite `RECHAZADO`, iterar sobre los bloqueos en `.local-quality/audit-loop/latest-rejection.md`, recompilar, re-probar y relanzar una sesión efímera fresca hasta obtener `APROBADO` antes de promover.
3. **Empaquetado y Verificación de Contrato Sellado**:
   - Empaquetar únicamente runtime mediante `tools/akibara-releases/package-runtime.sh` y validar con `tools/akibara-releases/verify-runtime-package.py`.
4. **Punto de Restauración Local y Promoción**:
   - Conservar respaldos únicamente en `.local-backups/` local (nunca dejar `.bak` ni volcados en producción).
   - Ejecutar el script de promoción correspondiente (`ops/akibara-deploy-runtime.sh` o `ops/akibara-prod-muplugins-deploy.sh`).
5. **Medición Post-Despliegue en Producción (`akibara.cl`)**:
   - Verificar hashes, salud de correo (`ops/akibara-prod-mail-health.sh`), superficie pública servida (`.min`, `?ver`, caché LiteSpeed/Cloudflare) e inventario productivo (`python3 ops/guardrail-inventario-akibara.py --target prod`).
   - Ejecutar `npm run sync:notebook` al cerrar.
