#!/bin/bash
# QA · ¿avanza el rebuild del espejo? Cuenta filas de las tablas sombra. Solo lee.
cd /Users/alefvaras/Documents/akibara || exit 1
N=${1:-4}
SQL="SELECT (SELECT COUNT(*) FROM wp_akb_search_docs_new) docs_new, (SELECT COUNT(*) FROM wp_akb_search_tokens_new) tok_new, (SELECT COUNT(*) FROM wp_akb_search_docs) docs, (SELECT COUNT(*) FROM wp_akb_search_work) work, (SELECT COUNT(*) FROM wp_actionscheduler_actions WHERE status='pending') pend"
for i in $(seq 1 "$N"); do
  printf '[%s] ' "$(date -u +%s)"
  docker compose run --rm -T cli db query "$SQL" --path=/var/www/html 2>/dev/null | tail -1
  printf '        estado: '
  docker compose run --rm -T cli option get akb_search_rebuild_state --path=/var/www/html 2>/dev/null \
    | python3 -c "import sys,json
try:
    d=json.load(sys.stdin)
    print('fase=%s hb=%s expected=%s ed_seq=%s err=%r' % (d.get('phase'),d.get('heartbeat_at'),d.get('expected'),d.get('editorial_wanted_seq'),d.get('error','')))
except Exception as e:
    print('SIN ESTADO (%s)' % e)"
  sleep 25
done
