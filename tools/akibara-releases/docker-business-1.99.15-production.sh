#!/usr/bin/env bash

set -Eeuo pipefail
IFS=$'\n\t'
umask 077

script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
repo_root="$(cd -- "${script_dir}/../.." && pwd)"
compose_file="${repo_root}/compose.yaml"
packager="${script_dir}/package-business-1.99.15-production.sh"
qa_gate="${repo_root}/tools/akibara-business/qa.sh"
commerce_fingerprint="${repo_root}/ops/akibara-protected-commerce-fingerprint.php"
search_fingerprint="${repo_root}/ops/akibara-search-state-fingerprint.php"
playwright_cli="${AKB_PLAYWRIGHT_CLI:-${HOME}/.codex/skills/playwright/scripts/playwright_cli.sh}"

die() {
	printf 'ERROR: %s\n' "$*" >&2
	exit 1
}

for command_name in curl docker jq node npx rg shasum unzip; do
	command -v "${command_name}" >/dev/null 2>&1 \
		|| die "Falta el comando requerido: ${command_name}"
done
[[ -x "${playwright_cli}" && ! -L "${playwright_cli}" ]] \
	|| die "Falta el wrapper Playwright CLI: ${playwright_cli}"
for required in \
	"${compose_file}" "${packager}" "${qa_gate}" \
	"${commerce_fingerprint}" "${search_fingerprint}"; do
	[[ -f "${required}" && ! -L "${required}" ]] \
		|| die "Falta archivo regular: ${required}"
done

wordpress_container="$(docker compose -f "${compose_file}" ps -q wordpress)"
[[ -n "${wordpress_container}" ]] || die "El contenedor WordPress Docker no existe"
[[ "$(docker inspect --format '{{.State.Running}}' "${wordpress_container}")" == true ]] \
	|| die "El contenedor WordPress Docker no está activo"

scratch="$(mktemp -d "${TMPDIR:-/tmp}/akibara-business-1.99.15-docker.XXXXXX")"
candidate_name="akibara-business-1-99-15-candidate-$$"
candidate_started=0
original_stopped=0
playwright_started=0
playwright_session="akibara-business-1-99-15-$$"

# Cleanup IDEMPOTENTE y sólo en EXIT. El patrón heredado
# (`trap cleanup EXIT HUP INT TERM` con `exit "${exit_status}"` dentro) tenía dos
# defectos: ante una señal el `$?` observado es 0 —así que un Ctrl-C salía con
# éxito— y ese `exit` re-dispara EXIT, ejecutando el cleanup dos veces. Los
# handlers de señal ahora sólo fijan el código 128+n y dejan que EXIT limpie una
# única vez [Codex ronda 2, P1-G].
cleanup_done=0
cleanup() {
	local exit_status=$?
	if ((cleanup_done)); then
		return
	fi
	cleanup_done=1
	set +e
	if ((playwright_started)) && [[ -d "${scratch}/playwright" ]]; then
		(
			cd -- "${scratch}/playwright"
			"${playwright_cli}" -s="${playwright_session}" close >/dev/null 2>&1
		) || true
	fi
	if ((candidate_started)); then
		docker stop --time 10 "${candidate_name}" >/dev/null 2>&1 || true
	fi
	if ((original_stopped)); then
		docker compose -f "${compose_file}" start wordpress >/dev/null 2>&1 || true
	fi
	case "${scratch:-}" in
		*/akibara-business-1.99.15-docker.*) rm -rf -- "${scratch}" ;;
	esac
	# NO se re-invoca `exit` acá: hacerlo re-dispara el trap EXIT y ejecuta el
	# cleanup dos veces. El código de salida lo fija quien llamó (o el handler
	# de señal, con 128+n).
	return "${exit_status}"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
trap 'exit 129' HUP

