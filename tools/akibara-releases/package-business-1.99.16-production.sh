#!/usr/bin/env bash

set -Eeuo pipefail
IFS=$'\n\t'
umask 077

script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
repo_root="$(cd -- "${script_dir}/../.." && pwd -P)"
contract_file="${repo_root}/ops/contracts/akibara-business-1.99.16.json"
search_contract="${repo_root}/ops/contracts/akibara-search-0.1.14.json"
source_delta_verifier="${script_dir}/verify-business-production-source-delta.py"
runtime_packager="${script_dir}/package-business-seo.sh"
output_dir="${repo_root}/dist"
verify_only=0
derive_only=0

usage() {
	cat <<'EOF'
Uso:
  bash tools/akibara-releases/package-business-1.99.16-production.sh [opciones]

Opciones:
  --output-dir RUTA  Directorio local para ZIP, inventario y sidecar.
  --verify-only      Construye dos veces y valida contra el contrato sellado.
  --derive-only      Imprime la propuesta reproducible; no sella ni publica.

Mientras el contrato esté draft, sólo --derive-only está permitido. Este
packager produce únicamente Business; la promoción exige además el contrato y
artefacto pareados de Search 0.1.14. Orden de sello: derivar/sellar Business,
derivar Search, ligar allí el SHA-256 del contrato Business y sellar Search;
después ambos --verify-only deben pasar.
EOF
}

die() {
	printf 'ERROR: %s\n' "$*" >&2
	exit 1
}

sha256_file() {
	shasum -a 256 "$1" | awk '{print $1}'
}

verify_regular_file() {
	[[ -f "$1" && ! -L "$1" && -s "$1" ]] \
		|| die "Archivo ausente, vacío o inseguro: $1"
}

assert_no_symlink_ancestors() {
	python3 - "$1" <<'PY'
import os
import pathlib
import stat
import sys

path = pathlib.Path(os.path.abspath(sys.argv[1]))
current = pathlib.Path(path.anchor)
missing = False
for part in path.parts[1:]:
    current /= part
    if missing:
        continue
    try:
        mode = os.lstat(current).st_mode
    except FileNotFoundError:
        missing = True
        continue
    if stat.S_ISLNK(mode):
        raise SystemExit(f"padre simbólico rechazado: {current}")
    if current != path and not stat.S_ISDIR(mode):
        raise SystemExit(f"padre no-directorio rechazado: {current}")
PY
}

