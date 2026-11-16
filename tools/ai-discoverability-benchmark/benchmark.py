#!/usr/bin/env python3
"""Hermetic evidence harness for the Akibara assistant-discoverability benchmark.

``run.yaml`` intentionally contains JSON. JSON is a strict YAML 1.2 subset, so
the file remains valid YAML while the harness needs only Python's standard
library and never guesses at YAML scalar semantics.
"""

from __future__ import annotations

import argparse
import hashlib
import json
import os
import re
import stat
import sys
import tempfile
from datetime import datetime
from pathlib import Path
from typing import Any, Iterable
from urllib.parse import urlsplit


SCHEMA_VERSION = 2
# B1: línea base nueva post-release integrada (Decisión 1 del 2026-08-07 cerró
# B0 v2 parcial en 30/216; B1 la reemplaza como baseline del sitio ya corregido).
PHASES = ("B0", "B1", "T0", "T7", "T14", "T30")
ENGINES = ("chatgpt", "gemini", "claude", "perplexity")
REPETITIONS = (1, 2, 3)
QUERY_IDS = tuple(
    [f"C{number:02d}" for number in range(1, 9)]
    + [f"S{number:02d}" for number in range(1, 6)]
    + [f"N{number:02d}" for number in range(1, 4)]
    + [f"X{number:02d}" for number in range(1, 3)]
)
EXPECTED_RUNS = len(QUERY_IDS) * len(ENGINES) * len(REPETITIONS)
RUN_FILES = ("run.yaml", "response.md", "cited-urls.txt", "screenshot.png")
REQUIRED_RUN_FILES = RUN_FILES + ("verification.json",)
MANIFEST_NAME = "manifest.json"
DEFAULT_PROTOCOL = (
    Path(__file__).resolve().parents[2]
    / "ops"
    / "ai-discoverability-prompt-benchmark.md"
)

RUN_ID_RE = re.compile(
    r"^(?P<batch>\d{8}-\d{4})-"
    r"(?P<engine>chatgpt|gemini|claude|perplexity)-"
    r"(?P<query>[CSNX]\d{2})-(?P<repetition>[123])$"
)
PHASE_SET = frozenset(PHASES)
ENGINE_SET = frozenset(ENGINES)
QUERY_ID_SET = frozenset(QUERY_IDS)
WEB_MODES = frozenset(("web_answer", "knowledge_only", "unknown"))
CONVERSATION_ISOLATIONS = frozenset(
    ("temporary", "incognito", "fresh_only", "unknown")
)
PERSONALIZATION_STATES = frozenset(("disabled", "enabled", "unknown"))
COMPARISON_STRATA = frozenset(("clean", "account_stratified", "invalid"))
SEARCH_TOGGLE_STATES = frozenset(
    ("on", "off", "automatic", "unavailable", "unknown")
)
ACCOUNT_LOCALES = frozenset(("es-CL", "other", "unknown"))
LOCATION_CONTEXTS = frozenset(("Chile/Santiago", "unknown"))
COMPLETION_SIGNALS = frozenset(("stop_disappeared", "send_enabled", "other"))
RUN_STATUSES = frozenset(
    ("prepared", "submitted", "completed", "verified", "invalid")
)
COMMERCIAL_PAGE_TYPES = frozenset(
    ("none", "home", "category", "guide", "product", "policy", "local")
)
CLAIM_NAMES = ("address", "stock", "price", "shipping", "preorder", "returns")
CLAIM_VALUES = {
    "address": frozenset(("absent", "correct", "incorrect", "unverifiable")),
    "stock": frozenset(
        ("absent", "correct_at_capture", "incorrect", "unverifiable")
    ),
    "price": frozenset(
        ("absent", "correct_at_capture", "incorrect", "unverifiable")
    ),
    "shipping": frozenset(("absent", "correct", "incorrect", "unverifiable")),
    "preorder": frozenset(("absent", "correct", "incorrect", "unverifiable")),
    "returns": frozenset(("absent", "correct", "incorrect", "unverifiable")),
}
PNG_SIGNATURE = b"\x89PNG\r\n\x1a\n"
PNG_IEND = b"\x00\x00\x00\x00IEND\xaeB`\x82"


class HarnessError(RuntimeError):
    """A deterministic validation failure suitable for CLI output."""


def _fail(message: str) -> None:
    raise HarnessError(message)