capture_local_fingerprint() {
	local php_file="$1" output="$2" php_code
	php_code="$(sed '1{/^<?php$/d;}' "${php_file}")"
	docker compose -f "${compose_file}" --profile tools run --rm -T cli \
		wp --path=/var/www/html eval "${php_code}" \
		| LC_ALL=C sort > "${output}"
	[[ -s "${output}" ]] || die "Fingerprint Docker vacío: ${php_file}"
}

printf 'QA fuente completo Business...\n'
bash "${qa_gate}"
printf 'Artefacto sellado y reproducible Business 1.99.15...\n'
bash "${packager}" --verify-only

capture_local_fingerprint "${commerce_fingerprint}" "${scratch}/commerce-before.tsv"
capture_local_fingerprint "${search_fingerprint}" "${scratch}/search-before.tsv"

mkdir -p -- "${scratch}/artifacts" "${scratch}/runtime"
bash "${packager}" --output-dir "${scratch}/artifacts" >/dev/null
unzip -q "${scratch}/artifacts/akibara-business-1.99.15.zip" \
	-d "${scratch}/runtime"

network="$(
	docker inspect \
		--format '{{range $name, $_ := .NetworkSettings.Networks}}{{println $name}}{{end}}' \
		"${wordpress_container}" | head -n 1
)"
image="$(docker inspect --format '{{.Config.Image}}' "${wordpress_container}")"
[[ -n "${network}" && -n "${image}" ]] \
	|| die "No se pudo resolver red o imagen del WordPress Docker"

docker compose -f "${compose_file}" stop wordpress >/dev/null
original_stopped=1

docker run --detach --rm \
	--name "${candidate_name}" \
	--network "${network}" \
	--publish 127.0.0.1:8080:80 \
	--volumes-from "${wordpress_container}" \
	--volume "${scratch}/runtime/akibara-business:/var/www/html/wp-content/plugins/akibara-business" \
	--env WORDPRESS_DB_HOST=db \
	--env WORDPRESS_DB_NAME=akibara \
	--env WORDPRESS_DB_USER=akibara \
	--env WORDPRESS_DB_PASSWORD=akibara_local \
	--env WP_ENVIRONMENT_TYPE=local \
	"${image}" >/dev/null
candidate_started=1

http_ready=0
for ((_attempt = 1; _attempt <= 30; _attempt++)); do
	if curl --silent --show-error --output /dev/null \
		--connect-timeout 2 --max-time 5 http://localhost:8080/; then
		http_ready=1
		break
	fi
	sleep 2
done
((http_ready)) || {
	docker logs "${candidate_name}" >&2 || true
	die "El runtime candidato no quedó disponible"
}

cli_args=(
	--rm
	--network "${network}"
	--volumes-from "${candidate_name}"
	--user 33:33
	--env WORDPRESS_DB_HOST=db
	--env WORDPRESS_DB_NAME=akibara
	--env WORDPRESS_DB_USER=akibara
	--env WORDPRESS_DB_PASSWORD=akibara_local
	--env WP_ENVIRONMENT_TYPE=local
	wordpress:cli-php8.4
	wp --path=/var/www/html
)

[[ "$(docker run "${cli_args[@]}" plugin get akibara-business --field=version)" == 1.99.15 ]] \
	|| die "Runtime candidato no cargó Business 1.99.15"
docker run "${cli_args[@]}" plugin is-active akibara-business
docker run "${cli_args[@]}" eval '
if (! class_exists("\\Akibara\\Business\\Plugin")
    || ! class_exists("\\Akibara\\Business\\Modules\\OrderPreventa\\ReadyToShipEmail")
    || ! class_exists("\\Akibara\\Business\\Modules\\TaxonomyDiscount\\AuthoritativeReader")) {
    throw new RuntimeException("Clases Business 1.99.15 no disponibles");
}
echo "AKIBARA_BUSINESS_CLASSES_PASS\n";
'