secure_publish_artifacts() {
	local destination="$1" source_dir="$2"
	shift 2
	python3 - "${destination}" "${source_dir}" "$@" <<'PY'
import errno
import os
import pathlib
import re
import secrets
import stat
import sys


def fail(message):
    raise SystemExit(f"publicación segura Business rechazada: {message}")


def open_directory(path, create):
    absolute = pathlib.Path(os.path.abspath(path))
    if not absolute.is_absolute():
        fail(f"ruta no absoluta: {path!r}")
    flags = os.O_RDONLY | os.O_DIRECTORY | getattr(os, "O_CLOEXEC", 0)
    nofollow = getattr(os, "O_NOFOLLOW", 0)
    descriptor = os.open(absolute.anchor, flags)
    try:
        for component in absolute.parts[1:]:
            if component in ("", ".", ".."):
                fail(f"componente inseguro: {component!r}")
            try:
                child = os.open(component, flags | nofollow, dir_fd=descriptor)
            except FileNotFoundError:
                if not create:
                    fail(f"directorio ausente: {absolute}")
                try:
                    os.mkdir(component, 0o755, dir_fd=descriptor)
                except FileExistsError:
                    pass
                try:
                    child = os.open(component, flags | nofollow, dir_fd=descriptor)
                except OSError as error:
                    fail(f"no se pudo abrir directorio recién creado sin seguir enlaces: {error}")
            except OSError as error:
                if error.errno in (errno.ELOOP, errno.ENOTDIR):
                    fail(f"ancestro simbólico/no-directorio: {absolute}")
                raise
            os.close(descriptor)
            descriptor = child
        return descriptor, str(absolute)
    except BaseException:
        os.close(descriptor)
        raise


def stat_entry(directory_fd, name):
    try:
        return os.stat(name, dir_fd=directory_fd, follow_symlinks=False)
    except FileNotFoundError:
        return None


def read_regular(directory_fd, name, require_single_link=True):
    flags = os.O_RDONLY | getattr(os, "O_CLOEXEC", 0) | getattr(os, "O_NOFOLLOW", 0)
    try:
        descriptor = os.open(name, flags, dir_fd=directory_fd)
    except OSError as error:
        fail(f"archivo inseguro {name!r}: {error}")
    try:
        metadata = os.fstat(descriptor)
        if not stat.S_ISREG(metadata.st_mode):
            fail(f"archivo no regular: {name}")
        if require_single_link and metadata.st_nlink != 1:
            fail(f"alias hardlink rechazado: {name}")
        chunks = []
        while True:
            chunk = os.read(descriptor, 1024 * 1024)
            if not chunk:
                break
            chunks.append(chunk)
        return b"".join(chunks), (metadata.st_dev, metadata.st_ino)
    finally:
        os.close(descriptor)


def unlink_if_identity(directory_fd, name, device, inode):
    metadata = stat_entry(directory_fd, name)
    if metadata is not None and (metadata.st_dev, metadata.st_ino) == (device, inode):
        os.unlink(name, dir_fd=directory_fd)


destination_raw, source_raw, *names = sys.argv[1:]
if not names:
    fail("lista de artefactos vacía")
safe_name = re.compile(r"^[A-Za-z0-9][A-Za-z0-9._-]*$")
if len(names) != len(set(names)) or any(safe_name.fullmatch(name) is None for name in names):
    fail("nombre de artefacto duplicado o con escape")

source_fd, _ = open_directory(source_raw, False)
destination_fd, destination_absolute = open_directory(destination_raw, True)
staged = []
created = []
try:
    sources = {}
    existing = {}
    for name in names:
        payload, _ = read_regular(source_fd, name)
        sources[name] = payload
        metadata = stat_entry(destination_fd, name)
        if metadata is None:
            existing[name] = None
            continue
        if not stat.S_ISREG(metadata.st_mode) or metadata.st_nlink != 1:
            fail(f"destino preexistente inseguro/alias: {name}")
        current, identity = read_regular(destination_fd, name)
        if current != payload:
            fail(f"se rechaza sobrescribir artefacto divergente: {name}")
        existing[name] = identity

    # Se preparan archivos privados dentro del mismo directorio. El enlace final
    # es O_EXCL por semántica de link(2): nunca reemplaza un nombre concurrente.
    for name in names:
        if existing[name] is not None:
            continue
        temporary = f".{name}.akb-stage-{os.getpid()}-{secrets.token_hex(8)}"
        flags = (
            os.O_WRONLY
            | os.O_CREAT
            | os.O_EXCL
            | getattr(os, "O_CLOEXEC", 0)
            | getattr(os, "O_NOFOLLOW", 0)
        )
        descriptor = os.open(temporary, flags, 0o600, dir_fd=destination_fd)
        try:
            payload = sources[name]
            offset = 0
            while offset < len(payload):
                offset += os.write(descriptor, payload[offset:])
            os.fchmod(descriptor, 0o644)
            os.fsync(descriptor)
            metadata = os.fstat(descriptor)
            staged.append((name, temporary, metadata.st_dev, metadata.st_ino))
        finally:
            os.close(descriptor)

    # Segunda foto antes del commit: un alias, reemplazo o aparición concurrente
    # cierra todo antes de enlazar el primer artefacto.
    for name in names:
        metadata = stat_entry(destination_fd, name)
        identity = existing[name]
        if identity is None:
            if metadata is not None:
                fail(f"destino apareció durante el preflight: {name}")
            continue
        if metadata is None or not stat.S_ISREG(metadata.st_mode) or metadata.st_nlink != 1:
            fail(f"destino cambió durante el preflight: {name}")
        if (metadata.st_dev, metadata.st_ino) != identity:
            fail(f"identidad destino cambió durante el preflight: {name}")
        current, current_identity = read_regular(destination_fd, name)
        if current_identity != identity or current != sources[name]:
            fail(f"bytes destino cambiaron durante el preflight: {name}")

    try:
        for name, temporary, device, inode in staged:
            os.link(
                temporary,
                name,
                src_dir_fd=destination_fd,
                dst_dir_fd=destination_fd,
                follow_symlinks=False,
            )
            created.append((name, device, inode))
    except BaseException:
        # Rollback acotado: sólo se retira un nombre que todavía apunta al inode
        # creado por esta invocación; jamás se borra un reemplazo ajeno.
        for name, device, inode in reversed(created):
            unlink_if_identity(destination_fd, name, device, inode)
        raise
    finally:
        for _, temporary, device, inode in staged:
            unlink_if_identity(destination_fd, temporary, device, inode)

    os.fsync(destination_fd)
    created_identities = {name: (device, inode) for name, device, inode in created}
    for name in names:
        current, identity = read_regular(destination_fd, name)
        expected_identity = existing[name] or created_identities.get(name)
        if expected_identity is None or identity != expected_identity or current != sources[name]:
            fail(f"postcondición de identidad/bytes divergente: {name}")
    print(destination_absolute)
finally:
    # También cubre fallos durante staging o el segundo preflight, antes de que
    # el bloque de commit alcance su propio finally.
    for _, temporary, device, inode in staged:
        unlink_if_identity(destination_fd, temporary, device, inode)
    os.close(source_fd)
    os.close(destination_fd)
PY
}