def sha256_bytes(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def sha256_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def canonical_json_bytes(value: Any) -> bytes:
    return (
        json.dumps(value, ensure_ascii=False, indent=2, sort_keys=True) + "\n"
    ).encode("utf-8")


def atomic_write(path: Path, data: bytes, mode: int = 0o600) -> None:
    path.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
    temporary: Path | None = None
    try:
        descriptor, raw_path = tempfile.mkstemp(
            prefix=f".{path.name}.", suffix=".partial", dir=path.parent
        )
        temporary = Path(raw_path)
        os.fchmod(descriptor, mode)
        with os.fdopen(descriptor, "wb") as handle:
            handle.write(data)
            handle.flush()
            os.fsync(handle.fileno())
        os.replace(temporary, path)
        temporary = None
        os.chmod(path, mode)
    finally:
        if temporary is not None:
            try:
                temporary.unlink()
            except FileNotFoundError:
                pass


def write_json(path: Path, value: Any) -> None:
    atomic_write(path, canonical_json_bytes(value))


def read_json(path: Path, label: str) -> Any:
    try:
        return json.loads(path.read_text(encoding="utf-8"))
    except (OSError, UnicodeDecodeError, json.JSONDecodeError) as error:
        _fail(f"{label} no es JSON/YAML-JSON válido: {path}: {error}")


def require_private_directory(path: Path, label: str) -> os.stat_result:
    try:
        metadata = path.lstat()
    except FileNotFoundError:
        _fail(f"falta {label}: {path}")
    if stat.S_ISLNK(metadata.st_mode) or not stat.S_ISDIR(metadata.st_mode):
        _fail(f"{label} debe ser un directorio real, no un enlace: {path}")
    if stat.S_IMODE(metadata.st_mode) != 0o700:
        _fail(f"{label} debe tener modo 0700: {path}")
    return metadata


def require_private_file(path: Path, label: str) -> os.stat_result:
    try:
        metadata = path.lstat()
    except FileNotFoundError:
        _fail(f"falta {label}: {path}")
    if stat.S_ISLNK(metadata.st_mode) or not stat.S_ISREG(metadata.st_mode):
        _fail(f"{label} debe ser un archivo regular, no un enlace: {path}")
    if metadata.st_nlink != 1:
        _fail(f"{label} no puede ser un hardlink: {path}")
    if stat.S_IMODE(metadata.st_mode) != 0o600:
        _fail(f"{label} debe tener modo 0600: {path}")
    return metadata


def parse_protocol(protocol_path: Path) -> dict[str, str]:
    if not protocol_path.is_file() or protocol_path.is_symlink():
        _fail(f"protocolo ausente o inseguro: {protocol_path}")
    found: dict[str, str] = {}
    for line_number, raw_line in enumerate(
        protocol_path.read_text(encoding="utf-8").splitlines(), start=1
    ):
        line = raw_line.strip()
        if not line.startswith("|") or not line.endswith("|"):
            continue
        cells = [cell.strip() for cell in line[1:-1].split("|")]
        if not cells or not re.fullmatch(r"[CSNX]\d{2}", cells[0]):
            continue
        if len(cells) < 3 or not cells[2]:
            _fail(f"consulta canónica inválida en línea {line_number}")
        query_id = cells[0]
        if query_id in found:
            _fail(f"consulta canónica duplicada: {query_id}")
        found[query_id] = cells[2]

    missing = [query_id for query_id in QUERY_IDS if query_id not in found]
    extras = sorted(set(found) - QUERY_ID_SET)
    if missing or extras or len(found) != len(QUERY_IDS):
        _fail(
            "el protocolo debe contener exactamente las 18 consultas canónicas; "
            f"faltan={missing}, extras={extras}, encontradas={len(found)}"
        )
    return {query_id: found[query_id] for query_id in QUERY_IDS}


def validate_phase(phase: str) -> None:
    if phase not in PHASE_SET:
        _fail(f"fase inválida {phase!r}; permitidas={','.join(PHASES)}")


def validate_batch_id(batch_id: str) -> None:
    if not re.fullmatch(r"\d{8}-\d{4}", batch_id):
        _fail("batch-id debe usar YYYYMMDD-HHMM")
    try:
        datetime.strptime(batch_id, "%Y%m%d-%H%M")
    except ValueError as error:
        _fail(f"batch-id no representa una fecha-hora válida: {error}")


def expected_run_id(
    batch_id: str, engine: str, query_id: str, repetition: int
) -> str:
    return f"{batch_id}-{engine}-{query_id}-{repetition}"


def initial_run(
    *,
    batch_id: str,
    phase: str,
    engine: str,
    query_id: str,
    query_exact: str,
    repetition: int,
) -> dict[str, Any]:
    return {
        "schema_version": SCHEMA_VERSION,
        "run_id": expected_run_id(batch_id, engine, query_id, repetition),
        "phase": phase,
        "engine": engine,
        "model_or_mode": "",
        "web_mode": "unknown",
        "conversation_isolation": "unknown",
        "personalization_state": "unknown",
        "comparison_stratum": "invalid",
        "search_toggle_state": "unknown",
        "account_locale": "unknown",
        "location_context": "unknown",
        "completion_signal": "other",
        "run_status": "prepared",
        "query_id": query_id,
        "query_exact": query_exact,
        "repetition": repetition,
        "started_at_chile": "",
        "completed_at_chile": "",
        "akibara_mentioned": False,
        "akibara_direct_link": False,
        "akibara_source_link": False,
        "mention_order": None,
        "recommendations_count": None,
        "first_mention": False,
        "top3_mention": False,
        "commercial_page_type": "none",
        "claims": {
            "address": "absent",
            "stock": "absent",
            "price": "absent",
            "shipping": "absent",
            "preorder": "absent",
            "returns": "absent",
        },
        "evidence": {
            "response_path": "response.md",
            "screenshot_path": "screenshot.png",
            "cited_urls_path": "cited-urls.txt",
            "cited_urls": [],
        },
        "notes": "",
    }


def initialize(
    *, root: Path, phase: str, batch_id: str, protocol_path: Path
) -> Path:
    validate_phase(phase)
    validate_batch_id(batch_id)
    queries = parse_protocol(protocol_path)
    phase_dir = root / phase
    if phase_dir.exists() or phase_dir.is_symlink():
        _fail(f"la fase ya existe; no se sobrescribe evidencia: {phase_dir}")

    runs_dir = phase_dir / "runs"
    runs_dir.mkdir(mode=0o700, parents=True, exist_ok=False)
    os.chmod(phase_dir, 0o700)
    os.chmod(runs_dir, 0o700)

    created = 0
    for engine in ENGINES:
        for query_id in QUERY_IDS:
            for repetition in REPETITIONS:
                run = initial_run(
                    batch_id=batch_id,
                    phase=phase,
                    engine=engine,
                    query_id=query_id,
                    query_exact=queries[query_id],
                    repetition=repetition,
                )
                run_dir = runs_dir / run["run_id"]
                run_dir.mkdir(mode=0o700)
                os.chmod(run_dir, 0o700)
                write_json(run_dir / "run.yaml", run)
                atomic_write(run_dir / "response.md", b"")
                atomic_write(run_dir / "cited-urls.txt", b"")
                atomic_write(run_dir / "screenshot.png", b"")
                write_json(
                    run_dir / "verification.json",
                    {
                        "schema_version": SCHEMA_VERSION,
                        "run_id": run["run_id"],
                        "status": "pending",
                        "artifacts": {},
                    },
                )
                created += 1

    if created != EXPECTED_RUNS:
        _fail(f"error interno: se crearon {created}, se esperaban {EXPECTED_RUNS}")
    return phase_dir


def require_bool(value: Any, label: str) -> None:
    if type(value) is not bool:
        _fail(f"{label} debe ser booleano")


def require_nullable_nonnegative_int(value: Any, label: str, minimum: int = 0) -> None:
    if value is None:
        return
    if type(value) is not int or value < minimum:
        _fail(f"{label} debe ser null o entero >= {minimum}")


def validate_chile_timestamp(value: Any, label: str) -> datetime:
    if not isinstance(value, str) or not value:
        _fail(f"{label} debe registrar la fecha-hora de captura")
    if not re.fullmatch(
        r"\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:-03:00|-04:00)", value
    ):
        _fail(f"{label} debe ser RFC3339 con offset Chile -03:00 o -04:00")
    try:
        return datetime.fromisoformat(value)
    except ValueError as error:
        _fail(f"{label} inválido: {error}")


def expected_comparison_stratum(
    conversation_isolation: str, personalization_state: str
) -> str:
    if conversation_isolation not in ("temporary", "incognito"):
        return "invalid"
    if personalization_state == "disabled":
        return "clean"
    if personalization_state == "enabled":
        return "account_stratified"
    return "invalid"


def read_cited_urls(path: Path) -> list[str]:
    try:
        text = path.read_text(encoding="utf-8")
    except UnicodeDecodeError as error:
        _fail(f"cited-urls.txt no es UTF-8: {error}")
    if "\r" in text:
        _fail("cited-urls.txt debe usar saltos de línea LF")
    urls = text.splitlines()
    if any(not url or url != url.strip() for url in urls):
        _fail("cited-urls.txt exige una URL limpia por línea, sin líneas vacías")
    if len(urls) != len(set(urls)):
        _fail("cited-urls.txt no permite URLs duplicadas")
    for url in urls:
        parsed = urlsplit(url)
        if parsed.scheme not in ("http", "https") or not parsed.netloc:
            _fail(f"URL citada inválida: {url!r}")
        if parsed.username is not None or parsed.password is not None:
            _fail("una URL citada no puede incluir credenciales")
    return urls


def validate_run_metadata(
    run: Any,
    *,
    run_dir: Path,
    queries: dict[str, str],
    expected_phase: str | None = None,
    required_status: str | None = None,
) -> tuple[str, str, str, int]:
    if not isinstance(run, dict):
        _fail(f"run.yaml debe contener un objeto: {run_dir}")
    required = {
        "schema_version",
        "run_id",
        "phase",
        "engine",
        "model_or_mode",
        "web_mode",
        "conversation_isolation",
        "personalization_state",
        "comparison_stratum",
        "search_toggle_state",
        "account_locale",
        "location_context",
        "completion_signal",
        "run_status",
        "query_id",
        "query_exact",
        "repetition",
        "started_at_chile",
        "completed_at_chile",
        "akibara_mentioned",
        "akibara_direct_link",
        "akibara_source_link",
        "mention_order",
        "recommendations_count",
        "first_mention",
        "top3_mention",
        "commercial_page_type",
        "claims",
        "evidence",
        "notes",
    }
    missing = sorted(required - set(run))
    if missing:
        _fail(f"run.yaml incompleto en {run_dir}; faltan={missing}")
    if run["schema_version"] != SCHEMA_VERSION:
        _fail(f"schema_version inválido en {run_dir}")

    run_id = run["run_id"]
    if not isinstance(run_id, str):
        _fail("run_id debe ser texto")
    match = RUN_ID_RE.fullmatch(run_id)
    if not match:
        _fail(f"run_id inválido: {run_id!r}")
    validate_batch_id(match.group("batch"))
    if run_dir.name != run_id:
        _fail(f"el directorio no coincide con run_id: {run_dir.name} != {run_id}")

    phase = run["phase"]
    if not isinstance(phase, str):
        _fail("phase debe ser texto")
    validate_phase(phase)
    if expected_phase is not None and phase != expected_phase:
        _fail(f"fase de run incorrecta: {phase} != {expected_phase}")

    engine = run["engine"]
    if engine not in ENGINE_SET or engine != match.group("engine"):
        _fail(f"engine no coincide con run_id: {engine!r}")
    query_id = run["query_id"]
    if query_id not in QUERY_ID_SET or query_id != match.group("query"):
        _fail(f"query_id no coincide con run_id: {query_id!r}")
    repetition = run["repetition"]
    if type(repetition) is not int or repetition not in REPETITIONS:
        _fail(f"repetition inválida: {repetition!r}")
    if repetition != int(match.group("repetition")):
        _fail("repetition no coincide con run_id")
    if run_id != expected_run_id(
        match.group("batch"), engine, query_id, repetition
    ):
        _fail("run_id no está en forma canónica")
    if run["query_exact"] != queries[query_id]:
        _fail(f"query_exact difiere del protocolo para {query_id}")

    run_status = run["run_status"]
    if run_status not in RUN_STATUSES:
        _fail(f"run_status inválido: {run_status!r}")
    if required_status is not None and run_status != required_status:
        _fail(f"run_status debe ser {required_status}, no {run_status}")

    if not isinstance(run["model_or_mode"], str) or not run["model_or_mode"].strip():
        _fail("model_or_mode debe registrar el texto visible en la UI")
    if run["web_mode"] not in WEB_MODES:
        _fail(f"web_mode inválido: {run['web_mode']!r}")
    if run["conversation_isolation"] not in CONVERSATION_ISOLATIONS:
        _fail(
            "conversation_isolation inválido: "
            f"{run['conversation_isolation']!r}"
        )
    if run["personalization_state"] not in PERSONALIZATION_STATES:
        _fail(f"personalization_state inválido: {run['personalization_state']!r}")
    if run["comparison_stratum"] not in COMPARISON_STRATA:
        _fail(f"comparison_stratum inválido: {run['comparison_stratum']!r}")
    if run["search_toggle_state"] not in SEARCH_TOGGLE_STATES:
        _fail(f"search_toggle_state inválido: {run['search_toggle_state']!r}")
    if run["account_locale"] not in ACCOUNT_LOCALES:
        _fail(f"account_locale inválido: {run['account_locale']!r}")
    if run["location_context"] not in LOCATION_CONTEXTS:
        _fail(f"location_context inválido: {run['location_context']!r}")
    if run["completion_signal"] not in COMPLETION_SIGNALS:
        _fail(f"completion_signal inválido: {run['completion_signal']!r}")

    derived_stratum = expected_comparison_stratum(
        run["conversation_isolation"], run["personalization_state"]
    )
    if run_status == "invalid":
        if run["comparison_stratum"] != "invalid":
            _fail("run_status=invalid exige comparison_stratum=invalid")
    elif derived_stratum == "invalid":
        if run_status != "prepared":
            _fail(
                "fresh_only/unknown o personalización unknown exige "
                "run_status=invalid"
            )
        if run["comparison_stratum"] != "invalid":
            _fail("aislamiento no comparable exige comparison_stratum=invalid")
    elif run["comparison_stratum"] != derived_stratum:
        _fail(
            f"comparison_stratum debe ser {derived_stratum} para el aislamiento "
            "y personalización registrados"
        )

    started_at = validate_chile_timestamp(run["started_at_chile"], "started_at_chile")
    completed_at = validate_chile_timestamp(
        run["completed_at_chile"], "completed_at_chile"
    )
    if completed_at < started_at:
        _fail("completed_at_chile debe ser igual o posterior a started_at_chile")

    for name in (
        "akibara_mentioned",
        "akibara_direct_link",
        "akibara_source_link",
        "first_mention",
        "top3_mention",
    ):
        require_bool(run[name], name)
    require_nullable_nonnegative_int(run["mention_order"], "mention_order", 1)
    require_nullable_nonnegative_int(
        run["recommendations_count"], "recommendations_count", 0
    )
    if run["commercial_page_type"] not in COMMERCIAL_PAGE_TYPES:
        _fail(f"commercial_page_type inválido: {run['commercial_page_type']!r}")

    if not run["akibara_mentioned"]:
        incompatible = (
            run["akibara_direct_link"],
            run["akibara_source_link"],
            run["first_mention"],
            run["top3_mention"],
            run["mention_order"] is not None,
        )
        if any(incompatible):
            _fail("una corrida sin mención no puede registrar enlace u orden de Akibara")
    if run["first_mention"] and run["mention_order"] != 1:
        _fail("first_mention=true exige mention_order=1")
    if run["top3_mention"] and (
        run["mention_order"] is None or run["mention_order"] > 3
    ):
        _fail("top3_mention=true exige mention_order entre 1 y 3")
    if run["mention_order"] == 1 and not run["first_mention"]:
        _fail("mention_order=1 exige first_mention=true")
    if run["mention_order"] is not None and run["mention_order"] <= 3 and not run["top3_mention"]:
        _fail("mention_order entre 1 y 3 exige top3_mention=true")

    claims = run["claims"]
    if not isinstance(claims, dict) or set(claims) != set(CLAIM_NAMES):
        _fail(f"claims debe contener exactamente: {','.join(CLAIM_NAMES)}")
    for claim_name in CLAIM_NAMES:
        if claims[claim_name] not in CLAIM_VALUES[claim_name]:
            _fail(f"claims.{claim_name} inválido: {claims[claim_name]!r}")

    evidence = run["evidence"]
    if not isinstance(evidence, dict):
        _fail("evidence debe ser un objeto")
    expected_paths = {
        "response_path": "response.md",
        "screenshot_path": "screenshot.png",
        "cited_urls_path": "cited-urls.txt",
    }
    for key, expected in expected_paths.items():
        if evidence.get(key) != expected:
            _fail(f"evidence.{key} debe ser {expected!r}")
    if not isinstance(evidence.get("cited_urls"), list) or not all(
        isinstance(value, str) for value in evidence["cited_urls"]
    ):
        _fail("evidence.cited_urls debe ser una lista de textos")
    if not isinstance(run["notes"], str):
        _fail("notes debe ser texto")

    return match.group("batch"), engine, query_id, repetition


def validate_capture_files(run_dir: Path, run: dict[str, Any]) -> None:
    response = (run_dir / "response.md").read_bytes()
    if not response.strip():
        _fail(f"response.md está vacío: {run_dir}")
    try:
        response.decode("utf-8")
    except UnicodeDecodeError as error:
        _fail(f"response.md no es UTF-8: {run_dir}: {error}")

    urls = read_cited_urls(run_dir / "cited-urls.txt")
    if urls != run["evidence"]["cited_urls"]:
        _fail(f"evidence.cited_urls no coincide con cited-urls.txt: {run_dir}")

    screenshot = (run_dir / "screenshot.png").read_bytes()
    if len(screenshot) < 32 or not screenshot.startswith(PNG_SIGNATURE):
        _fail(f"screenshot.png no contiene un PNG válido: {run_dir}")
    if not screenshot.endswith(PNG_IEND):
        _fail(f"screenshot.png no termina en un chunk IEND válido: {run_dir}")


def artifact_record(path: Path) -> dict[str, Any]:
    metadata = require_private_file(path, path.name)
    return {
        "mode": "0600",
        "sha256": sha256_file(path),
        "size": metadata.st_size,
    }


def expected_verification(run_dir: Path, run_id: str) -> dict[str, Any]:
    return {
        "schema_version": SCHEMA_VERSION,
        "run_id": run_id,
        "status": "verified",
        "artifacts": {
            filename: artifact_record(run_dir / filename) for filename in RUN_FILES
        },
    }


def ensure_exact_run_files(run_dir: Path) -> None:
    actual = {entry.name for entry in run_dir.iterdir()}
    expected = set(REQUIRED_RUN_FILES)
    if actual != expected:
        _fail(
            f"contenido inesperado en {run_dir}; "
            f"faltan={sorted(expected - actual)}, extras={sorted(actual - expected)}"
        )


def seal_run(*, run_dir: Path, protocol_path: Path) -> dict[str, Any]:
    require_private_directory(run_dir, "directorio de corrida")
    ensure_exact_run_files(run_dir)
    for filename in REQUIRED_RUN_FILES:
        require_private_file(run_dir / filename, filename)

    queries = parse_protocol(protocol_path)
    run_path = run_dir / "run.yaml"
    verification_path = run_dir / "verification.json"
    run = read_json(run_path, "run.yaml")
    if not isinstance(run, dict):
        _fail(f"run.yaml debe contener un objeto: {run_dir}")
    run_status = run.get("run_status")
    if run_status == "invalid":
        _fail("una corrida invalid se preserva, pero no puede sellarse")
    if run_status not in ("completed", "verified"):
        _fail(
            "seal-run exige que el operador cambie run_status de prepared/submitted "
            "a completed"
        )
    path_phase = (
        run_dir.parent.parent.name
        if run_dir.parent.name == "runs" and run_dir.parent.parent.name in PHASE_SET
        else None
    )
    validate_run_metadata(
        run,
        run_dir=run_dir,
        queries=queries,
        expected_phase=path_phase,
        required_status=run_status,
    )
    validate_capture_files(run_dir, run)

    current = read_json(verification_path, "verification.json")
    if not isinstance(current, dict) or current.get("status") not in (
        "pending",
        "verified",
    ):
        _fail("verification.json debe estar en estado pending o verified")

    if run_status == "verified":
        expected = expected_verification(run_dir, run["run_id"])
        if current != expected:
            _fail(
                "la corrida ya estaba verified y cambió; no se resella evidencia "
                "sin volverla explícitamente a completed y el sello a pending"
            )
        return expected

    if current.get("status") != "pending":
        _fail("run_status=completed exige verification.json en estado pending")

    verified_run = dict(run)
    verified_run["run_status"] = "verified"
    try:
        # The whole run record changes atomically. Hashing deliberately happens
        # only after the verified state is durable on disk.
        write_json(run_path, verified_run)
        expected = expected_verification(run_dir, verified_run["run_id"])
        write_json(verification_path, expected)
    except Exception:
        # Best-effort rollback for ordinary failures. A later invocation also
        # detects any interrupted mismatch instead of silently overwriting it.
        write_json(run_path, run)
        write_json(verification_path, current)
        raise
    return expected


def validate_run(
    *,
    run_dir: Path,
    queries: dict[str, str],
    expected_phase: str | None = None,
) -> dict[str, Any]:
    require_private_directory(run_dir, "directorio de corrida")
    ensure_exact_run_files(run_dir)
    for filename in REQUIRED_RUN_FILES:
        require_private_file(run_dir / filename, filename)

    run = read_json(run_dir / "run.yaml", "run.yaml")
    batch_id, engine, query_id, repetition = validate_run_metadata(
        run,
        run_dir=run_dir,
        queries=queries,
        expected_phase=expected_phase,
        required_status="verified",
    )
    validate_capture_files(run_dir, run)
    verification = read_json(run_dir / "verification.json", "verification.json")
    expected = expected_verification(run_dir, run["run_id"])
    if verification != expected:
        status_value = verification.get("status") if isinstance(verification, dict) else None
        if status_value != "verified":
            _fail(f"corrida no verificada ({status_value!r}): {run_dir}")
        _fail(f"hash, tamaño o modo no coincide con verification.json: {run_dir}")
    return {
        "batch_id": batch_id,
        "engine": engine,
        "query_id": query_id,
        "repetition": repetition,
        "run": run,
        "verification": verification,
    }


def protocol_record(protocol_path: Path, queries: dict[str, str]) -> dict[str, Any]:
    query_payload = [
        {"query_id": query_id, "query_exact": queries[query_id]}
        for query_id in QUERY_IDS
    ]
    return {
        "sha256": sha256_file(protocol_path),
        "query_set_sha256": sha256_bytes(canonical_json_bytes(query_payload)),
        "queries": query_payload,
    }


def collect_manifest(
    *, root: Path, phase: str, protocol_path: Path
) -> dict[str, Any]:
    validate_phase(phase)
    queries = parse_protocol(protocol_path)
    phase_dir = root / phase
    runs_dir = phase_dir / "runs"
    require_private_directory(phase_dir, "directorio de fase")
    require_private_directory(runs_dir, "directorio runs")

    entries = sorted(runs_dir.iterdir(), key=lambda path: path.name)
    if len(entries) != EXPECTED_RUNS:
        _fail(
            f"la fase debe contener exactamente {EXPECTED_RUNS} directorios; "
            f"encontrados={len(entries)}"
        )

    seen_coordinates: set[tuple[str, str, int]] = set()
    batches: set[str] = set()
    runs: list[dict[str, Any]] = []
    for run_dir in entries:
        if run_dir.is_symlink() or not run_dir.is_dir():
            _fail(f"entrada no permitida dentro de runs: {run_dir}")
        result = validate_run(
            run_dir=run_dir, queries=queries, expected_phase=phase
        )
        coordinate = (
            result["engine"],
            result["query_id"],
            result["repetition"],
        )
        if coordinate in seen_coordinates:
            _fail(f"coordenada de benchmark duplicada: {coordinate}")
        seen_coordinates.add(coordinate)
        batches.add(result["batch_id"])
        verification_path = run_dir / "verification.json"
        verification_metadata = require_private_file(
            verification_path, "verification.json"
        )
        runs.append(
            {
                "run_id": result["run"]["run_id"],
                "path": f"runs/{run_dir.name}",
                "engine": result["engine"],
                "query_id": result["query_id"],
                "repetition": result["repetition"],
                "comparison_stratum": result["run"]["comparison_stratum"],
                "conversation_isolation": result["run"]["conversation_isolation"],
                "personalization_state": result["run"]["personalization_state"],
                "web_mode": result["run"]["web_mode"],
                "search_toggle_state": result["run"]["search_toggle_state"],
                "query_sha256": sha256_bytes(
                    queries[result["query_id"]].encode("utf-8")
                ),
                "artifacts": result["verification"]["artifacts"],
                "verification": {
                    "mode": "0600",
                    "sha256": sha256_file(verification_path),
                    "size": verification_metadata.st_size,
                    "status": "verified",
                },
            }
        )

    expected_coordinates = {
        (engine, query_id, repetition)
        for engine in ENGINES
        for query_id in QUERY_IDS
        for repetition in REPETITIONS
    }
    if seen_coordinates != expected_coordinates:
        _fail(
            "la matriz 4x18x3 está incompleta o contiene coordenadas inesperadas"
        )
    if len(batches) != 1:
        _fail(f"todas las corridas de una fase deben compartir batch-id: {sorted(batches)}")

    return {
        "schema_version": SCHEMA_VERSION,
        "phase": phase,
        "batch_id": next(iter(batches)),
        "cardinality": {
            "engines": len(ENGINES),
            "queries": len(QUERY_IDS),
            "repetitions": len(REPETITIONS),
            "runs": EXPECTED_RUNS,
        },
        "engines": list(ENGINES),
        "protocol": protocol_record(protocol_path, queries),
        "runs": runs,
    }


def finalize(*, root: Path, phase: str, protocol_path: Path) -> Path:
    manifest = collect_manifest(root=root, phase=phase, protocol_path=protocol_path)
    manifest_path = root / phase / MANIFEST_NAME
    atomic_write(manifest_path, canonical_json_bytes(manifest))
    return manifest_path


def validate_manifest(*, root: Path, phase: str, protocol_path: Path) -> Path:
    manifest_path = root / phase / MANIFEST_NAME
    require_private_file(manifest_path, MANIFEST_NAME)
    expected = canonical_json_bytes(
        collect_manifest(root=root, phase=phase, protocol_path=protocol_path)
    )
    actual = manifest_path.read_bytes()
    if actual != expected:
        _fail(
            f"{MANIFEST_NAME} no coincide byte a byte con la evidencia actual: "
            f"{manifest_path}"
        )
    return manifest_path


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(
        description="Harness hermético del benchmark de descubribilidad de Akibara"
    )
    subparsers = parser.add_subparsers(dest="command", required=True)

    def add_protocol(argument_parser: argparse.ArgumentParser) -> None:
        argument_parser.add_argument(
            "--protocol", type=Path, default=DEFAULT_PROTOCOL
        )

    init_parser = subparsers.add_parser(
        "init", help="crear el scaffold privado de 216 corridas"
    )
    init_parser.add_argument("--root", type=Path, required=True)
    init_parser.add_argument("--phase", choices=PHASES, required=True)
    init_parser.add_argument("--batch-id", required=True)
    add_protocol(init_parser)

    seal_parser = subparsers.add_parser(
        "seal-run", help="sellar una corrida capturada como verified"
    )
    seal_parser.add_argument("--run-dir", type=Path, required=True)
    add_protocol(seal_parser)

    finalize_parser = subparsers.add_parser(
        "finalize", help="validar 216 corridas y escribir manifest.json"
    )
    finalize_parser.add_argument("--root", type=Path, required=True)
    finalize_parser.add_argument("--phase", choices=PHASES, required=True)
    add_protocol(finalize_parser)

    validate_parser = subparsers.add_parser(
        "validate", help="validar evidencia y manifest.json sin modificar nada"
    )
    validate_parser.add_argument("--root", type=Path, required=True)
    validate_parser.add_argument("--phase", choices=PHASES, required=True)
    add_protocol(validate_parser)
    return parser


def main(argv: Iterable[str] | None = None) -> int:
    args = build_parser().parse_args(argv)
    try:
        if args.command == "init":
            phase_dir = initialize(
                root=args.root,
                phase=args.phase,
                batch_id=args.batch_id,
                protocol_path=args.protocol,
            )
            print(f"initialized {EXPECTED_RUNS} runs: {phase_dir}")
        elif args.command == "seal-run":
            verification = seal_run(
                run_dir=args.run_dir, protocol_path=args.protocol
            )
            print(f"verified {verification['run_id']}")
        elif args.command == "finalize":
            manifest_path = finalize(
                root=args.root, phase=args.phase, protocol_path=args.protocol
            )
            print(f"finalized {EXPECTED_RUNS} verified runs: {manifest_path}")
        elif args.command == "validate":
            manifest_path = validate_manifest(
                root=args.root, phase=args.phase, protocol_path=args.protocol
            )
            print(f"valid {EXPECTED_RUNS} verified runs: {manifest_path}")
        else:  # pragma: no cover - argparse makes this unreachable.
            _fail(f"comando desconocido: {args.command}")
    except HarnessError as error:
        print(f"ERROR: {error}", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
