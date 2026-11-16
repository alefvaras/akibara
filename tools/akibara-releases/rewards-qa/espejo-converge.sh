#!/bin/bash
# QA · cronometra la convergencia del espejo. Solo lee. Uso: bash espejo-converge.sh [segundos]
cd /Users/alefvaras/Documents/akibara || exit 1
LIMITE=${1:-1800}
INICIO=$(date +%s)
while :; do
  AHORA=$(date +%s)
  T=$(( AHORA - INICIO ))
  [ "$T" -gt "$LIMITE" ] && { echo "[$T s] LIMITE ALCANZADO sin converger"; break; }
  R=$(docker compose run --rm -T cli option get akb_search_rebuild_state --path=/var/www/html 2>/dev/null)
  E=$(docker compose run --rm -T cli option get akb_search_editorial_discount_state --path=/var/www/html 2>/dev/null)
  P=$(docker compose run --rm -T cli db query "SELECT COUNT(*) FROM wp_actionscheduler_actions WHERE status='pending'" --path=/var/www/html 2>/dev/null | tail -1)
  LINEA=$(python3 - "$R" "$E" <<'PY'
import sys, json
r = sys.argv[1].strip(); e = sys.argv[2].strip()
def j(s):
    try: return json.loads(s)
    except Exception: return {}
R, E = j(r), j(e)
if not R:
    print("rebuild=SIN-ESTADO", end=" ")
else:
    print("fase=%s expected=%s ed_seq=%s hb=%s err=%r" % (
        R.get("phase"), R.get("expected"), R.get("editorial_wanted_seq"),
        R.get("heartbeat_at"), R.get("error","")), end=" ")
print("| vivo_seq=%s" % E.get("wanted_seq"), end="")
PY
)
  echo "[$T s] $LINEA | AS_pend=$P"
  if echo "$LINEA" | grep -q "rebuild=SIN-ESTADO"; then echo "[$T s] CONVERGIDO (no queda rebuild en curso)"; break; fi
  sleep 20
done