verify_archive_inventory() {
	local archive="$1" inventory="$2" expected_root="$3"
	python3 - "${archive}" "${inventory}" "${expected_root}" <<'PY'
import hashlib
import pathlib
import re
import stat
import sys
import zipfile

archive = pathlib.Path(sys.argv[1])
inventory = pathlib.Path(sys.argv[2])
root = sys.argv[3]
row = re.compile(r"^([a-f0-9]{64})  (" + re.escape(root) + r"/[A-Za-z0-9._/-]+)$")
expected = {}
ordered = []
for line in inventory.read_text(encoding="utf-8").splitlines():
    match = row.fullmatch(line)
    if match is None:
        raise SystemExit(f"línea de inventario inválida: {line!r}")
    name = match.group(2)
    parts = pathlib.PurePosixPath(name).parts
    if not parts or parts[0] != root or any(part in ("", ".", "..") for part in parts):
        raise SystemExit(f"ruta de inventario insegura: {name}")
    if name in expected:
        raise SystemExit(f"ruta de inventario duplicada: {name}")
    expected[name] = match.group(1)
    ordered.append(name)
if not expected or ordered != sorted(ordered):
    raise SystemExit("inventario vacío o fuera de orden canónico")

expected_dirs = {root}
for name in expected:
    parent = pathlib.PurePosixPath(name).parent
    while parent.parts:
        expected_dirs.add(parent.as_posix())
        if parent.as_posix() == root:
            break
        parent = parent.parent

seen = set()
files = {}
with zipfile.ZipFile(archive) as bundle:
    for info in bundle.infolist():
        name = info.filename
        if not name or "\\" in name or name.startswith("/") or "\x00" in name:
            raise SystemExit(f"entrada ZIP insegura: {name!r}")
        parts = pathlib.PurePosixPath(name.rstrip("/")).parts
        if not parts or parts[0] != root or any(part in ("", ".", "..") for part in parts):
            raise SystemExit(f"entrada ZIP fuera del slug: {name!r}")
        if name in seen:
            raise SystemExit(f"entrada ZIP duplicada: {name}")
        seen.add(name)
        mode = (info.external_attr >> 16) & 0xFFFF
        kind = stat.S_IFMT(mode)
        if info.is_dir():
            if kind not in (0, stat.S_IFDIR) or name.rstrip("/") not in expected_dirs:
                raise SystemExit(f"directorio ZIP inesperado: {name}")
            continue
        if kind not in (0, stat.S_IFREG):
            raise SystemExit(f"entrada ZIP no regular: {name}")
        files[name] = hashlib.sha256(bundle.read(info)).hexdigest()
if set(files) != set(expected):
    raise SystemExit(
        f"ZIP e inventario no son exactos; extra={sorted(set(files) - set(expected))}, "
        f"missing={sorted(set(expected) - set(files))}"
    )
for name, digest in expected.items():
    if files[name] != digest:
        raise SystemExit(f"hash ZIP divergente: {name}")
PY
}

