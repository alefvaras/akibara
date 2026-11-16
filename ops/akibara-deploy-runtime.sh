#!/usr/bin/env bash
# Despliega un paquete runtime sellado, con respaldo local y verificación SHA-256.
# El árbol se prepara fuera de plugins/themes. Dos renames bajo mantenimiento
# impiden servir una copia parcial; no constituyen un intercambio sin downtime.
# La recuperación queda disponible hasta completar los chequeos técnicos.
# La certificación local precede cualquier conexión remota; la certificación
# pública y ambas purgas deben terminar antes de descartar la recuperación.
# Uso: bash ops/akibara-deploy-runtime.sh [--dry-run] COMPONENTE VERSION
set -euo pipefail

SSH_HOST="${AKB_SSH_HOST:-akibara}"
WEBROOT="${AKB_WEBROOT:-~/domains/akibara.cl/public_html}"
REPO_ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)"
DRY_RUN=0

die() { printf '\n✗ %s\n' "$*" >&2; exit 1; }
say() { printf '  %s\n' "$*"; }
hdr() { printf '\n══ %s\n' "$*"; }

source_digest() {
	python3 - "$REPO_ROOT" <<'PY'
import hashlib, pathlib, sys
root = pathlib.Path(sys.argv[1])
roots = [root / p for p in ('ops', 'tools', '.semgrep', '.githooks', 'public_html/wp-content/mu-plugins')]
roots += list((root / 'public_html/wp-content/plugins').glob('akibara-*'))
roots += [root / 'public_html/wp-content/themes/akibara-gp']
config_names = {'.eslintignore', '.sqlfluff', '.sqlfluffignore', '.semgrepignore'}
files = [p for p in root.iterdir() if p.is_file() and (p.suffix in ('.json', '.lock', '.dist', '.php', '.xml', '.js', '.neon') or p.name in config_names)]
excluded = {'node_modules', 'vendor', 'output', 'outputs', 'backups', 'test-results', '__pycache__'}
for base in roots:
    if base.is_dir():
        files.extend(p for p in base.rglob('*') if p.is_file()
                     and not excluded.intersection(p.relative_to(base).parts)
                     and not any(part.startswith('.local') for part in p.parts)
                     and p.suffix not in ('.log', '.zip', '.gz', '.pyc'))
digest = hashlib.sha256()
for p in sorted(set(files)):
    digest.update(p.relative_to(root).as_posix().encode() + b'\0' + hashlib.sha256(p.read_bytes()).digest())
print(digest.hexdigest())
PY
}

verify_local_candidate() (
	# Reuse the sealed packager's complete runtime selection, including files
	# absent from an old ZIP. Its staging is isolated and never changes sources.
	candidate_dir="$(mktemp -d "${TMPDIR:-/tmp}/akb-candidate-${slug}.XXXXXX")" || return 1
	trap 'rm -rf -- "$candidate_dir"' EXIT
	if ! AKIBARA_EXPECTED_VERSION="$version" bash "${REPO_ROOT}/tools/akibara-releases/package-runtime.sh" "$component" \
		--source-dir "${REPO_ROOT}/public_html/${dest}" --output-dir "$candidate_dir" > "${candidate_dir}/package.log" 2>&1; then
		cat "${candidate_dir}/package.log" >&2
		return 1
	fi
	python3 - "$zip" "${REPO_ROOT}/public_html/${dest}" "$inv" "${candidate_dir}/${slug}-${version}.files.sha256" <<'PY'
import pathlib, sys, zipfile
source = pathlib.Path(sys.argv[2])
def inventory(filename):
    return {line.split('  ', 1)[1]: line.split('  ', 1)[0]
            for line in pathlib.Path(filename).read_text().splitlines()}
artifact_files = inventory(sys.argv[3])
candidate_files = inventory(sys.argv[4])
if candidate_files != artifact_files:
    extra = sorted(candidate_files.keys() - artifact_files.keys())
    missing = sorted(artifact_files.keys() - candidate_files.keys())
    changed = sorted(name for name in artifact_files.keys() & candidate_files.keys()
                     if artifact_files[name] != candidate_files[name])
    raise SystemExit('El candidato difiere del espejo local; inventario runtime distinto: '
                     + repr({'solo_en_espejo': extra, 'solo_en_paquete': missing, 'contenido_distinto': changed}))
with zipfile.ZipFile(sys.argv[1]) as archive:
    for entry in archive.infolist():
        if entry.is_dir():
            continue
        relative = pathlib.PurePosixPath(entry.filename).parts[1:]
        path = source.joinpath(*relative)
        if not path.is_file() or path.is_symlink() or path.read_bytes() != archive.read(entry):
            raise SystemExit('El candidato difiere del espejo local: ' + '/'.join(relative))
PY
)

