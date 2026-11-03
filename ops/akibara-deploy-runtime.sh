#!/usr/bin/env bash
# Despliega un paquete runtime sellado, con respaldo local y verificación SHA-256.
# El árbol se prepara fuera de plugins/themes. Dos renames bajo mantenimiento
# impiden servir una copia parcial; no constituyen un intercambio sin downtime.
# La recuperación queda disponible hasta completar los chequeos técnicos.
# La purga de Cloudflare y el QA de cliente se ejecutan después por el supervisor.
# Uso: bash ops/akibara-deploy-runtime.sh [--dry-run] COMPONENTE VERSION
set -euo pipefail

SSH_HOST="${AKB_SSH_HOST:-akibara}"
WEBROOT="${AKB_WEBROOT:-~/domains/akibara.cl/public_html}"
REPO_ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
DRY_RUN=0

die() { printf '\n✗ %s\n' "$*" >&2; exit 1; }
say() { printf '  %s\n' "$*"; }
hdr() { printf '\n══ %s\n' "$*"; }

[[ "${1:-}" == "--dry-run" ]] && { DRY_RUN=1; shift; }

component="${1:-}"
version="${2:-}"
[[ "$#" == 2 && "$version" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || die "uso: $0 [--dry-run] <business|search|checkout|rewards|theme> <version>"

case "$component" in
	business) slug="akibara-business";              dest="wp-content/plugins/${slug}" ;;
	search)   slug="akibara-search";                dest="wp-content/plugins/${slug}" ;;
	checkout) slug="akibara-cart-checkout-blocks";  dest="wp-content/plugins/${slug}" ;;
	rewards)  slug="akibara-rewards";               dest="wp-content/plugins/${slug}" ;;
	theme)    slug="akibara-gp";                    dest="wp-content/themes/${slug}" ;;
	*) die "componente desconocido: ${component}" ;;
esac

zip="${REPO_ROOT}/dist/${slug}-${version}.zip"
inv="${REPO_ROOT}/dist/${slug}-${version}.files.sha256"
sum="${REPO_ROOT}/dist/${slug}-${version}.zip.sha256"

for f in "$zip" "$inv" "$sum"; do
	[[ -f "$f" ]] || die "falta $f — empaqueta primero (tools/akibara-releases/package-*.sh)"
done

[[ "$SSH_HOST" =~ ^[a-zA-Z0-9_][a-zA-Z0-9_.@-]*$ ]] || die "AKB_SSH_HOST inválido"
[[ "$WEBROOT" =~ ^[a-zA-Z0-9_~/][a-zA-Z0-9_./~-]*$ ]] || die "AKB_WEBROOT debe ser una ruta sin espacios ni metacaracteres"
python3 "${REPO_ROOT}/tools/akibara-releases/verify-runtime-package.py" "$zip" "$inv" "$sum" "$slug" "$version" \
	|| die "el paquete local no supera la validación"

expected_files="$(wc -l < "$inv" | tr -d ' ')"
zip_sha="$(cut -d' ' -f1 "$sum")"

hdr "Plan"
say "componente : ${component} (${slug})"
say "versión    : ${version}"
say "destino    : ${WEBROOT}/${dest}"
say "archivos   : ${expected_files}"
say "sha256 zip : ${zip_sha}"
[[ "$DRY_RUN" == 1 ]] && { printf '\n(dry-run: no se toca producción)\n'; exit 0; }

hdr "Estado ANTES"
ssh -o BatchMode=yes "$SSH_HOST" "cd ${WEBROOT}/${dest} 2>/dev/null && \
	printf '  versión actual: %s\n' \"\$(grep -m1 -E '^( \* )?Version:' *.php style.css 2>/dev/null | head -1 | sed 's/.*Version: *//')\" && \
	printf '  archivos: %s\n' \"\$(find . -type f | wc -l | tr -d ' ')\"" || die "no pude leer el destino"

