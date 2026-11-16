#!/usr/bin/env bash
set -euo pipefail

project_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
interpreter_file="${project_root}/graphify-out/.graphify_python"

if [[ ! -s "${interpreter_file}" ]]; then
	echo "Falta graphify-out/.graphify_python. Ejecuta primero la instalación de Graphify." >&2
	exit 1
fi

graphify_python="$(<"${interpreter_file}")"
if [[ ! -x "${graphify_python}" ]]; then
	echo "El intérprete registrado de Graphify no existe o no es ejecutable." >&2
	exit 1
fi

# El grafo de Akibara superó el límite por defecto de la visualización HTML (5.000
# nodos) el 2026-07-31: eran 6.848 nodos y 12.748 aristas. El 2026-08-10 son
# 10.286 y 20.760 — creció un 50% en diez días, así que el techo de 12.000 que
# tenía este script quedaba a una sesión de distancia de volver a romperse. NO es basura indexada —
# medido, el 93% es código propio (plugins akibara-* 60%, ops/ 25%, theme 8%,
# tools/ 3%) y los terceros aportan 2 nodos, porque .graphifyignore ya los excluye.
# Sin esto, `update-graphify.py` construye y ESCRIBE graph.json correctamente y
# después revienta con un ValueError al exportar graph.html, lo que deja el grafo
# actualizado pero la visualización stale y un traceback que parece un fallo total.
# El 2026-09-19 el grafo llegó a 20.203 nodos y el techo de 20.000 volvió a romperse
# (misma historia: graph.json al día, graph.html viejo y un traceback que asusta).
# Se deja con holgura de verdad —el doble— en vez de otra cifra a una sesión de distancia.
# El 2026-10-28 el grafo llegó a 42.912 nodos y el techo de 40.000 volvió a romperse.
# Se amplía a 100.000 para garantizar holgura de sobra.
: "${GRAPHIFY_VIZ_NODE_LIMIT:=100000}"
export GRAPHIFY_VIZ_NODE_LIMIT

exec "${graphify_python}" "${project_root}/tools/update-graphify.py" "$@"