for path in / /tienda/ /devoluciones/ /manga/ /manga/page/2/; do
	http_code="$(
		curl --silent --show-error --location \
			--connect-timeout 10 --max-time 45 \
			--retry 3 --retry-delay 1 --retry-all-errors \
			--output /dev/null --write-out '%{http_code}' \
			"http://localhost:8080${path}"
	)"
	[[ "${http_code}" == 200 ]] \
		|| die "Docker candidato esperaba 200 en ${path}; recibió ${http_code}"
done

curl --silent --show-error --connect-timeout 10 --max-time 45 \
	--retry 3 --retry-delay 1 --retry-all-errors \
	http://localhost:8080/ > "${scratch}/home.html"
curl --silent --show-error --connect-timeout 10 --max-time 45 \
	--retry 3 --retry-delay 1 --retry-all-errors \
	http://localhost:8080/devoluciones/ > "${scratch}/returns.html"
curl --silent --show-error --connect-timeout 10 --max-time 45 \
	--retry 3 --retry-delay 1 --retry-all-errors \
	http://localhost:8080/manga/ > "${scratch}/manga.html"
curl --silent --show-error --connect-timeout 10 --max-time 45 \
	--retry 3 --retry-delay 1 --retry-all-errors \
	'http://localhost:8080/manga/?orderby=price' > "${scratch}/manga-query.html"
curl --silent --show-error --connect-timeout 10 --max-time 45 \
	--retry 3 --retry-delay 1 --retry-all-errors \
	http://localhost:8080/manga/page/2/ > "${scratch}/manga-page-2.html"
node - "${scratch}/home.html" "${scratch}/returns.html" <<'JS'
const fs = require('fs');

function schemaNodes(path) {
  const html = fs.readFileSync(path, 'utf8');
  const scripts = [...html.matchAll(
    /<script[^>]+type=["']application\/ld\+json["'][^>]*>([\s\S]*?)<\/script>/gi
  )];
  return scripts.flatMap((match) => {
    const root = JSON.parse(match[1]);
    return Array.isArray(root['@graph']) ? root['@graph'] : [root];
  });
}

const homeOwners = schemaNodes(process.argv[2]).filter((node) =>
  node && typeof node === 'object' &&
  Object.prototype.hasOwnProperty.call(node, 'hasMerchantReturnPolicy')
);
if (homeOwners.length !== 1) {
  throw new Error(`portada esperaba una política; recibió ${homeOwners.length}`);
}
const ownerTypes = Array.isArray(homeOwners[0]['@type'])
  ? homeOwners[0]['@type'] : [homeOwners[0]['@type']];
if (!ownerTypes.includes('Organization') && !ownerTypes.includes('OnlineStore')) {
  throw new Error('la política no pertenece a Organization/OnlineStore');
}
const policy = homeOwners[0].hasMerchantReturnPolicy;
if (policy['@type'] !== 'MerchantReturnPolicy'
    || policy.merchantReturnLink !== 'http://localhost:8080/devoluciones/') {
  throw new Error('MerchantReturnPolicy local divergente');
}
const returnOwners = schemaNodes(process.argv[3]).filter((node) =>
  node && typeof node === 'object' &&
  Object.prototype.hasOwnProperty.call(node, 'hasMerchantReturnPolicy')
);
if (returnOwners.length !== 0) {
  throw new Error('la página devoluciones repite MerchantReturnPolicy');
}
process.stdout.write('BUSINESS_SCHEMA_RUNTIME_PASS\n');
JS

node - \
	"${scratch}/manga.html" \
	"${scratch}/manga-query.html" \
	"${scratch}/manga-page-2.html" <<'JS'
const fs = require('fs');

const canonical = fs.readFileSync(process.argv[2], 'utf8');
const query = fs.readFileSync(process.argv[3], 'utf8');
const pageTwo = fs.readFileSync(process.argv[4], 'utf8');
const count = (html, token) => html.split(token).length - 1;

if (count(canonical, 'akb-archive-desc__lead--manga') !== 1
    || count(canonical, 'akb-archive-desc--manga') !== 1) {
  throw new Error('Manga canónico no contiene exactamente un lead y un resto');
}
for (const [label, html] of [['query', query], ['page/2', pageTwo]]) {
  if (count(html, 'akb-archive-desc__lead--manga') !== 0
      || count(html, 'akb-archive-desc--manga') !== 0
      || /<div class="[^"]*term-description/i.test(html)) {
    throw new Error(`${label} recibió una copia del split canónico de Manga`);
  }
}
if (/FAQPage/i.test(canonical)) {
  throw new Error('El split editorial no debe emitir FAQ schema');
}

