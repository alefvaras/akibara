#!/usr/bin/env python3
"""
Cliente Firecrawl oficial para Akibara.

Permite extracción limpia en Markdown/JSON y búsqueda profunda
saltando bloqueos de WAF/Cloudflare/Queue-It en webs de editoriales.

Las credenciales se leen exclusivamente de ~/.codex/credentials/firecrawl-key.txt
o de la variable de entorno FIRECRAWL_API_KEY (regla de oro de AGENTS.md).
"""

import json
import os
import sys
import urllib.error
import urllib.request
from pathlib import Path
from typing import Any, Dict, List, Optional

FIRECRAWL_API_BASE = "https://api.firecrawl.dev/v1"
CREDENTIALS_FILE = Path.home() / ".codex" / "credentials" / "firecrawl-key.txt"


def get_api_key() -> str:
    """Obtiene la clave de API de forma segura."""
    env_key = os.environ.get("FIRECRAWL_API_KEY")
    if env_key and env_key.strip():
        return env_key.strip()

    if CREDENTIALS_FILE.exists():
        key = CREDENTIALS_FILE.read_text(encoding="utf-8").strip()
        if key:
            return key

    raise RuntimeError(
        f"Firecrawl API key no encontrada. Configúrala en {CREDENTIALS_FILE} "
        "o en la variable de entorno FIRECRAWL_API_KEY."
    )


def _request(endpoint: str, payload: Dict[str, Any], timeout: int = 45) -> Dict[str, Any]:
    """Ejecuta una petición POST a la API de Firecrawl."""
    api_key = get_api_key()
    url = f"{FIRECRAWL_API_BASE}/{endpoint.lstrip('/')}"
    data = json.dumps(payload).encode("utf-8")

    req = urllib.request.Request(
        url,
        data=data,
        headers={
            "Authorization": f"Bearer {api_key}",
            "Content-Type": "application/json",
            "User-Agent": "Akibara-Catalog-Agent/1.0",
        },
    )

    try:
        with urllib.request.urlopen(req, timeout=timeout) as resp:
            return json.loads(resp.read().decode("utf-8"))
    except urllib.error.HTTPError as e:
        body = e.read().decode("utf-8", errors="replace")
        raise RuntimeError(f"Firecrawl API Error ({e.code}): {body}") from e
    except Exception as e:
        raise RuntimeError(f"Error de conexión con Firecrawl: {e}") from e


def scrape_url(
    url: str,
    formats: Optional[List[str]] = None,
    only_main_content: bool = True,
    wait_for: Optional[int] = None,
) -> Dict[str, Any]:
    """
    Extrae el contenido de una URL en Markdown limpio y metadata estructurada.
    """
    if formats is None:
        formats = ["markdown"]

    payload: Dict[str, Any] = {
        "url": url,
        "formats": formats,
        "onlyMainContent": only_main_content,
    }
    if wait_for:
        payload["waitFor"] = wait_for

    res = _request("scrape", payload)
    data = res.get("data", {})
    return {
        "markdown": data.get("markdown", ""),
        "metadata": data.get("metadata", {}),
        "title": data.get("metadata", {}).get("title", ""),
        "description": data.get("metadata", {}).get("description", ""),
        "raw": data,
    }


def search(
    query: str,
    limit: int = 5,
    scrape_options: Optional[Dict[str, Any]] = None,
) -> List[Dict[str, Any]]:
    """
    Realiza una búsqueda web a través de Firecrawl y devuelve los resultados.
    """
    payload: Dict[str, Any] = {
        "query": query,
        "limit": limit,
    }
    if scrape_options:
        payload["scrapeOptions"] = scrape_options

    res = _request("search", payload)
    return res.get("data", [])


def map_site(url: str, search_filter: Optional[str] = None) -> List[str]:
    """
    Mapea todas las URLs de un sitio o sección específica.
    """
    payload: Dict[str, Any] = {"url": url}
    if search_filter:
        payload["search"] = search_filter

    res = _request("map", payload)
    return res.get("links", [])


if __name__ == "__main__":
    if len(sys.argv) < 3:
        print("Uso:")
        print("  python3 ops/lib/firecrawl_client.py scrape <url>")
        print('  python3 ops/lib/firecrawl_client.py search "<query>" [limit]')
        print("  python3 ops/lib/firecrawl_client.py map <url> [search_filter]")
        sys.exit(1)

    cmd = sys.argv[1].lower()
    target = sys.argv[2]

    try:
        if cmd == "scrape":
            result = scrape_url(target)
            print(f"=== Título: {result['title']} ===")
            print(f"=== Descripción: {result['description']} ===")
            print("\n--- Markdown (primeros 1000 caracteres) ---")
            print(result["markdown"][:1000])
        elif cmd == "search":
            lim = int(sys.argv[3]) if len(sys.argv) > 3 else 3
            results = search(target, limit=lim)
            print(f"Encontrados {len(results)} resultados para '{target}':")
            for i, r in enumerate(results, 1):
                print(f"\n{i}. {r.get('title')} ({r.get('url')})")
                if r.get("description"):
                    print(f"   {r.get('description')}")
        elif cmd == "map":
            s_filter = sys.argv[3] if len(sys.argv) > 3 else None
            links = map_site(target, search_filter=s_filter)
            print(f"Mapeadas {len(links)} URLs:")
            for link in links[:20]:
                print(f" - {link}")
            if len(links) > 20:
                print(f" ... y {len(links) - 20} más.")
        else:
            print(f"Comando desconocido: {cmd}")
            sys.exit(1)
    except Exception as err:
        print(f"ERROR: {err}", file=sys.stderr)
        sys.exit(1)