purge_live_caches() {
	local phase="$1"
	ssh -o BatchMode=yes "$SSH_HOST" "set -e; cd ${WEBROOT}
		wp litespeed-purge all
		wp eval '\\RankMath\\Sitemap\\Cache::invalidate_storage();'
		rm -f wp-content/litespeed/css/*.css
		wp eval-file -" <<'PHP' | tee "${evidence_dir}/${phase}-cache.log"
<?php
// Reuse the installed catalogue purge client and validate its HTTP receipt.
if ('https://akibara.cl' !== untrailingslashit(home_url('/')) || !class_exists('\Akibara\Business\Modules\SiteSpeed\EdgePurge')) {
    WP_CLI::error('No se pudo comprobar la integración Cloudflare del sitio.');
}
$zone = (string) get_option('litespeed.conf.cdn-cloudflare_zone', '');
if (!preg_match('/^[a-f0-9]{32}$/D', $zone)) {
    WP_CLI::error('Zona Cloudflare no válida.');
}
$receipt = null;
$prefix = (string) wp_parse_url(home_url('/'), PHP_URL_HOST) . '/';
add_action('http_api_debug', static function ($response, $context, $transport, $args, $url) use (&$receipt, $zone, $prefix) {
    if ('response' !== $context || 'https://api.cloudflare.com/client/v4/zones/' . $zone . '/purge_cache' !== $url) {
        return;
    }
    $body = is_wp_error($response) ? null : json_decode(wp_remote_retrieve_body($response), true);
    $request = json_decode((string) ($args['body'] ?? ''), true);
    $receipt = !is_wp_error($response) && 200 === wp_remote_retrieve_response_code($response)
        && 'POST' === ($args['method'] ?? '') && [$prefix] === ($request['prefixes'] ?? null)
        && true === ($body['success'] ?? false) && empty($body['errors']);
}, 10, 5);
\Akibara\Business\Modules\SiteSpeed\EdgePurge::run([home_url('/')]);
if (true !== $receipt) {
    WP_CLI::error('La purga Cloudflare carece de recibo satisfactorio.');
}
WP_CLI::success('LiteSpeed, sitemap y Cloudflare purgados y verificados.');
PHP
}

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

for required_command in python3 node docker ssh scp tar shasum tee; do
	command -v "$required_command" >/dev/null 2>&1 || die "falta herramienta obligatoria: ${required_command}"
done
for required_file in tools/verify-devsecops-seal.py tools/guardrail-ci.sh tools/qa-fresh-auditor-gate.py tools/qa-playwright-full-suite.mjs tools/akibara-releases/package-runtime.sh tools/akibara-releases/rewards-qa/qa-paridad-espejo.mjs ops/akibara-prod-mail-health.sh tools/sync-notebooklm.mjs; do
	[[ -f "${REPO_ROOT}/${required_file}" && ! -L "${REPO_ROOT}/${required_file}" ]] || die "falta control obligatorio: ${required_file}"
done

[[ "$SSH_HOST" =~ ^[a-zA-Z0-9_][a-zA-Z0-9_.@-]*$ ]] || die "AKB_SSH_HOST inválido"
[[ "$WEBROOT" =~ ^[a-zA-Z0-9_~/][a-zA-Z0-9_./~-]*$ ]] || die "AKB_WEBROOT debe ser una ruta sin espacios ni metacaracteres"
python3 "${REPO_ROOT}/tools/akibara-releases/verify-runtime-package.py" "$zip" "$inv" "$sum" "$slug" "$version" \
	|| die "el paquete local no supera la validación"

python3 "${REPO_ROOT}/tools/verify-devsecops-seal.py" || die "VIOLACIÓN DE CANDADO DEVSECOPS: configuración de análisis alterada — despliegue abortado"

expected_files="$(wc -l < "$inv" | tr -d ' ')"
zip_sha="$(cut -d' ' -f1 "$sum")"

hdr "Plan"
say "componente : ${component} (${slug})"
say "versión    : ${version}"
say "destino    : ${WEBROOT}/${dest}"
say "archivos   : ${expected_files}"
say "sha256 zip : ${zip_sha}"
verify_local_candidate || die "el paquete debe coincidir con el candidato ensayado en local"
[[ "$DRY_RUN" == 1 ]] && { printf '\n(dry-run: no se toca producción)\n'; exit 0; }

mkdir -p "${REPO_ROOT}/.local-quality/runtime"
evidence_dir="$(mktemp -d "${REPO_ROOT}/.local-quality/runtime/${slug}-${version}.XXXXXX")"
chmod 700 "$evidence_dir"
validated_sources="$(source_digest)"
printf '%s\n' "$validated_sources" > "${evidence_dir}/sources.sha256"
shasum -a 256 "$zip" "$inv" "$sum" > "${evidence_dir}/artifact.sha256"
hdr "Auditoría completa previa al acceso remoto"
bash "${REPO_ROOT}/tools/guardrail-ci.sh" --full 2>&1 | tee "${evidence_dir}/guardrail-full.log" \
	|| die "la suite completa de guardrails falló; evidencia ${evidence_dir}"
[[ "$(source_digest)" == "$validated_sources" ]] || die "las fuentes cambiaron durante los guardrails"
python3 "${REPO_ROOT}/tools/qa-fresh-auditor-gate.py" \
	--component "$component" \
	--engine "${AKB_AUDITOR_ENGINE:-auto}" \
	--allow-fallback-engine \
	--timeout "${AKB_AUDITOR_TIMEOUT:-1200}" \
	--source-digest "$validated_sources" \
	--guardrail-log "${evidence_dir}/guardrail-full.log" \
	--evidence-out "${evidence_dir}/fresh-auditor.json" 2>&1 | tee "${evidence_dir}/fresh-auditor.log" \
	|| die "AUDITORÍA FRESCA PRE-DESPLIEGUE RECHAZADA; evidencia ${evidence_dir}/fresh-auditor.json"
python3 "${REPO_ROOT}/tools/qa-fresh-auditor-gate.py" \
	--verify-receipt "${evidence_dir}/fresh-auditor.json" \
	--source-digest "$validated_sources" \
	|| die "recibo de auditoría fresca inválido o desalineado"
[[ "$(source_digest)" == "$validated_sources" ]] || die "las fuentes cambiaron durante la auditoría fresca"
node "${REPO_ROOT}/tools/akibara-releases/rewards-qa/qa-paridad-espejo.mjs" 2>&1 | tee "${evidence_dir}/mirror-parity.log" \
	|| die "el espejo no permite certificar este candidato"
docker exec -i akibara-cron-1 sh -c "cd /var/www/html/$(dirname "$dest") && sha256sum -c -" < "$inv" \
	| tee "${evidence_dir}/mirror-artifact.log" || die "el contenedor no sirve el artefacto exacto que se pretende promover"
AKB_BASE_URL=http://localhost:8080 node "${REPO_ROOT}/tools/qa-playwright-full-suite.mjs" 2>&1 | tee "${evidence_dir}/qa-local.log" \
	|| die "CERTIFICACIÓN QA ADVERSARIAL FALLIDA en local"
[[ "$(source_digest)" == "$validated_sources" ]] || die "las fuentes cambiaron durante la certificación local"
shasum -a 256 -c "${evidence_dir}/artifact.sha256" || die "el paquete cambió durante la certificación local"
verify_local_candidate || die "el candidato local cambió después de su validación"
shasum -a 256 "${evidence_dir}"/* > "${evidence_dir}/validation.sha256"

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
	local restored=""
	if [[ "$swap_started" == 1 && "$deployment_succeeded" != 1 ]]; then
		printf '\nRestaurando el árbol anterior tras fallo de validación...\n' >&2
			if ! restored="$(ssh -o BatchMode=yes "$SSH_HOST" "set -e; cd ${WEBROOT}
			if [ -d ${stage_root}/${slug}.prev ]; then
			# A recovery outage must not expire after WordPress' ten-minute limit.
			# Preserve maintenance owned by another operation, including one that
			# appeared after the initial swap.
			if [ ! -e .maintenance ] || grep -Fq 'akb-deploy:${deploy_token}' .maintenance; then
				printf '<?php \$upgrading = time(); /* akb-deploy:${deploy_token} */ ?>' > .maintenance
			fi
			if [ -d ${dest} ]; then mv ${dest} ${stage_root}/${slug}.failed; fi
			mv ${stage_root}/${slug}.prev ${dest}
			rm -rf ${stage_root}/${slug}.failed
				echo restored
			fi
				if [ ! -d ${stage_root}/${slug}.prev ] && [ ! -d ${dest} ]; then exit 1; fi")"; then
			printf 'ERROR: restauración remota incompleta; recuperar desde %s\n' "$backup_dir" >&2
			return 1
		fi
			if [[ "$restored" != *restored* ]] || purge_live_caches rollback; then
				ssh -o BatchMode=yes "$SSH_HOST" "set -e; cd ${WEBROOT}; if [ -f .maintenance ] && grep -Fq 'akb-deploy:${deploy_token}' .maintenance; then rm -f .maintenance; fi" \
					|| { printf 'ERROR: no se pudo retirar el mantenimiento propio tras la recuperación.\n' >&2; result=1; }
			else
				printf 'ERROR: cachés de rollback pendientes; mantenimiento conservado, recuperación local %s; evidencia %s\n' "$backup_dir" "$evidence_dir" >&2
				result=1
			fi
	fi
	if [[ "$lock_acquired" == 1 ]]; then
			ssh -o BatchMode=yes "$SSH_HOST" "set -e; rm -rf ${remote_tmp} ${stage_root}; rmdir ${lock_path}; [ ! -e ${remote_tmp} ] && [ ! -e ${stage_root} ] && [ ! -e ${lock_path} ]" \
			|| { printf 'ERROR: no se pudo limpiar el staging remoto.\n' >&2; return 1; }
			lock_acquired=0
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

[[ "$(source_digest)" == "$validated_sources" ]] || die "las fuentes cambiaron antes del cambio en vivo"
shasum -a 256 -c "${evidence_dir}/validation.sha256" || die "la evidencia de validación fue alterada"
python3 "${REPO_ROOT}/tools/qa-fresh-auditor-gate.py" --verify-receipt "${evidence_dir}/fresh-auditor.json" --source-digest "$validated_sources" || die "recibo de auditoría fresca inválido antes del cambio en vivo"

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
purge_live_caches candidate || die "la purga de cachés falló; se restaurará el árbol anterior"

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

hdr "Certificación QA Adversarial en Vivo (Playwright)"
AKB_BASE_URL=https://akibara.cl node "${REPO_ROOT}/tools/qa-playwright-full-suite.mjs" 2>&1 | tee "${evidence_dir}/qa-production.log" \
	|| die "CERTIFICACIÓN QA ADVERSARIAL FALLIDA en producción"
[[ "$(source_digest)" == "$validated_sources" ]] || die "las fuentes cambiaron durante la certificación pública"
shasum -a 256 -c "${evidence_dir}/artifact.sha256" || die "el paquete cambió durante la certificación pública"

hdr "Sincronización continua de Documentación y Segundo Cerebro"
node "${REPO_ROOT}/tools/sync-notebooklm.mjs" || die "no se completó la sincronización posterior al despliegue"
deployment_succeeded=1
cleanup || die "no se completó la limpieza verificada del despliegue"
trap - EXIT
shasum -a 256 "${evidence_dir}"/*.log > "${evidence_dir}/results.sha256"

printf '\n✓ %s %s instalado y CERTIFICADO con QA Adversarial en vivo. Evidencia %s\n' "$slug" "$version" "$evidence_dir"