const lead = canonical.match(
  /<div class="[^"]*akb-archive-desc__lead--manga[^"]*">([\s\S]*?)<\/div>/i
);
const rest = canonical.match(
  /<section class="[^"]*akb-archive-desc--manga[^"]*"[^>]*>([\s\S]*?)<\/section>/i
);
if (!lead || !rest) {
  throw new Error('No fue posible aislar los dos fragmentos de Manga');
}
const allowed = new Set(['p', 'a', 'strong', 'em', 'br', 'ul', 'ol', 'li']);
for (const [label, fragment] of [['lead', lead[1]], ['rest', rest[1]]]) {
  for (const match of fragment.matchAll(/<\/?([a-z0-9]+)\b[^>]*>/gi)) {
    if (!allowed.has(match[1].toLowerCase())) {
      throw new Error(`${label} contiene tag fuera del allowlist: ${match[1]}`);
    }
  }
}
if ((lead[1].match(/<p\b/gi) || []).length !== 1) {
  throw new Error('El lead de Manga debe contener exactamente el primer párrafo');
}
const plain = (html) => html.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
const leadText = plain(lead[1]);
const restText = plain(rest[1]);
if (!leadText || !restText || restText.includes(leadText)) {
  throw new Error('El contenido de Manga está vacío o duplicado entre lead y resto');
}
process.stdout.write('BUSINESS_MANGA_HTML_CONTRACT_PASS\n');
JS

mkdir -p -- "${scratch}/playwright"
(
	cd -- "${scratch}/playwright"
	"${playwright_cli}" -s="${playwright_session}" open \
		http://localhost:8080/manga/ >/dev/null
)
playwright_started=1

