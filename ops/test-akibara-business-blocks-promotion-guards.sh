#!/usr/bin/env bash

set -Eeuo pipefail
IFS=$'\n\t'
umask 077

script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
repo_root="$(cd -- "${script_dir}/.." && pwd)"
pipeline="${script_dir}/akibara-business-blocks-production-promotion.sh"
packager="${repo_root}/tools/akibara-releases/package-business-blocks-production.sh"
docker_gate="${repo_root}/tools/akibara-releases/docker-business-blocks-production.sh"
pudo_data_gate="${script_dir}/akibara-business-blocks-pudo-docker-gate.php"

die() {
	printf 'FAIL: %s\n' "$*" >&2
	exit 1
}

for required in bash grep python3; do
	command -v "${required}" >/dev/null 2>&1 || die "falta ${required}"
done
for file in "${pipeline}" "${packager}" "${docker_gate}" "${pudo_data_gate}"; do
	[[ -f "${file}" && ! -L "${file}" ]] || die "falta archivo regular: ${file}"
done
bash -n "${pipeline}"
bash -n "${packager}"
bash -n "${docker_gate}"
grep -Fq 'akibara-business-blocks-pudo-docker-gate.php' "${docker_gate}" \
	|| die "gate Docker no ejecuta la regresión PUDO MariaDB real"
grep -Fq 'test_rows_remaining == 0' "${docker_gate}" \
	|| die "gate Docker no exige cero filas PUDO de prueba"
grep -Fq 'delete_malformed_if_unchanged' "${pudo_data_gate}" \
	|| die "gate PUDO no reproduce la carrera read/renew/delete exacta"
grep -Fq 'business_fingerprint_stable' "${pudo_data_gate}" \
	|| die "gate PUDO no protege el fingerprint Business"
grep -Fq 'package_hash_clear' "${pudo_data_gate}" \
	|| die "gate PUDO no exige rotación A -> B -> clear"

bash "${pipeline}" --help \
	| grep -Fq 'Docker local -> producción' \
	|| die "--help depende indebidamente de un contrato sellado"
grep -Fq 'verify_source_tree_scope "${component}"' "${packager}" \
	|| die "empaquetador no ejecuta el allowlist fuente completo"

if grep -Eq -- \
	'akibara-(business|cart-checkout-blocks):/var/www/html/wp-content/plugins/[^[:space:]]+:ro' \
	"${docker_gate}"; then
	die "gate Docker conserva el candidato de runtime en solo lectura"
fi
grep -Fq \
	'akibara-business:/var/www/html/wp-content/plugins/akibara-business"' \
	"${docker_gate}" || die "gate Docker no monta Business candidato RW"
grep -Fq \
	'akibara-cart-checkout-blocks:/var/www/html/wp-content/plugins/akibara-cart-checkout-blocks"' \
	"${docker_gate}" || die "gate Docker no monta Blocks candidato RW"
grep -Fq 'post-runtime.sha256' "${docker_gate}" \
	|| die "gate Docker no regenera inventarios tras ejecutar el candidato"
grep -Fq 'El runtime Docker alteró el inventario exacto' "${docker_gate}" \
	|| die "gate Docker no compara el inventario posterior con el artefacto"

candidate_logs_line="$(
	grep -n 'log_delta="$(docker logs' "${docker_gate}" \
		| head -n 1 | cut -d: -f1
)"
candidate_stop_line="$(
	grep -n '^[[:space:]]*docker stop --time 10 "${candidate_name}"' "${docker_gate}" \
		| tail -n 1 | cut -d: -f1
)"
post_inventory_line="$(
	grep -n 'post-runtime.sha256' "${docker_gate}" \
		| head -n 1 | cut -d: -f1
)"
original_start_line="$(
	grep -n '^[[:space:]]*docker compose .* start wordpress' "${docker_gate}" \
		| tail -n 1 | cut -d: -f1
)"
[[ -n "${candidate_logs_line}" && -n "${candidate_stop_line}"
	&& -n "${post_inventory_line}" && -n "${original_start_line}"
	&& "${candidate_logs_line}" -lt "${candidate_stop_line}"
	&& "${candidate_stop_line}" -lt "${post_inventory_line}"
	&& "${post_inventory_line}" -lt "${original_start_line}" ]] \
	|| die "inventario post-runtime no queda entre ejecución y restauración Docker"

scratch="$(mktemp -d "${TMPDIR:-/tmp}/akibara-promotion-guards.XXXXXX")"
cleanup() {
	case "${scratch:-}" in
		*/akibara-promotion-guards.*) rm -rf -- "${scratch}" ;;
	esac
}
trap cleanup EXIT HUP INT TERM

fake_bin="${scratch}/fake-bin"
mkdir -p -- "${fake_bin}"
cat > "${fake_bin}/stat" <<'EOF'
#!/usr/bin/env bash
set -Eeuo pipefail
[[ "${1:-}" == -c && $# == 3 ]]
case "$2" in
	%i)
		python3 -c 'import os, sys; print(os.stat(sys.argv[1]).st_ino)' "$3"
		;;
	%s)
		python3 -c 'import os, sys; print(os.stat(sys.argv[1]).st_size)' "$3"
		;;
	*)
		exit 2
		;;
esac
EOF
chmod 0700 "${fake_bin}/stat"

AKB_BUSINESS_BLOCKS_PROMOTION_LIBRARY_ONLY=1
export AKB_BUSINESS_BLOCKS_PROMOTION_LIBRARY_ONLY
# shellcheck source=ops/akibara-business-blocks-production-promotion.sh
source "${pipeline}"
unset AKB_BUSINESS_BLOCKS_PROMOTION_LIBRARY_ONLY

log_fixture="${scratch}/wordpress-error.log"
log_baseline="${scratch}/logs-prefix-baseline.tsv"
printf 'stable prefix\n' > "${log_fixture}"
AKB_LOG_PATHS="${log_fixture}"
ssh_logs_read() {
	PATH="${fake_bin}:${PATH}" \
		AKB_LOG_PATHS="${AKB_LOG_PATHS}" \
		bash -s <<< "$1"
}
capture_log_baseline "${log_baseline}"
printf 'safe append\n' >> "${log_fixture}"
verify_log_delta "${log_baseline}" "${scratch}/logs-safe.tsv"
printf 'mutate prefix\nsafe append\n' > "${log_fixture}"
if (verify_log_delta \
	"${log_baseline}" "${scratch}/logs-rewritten.tsv" \
	> "${scratch}/logs-rewritten.out" 2>&1); then
	die "gate de logs aceptó una reescritura in-place del prefijo"
fi

printf 'PASS: help pending, mounts RW, inventario post-runtime y hash de prefijo fail-closed\n'
