#!/usr/bin/env bash
# ops/akibara-prod-mail-health.sh — ¿está vivo el transporte de correo de producción?
#
# Nació del incidente 2026-08-18 → 2026-08-21: el mu-plugin `akibara-correo-smtp.php`
# (ÚNICO transporte SMTP de la tienda desde que se retiraron Brevo y Resend el 14-ago)
# desapareció de prod sin que ninguna sesión ejecutara un rm. Durante 3 días TODOS los
# correos —pedidos, cuentas nuevas, reset de contraseña, tracking de BlueX— cayeron al
# mail() de PHP, que en Hostinger devuelve false («No se ha podido establecer la función
# de correo»), y nadie lo vio porque el correo no puede avisar de que el correo está roto.
# Este chequeo se ejecuta al final de cada deploy (ops/akibara-deploy-runtime.sh) y a mano.
#
# Sale con 1 si algo crítico falla. Solo lee: no envía correos (el test SMTP autentica y cierra).
set -euo pipefail

SSH_HOST="${AKB_SSH_HOST:-akibara}"
WEBROOT="${AKB_WEBROOT:-~/domains/akibara.cl/public_html}"
REPO_ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
MU_LOCAL="${REPO_ROOT}/public_html/wp-content/mu-plugins"
CRITICAL_MU="akibara-correo-smtp.php"
WATCH_MU="akibara-mail-transport-watch.php"
# Copia de rescate del transporte: hermana del docroot, nunca servible por web.
# El centinela repone desde aquí cuando el archivo desaparece.
BACKUP_DIR="${AKB_BACKUP_DIR:-~/domains/akibara.cl/akb-runtime}"

ok()   { printf '  ✓ %s\n' "$*"; }
warn() { printf '  ⚠ %s\n' "$*"; }
bad()  { printf '  ✗ %s\n' "$*" >&2; FAIL=1; }
FAIL=0

printf '\n══ Salud del correo en producción (%s)\n' "$SSH_HOST"

# 1. Inventario de mu-plugins: el crítico es bloqueante; el resto solo se informa,
#    porque hay archivos en el repo que son trabajo en curso o solo-local.
remote_mu="$(ssh -o BatchMode=yes "$SSH_HOST" "ls ${WEBROOT}/wp-content/mu-plugins/ 2>/dev/null" || true)"
[[ -n "$remote_mu" ]] || { bad "no pude listar mu-plugins en prod"; exit 1; }
if grep -qx "$CRITICAL_MU" <<<"$remote_mu"; then
	ok "mu-plugin crítico presente: ${CRITICAL_MU}"
else
	bad "FALTA ${CRITICAL_MU} en prod — restaurar con: scp public_html/wp-content/mu-plugins/${CRITICAL_MU} ${SSH_HOST}:${WEBROOT}/wp-content/mu-plugins/"
fi
# Cuarentena: archivos del repo que NO deben copiarse a prod (reescritura de Codex no desplegable,
# ver ops/qa-puntos/ESTADO-2026-08-21.md) o que son solo-local.
QUARANTINE_RE='^(akibara-local-|akibara-debug-log-rotation\.php$)'
missing="$(comm -23 <(ls "$MU_LOCAL" | grep -Ev "$QUARANTINE_RE" | sort) <(sort <<<"$remote_mu") | grep -vx "$CRITICAL_MU" || true)"
[[ -z "$missing" ]] && ok "inventario mu-plugins: prod tiene todo lo del repo" \
	|| warn "mu-plugins del repo ausentes en prod (informativo): $(tr '\n' ' ' <<<"$missing")"

# 1b. Que el archivo ESTÉ no basta: hay que comprobar que es EL archivo.
#     El cron de auto-restauración retirado el 2026-08-23 (hPanel 5WxqXVQ5zM) reponía
#     una copia congelada en la 1.0.0 sobre la 1.1.0 desplegada — un downgrade al guard
#     ciego— y este chequeo lo daba por sano, porque sólo miraba el nombre del archivo,
#     que el hook existiera y que el SMTP autenticara: las tres cosas las cumple
#     cualquier versión. Se compara el contenido, no la presencia.
local_sum="$(md5 -q "${MU_LOCAL}/${CRITICAL_MU}" 2>/dev/null || md5sum "${MU_LOCAL}/${CRITICAL_MU}" | cut -d' ' -f1)"
remote_sums="$(ssh -o BatchMode=yes "$SSH_HOST" \
	"md5sum ${WEBROOT}/wp-content/mu-plugins/${CRITICAL_MU} ${BACKUP_DIR}/${CRITICAL_MU} 2>/dev/null" || true)"
prod_sum="$(grep "mu-plugins/${CRITICAL_MU}" <<<"$remote_sums" | cut -d' ' -f1 || true)"
copy_sum="$(grep -v "mu-plugins/" <<<"$remote_sums" | grep "${CRITICAL_MU}" | cut -d' ' -f1 || true)"

if [[ "$prod_sum" == "$local_sum" ]]; then
	ok "el transporte en prod es byte a byte el del repo ($(grep -m1 'Version:' "${MU_LOCAL}/${CRITICAL_MU}" | sed 's/.*Version: *//'))"