# El snippet de `run-code` va como FUNCIÓN async: el CLI de Playwright vigente
# envuelve el argumento en `await (<code>)(page)`, así que un `await` de nivel
# superior —la forma que traía el gate 1.99.10— revienta con
# `SyntaxError: Unexpected identifier 'page'` y tumbaba la etapa visual entera
# (verificado contra el sitio local, sin relación con el artefacto).
render_manga_viewport() {
	local width="$1" height="$2" max_grid_y="$3" label="$4" result_file
	result_file="${scratch}/playwright/${label}.json"
	(
		cd -- "${scratch}/playwright"
		"${playwright_cli}" -s="${playwright_session}" resize \
			"${width}" "${height}" >/dev/null
		"${playwright_cli}" -s="${playwright_session}" reload >/dev/null
		"${playwright_cli}" -s="${playwright_session}" run-code \
			'async () => { await page.waitForLoadState("networkidle"); await page.evaluate(() => document.fonts.ready); }' \
			>/dev/null
		"${playwright_cli}" -s="${playwright_session}" snapshot >/dev/null
		"${playwright_cli}" -s="${playwright_session}" screenshot >/dev/null
		"${playwright_cli}" -s="${playwright_session}" --raw eval '() => {
  const lead = document.querySelector(".akb-archive-desc__lead--manga");
  const grid = document.querySelector("ul.products");
  const rest = document.querySelector(".akb-archive-desc--manga");
  const y = (element) => element
    ? Math.round((element.getBoundingClientRect().top + scrollY) * 100) / 100
    : null;
  const allowed = new Set(["p", "a", "strong", "em", "br", "ul", "ol", "li"]);
  const descendants = lead && rest
    ? [...lead.querySelectorAll("*"), ...rest.querySelectorAll("*")]
    : [];
  const schemaText = [...document.querySelectorAll("script[type=\"application/ld+json\"]")]
    .map((script) => script.textContent || "").join("\n");
  const leadText = lead ? lead.innerText.trim().replace(/\s+/g, " ") : "";
  const restText = rest ? rest.innerText.trim().replace(/\s+/g, " ") : "";
  return {
    viewportWidth: innerWidth,
    viewportHeight: innerHeight,
    leadCount: document.querySelectorAll(".akb-archive-desc__lead--manga").length,
    restCount: document.querySelectorAll(".akb-archive-desc--manga").length,
    leadParagraphs: lead ? lead.querySelectorAll(":scope > p").length : 0,
    leadY: y(lead),
    gridY: y(grid),
    restY: y(rest),
    leadBeforeGrid: Boolean(lead && grid && (lead.compareDocumentPosition(grid) & Node.DOCUMENT_POSITION_FOLLOWING)),
    gridBeforeRest: Boolean(grid && rest && (grid.compareDocumentPosition(rest) & Node.DOCUMENT_POSITION_FOLLOWING)),
    disallowedTags: descendants.filter((element) => !allowed.has(element.tagName.toLowerCase())).length,
    faqSchemaCount: (schemaText.match(/FAQPage/gi) || []).length,
    duplicatedLeadInRest: Boolean(leadText && restText.includes(leadText)),
    horizontalOverflow: Math.max(0, document.documentElement.scrollWidth - innerWidth),
    leadWords: leadText ? leadText.split(/\s+/).length : 0,
    restWords: restText ? restText.split(/\s+/).length : 0
  };
}' > "${result_file}"
	)

	# `leadWords > 0` y NO `> 20`: el contrato sellado exige primer párrafo
	# canónico, no vacío, resto separado y `restWords > leadWords`
	# (`production_invariants.manga_archive_copy`), pero NO fija ningún umbral de
	# palabras — ese `> 20` era un invariante inventado por el harness, no un
	# requisito de contenido, y hacía fallar el gate por una copia productiva
	# perfectamente válida. El resto de las condiciones queda intacto.
	jq -e \
		--argjson width "${width}" \
		--argjson height "${height}" \
		--argjson max_grid_y "${max_grid_y}" '
    .viewportWidth == $width
    and .viewportHeight == $height
    and .leadCount == 1
    and .restCount == 1
    and .leadParagraphs == 1
    and .leadBeforeGrid == true
    and .gridBeforeRest == true
    and (.leadY < .gridY and .gridY < .restY)
    and .gridY < $max_grid_y
    and .disallowedTags == 0
    and .faqSchemaCount == 0
    and .duplicatedLeadInRest == false
    and .horizontalOverflow <= 1
    and .leadWords > 0
    and .restWords > .leadWords
  ' "${result_file}" >/dev/null \
		|| {
			jq . "${result_file}" >&2
			die "Render ${label} de Manga incumplió el contrato UX"
		}
	printf 'PASS: Manga %s %sx%s gridY=%s leadWords=%s restWords=%s\n' \
		"${label}" "${width}" "${height}" \
		"$(jq -r '.gridY' "${result_file}")" \
		"$(jq -r '.leadWords' "${result_file}")" \
		"$(jq -r '.restWords' "${result_file}")"
}

render_manga_viewport 375 812 900 mobile
render_manga_viewport 1440 1000 800 desktop