remote_tmp="/tmp/akb-deploy-${slug}-${version}-$$"
stage_root="${WEBROOT}/wp-content/.akb-deploy-stage/${slug}-${version}-$$"
lock_path="${WEBROOT}/wp-content/.akb-runtime-deploy.lock"
deploy_token="${slug}-${version}-$$"
lock_acquired=0
swap_started=0
deployment_succeeded=0
cleanup() {
	local result="$?"
	if [[ "$swap_started" == 1 && "$deployment_succeeded" != 1 ]]; then
		printf '\nRestaurando el árbol anterior tras fallo de validación...\n' >&2
		if ! ssh -o BatchMode=yes "$SSH_HOST" "set -e; cd ${WEBROOT}
			if [ -d ${stage_root}/${slug}.prev ]; then
			printf '<?php \$upgrading = %s; /* akb-deploy:${deploy_token} */ ?>' \"\$(date +%s)\" > .maintenance
			if [ -d ${dest} ]; then mv ${dest} ${stage_root}/${slug}.failed; fi
			mv ${stage_root}/${slug}.prev ${dest}
			rm -rf ${stage_root}/${slug}.failed
			fi
			if [ -f .maintenance ] && grep -Fq 'akb-deploy:${deploy_token}' .maintenance; then rm -f .maintenance; fi"; then
			printf 'ERROR: restauración remota incompleta; recuperar desde %s\n' "$backup_dir" >&2
			return 1
		fi
	fi
	if [[ "$lock_acquired" == 1 ]]; then
		ssh -o BatchMode=yes "$SSH_HOST" "rm -rf ${remote_tmp} ${stage_root}; rmdir ${lock_path}" \
			|| { printf 'ERROR: no se pudo limpiar el staging remoto.\n' >&2; return 1; }
	fi
	return "$result"
}
trap cleanup EXIT

hdr "Bloqueo de despliegue y respaldo exclusivamente local"
ssh -o BatchMode=yes "$SSH_HOST" "mkdir ${lock_path}" || die "otro despliegue mantiene el bloqueo ${lock_path}"
lock_acquired=1
backup_dir="${REPO_ROOT}/.local-backups/runtime/$(date -u +%Y%m%dT%H%M%SZ)-${slug}-$$"
mkdir -p "$backup_dir"
chmod 700 "$backup_dir"
ssh -o BatchMode=yes "$SSH_HOST" "cd ${WEBROOT} && tar -czf - ${dest}" > "${backup_dir}/before.tar.gz" \
	|| die "no se pudo descargar el respaldo previo; producción no se modificó"
tar -tzf "${backup_dir}/before.tar.gz" >/dev/null || die "respaldo local ilegible"
say "recuperación local: ${backup_dir}/before.tar.gz"

hdr "Subiendo y verificando el paquete"
ssh -o BatchMode=yes "$SSH_HOST" "mkdir -p ${remote_tmp}"
scp -q "$zip" "$inv" "${SSH_HOST}:${remote_tmp}/"
ssh -o BatchMode=yes "$SSH_HOST" "cd ${remote_tmp} && echo '${zip_sha}  $(basename "$zip")' | sha256sum -c" \
	|| die "el ZIP llegó corrupto"

hdr "Preparando el árbol nuevo (fuera del directorio vivo)"
# La ruta de staging es exclusiva de esta ejecución y comparte filesystem con el destino.
ssh -o BatchMode=yes "$SSH_HOST" "set -e
	cd ${remote_tmp}
	unzip -qo '$(basename "$zip")'
	mkdir -p ${stage_root}
	cp -a ${remote_tmp}/${slug} ${stage_root}/${slug}
	# El ZIP reproducible fija mtimes antiguos; el runtime requiere fechas nuevas
	# para invalidar filemtime en assets y permitir revalidación del bytecode.
	find ${stage_root}/${slug} -exec touch {} +
"
say "árbol preparado en ${stage_root}/${slug} con mtimes reales"

hdr "Verificando paridad byte a byte ANTES de poner en vivo"
ok_count="$(ssh -o BatchMode=yes "$SSH_HOST" \
	"cd ${stage_root} && sha256sum -c ${remote_tmp}/$(basename "$inv") 2>/dev/null | grep -c ': OK$'" || true)"
