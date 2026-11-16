#!/bin/bash
# QA · ¿coinciden los interruptores del espejo y de producción? Solo lee.
set -u
cd /Users/alefvaras/Documents/akibara || exit 1
SQL="SELECT option_name, option_value FROM wp_options WHERE (option_name LIKE 'akb\_%' OR option_name LIKE 'akibara\_%') AND option_name NOT LIKE '%_state' AND option_name NOT LIKE '%_receipt' AND option_name NOT LIKE '%_history' AND option_name NOT LIKE '%_backup' ORDER BY option_name"

docker compose run --rm -T cli db query "$SQL" --path=/var/www/html 2>/dev/null | tail -n +2 > /tmp/qa-esp-opts.tsv
cat > /tmp/qa-prod-opts.sh <<EOF
cd ~/domains/akibara.cl/public_html || exit 1
/usr/local/bin/wp db query "$SQL"
EOF
ssh akibara 'bash -s' < /tmp/qa-prod-opts.sh 2>/dev/null | tail -n +2 > /tmp/qa-prod-opts.tsv

python3 - <<'PY'
def carga(p):
    d = {}
    for linea in open(p, encoding='utf-8', errors='replace'):
        if '\t' in linea:
            k, v = linea.rstrip('\n').split('\t', 1)
            d[k] = v
    return d
e, p = carga('/tmp/qa-esp-opts.tsv'), carga('/tmp/qa-prod-opts.tsv')
solo_e = sorted(set(e) - set(p)); solo_p = sorted(set(p) - set(e))
dif = sorted(k for k in set(e) & set(p) if e[k] != p[k])
print(f"espejo {len(e)} options · producción {len(p)} · sólo espejo {len(solo_e)} · sólo prod {len(solo_p)} · difieren {len(dif)}")
INTERRUPTOR = ('enabled', 'disabled', 'show_', 'activo', '_on')
print("\n--- INTERRUPTORES que difieren (lo grave) ---")
n = 0
for k in dif:
    if any(s in k for s in INTERRUPTOR) and len(e[k]) < 12 and len(p[k]) < 12:
        print(f"  {k:52} espejo={e[k]:10} prod={p[k]}")
        n += 1
if not n: print("  (ninguno)")
print("\n--- interruptores que sólo existen en un lado ---")
m = 0
for k in solo_p:
    if any(s in k for s in INTERRUPTOR): print(f"  {k:52} sólo en PRODUCCIÓN = {p[k][:20]}"); m += 1
for k in solo_e:
    if any(s in k for s in INTERRUPTOR): print(f"  {k:52} sólo en ESPEJO = {e[k][:20]}"); m += 1
if not m: print("  (ninguno)")
print(f"\n--- otras options que difieren: {len([k for k in dif])} (muestra) ---")
for k in dif[:12]:
    print(f"  {k:52} espejo={e[k][:26]:28} prod={p[k][:26]}")
PY