if [[ "${AKB_BUSINESS_19916_PACKAGER_LIBRARY_ONLY:-0}" == 1 ]]; then
	return 0 2>/dev/null || exit 0
fi

while (($#)); do
	case "$1" in
		--output-dir)
			(($# >= 2)) || die 'Falta ruta para --output-dir'
			output_dir="$2"
			shift 2
			;;
		--verify-only)
			verify_only=1
			shift
			;;
		--derive-only)
			derive_only=1
			shift
			;;
		-h|--help)
			usage
			exit 0
			;;
		*) die "Opción desconocida: $1" ;;
	esac
done

((verify_only + derive_only <= 1)) \
	|| die '--verify-only y --derive-only son mutuamente excluyentes'

for command_name in awk bash cat cmp install jq mkdir mktemp python3 rm shasum tr unzip wc zipinfo; do
	command -v "${command_name}" >/dev/null 2>&1 \
		|| die "Falta el comando requerido: ${command_name}"
done
for required in \
	"${contract_file}" \
	"${search_contract}" \
	"${source_delta_verifier}" \
	"${runtime_packager}"; do
	verify_regular_file "${required}"
done

jq -e '
  .schema_version == 2
  and .contract == "akibara-business-production-release"
  and .release_id == "akibara-business-1.99.16"
  and (.seal_state == "draft" or .seal_state == "sealed")
  and .promotion_sequence == ["docker-local-paired","production-ui-paired"]
  and .exclusive_targets == ["public_html/wp-content/plugins/akibara-business"]
  and (.components | keys) == ["business"]
  and .components.business == {
    kind:"plugin",
    slug:"akibara-business",
    source_root:"public_html/wp-content/plugins/akibara-business",
    preimage:.components.business.preimage,
    remote_baseline:.components.business.remote_baseline,
    candidate:.components.business.candidate,
    modified_runtime_files:.components.business.modified_runtime_files,
    added_runtime_files:.components.business.added_runtime_files,
    removed_runtime_files:.components.business.removed_runtime_files,
    source_inputs:.components.business.source_inputs
  }
  and .components.business.preimage == {
    version:"1.99.10",
    archive:"dist/akibara-business-1.99.10.zip",
    archive_sha256:"7b397d2c5c2518cfafa0a138b7ee6a7801a91e6a891609d14fbfbf9213763134",
    inventory:"dist/akibara-business-1.99.10.files.sha256",
    inventory_sha256:"5172c0291ac7c92606a1f4984d3d71e14e907301fe58ced7567da943c0774522",
    runtime_files:221
  }
  and .components.business.remote_baseline == {
    version:"1.99.10",
    inventory:"dist/akibara-business-1.99.10.files.sha256",
    inventory_sha256:"5172c0291ac7c92606a1f4984d3d71e14e907301fe58ced7567da943c0774522",
    runtime_files:221
  }
  and .components.business.candidate.version == "1.99.16"
  and .components.business.candidate.archive == "dist/akibara-business-1.99.16.zip"
  and .components.business.candidate.inventory == "dist/akibara-business-1.99.16.files.sha256"
  and .paired_release.required == true
  and .paired_release.search_contract == "ops/contracts/akibara-search-0.1.14.json"
  and .paired_release.candidate == {business_version:"1.99.16",search_version:"0.1.14"}
  and .paired_release.rollback == {business_version:"1.99.10",search_version:"0.1.12"}
  and .paired_release.compatibility_policy == "exact-pair-only"
  and .paired_release.search_must_be_inactive_during_component_switch == true
  and .paired_release.production_transition.transport == "wordpress-plugin-ui-with-official-plugin-deploy"
  and .paired_release.production_transition.forward == ["deactivate-search-in-wordpress-ui","deploy-business-1.99.16","deploy-search-0.1.14-while-inactive","verify-both-versions-while-search-inactive","activate-search-in-wordpress-ui"]
  and .paired_release.production_transition.rollback == ["deactivate-search-in-wordpress-ui","deploy-business-1.99.10","deploy-search-0.1.12-while-inactive","verify-both-versions-while-search-inactive","activate-search-in-wordpress-ui"]
  and .paired_release.production_transition.mixed_midpoints == {
    rollback_after_business_deploy:{business_version:"1.99.10",search_version:"0.1.14",search_active:false},
    forward_after_business_deploy:{business_version:"1.99.16",search_version:"0.1.12",search_active:false}
  }
  and .production_invariants.runtime_outside_pair_must_remain_byte_identical == true
  and .production_invariants.remote_backups_allowed == false
  and .production_invariants.rollback_artifacts_location == "local-only"
  and .production_invariants.graphify_during_release_allowed == false
  and .production_invariants.required_active_plugins == [
    "akibara-business","akibara-cart-checkout-blocks","akibara-search",
    "bluex-for-woocommerce","litespeed-cache","seo-by-rank-math","woocommerce"
  ]
  and .search_feed_contract.version == "2"
  and .search_feed_contract.snapshot_contract == "akibara_search_price_snapshot_v1"
  and .search_feed_contract.capture_provider_final_sql_budget == 11
  and .quality_gates.unit == {
    command:"tools/akibara-business/vendor/bin/phpunit -c tools/akibara-business/phpunit.xml.dist",
    expected:"804 tests / 1928 assertions"
  }
  and .quality_gates.required_passes == 2
