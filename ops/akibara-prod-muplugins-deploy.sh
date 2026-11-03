#!/usr/bin/env bash
# Promote an explicit batch of first-party MU runtime files and the SMTP rescue source.
# Rollback preimages and deployment archives are kept only on the local machine.
# Usage: bash ops/akibara-prod-muplugins-deploy.sh [--dry-run] [--all | FILE.php ...]
set -euo pipefail
umask 077

SSH_HOST="${AKB_SSH_HOST:-akibara}"
WEBROOT="${AKB_WEBROOT:-~/domains/akibara.cl/public_html}"
BACKUP_DIR="${AKB_BACKUP_DIR:-~/domains/akibara.cl/akb-runtime}"
REPO_ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
MU_LOCAL="${REPO_ROOT}/public_html/wp-content/mu-plugins"
TRANSPORT=akibara-correo-smtp.php
QUARANTINE_RE='^(akibara-local-|akibara-debug-log-rotation\.php$)'
DRY_RUN=0

die() { printf '\n✗ %s\n' "$*" >&2; exit 1; }
say() { printf '  %s\n' "$*"; }
hdr() { printf '\n══ %s\n' "$*"; }
sha() { shasum -a 256 "$1" | cut -d' ' -f1; }

[[ "${1:-}" == --dry-run ]] && { DRY_RUN=1; shift; }
FILES=()
if [[ "${1:-}" == --all ]]; then
	[[ "$#" == 1 ]] || die '--all no admite otros archivos'
	for path in "${MU_LOCAL}"/akibara-*.php; do
		name="${path##*/}"
		[[ -e "$path" ]] || continue
		[[ "$name" =~ $QUARANTINE_RE ]] || FILES+=("$name")
	done
elif [[ "$#" -gt 0 ]]; then
	FILES=("$@")
else
	FILES=("$TRANSPORT" akibara-mail-transport-watch.php)
fi
[[ "${#FILES[@]}" -gt 0 ]] || die 'no hay archivos desplegables'
[[ "$SSH_HOST" =~ ^[a-zA-Z0-9_][a-zA-Z0-9_.@-]*$ ]] || die 'AKB_SSH_HOST inválido'
for path in "$WEBROOT" "$BACKUP_DIR"; do
	[[ "$path" =~ ^[a-zA-Z0-9_~/][a-zA-Z0-9_./~-]*$ ]] || die 'las rutas remotas no admiten espacios ni metacaracteres'
done
[[ "$WEBROOT" == */public_html ]] || die 'el webroot debe terminar en /public_html'

names=''
ships_transport=0
seen=' '
for f in "${FILES[@]}"; do
	[[ "$f" =~ ^akibara-[a-z0-9-]+\.php$ ]] || die "nombre fuera de la allowlist: $f"
	[[ ! "$f" =~ $QUARANTINE_RE ]] || die "archivo en cuarentena: $f"
	[[ ! "$f" =~ (^|[-])(seed|fixture|test|backup|debug)([-.]|$) ]] || die "archivo de desarrollo: $f"
	[[ "$seen" != *" $f "* ]] || die "archivo duplicado: $f"
	seen+="$f "
	[[ -f "${MU_LOCAL}/${f}" && ! -L "${MU_LOCAL}/${f}" ]] || die "origen no regular: $f"
	php -l "${MU_LOCAL}/${f}" >/dev/null || die "PHP inválido: $f"
	names+=" $f"
	[[ "$f" == "$TRANSPORT" ]] && ships_transport=1
	digest="$(sha "${MU_LOCAL}/${f}")"
	say "$digest  $f"
done
[[ "$ships_transport" == 0 ]] || say "fuente de rescate SMTP sincronizada: ${BACKUP_DIR}/${TRANSPORT}"
[[ "$DRY_RUN" == 1 ]] && { say 'dry-run: sin SSH ni escrituras remotas'; exit 0; }

token="mu-$(date -u +%Y%m%dT%H%M%SZ)-$$"
stage="${WEBROOT}/../.akb-${token}"
lock="${WEBROOT}/wp-content/.akb-runtime-deploy.lock"
mu_remote="${WEBROOT}/wp-content/mu-plugins"
local_backup="${REPO_ROOT}/.local-backups/mu-deploy/${token}"
lock_acquired=0
completed=0
present_names=''
missing_names=''
rescue_present=0