render_suppressed_manga_variant() {
	local route="$1" label="$2" result_file
	result_file="${scratch}/playwright/${label}.json"
	(
		cd -- "${scratch}/playwright"
		"${playwright_cli}" -s="${playwright_session}" resize 375 812 >/dev/null
		"${playwright_cli}" -s="${playwright_session}" goto \
			"http://localhost:8080${route}" >/dev/null
		"${playwright_cli}" -s="${playwright_session}" run-code \
			'async () => { await page.waitForLoadState("networkidle"); await page.evaluate(() => document.fonts.ready); }' \
			>/dev/null
		"${playwright_cli}" -s="${playwright_session}" snapshot >/dev/null
		"${playwright_cli}" -s="${playwright_session}" screenshot >/dev/null
		"${playwright_cli}" -s="${playwright_session}" --raw eval '() => {
  const grid = document.querySelector("ul.products");
  return {
    viewportWidth: innerWidth,
    viewportHeight: innerHeight,
    leadCount: document.querySelectorAll(".akb-archive-desc__lead--manga").length,
    restCount: document.querySelectorAll(".akb-archive-desc--manga").length,
    nativeDescriptionCount: document.querySelectorAll(".term-description").length,
    gridY: grid ? Math.round((grid.getBoundingClientRect().top + scrollY) * 100) / 100 : null,
    horizontalOverflow: Math.max(0, document.documentElement.scrollWidth - innerWidth)
  };
}' > "${result_file}"
	)
	jq -e '
    .viewportWidth == 375
    and .viewportHeight == 812
    and .leadCount == 0
    and .restCount == 0
    and .nativeDescriptionCount == 0
    and (.gridY != null and .gridY < 700)
    and .horizontalOverflow <= 1
  ' "${result_file}" >/dev/null || {
		jq . "${result_file}" >&2
		die "Variante Manga ${label} volvió a renderizar la descripción"
	}
	printf 'PASS: Manga %s 375x812 gridY=%s copy=0\n' \
		"${label}" "$(jq -r '.gridY' "${result_file}")"
}

render_suppressed_manga_variant '/manga/?orderby=price' query-orderby
render_suppressed_manga_variant '/manga/page/2/' page-2
(
	cd -- "${scratch}/playwright"
	"${playwright_cli}" -s="${playwright_session}" close >/dev/null
)
playwright_started=0

log_delta="$(docker logs "${candidate_name}" 2>&1 || true)"
if rg -i 'PHP (Fatal|Parse|Warning)|Uncaught|Allowed memory|Maximum execution|timed out' \
	<<< "${log_delta}"; then
	die "El runtime candidato emitió errores en Docker"
fi

docker stop --time 10 "${candidate_name}" >/dev/null
candidate_started=0
(
	cd -- "${scratch}/runtime"
	find akibara-business -type f -print | LC_ALL=C sort \
		> "${scratch}/akibara-business.post-runtime.files"
	while IFS= read -r runtime_file; do
		printf '%s  %s\n' \
			"$(shasum -a 256 "${runtime_file}" | awk '{print $1}')" \
			"${runtime_file}"
	done < "${scratch}/akibara-business.post-runtime.files" \
		> "${scratch}/akibara-business.post-runtime.sha256"
)
cmp -s \
	"${scratch}/artifacts/akibara-business-1.99.15.files.sha256" \
	"${scratch}/akibara-business.post-runtime.sha256" \
	|| die "El runtime Docker alteró el inventario exacto de Business"
runtime_root="${scratch}/runtime/akibara-business"
[[ -d "${runtime_root}" && ! -L "${runtime_root}" ]] \
	|| die "Runtime candidato ausente o inseguro tras ejecutar"
test -z "$(find "${runtime_root}" ! -type d ! -type f -print -quit)" \
	|| die "Runtime candidato creó enlaces o entradas especiales"

docker compose -f "${compose_file}" start wordpress >/dev/null
original_stopped=0
capture_local_fingerprint "${commerce_fingerprint}" "${scratch}/commerce-after.tsv"
capture_local_fingerprint "${search_fingerprint}" "${scratch}/search-after.tsv"
cmp -s "${scratch}/commerce-before.tsv" "${scratch}/commerce-after.tsv" \
	|| die "El gate Docker alteró el fingerprint comercial"
cmp -s "${scratch}/search-before.tsv" "${scratch}/search-after.tsv" \
	|| die "El gate Docker alteró Search"

printf 'PASS: Business 1.99.15 exacto en Docker, schema/HTTP/UX y fingerprints intactos\n'