' "${contract_file}" >/dev/null || die 'Contrato base Business 1.99.16 rechazado'

jq -e '
  .release_id == "akibara-search-0.1.14"
  and .components.search.candidate.version == "0.1.14"
  and .components.search.preimage.version == "0.1.12"
  and .paired_release.business_contract == "ops/contracts/akibara-business-1.99.16.json"
  and .paired_release.candidate == {business_version:"1.99.16",search_version:"0.1.14"}
  and .paired_release.rollback == {business_version:"1.99.10",search_version:"0.1.12"}
  and .paired_release.search_must_be_inactive_during_component_switch == true
  and .paired_release.production_transition.forward == ["deactivate-search-in-wordpress-ui","deploy-business-1.99.16","deploy-search-0.1.14-while-inactive","verify-both-versions-while-search-inactive","activate-search-in-wordpress-ui"]
  and .paired_release.production_transition.rollback == ["deactivate-search-in-wordpress-ui","deploy-business-1.99.10","deploy-search-0.1.12-while-inactive","verify-both-versions-while-search-inactive","activate-search-in-wordpress-ui"]
  and .paired_release.production_transition.mixed_midpoints == {
    rollback_after_business_deploy:{business_version:"1.99.10",search_version:"0.1.14",search_active:false},
    forward_after_business_deploy:{business_version:"1.99.16",search_version:"0.1.12",search_active:false}
  }
' "${search_contract}" >/dev/null || die 'Contrato Search no declara el mismo par exacto'

seal_state="$(jq -r '.seal_state' "${contract_file}")"
if ((derive_only)); then
	[[ "${seal_state}" == draft ]] || die '--derive-only exige contrato draft'
	jq -e '
      .sealed_at_utc == null
      and .components.business.candidate.archive_sha256 == null
      and .components.business.candidate.inventory_sha256 == null
      and .components.business.candidate.runtime_files == null
      and .components.business.modified_runtime_files == null
      and .components.business.added_runtime_files == null
      and .components.business.removed_runtime_files == null
      and .components.business.source_inputs == null
	    ' "${contract_file}" >/dev/null || die 'Draft Business mezcla valores sellados'
	jq -e '
      .seal_state == "draft"
      and .paired_release.business_contract_sha256 == null
    ' "${search_contract}" >/dev/null \
		|| die 'Derivación Business exige contrato Search pareado todavía draft'