say "coinciden: ${ok_count}/${expected_files}"
if [[ "$ok_count" != "$expected_files" ]]; then
	ssh -o BatchMode=yes "$SSH_HOST" \
		"cd ${stage_root} && sha256sum -c ${remote_tmp}/$(basename "$inv") 2>/dev/null | grep -v ': OK$' | head -10; rm -rf ${stage_root}/${slug}" || true
	die "PARIDAD INCOMPLETA en el árbol preparado — producción NO se tocó"
fi

hdr "Cambio bajo mantenimiento"
swap_started=1
swap_out="$(ssh -o BatchMode=yes "$SSH_HOST" "set -e
	cd ${WEBROOT}
	[ ! -e .maintenance ] || { echo mantenimiento-ajeno >&2; exit 1; }
	printf '<?php \$upgrading = %s; /* akb-deploy:${deploy_token} */ ?>' \"\$(date +%s)\" > .maintenance
	mv ${dest} ${stage_root}/${slug}.prev
	mv ${stage_root}/${slug} ${dest}
	rm -f .maintenance
	echo swap-ok
" 2>&1)" || true
if [[ "$swap_out" != *swap-ok* ]]; then
	# El trap restaura el árbol previo incluso si la conexión perdió el recibo.
	die "el cambio bajo mantenimiento falló: ${swap_out}"
fi
say "dos mv bajo .maintenance: hecho (recuperación temporal en ${stage_root}/${slug}.prev)"

hdr "Verificando paridad en vivo"
parent="$(dirname "$dest")"
ok_count="$(ssh -o BatchMode=yes "$SSH_HOST" \
	"cd ${WEBROOT}/${parent} && sha256sum -c ${remote_tmp}/$(basename "$inv") 2>/dev/null | grep -c ': OK$'" || true)"
say "coinciden: ${ok_count}/${expected_files}"
if [[ "$ok_count" != "$expected_files" ]]; then
	die "PARIDAD INCOMPLETA en vivo — se restaurará el árbol anterior"
fi

hdr "Purgando cachés"
ssh -o BatchMode=yes "$SSH_HOST" "cd ${WEBROOT} && \
	wp litespeed-purge all >/dev/null 2>&1 && \
	rm -f wp-content/litespeed/css/*.css"
say "LiteSpeed purgado (página + CSS combinado en disco)"
say "OJO: esto NO purga Cloudflare. Para cambios de MARKUP hay que purgar el borde aparte."

hdr "Estado DESPUÉS"
ssh -o BatchMode=yes "$SSH_HOST" "set -e; cd ${WEBROOT}
	actual=\$(wp $([[ "$component" == theme ]] && echo theme || echo plugin) get ${slug} --field=version)
	[ \"\$actual\" = '${version}' ] || { echo 'WordPress no reporta la versión solicitada' >&2; exit 1; }
	printf '  wp reporta: %s\n' \"\$actual\"
	m=\$(find ${dest} -type f -exec stat -c %Y {} \; | sort -n | head -1)
	[ -n \"\$m\" ] && [ \"\$m\" -gt 315532800 ] || { echo 'mtime inválido o congelado' >&2; exit 1; }
	printf '  mtime mínimo: %s\n' \"\$m\"
"

hdr "Claves de proveedores"
# Los requisitos operativos se verifican antes de descartar el árbol recuperable.
ssh -o BatchMode=yes "$SSH_HOST" "cd ${WEBROOT} && wp akibara access-check" \
	|| die "faltan claves obligatorias — revísalas con: ssh ${SSH_HOST} 'cd ${WEBROOT} && wp akibara access-list'"

hdr "Salud del correo"
# Este chequeo autentica el transporte sin enviar correo.
"${REPO_ROOT}/ops/akibara-prod-mail-health.sh" || die "el transporte de correo NO está sano — arréglalo antes de dar el deploy por cerrado"

deployment_succeeded=1
printf '\n✓ %s %s instalado con comprobaciones técnicas; pendiente QA público y Cloudflare.\n' "$slug" "$version"
