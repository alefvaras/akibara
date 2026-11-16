#!/usr/bin/env bash
# Gate QA de 4 agentes (orden del dueño 2026-08-31) — compuerta FINAL antes
# del cutover. Corre en LOCAL contra Docker; falla ruidosamente por agente.
# Uso: ./qa-rewards.sh [cliente|dueno|rendimiento|plugins|todos]
set -uo pipefail

# El sello de restauración vigila el conteo de Mailpit, pero los agentes que
# crean pedidos no borran sus correos: sin esta remediación, un gate que
# arranca en 0 termina en ~32 y el cierre sale rojo (pasada 4, 2026-09-01).
# Se capturan los IDs existentes ANTES y al final se borran SOLO los nuevos.
MAILPIT_IDS_ANTES=$(curl -s 'http://localhost:8025/api/v1/messages?limit=2000' | python3 -c 'import json,sys; print("\n".join(m["ID"] for m in json.load(sys.stdin).get("messages", [])))' 2>/dev/null || echo '')
cd "$(dirname "$0")"
[ -d node_modules/playwright ] || npm i playwright --no-audit --no-fund >/dev/null
npx playwright install chromium >/dev/null 2>&1 || true

QUE="${1:-todos}"
declare -A AGENTES=(
  [cliente]="agente-cliente.mjs"
  [dueno]="agente-dueno.mjs"
  [rendimiento]="agente-rendimiento.mjs"
  [plugins]="agente-plugins.mjs"
)
ORDEN=(cliente dueno rendimiento plugins)
FALLAS=0

for a in "${ORDEN[@]}"; do
  if [ "$QUE" != "todos" ] && [ "$QUE" != "$a" ]; then continue; fi
  f="${AGENTES[$a]}"
  if [ ! -f "$f" ]; then
    echo "== AGENTE $a: spec $f AUSENTE"; FALLAS=$((FALLAS+1)); continue
  fi
  echo "== AGENTE $a =="
  # Respiro entre agentes: el anterior deja contenedores `docker compose run`
  # terminando y PHP ocupado. 10 s NO alcanzaban: el bloque de restauración del
  # agente DUEÑO dispara una ráfaga de ~12 contenedores CLI (uno por `wp eval`)
  # y el agente siguiente arrancaba compitiendo con ellos por el PHP del
  # espejo — de ahí parte del ruido de latencia de las pasadas 1 y 2.
  sleep 30
  if node "$f"; then
    echo "== AGENTE $a: VERDE"
  else
    echo "== AGENTE $a: ROJO"
    FALLAS=$((FALLAS+1))
  fi
done

# Paso extra: QA vivo del ajuste manual de puntos (formulario real + ledger
# auditado + espejo restaurado). En serie, como los agentes.
echo "== QA ajuste manual =="
if node qa-ajuste-manual.mjs 2>&1 | grep -v " Container "; then
  echo "== QA ajuste manual: VERDE"
else
  echo "== QA ajuste manual: ROJO"
  FALLAS=$((FALLAS+1))
fi

# Remediación de Mailpit: borrar SOLO los correos nacidos durante el gate.
python3 - "$MAILPIT_IDS_ANTES" << 'PYEOF'
import json, sys, urllib.request
antes = set(sys.argv[1].split())
req = urllib.request.urlopen('http://localhost:8025/api/v1/messages?limit=2000')
ahora = [m['ID'] for m in json.load(req).get('messages', [])]
nuevos = [i for i in ahora if i not in antes]
if nuevos:
    data = json.dumps({'IDs': nuevos}).encode()
    r = urllib.request.Request('http://localhost:8025/api/v1/messages', data=data, method='DELETE', headers={'Content-Type': 'application/json'})
    urllib.request.urlopen(r)
print(f'mailpit-remediado: {len(nuevos)} correo(s) del gate borrados, {len(antes)} previos intactos')
PYEOF

echo "GATE-AGENTES-ROJOS=$FALLAS"
exit $(( FALLAS > 0 ? 1 : 0 ))