else
	bad "el transporte de prod NO coincide con el repo (prod ${prod_sum:-ausente} vs repo ${local_sum}) — puede ser un downgrade silencioso"
fi

# La copia de rescate desactualizada es exactamente el defecto que se retiró: si el
# centinela tuviera que reponer, repondría una versión vieja sin decírselo a nadie.
if [[ -z "$copy_sum" ]]; then
	bad "falta la copia de rescate ${BACKUP_DIR}/${CRITICAL_MU} — el centinela no podría reponer nada"
elif [[ "$copy_sum" == "$local_sum" ]]; then
	ok "copia de rescate al día"
else
	bad "la copia de rescate está desfasada (${copy_sum} vs ${local_sum}) — reponerla serviría una versión vieja"
fi

if grep -qx "$WATCH_MU" <<<"$remote_mu"; then
	ok "centinela presente: ${WATCH_MU}"
else
	bad "FALTA ${WATCH_MU} en prod — sin él, que el transporte desaparezca vuelve a ser invisible"
fi

# 2. Hooks del transporte + conexión SMTP real (autentica y cierra, NO envía nada).
#    La sonda va en un archivo propio: un `wp eval` inline por ssh destroza las barras del namespace.
probe_status=0
probe="$(ssh -o BatchMode=yes "$SSH_HOST" "cd ${WEBROOT} && wp eval-file - 2>&1" \
	< "${REPO_ROOT}/ops/lib/akibara-mail-probe.php")" || probe_status="$?"
[[ "$probe_status" == 0 ]] || bad 'la sonda no terminó correctamente; no se puede aprobar el transporte'
sed 's/^/  /' <<<"$probe"
grep -q 'env=production' <<<"$probe" || bad "wp_get_environment_type() no es production: el mu-plugin bloquea todo envío"
grep -q 'enabled=true'   <<<"$probe" || bad "AKIBARA_HOSTINGER_MAIL_ENABLED no está en true en wp-config.php"
grep -q 'hook=true'      <<<"$probe" || bad "el hook Akibara\\HostingerMail\\Transport::configure_phpmailer NO está registrado"
grep -q 'transport=smtp' <<<"$probe" || bad "PHPMailer no quedó en SMTP (caería a mail(), que en Hostinger falla)"
grep -q 'smtp=auth-ok'   <<<"$probe" || bad "no se pudo autenticar contra smtp.hostinger.com"

# 3. Fallos recientes en el log transaccional de WooCommerce (48 h). Bloquea solo si la
#    ÚLTIMA caída a mail() es más reciente que el último envío correcto: un incidente ya
#    resuelto se informa, no frena el deploy dos días.
recent_status=0
recent="$(ssh -o BatchMode=yes "$SSH_HOST" "set -e; cd ${WEBROOT}/wp-content/uploads/wc-logs
	for day in \$(date -u +%F) \$(date -u -d yesterday +%F); do
		for log in transactional-emails-\$day-*.log; do
			if [ -f \"\$log\" ]; then cat \"\$log\"; fi
		done
	done")" || recent_status="$?"
[[ "$recent_status" == 0 ]] || bad 'no se pudieron medir los registros recientes de correo'
n_fail="$(grep -c ' WARNING ' <<<"$recent" || true)"
n_sent="$(grep -c ' INFO ' <<<"$recent" || true)"
# `|| true` en los dos, y no es cosmética: con `set -o pipefail` un grep sin
# coincidencias hace fallar la tubería entera y `set -e` mata el script SIN
# imprimir su veredicto. O sea que este chequeo reventaba exactamente cuando el
# correo estaba PERFECTO —cero caídas a mail() en 48 h— y el deploy lo leía como
# «el transporte NO está sano». Sólo pasaba desapercibido mientras el log aún
# arrastraba fallos del incidente de agosto. Cazado el 2026-08-23, en el primer
# deploy con la ventana de 48 h ya limpia.
last_sent="$(grep ' INFO ' <<<"$recent" | tail -n 1 | cut -d' ' -f1 || true)"
last_fallback="$(grep 'establecer la función de correo\|Could not instantiate mail' <<<"$recent" | tail -n 1 | cut -d' ' -f1 || true)"
if [[ -n "$last_fallback" && "$last_fallback" > "$last_sent" ]]; then
	bad "el correo está cayendo a mail() AHORA: última caída ${last_fallback}, último envío OK ${last_sent:-nunca} (${n_fail} fallos / ${n_sent} enviados en 48 h)"
elif [[ -n "$last_fallback" ]]; then
	warn "hubo caídas a mail() en 48 h (última ${last_fallback}) ya superadas: último envío OK ${last_sent} (${n_fail} fallos / ${n_sent} enviados)"
elif [[ "$n_fail" -gt 0 ]]; then
	warn "${n_fail} fallo(s) de correo en 48 h que no son de transporte (revisar destinatarios); ${n_sent} enviados"
else
	ok "sin fallos de correo en 48 h (${n_sent} enviados, último ${last_sent:-—})"
fi

if [[ "$FAIL" == 1 ]]; then printf '\n✗ correo de producción NO sano\n' >&2; exit 1; fi
printf '\n✓ correo de producción sano\n'