remove_maintenance() {
	ssh -o BatchMode=yes "$SSH_HOST" "set -e; cd ${WEBROOT}; if [ -f .maintenance ] && grep -Fq 'akb-deploy:${token}' .maintenance; then rm -f .maintenance; fi"
}
rollback() {
	hdr 'Restaurando preimagen desde respaldo exclusivamente local'
	# Restore only files journaled by this transaction, never an unattempted preimage.
	ssh -o BatchMode=yes "$SSH_HOST" "set -e
		[ \"\$(cat ${lock}/owner)\" = '${token}' ]
		grep -Fq 'akb-deploy:${token}' ${WEBROOT}/.maintenance
		mkdir ${stage}/restore-live ${stage}/restore-rescue"
	ssh -o BatchMode=yes "$SSH_HOST" "tar -xzf - -C ${stage}/restore-live" < "${local_backup}/before-live.tar.gz"
	if [[ "$rescue_present" == 1 ]]; then
		ssh -o BatchMode=yes "$SSH_HOST" "tar -xzf - -C ${stage}/restore-rescue" < "${local_backup}/before-rescue.tar.gz"
	fi
	ssh -o BatchMode=yes "$SSH_HOST" "set -e
		[ \"\$(cat ${lock}/owner)\" = '${token}' ]
		grep -Fq 'akb-deploy:${token}' ${WEBROOT}/.maintenance
		for f in ${names}; do
			[ -f ${stage}/attempted-live-\$f ] || continue
			old=\$(awk -v f=\"\$f\" '\$2 == f {print \$1}' ${stage}/before-live.sha256)
			new=\$(awk -v f=\"\$f\" '\$2 == f {print \$1}' ${stage}/payload.sha256)
			current=''; if [ -f ${mu_remote}/\$f ] && [ ! -L ${mu_remote}/\$f ]; then current=\$(sha256sum ${mu_remote}/\$f | cut -d' ' -f1); elif [ -e ${mu_remote}/\$f ]; then exit 1; fi
			[ \"\$current\" = \"\$new\" ] || [ \"\$current\" = \"\$old\" ] || { echo conflicto-rollback-live >&2; exit 1; }
		done
		if [ -f ${stage}/attempted-rescue ]; then
			old=''; if [ '${rescue_present}' = 1 ]; then old=\$(cut -d' ' -f1 ${stage}/before-rescue.sha256); fi
			new=\$(awk -v f='${TRANSPORT}' '\$2 == f {print \$1}' ${stage}/payload.sha256)
			current=''; if [ -f ${BACKUP_DIR}/${TRANSPORT} ] && [ ! -L ${BACKUP_DIR}/${TRANSPORT} ]; then current=\$(sha256sum ${BACKUP_DIR}/${TRANSPORT} | cut -d' ' -f1); elif [ -e ${BACKUP_DIR}/${TRANSPORT} ]; then exit 1; fi
			[ \"\$current\" = \"\$new\" ] || [ \"\$current\" = \"\$old\" ] || { echo conflicto-rollback-rescate >&2; exit 1; }
			if [ '${rescue_present}' = 1 ]; then mv -f ${stage}/restore-rescue/${TRANSPORT} ${BACKUP_DIR}/${TRANSPORT}; cd ${BACKUP_DIR}; sha256sum -c ${stage}/before-rescue.sha256;
			else rm -f ${BACKUP_DIR}/${TRANSPORT}; fi
		fi
		for f in ${names}; do
			[ -f ${stage}/attempted-live-\$f ] || continue
			if [ -f ${stage}/restore-live/\$f ]; then
				mv -f ${stage}/restore-live/\$f ${mu_remote}/\$f
				expected=\$(awk -v f=\"\$f\" '\$2 == f {print \$1}' ${stage}/before-live.sha256)
				[ \"\$(sha256sum ${mu_remote}/\$f | cut -d' ' -f1)\" = \"\$expected\" ]
			else rm -f ${mu_remote}/\$f; [ ! -e ${mu_remote}/\$f ]; fi
		done
	"
}
cleanup() {
	local result="$?" transaction
	set +e
	if [[ "$lock_acquired" == 1 ]]; then
		# The remote acknowledgement survives an interrupted SSH response. It is
		# written only after ownership and every preimage compare have succeeded.
		transaction="$(ssh -o BatchMode=yes "$SSH_HOST" "set -e; [ \"\$(cat ${lock}/owner)\" = '${token}' ]; if [ -f ${stage}/transaction-started ]; then echo started; else echo untouched; fi")"
		if [[ "$?" != 0 ]]; then printf 'ERROR: estado remoto desconocido; conservar bloqueo y recuperar desde %s\n' "$local_backup" >&2; return 1; fi
		if [[ "$transaction" == started && "$completed" != 1 ]]; then
			( set -e; rollback )
			if [[ "$?" != 0 ]]; then
				printf 'ERROR: rollback no verificado; conservar mantenimiento y recuperar desde %s\n' "$local_backup" >&2
				return 1
			fi
		fi
		remove_maintenance || { printf 'ERROR: no se pudo retirar mantenimiento propio\n' >&2; return 1; }
		ssh -o BatchMode=yes "$SSH_HOST" "set -e; [ \"\$(cat ${lock}/owner)\" = '${token}' ]; rm -rf ${stage}; rm ${lock}/owner; rmdir ${lock}; [ ! -e ${stage} ] && [ ! -e ${lock} ]" \
			|| { printf 'ERROR: limpieza remota incompleta\n' >&2; return 1; }
	fi
	return "$result"
}
trap cleanup EXIT

hdr 'Bloqueo y preflight de destinos'
ssh -o BatchMode=yes "$SSH_HOST" "set -e
	[ -d ${mu_remote} ] && [ ! -L ${mu_remote} ]
	[ ! -L ${BACKUP_DIR} ]
	root=\$(cd ${WEBROOT} && pwd -P)
	rescue_parent=\$(cd ${BACKUP_DIR}/.. && pwd -P)
	rescue=\$rescue_parent/$(basename "$BACKUP_DIR")
	case \"\$rescue/\" in \"\$root/\"*) echo 'rescate dentro del docroot' >&2; exit 1;; esac
	mkdir ${lock}
	printf '%s\n' '${token}' > ${lock}/owner
" || die 'destino no válido o despliegue concurrente'
lock_acquired=1
mkdir -p "${local_backup}/live"
chmod 700 "$local_backup"
ssh -o BatchMode=yes "$SSH_HOST" "set -e; cd ${mu_remote}; for f in ${names}; do
	[ ! -L \$f ] || { echo symlink-prohibido >&2; exit 1; }
	if [ -f \$f ]; then sha256sum \$f; elif [ -e \$f ]; then exit 1; else printf 'MISSING  %s\n' \"\$f\"; fi
 done" > "${local_backup}/before-state.txt"
: > "${local_backup}/before-live.sha256"
while read -r digest f; do
	if [[ "$digest" == MISSING ]]; then missing_names+=" $f";
	else present_names+=" $f"; printf '%s  %s\n' "$digest" "$f" >> "${local_backup}/before-live.sha256"; fi
done < "${local_backup}/before-state.txt"
if [[ -n "$present_names" ]]; then
	ssh -o BatchMode=yes "$SSH_HOST" "tar -czf - -C ${mu_remote} ${present_names}" > "${local_backup}/before-live.tar.gz"
else
	tar -czf "${local_backup}/before-live.tar.gz" -T /dev/null
fi
if [[ "$ships_transport" == 1 ]]; then
	ssh -o BatchMode=yes "$SSH_HOST" "set -e; [ ! -L ${BACKUP_DIR}/${TRANSPORT} ]; if [ -f ${BACKUP_DIR}/${TRANSPORT} ]; then cd ${BACKUP_DIR}; sha256sum ${TRANSPORT}; elif [ -e ${BACKUP_DIR}/${TRANSPORT} ]; then exit 1; fi" > "${local_backup}/before-rescue.sha256"
	if [[ -s "${local_backup}/before-rescue.sha256" ]]; then
		rescue_present=1
		ssh -o BatchMode=yes "$SSH_HOST" "tar -czf - -C ${BACKUP_DIR} ${TRANSPORT}" > "${local_backup}/before-rescue.tar.gz"
	fi
fi
# Verify streamed preimages locally before changing any runtime file.
tar -xzf "${local_backup}/before-live.tar.gz" -C "${local_backup}/live"
( cd "${local_backup}/live"; [[ ! -s ../before-live.sha256 ]] || shasum -a 256 -c ../before-live.sha256 )
if [[ "$rescue_present" == 1 ]]; then
	mkdir "${local_backup}/rescue"
	tar -xzf "${local_backup}/before-rescue.tar.gz" -C "${local_backup}/rescue"
	( cd "${local_backup}/rescue"; shasum -a 256 -c ../before-rescue.sha256 )
fi

hdr 'Preparando lote sellado fuera del docroot'
: > "${local_backup}/payload.sha256"
for f in "${FILES[@]}"; do printf '%s  %s\n' "$(sha "${MU_LOCAL}/${f}")" "$f" >> "${local_backup}/payload.sha256"; done
tar -czf "${local_backup}/payload.tar.gz" -C "$MU_LOCAL" "${FILES[@]}"
ssh -o BatchMode=yes "$SSH_HOST" "set -e; mkdir ${stage}; mkdir ${stage}/new"
ssh -o BatchMode=yes "$SSH_HOST" "tar -xzf - -C ${stage}/new" < "${local_backup}/payload.tar.gz"
scp -q "${local_backup}/payload.sha256" "${local_backup}/before-live.sha256" "${SSH_HOST}:${stage}/"
if [[ "$rescue_present" == 1 ]]; then scp -q "${local_backup}/before-rescue.sha256" "${SSH_HOST}:${stage}/"; fi
ssh -o BatchMode=yes "$SSH_HOST" "set -e; cd ${stage}/new; sha256sum -c ../payload.sha256
	[ \"\$(stat -c %d ${stage}/new)\" = \"\$(stat -c %d ${mu_remote})\" ]
	if [ '${ships_transport}' = 1 ]; then mkdir -p ${BACKUP_DIR}; [ \"\$(stat -c %d ${BACKUP_DIR})\" = \"\$(stat -c %d ${stage}/new)\" ]; cp ${TRANSPORT} ${stage}/rescue-new.php; fi
"

hdr 'Reemplazo por archivo bajo mantenimiento propio'
ssh -o BatchMode=yes "$SSH_HOST" "set -e; cd ${WEBROOT}
	[ \"\$(cat ${lock}/owner)\" = '${token}' ]
	[ ! -e .maintenance ] || { echo mantenimiento-ajeno >&2; exit 1; }
	if [ -n '${present_names}' ]; then cd ${mu_remote}; sha256sum -c ${stage}/before-live.sha256; fi
	for f in ${missing_names}; do [ ! -e ${mu_remote}/\$f ]; done
	if [ '${ships_transport}' = 1 ]; then
		if [ '${rescue_present}' = 1 ]; then cd ${BACKUP_DIR}; sha256sum -c ${stage}/before-rescue.sha256; else [ ! -e ${BACKUP_DIR}/${TRANSPORT} ]; fi
	fi
	cd ${WEBROOT}
	# noclobber makes acquisition exclusive even if another writer ignores our lock.
	( set -C; printf '<?php \$upgrading = %s; /* akb-deploy:${token} */ ?>' \"\$(date +%s)\" > .maintenance )
	: > ${stage}/transaction-started
	if [ '${ships_transport}' = 1 ]; then : > ${stage}/attempted-rescue; mv -f ${stage}/rescue-new.php ${BACKUP_DIR}/${TRANSPORT}; fi
	for f in ${names}; do : > ${stage}/attempted-live-\$f; touch ${stage}/new/\$f; mv -f ${stage}/new/\$f ${mu_remote}/\$f; done
	cd ${mu_remote}; sha256sum -c ${stage}/payload.sha256
	if [ '${ships_transport}' = 1 ]; then [ \"\$(sha256sum ${BACKUP_DIR}/${TRANSPORT} | cut -d' ' -f1)\" = \"\$(sha256sum ${mu_remote}/${TRANSPORT} | cut -d' ' -f1)\" ]; fi
" || die 'falló el lote MU; se verificará su diario remoto'

hdr 'Salud operativa antes de reabrir'
ssh -o BatchMode=yes "$SSH_HOST" "cd ${WEBROOT} && wp akibara access-check"
"${REPO_ROOT}/ops/akibara-prod-mail-health.sh" || die 'el transporte no quedó sano'
completed=1
say "${#FILES[@]} MU instalados; recuperación sólo local: ${local_backup}"
say 'Pendiente: purga de borde y QA público por el supervisor.'