else
	jq -e '
      .seal_state == "sealed"
      and (.sealed_at_utc | type == "string" and test("^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$"))
      and (.components.business.candidate.archive_sha256 | type == "string" and test("^[a-f0-9]{64}$"))
      and (.components.business.candidate.inventory_sha256 | type == "string" and test("^[a-f0-9]{64}$"))
      and .components.business.candidate.runtime_files == 226
      and (.components.business.modified_runtime_files | type == "array" and length == 6)
      and (.components.business.added_runtime_files | type == "array" and length == 5)
      and .components.business.removed_runtime_files == []
      and (.components.business.source_inputs | type == "array" and length == 11)
	    ' "${contract_file}" >/dev/null || die 'Contrato Business draft o incompleto'
	jq -e '
      .seal_state == "sealed"
      and (.paired_release.business_contract_sha256 | type == "string" and test("^[a-f0-9]{64}$"))
    ' "${search_contract}" >/dev/null \
		|| die 'Verificación Business exige contrato Search pareado sellado'
	[[ "$(sha256_file "${contract_file}")" == "$(jq -r '.paired_release.business_contract_sha256' "${search_contract}")" ]] \
		|| die 'Contrato Search no está ligado al sello Business exacto'
fi

source_root="${repo_root}/$(jq -r '.components.business.source_root' "${contract_file}")"
preimage_archive="${repo_root}/$(jq -r '.components.business.preimage.archive' "${contract_file}")"
preimage_inventory="${repo_root}/$(jq -r '.components.business.preimage.inventory' "${contract_file}")"
[[ -d "${source_root}" && ! -L "${source_root}" ]] || die 'Árbol Business inseguro'
for required in "${preimage_archive}" "${preimage_archive}.sha256" "${preimage_inventory}"; do
	verify_regular_file "${required}"
done
[[ "$(sha256_file "${preimage_archive}")" == "$(jq -r '.components.business.preimage.archive_sha256' "${contract_file}")" ]] \
	|| die 'ZIP preimagen Business 1.99.10 cambió'
[[ "$(sha256_file "${preimage_inventory}")" == "$(jq -r '.components.business.preimage.inventory_sha256' "${contract_file}")" ]] \
	|| die 'Inventario preimagen Business 1.99.10 cambió'
(
	cd -- "$(dirname -- "${preimage_archive}")"
	shasum -a 256 -c "$(basename -- "${preimage_archive}.sha256")" >/dev/null
) || die 'Sidecar preimagen Business inválido'
unzip -tq "${preimage_archive}" >/dev/null
verify_archive_inventory "${preimage_archive}" "${preimage_inventory}" akibara-business \
	|| die 'ZIP preimagen Business inseguro o no exacto'
[[ "$(wc -l < "${preimage_inventory}" | tr -d ' ')" == 221 ]] \
	|| die 'Conteo preimagen Business inesperado'

temp_root="${TMPDIR:-/tmp}"
[[ -d "${temp_root}" ]] || die 'TMPDIR no es un directorio existente'
temp_root="$(cd -- "${temp_root}" && pwd -P)"
assert_no_symlink_ancestors "${temp_root}" || die 'TMPDIR canónico cruza un enlace simbólico'
scratch="$(mktemp -d "${temp_root%/}/akibara-business-1.99.16-package.XXXXXX")"
scratch="$(cd -- "$(dirname -- "${scratch}")" && printf '%s/%s\n' "$(pwd -P)" "$(basename -- "${scratch}")")"
assert_no_symlink_ancestors "${scratch}" || die 'Scratch de packaging inseguro'
cleanup_done=0
cleanup() {
	if ((cleanup_done)); then return; fi
	cleanup_done=1
	case "${scratch:-}" in
		*/akibara-business-1.99.16-package.*) rm -rf -- "${scratch}" ;;
	esac
}
trap cleanup EXIT
trap 'exit 129' HUP
trap 'exit 130' INT
trap 'exit 143' TERM

