#!/usr/bin/env bash

set -Eeuo pipefail
IFS=$'\n\t'
umask 077

script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
repo_root="$(cd -- "${script_dir}/../.." && pwd)"
compose_file="${repo_root}/compose.yaml"
packager="${script_dir}/package-business-blocks-production.sh"
release_test="${script_dir}/test-business-blocks-production-release.sh"
commerce_fingerprint="${repo_root}/ops/akibara-protected-commerce-fingerprint.php"
search_fingerprint="${repo_root}/ops/akibara-search-state-fingerprint.php"
pudo_data_gate="${repo_root}/ops/akibara-business-blocks-pudo-docker-gate.php"

die() {
	printf 'ERROR: %s\n' "$*" >&2
	exit 1
}

for command_name in curl docker jq node rg shasum unzip; do
	command -v "${command_name}" >/dev/null 2>&1 \
		|| die "Falta el comando requerido: ${command_name}"
done
for required in \
	"${compose_file}" "${packager}" "${release_test}" \
	"${commerce_fingerprint}" "${search_fingerprint}" "${pudo_data_gate}"; do
	[[ -f "${required}" && ! -L "${required}" ]] \
		|| die "Falta archivo regular: ${required}"
done

wordpress_container="$(docker compose -f "${compose_file}" ps -q wordpress)"
[[ -n "${wordpress_container}" ]] \
	|| die "El contenedor WordPress Docker no existe"
[[ "$(docker inspect --format '{{.State.Running}}' "${wordpress_container}")" == true ]] \
	|| die "El contenedor WordPress Docker no está activo"

scratch="$(mktemp -d "${TMPDIR:-/tmp}/akibara-business-blocks-docker.XXXXXX")"
candidate_name="akibara-business-blocks-candidate-$$"
candidate_started=0
original_stopped=0

cleanup() {
	local exit_status=$?
	set +e
	if ((candidate_started)); then
		docker stop --time 10 "${candidate_name}" >/dev/null 2>&1 || true
	fi
	if ((original_stopped)); then
		docker compose -f "${compose_file}" start wordpress >/dev/null 2>&1 || true
	fi
	case "${scratch:-}" in
		*/akibara-business-blocks-docker.*) rm -rf -- "${scratch}" ;;
	esac
	exit "${exit_status}"
}
trap cleanup EXIT HUP INT TERM

capture_local_fingerprint() {
	local php_file="$1" output="$2" php_code
	php_code="$(sed '1{/^<?php$/d;}' "${php_file}")"
	docker compose -f "${compose_file}" --profile tools run --rm -T cli \
		wp --path=/var/www/html eval "${php_code}" \
		| LC_ALL=C sort > "${output}"
	[[ -s "${output}" ]] || die "Fingerprint Docker vacío: ${php_file}"
}

printf 'Gate exacto de artefactos Business + Blocks...\n'
bash "${release_test}"

printf 'Gate de arquitectura y suite fuente completa...\n'
bash "${script_dir}/docker-checkout.sh"

capture_local_fingerprint "${commerce_fingerprint}" "${scratch}/commerce-before.tsv"
capture_local_fingerprint "${search_fingerprint}" "${scratch}/search-before.tsv"

mkdir -p -- "${scratch}/artifacts" "${scratch}/runtime"
bash "${packager}" --output-dir "${scratch}/artifacts" >/dev/null
unzip -q "${scratch}/artifacts/akibara-business-1.98.2.zip" \
	-d "${scratch}/runtime"
unzip -q "${scratch}/artifacts/akibara-cart-checkout-blocks-2.1.6.zip" \
	-d "${scratch}/runtime"

network="$(
	docker inspect \
		--format '{{range $name, $_ := .NetworkSettings.Networks}}{{println $name}}{{end}}' \
		"${wordpress_container}" | head -n 1
)"
image="$(docker inspect --format '{{.Config.Image}}' "${wordpress_container}")"
[[ -n "${network}" && -n "${image}" ]] \
	|| die "No se pudo resolver red/imagen del WordPress Docker"

docker compose -f "${compose_file}" stop wordpress >/dev/null
original_stopped=1

docker run --detach --rm \
	--name "${candidate_name}" \
	--network "${network}" \
	--publish 127.0.0.1:8080:80 \
	--volumes-from "${wordpress_container}" \
	--volume "${scratch}/runtime/akibara-business:/var/www/html/wp-content/plugins/akibara-business" \
	--volume "${scratch}/runtime/akibara-cart-checkout-blocks:/var/www/html/wp-content/plugins/akibara-cart-checkout-blocks" \
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
		--connect-timeout 2 --max-time 5 \
		http://localhost:8080/; then
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

[[ "$(docker run "${cli_args[@]}" plugin get akibara-business --field=version)" == 1.98.2 ]] \
	|| die "Runtime candidato no cargó Business 1.98.2"
[[ "$(docker run "${cli_args[@]}" plugin get akibara-cart-checkout-blocks --field=version)" == 2.1.6 ]] \
	|| die "Runtime candidato no cargó Blocks 2.1.6"
