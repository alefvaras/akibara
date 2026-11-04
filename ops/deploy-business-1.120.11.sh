#!/usr/bin/env bash
# Despliegue de akibara-business 1.120.11 a producción (akibara.cl).
# Resuelve los 4 mandatos de la auditoría 2026-11-04:
# 1. Coherencia productiva: sincronización de lookup table min_max_price y unmanaged stock = NULL.
# 2. Seguridad de decisiones: Fail-Closed en OrderLockGuard (!empty(last_error) || false === rows),
#    validación estricta de distribuidora oficial (APPROVED_SUPPLIERS), registro de operador (_akb_rescue_by)
#    y re-evaluación viva contra pedidos intercalados.
# 3. UX del dueño: panel de revisión persistente con tomos antes/después y botón directo de confirmación,
#    preservación del borrador en la fila tras simular, y layout móvil responsive en 1 columna.
# 4. Trazabilidad: unificación de ADR-003, ADR-008 y Regla 18.
set -euo pipefail

DIST="/Users/alefvaras/Documents/akibara/dist"
ZIP="akibara-business-1.120.11.zip"
REMOTO="akibara"
WEB="domains/akibara.cl/public_html"
PLUGINS="$WEB/wp-content/plugins"
TS="$(date +%s)"
BACKUP_DIR="/Users/alefvaras/Documents/akibara/.local-backups/prod-business-1.120.10-$TS"

mkdir -p "$BACKUP_DIR"

echo "============================================================"
echo "  DESPLIEGUE ATÓMICO: AKIBARA-BUSINESS 1.120.11 (PROD)"
echo "============================================================"

echo "== 1/7 Descargando respaldo previo a local =="
ssh "$REMOTO" "tar -czf - -C $PLUGINS akibara-business" > "$BACKUP_DIR/akibara-business-1.120.10.tar.gz"
echo "Respaldo local guardado en: $BACKUP_DIR/akibara-business-1.120.10.tar.gz"

echo "== 2/7 Subiendo $ZIP al servidor =="
ssh "$REMOTO" "mkdir -p ~/deploy-tmp"
scp -q "$DIST/$ZIP" "$REMOTO:deploy-tmp/$ZIP"

echo "== 3/7 Verificando SHA-256 =="
LOCAL_SHA="$(cut -d' ' -f1 "$DIST/$ZIP.sha256")"
REMOTE_SHA="$(ssh "$REMOTO" "sha256sum deploy-tmp/$ZIP" | cut -d' ' -f1)"
if [ "$LOCAL_SHA" != "$REMOTE_SHA" ]; then
  echo "ERROR: SHA NO COINCIDE! local=$LOCAL_SHA remoto=$REMOTE_SHA" >&2
  ssh "$REMOTO" "rm -rf ~/deploy-tmp"
  exit 1
fi
echo "SHA-256 verificado: $REMOTE_SHA"

echo "== 4/7 Desempaquetando staging remoto =="
ssh "$REMOTO" "rm -rf ~/deploy-tmp/stage && mkdir -p ~/deploy-tmp/stage && cd ~/deploy-tmp/stage && unzip -q ../$ZIP && test -f akibara-business/akibara-business.php"

echo "== 5/7 Swap atómico con ventana de mantenimiento =="
ssh "$REMOTO" "printf '<?php \$upgrading = time();' > $WEB/.maintenance \
  && rm -rf $PLUGINS/akibara-business \
  && cp -r ~/deploy-tmp/stage/akibara-business $PLUGINS/akibara-business \
  && rm -f $WEB/.maintenance"

echo "== 6/7 Limpieza inmediata de temporales remotos =="
ssh "$REMOTO" "rm -rf ~/deploy-tmp"

echo "== 7/7 Purga de cachés y verificación en vivo =="
ssh "$REMOTO" "cd $WEB && /usr/local/bin/wp eval 'do_action(\"litespeed_purge_all\");' 2>/dev/null || true"
ssh "$REMOTO" "cd $WEB && /usr/local/bin/wp cache flush 2>/dev/null || true"

LIVE_VER="$(ssh "$REMOTO" "cd $WEB && /usr/local/bin/wp plugin get akibara-business --field=version")"
echo "Versión activa en producción: $LIVE_VER"

if [ "$LIVE_VER" != "1.120.11" ]; then
  echo "ERROR: La versión reportada ($LIVE_VER) no coincide con 1.120.11" >&2
  exit 1
fi

echo "============================================================"
echo "  DESPLIEGUE EXITOSO: AKIBARA-BUSINESS 1.120.11 EN VIVO"
echo "============================================================"