first_output="${scratch}/first"
second_output="${scratch}/second"
delta_file="${scratch}/delta.json"
candidate_archive_name='akibara-business-1.99.16.zip'
candidate_inventory_name='akibara-business-1.99.16.files.sha256'
candidate_sidecar_name='akibara-business-1.99.16.zip.sha256'
candidate_artifact_names=(
	"${candidate_archive_name}"
	"${candidate_inventory_name}"
	"${candidate_sidecar_name}"
)
runtime_temp_root="${scratch}/runtime-tmp"
mkdir -p -- "${first_output}" "${second_output}" "${runtime_temp_root}"
chmod 700 "${runtime_temp_root}"

run_runtime_build() {
	local timezone="$1" destination="$2"
	mkdir -p -- "${destination}"
	TMPDIR="${runtime_temp_root}" TZ="${timezone}" \
		bash "${runtime_packager}" --output-dir "${destination}" >/dev/null
}

assert_artifact_set_equal() {
	local expected_dir="$1" actual_dir="$2" artifact_name
	for artifact_name in "${candidate_artifact_names[@]}"; do
		verify_regular_file "${expected_dir}/${artifact_name}"
		verify_regular_file "${actual_dir}/${artifact_name}"
		cmp -s "${expected_dir}/${artifact_name}" "${actual_dir}/${artifact_name}" \
			|| die "Árbol fuente cambió entre builds: ${artifact_name}"
	done
}

assert_current_source_matches_candidate() {
	local label="$1" snapshot_output
	[[ "${label}" =~ ^[a-z0-9-]+$ ]] || die 'Etiqueta de snapshot insegura'
	snapshot_output="${scratch}/source-snapshot-${label}"
	run_runtime_build UTC "${snapshot_output}"
	assert_artifact_set_equal "${first_output}" "${snapshot_output}"
	verify_archive_inventory \
		"${snapshot_output}/${candidate_archive_name}" \
		"${snapshot_output}/${candidate_inventory_name}" \
		akibara-business || die "Snapshot fuente final inválido: ${label}"
}

run_runtime_build Pacific/Honolulu "${first_output}"
run_runtime_build Asia/Tokyo "${second_output}"

for artifact_name in "${candidate_archive_name}" "${candidate_inventory_name}" "${candidate_sidecar_name}"; do
	verify_regular_file "${first_output}/${artifact_name}"
	verify_regular_file "${second_output}/${artifact_name}"
	cmp -s "${first_output}/${artifact_name}" "${second_output}/${artifact_name}" \
		|| die "Artefacto Business no reproducible: ${artifact_name}"
done

candidate_archive="${first_output}/${candidate_archive_name}"
candidate_inventory="${first_output}/${candidate_inventory_name}"
(
	cd -- "${first_output}"
	shasum -a 256 -c "${candidate_sidecar_name}" >/dev/null
) || die 'Sidecar candidato Business inválido'
unzip -tq "${candidate_archive}" >/dev/null
verify_archive_inventory "${candidate_archive}" "${candidate_inventory}" akibara-business \
	|| die 'ZIP candidato Business inseguro o no exacto'
runtime_files="$(wc -l < "${candidate_inventory}" | tr -d ' ')"
[[ "${runtime_files}" == 226 ]] || die 'Candidato Business no contiene 226 archivos runtime'

python3 - "${preimage_inventory}" "${candidate_inventory}" > "${delta_file}" <<'PY'
import json
import pathlib
import re
import sys

row = re.compile(r"^([a-f0-9]{64})  (akibara-business/[A-Za-z0-9._/-]+)$")

def load(path):
    result = {}
    for line in pathlib.Path(path).read_text(encoding="utf-8").splitlines():
        match = row.fullmatch(line)
        if match is None or match.group(2) in result or ".." in match.group(2):
            raise SystemExit(f"inventario inválido: {path}")
        result[match.group(2)] = match.group(1)
    return result