docker run "${cli_args[@]}" plugin is-active akibara-business
docker run "${cli_args[@]}" plugin is-active akibara-cart-checkout-blocks
docker run "${cli_args[@]}" eval '
if (! class_exists("\\Akibara\\Business\\Plugin")
    || ! class_exists("\\Akibara\\CartCheckoutBlocks\\Plugin")) {
    throw new RuntimeException("Akibara release classes are unavailable");
}
echo "AKIBARA_CLASSES_PASS\n";
'
docker run \
	--rm \
	--network "${network}" \
	--volumes-from "${candidate_name}" \
	--user 33:33 \
	--volume "${pudo_data_gate}:/tmp/akibara-pudo-docker-gate.php:ro" \
	--env WORDPRESS_DB_HOST=db \
	--env WORDPRESS_DB_NAME=akibara \
	--env WORDPRESS_DB_USER=akibara \
	--env WORDPRESS_DB_PASSWORD=akibara_local \
	--env WP_ENVIRONMENT_TYPE=local \
	wordpress:cli-php8.4 \
	wp --path=/var/www/html eval-file /tmp/akibara-pudo-docker-gate.php --use-include \
	| tee "${scratch}/pudo-data-gate.json"
jq -e '
  .schema_version == 1
  and .gate == "akibara-business-blocks-pudo-docker"
  and .status == "PASS"
  and .exact_malformed_self_heal == true
  and .renewed_row_preserved == true
  and .new_state_created == true
  and .business_fingerprint_stable == true
  and .test_rows_remaining == 0
  and ([.package_hash_a, .package_hash_b, .package_hash_clear]
    | unique | length) == 3
' "${scratch}/pudo-data-gate.json" >/dev/null \
	|| die "Gate PUDO MariaDB/package hash rechazado"

cookie_jar="${scratch}/store-api.cookies"
for path in / /tienda/ /carrito/ /checkout/; do
	http_code="$(
		curl --silent --show-error --location \
			--connect-timeout 10 --max-time 45 \
			--output /dev/null --write-out '%{http_code}' \
			"http://localhost:8080${path}"
	)"
	[[ "${http_code}" == 200 ]] \
		|| die "Docker candidato esperaba 200 en ${path}; recibió ${http_code}"
done
curl --silent --show-error \
	--connect-timeout 10 --max-time 45 \
	--cookie-jar "${cookie_jar}" --cookie "${cookie_jar}" \
	http://localhost:8080/wp-json/wc/store/v1/cart \
	> "${scratch}/store-api-cart.json"
jq -e '
  type == "object"
  and has("items")
  and has("totals")
  and has("extensions")
' "${scratch}/store-api-cart.json" >/dev/null \
	|| die "Store API cart no respondió su contrato base"

log_delta="$(docker logs "${candidate_name}" 2>&1 || true)"
if rg -i 'PHP (Fatal|Parse|Warning)|Uncaught|Allowed memory|Maximum execution|timed out' \
	<<< "${log_delta}"; then
	die "El runtime candidato emitió errores en Docker"
fi

docker stop --time 10 "${candidate_name}" >/dev/null
candidate_started=0
(
	cd -- "${scratch}/runtime"
	for slug in akibara-business akibara-cart-checkout-blocks; do
		find "${slug}" -type f -print | LC_ALL=C sort \
			> "${scratch}/${slug}.post-runtime.files"
		while IFS= read -r runtime_file; do
			printf '%s  %s\n' \
				"$(shasum -a 256 "${runtime_file}" | awk '{print $1}')" \
				"${runtime_file}"
		done < "${scratch}/${slug}.post-runtime.files" \
			> "${scratch}/${slug}.post-runtime.sha256"
		cmp -s \
			"${scratch}/artifacts/${slug}-$(
				case "${slug}" in
					akibara-business) printf '1.98.2' ;;
					akibara-cart-checkout-blocks) printf '2.1.6' ;;
				esac
			).files.sha256" \
			"${scratch}/${slug}.post-runtime.sha256" \
			|| die "El runtime Docker alteró el inventario exacto de ${slug}"
	done
)
for runtime_root in \
	"${scratch}/runtime/akibara-business" \
	"${scratch}/runtime/akibara-cart-checkout-blocks"; do
	[[ -d "${runtime_root}" && ! -L "${runtime_root}" ]] \
		|| die "Runtime candidato ausente o inseguro tras ejecución: ${runtime_root}"
	test -z "$(find "${runtime_root}" ! -type d ! -type f -print -quit)" \
		|| die "Runtime candidato creó enlaces o entradas especiales"
done
docker compose -f "${compose_file}" start wordpress >/dev/null
original_stopped=0

capture_local_fingerprint "${commerce_fingerprint}" "${scratch}/commerce-after.tsv"
capture_local_fingerprint "${search_fingerprint}" "${scratch}/search-after.tsv"
cmp -s "${scratch}/commerce-before.tsv" "${scratch}/commerce-after.tsv" \
	|| die "El gate Docker alteró el fingerprint comercial"
cmp -s "${scratch}/search-before.tsv" "${scratch}/search-after.tsv" \
	|| die "El gate Docker alteró Search"

printf 'PASS: runtime exacto Business 1.98.2 + Blocks 2.1.6 en Docker, HTTP/Store API y fingerprints intactos\n'