old = load(sys.argv[1])
new = load(sys.argv[2])
added = sorted(set(new) - set(old))
removed = sorted(set(old) - set(new))
modified = sorted(path for path in set(old) & set(new) if old[path] != new[path])
inputs = [{"path": path, "sha256": new[path]} for path in sorted(modified + added)]
print(json.dumps({
    "modified_runtime_files": modified,
    "added_runtime_files": added,
    "removed_runtime_files": removed,
    "source_inputs": inputs,
}, separators=(",", ":"), sort_keys=True))
PY

jq -e '
  (.modified_runtime_files | length) == 6
  and (.added_runtime_files | length) == 5
  and .removed_runtime_files == []
  and (.source_inputs | length) == 11
' "${delta_file}" >/dev/null || die 'Delta Business no es 6 modificados + 5 altas + 0 bajas'

archive_sha="$(sha256_file "${candidate_archive}")"
inventory_sha="$(sha256_file "${candidate_inventory}")"
if ((derive_only)); then
	proposal_file="${scratch}/derive-proposal.json"
	jq -n \
		--arg archive_sha256 "${archive_sha}" \
		--arg inventory_sha256 "${inventory_sha}" \
		--argjson runtime_files "${runtime_files}" \
		--slurpfile delta "${delta_file}" '
      {
        seal_state:"draft-proposal",
        candidate:{
          version:"1.99.16",
          archive:"dist/akibara-business-1.99.16.zip",
          archive_sha256:$archive_sha256,
          inventory:"dist/akibara-business-1.99.16.files.sha256",
          inventory_sha256:$inventory_sha256,
          runtime_files:$runtime_files
        }
      } + $delta[0]
	    ' > "${proposal_file}"
	assert_current_source_matches_candidate derive-final
	cat "${proposal_file}"
	exit 0
fi

python3 "${source_delta_verifier}" \
	--contract "${contract_file}" \
	--source-root "${source_root}" \
	--preimage-inventory "${preimage_inventory}" \
	--expected-modified 6 \
	--expected-added 5 \
	--expected-removed 0
jq -e --slurpfile actual "${delta_file}" \
	--arg archive_sha "${archive_sha}" \
	--arg inventory_sha "${inventory_sha}" \
	--argjson runtime_files "${runtime_files}" '
  .components.business.candidate.archive_sha256 == $archive_sha
  and .components.business.candidate.inventory_sha256 == $inventory_sha
  and .components.business.candidate.runtime_files == $runtime_files
  and .components.business.modified_runtime_files == $actual[0].modified_runtime_files
  and .components.business.added_runtime_files == $actual[0].added_runtime_files
  and .components.business.removed_runtime_files == $actual[0].removed_runtime_files
  and .components.business.source_inputs == $actual[0].source_inputs
' "${contract_file}" >/dev/null || die 'Candidato Business diverge del sello'

# Una tercera construcción vuelve a leer todo el árbol que realmente entra al
# ZIP. Detecta altas, bajas o cambios concurrentes incluso en archivos que eran
# idénticos a la preimagen y, por tanto, no aparecen en source_inputs.
assert_current_source_matches_candidate sealed-final

if ((verify_only)); then
	printf 'PASS: Business 1.99.16 reproducible y coincide con el contrato pareado\n'
	exit 0
fi

assert_no_symlink_ancestors "${output_dir}" || die 'Directorio de salida con ancestro simbólico'
output_dir="$(secure_publish_artifacts \
	"${output_dir}" \
	"${first_output}" \
	"${candidate_artifact_names[@]}")" || die 'No se pudieron publicar artefactos Business de forma segura'

# La publicación no autoriza una ventana silenciosa de drift en la fuente. Se
# reconstruye otra vez después del commit y luego se reabre el output mediante
# el mismo recorrido O_NOFOLLOW para comprobar bytes, inode regular y nlink=1.
assert_current_source_matches_candidate post-publish
output_dir="$(secure_publish_artifacts \
	"${output_dir}" \
	"${first_output}" \
	"${candidate_artifact_names[@]}")" || die 'Postcondición de artefactos Business publicada falló'

printf 'PASS: artefactos Business 1.99.16 publicados localmente en %s\n' "${output_dir}"
