#!/usr/bin/env bash
#
# Endurecimiento y aprovechamiento de Cloudflare para akibara.cl
#
# Auditoría de origen: 2026-07-29. Todas las unidades son idempotentes y
# ninguna reemplaza reglas existentes: se añaden reglas nuevas a los rulesets
# vivos (POST .../rules) y se identifican por el prefijo "AKB:" en la
# descripción, que es lo que hace posible el rollback selectivo.
#
# Uso:
#   export CF_API_TOKEN=...            # scopes en el runbook
#   ops/akibara-cloudflare-hardening.sh snapshot
#   ops/akibara-cloudflare-hardening.sh plan
#   ops/akibara-cloudflare-hardening.sh apply --confirm --only=caa,tiered-cache
#   ops/akibara-cloudflare-hardening.sh verify
#   ops/akibara-cloudflare-hardening.sh rollback --confirm --from=<dir-snapshot>
#
set -Eeuo pipefail
IFS=$'\n\t'
umask 077

script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
repo_root="$(cd -- "${script_dir}/.." && pwd)"

readonly ZONE_ID="b298201b3a38d017bc8413d9fd7414cd"
readonly ZONE_NAME="akibara.cl"
readonly ACCOUNT_ID="0b7a0ebd3a8d35560954115191ba99a4"
readonly ORIGIN_IP="82.25.67.26"
readonly API="https://api.cloudflare.com/client/v4"
readonly RULE_PREFIX="AKB:"

readonly ALL_UNITS="caa dnssec early-hints hsts tiered-cache qs-normalize cache-404 redirect-www"
#
# Unidades retiradas tras leer el snapshot del 2026-07-29 — la zona ya estaba
# mucho mejor configurada de lo que la auditoría a ciegas podía ver:
#
#   min-tls          RETIRADA POR PELIGROSA. La zona ya exige TLS 1.3; fijar 1.2
#                    habría sido un retroceso de seguridad, no una mejora.
#   ratelimit-login  Ya existe "Block wp-login.php brute force" (5 req/10 s) y
#                    el plan Free admite una sola regla de rate limiting.
#   cache-home       La home NO está sin cachear por descuido: hay una regla
#                    "BYPASS Home reviews freshness" para que las reseñas se
#                    vean frescas. Es una decisión de producto, no un hueco.
#   always-online    Sigue en off, que es lo correcto para un catálogo con
#                    precio y stock.

evidence_root="${repo_root}/.local-backups/cloudflare-hardening"
evidence_dir=""
selected_units="${ALL_UNITS}"
confirmed=0
rollback_from=""

die() { printf '\033[31mERROR:\033[0m %s\n' "$*" >&2; exit 1; }
info() { printf '\033[36m›\033[0m %s\n' "$*"; }
ok() { printf '\033[32m✓\033[0m %s\n' "$*"; }
warn() { printf '\033[33m!\033[0m %s\n' "$*"; }

require_deps() {
  command -v curl >/dev/null || die "falta curl"
  command -v jq >/dev/null || die "falta jq"
}

require_token() {
  [[ -n "${CF_API_TOKEN:-}" ]] || die "exporta CF_API_TOKEN (scopes en el runbook)"
  # Los placeholders del runbook copiados tal cual son el error más habitual y
  # Cloudflare los reporta como "6003 Invalid request headers", que no orienta.
  case "${CF_API_TOKEN}" in
    ...|'<token>'|TOKEN|xxx*|tu_token*)
      die "CF_API_TOKEN vale '${CF_API_TOKEN}': pega el token real, no el marcador del runbook" ;;
  esac
  if (( ${#CF_API_TOKEN} < 30 )); then
    die "CF_API_TOKEN parece truncado (${#CF_API_TOKEN} caracteres; un API token de Cloudflare tiene ~40)"
  fi

  # Preflight: falla aquí, con un diagnóstico útil, en vez de a media escritura.
  local resp code
  resp="$(curl -sS -X GET "${API}/zones/${ZONE_ID}" \
          -H "Authorization: Bearer ${CF_API_TOKEN}" -H "Content-Type: application/json")" \
    || die "no hay conexión con la API de Cloudflare"
  if [[ "$(jq -r '.success' <<<"${resp}")" != "true" ]]; then
    code="$(jq -r '.errors[0].code // "?"' <<<"${resp}")"
    case "${code}" in
      6003|1000) die "token inválido o mal formado. Créalo en My Profile → API Tokens → Create Token → Custom (scopes en el runbook)" ;;
      9109|10000) die "el token es válido pero le faltan permisos sobre ${ZONE_NAME}. Revisa los scopes del runbook" ;;
      *) jq -r '.errors[]? | "  [\(.code)] \(.message)"' <<<"${resp}" >&2
         die "la API rechazó el preflight" ;;
    esac
  fi
  ok "token válido sobre ${ZONE_NAME}"
}

# cf <método> <path> [body-json]
cf() {
  local method="$1" path="$2" body="${3:-}"
  local args=(-sS -X "${method}" "${API}${path}"
              -H "Authorization: Bearer ${CF_API_TOKEN}"
              -H "Content-Type: application/json")
  [[ -n "${body}" ]] && args+=(--data "${body}")
  local resp
  resp="$(curl "${args[@]}")" || die "curl falló: ${method} ${path}"
  if [[ "$(jq -r '.success' <<<"${resp}")" != "true" ]]; then
    printf '%s\n' "${resp}" | jq -r '.errors[]? | "  [\(.code)] \(.message)"' >&2
    die "API rechazó ${method} ${path}"
  fi
  printf '%s\n' "${resp}"
}

cf_result() { cf "$@" | jq '.result'; }

# Igual que cf_result pero no aborta el script: devuelve 1 si la API rechaza.
# `cf` corre en subshell, así que su `die` sólo mata ese subshell.
cf_result_try() {
  local out
  out="$(cf "$@" 2>/dev/null)" || return 1
  jq '.result' <<<"${out}"
}

# Respalda todos los rulesets de zona legibles en <dir>/rulesets/.
# Best-effort a propósito: el token puede no tener permiso sobre alguna fase
# (p. ej. http_request_dynamic_redirect necesita Single Redirects) y eso no
# debe impedir aplicar unidades que tocan otras fases.
backup_rulesets() {
  local dir="$1" id phase omitidos=0
  mkdir -p "${dir}/rulesets"
  while IFS=$'\t' read -r id phase; do
    if cf_result_try GET "/zones/${ZONE_ID}/rulesets/${id}" > "${dir}/rulesets/${phase}.json" 2>/dev/null; then
      info "  ruleset ${phase} ($(jq '.rules | length' <"${dir}/rulesets/${phase}.json") reglas)"
    else
      rm -f "${dir}/rulesets/${phase}.json"
      warn "  ruleset ${phase}: el token no puede leerlo — NO queda respaldado"
      omitidos=$((omitidos + 1))
    fi
  done < <(cf_result GET "/zones/${ZONE_ID}/rulesets" | jq -r '.[] | select(.kind=="zone") | [.id,.phase] | @tsv')
  (( omitidos == 0 )) || warn "  ${omitidos} ruleset(s) sin respaldo: no apliques unidades que los toquen"
}

selected() { [[ " ${selected_units} " == *" $1 "* ]]; }

new_evidence_dir() {
  evidence_dir="${evidence_root}/$(date -u +%Y%m%dT%H%M%SZ)"
  mkdir -p "${evidence_dir}"
}

# ---------------------------------------------------------------- snapshot ---

cmd_snapshot() {
  require_deps; require_token; new_evidence_dir
  info "snapshot → ${evidence_dir}"

  cf_result GET "/zones/${ZONE_ID}"                        > "${evidence_dir}/zone.json"
  cf_result GET "/zones/${ZONE_ID}/settings"               > "${evidence_dir}/settings.json"
  cf_result GET "/zones/${ZONE_ID}/dns_records?per_page=200" > "${evidence_dir}/dns_records.json"
  cf_result GET "/zones/${ZONE_ID}/dnssec"                 > "${evidence_dir}/dnssec.json" || true
  cf_result GET "/zones/${ZONE_ID}/argo/tiered_caching"    > "${evidence_dir}/tiered_caching.json" || true
  cf_result GET "/zones/${ZONE_ID}/rulesets"               > "${evidence_dir}/rulesets.json"

  # Cada ruleset de zona, con sus reglas: es el respaldo real del rollback.
  backup_rulesets "${evidence_dir}"

  ok "snapshot completo. Guárdalo: es la base del rollback."
  printf '%s\n' "${evidence_dir}"
}

latest_snapshot() {
  local d
  d="$(ls -1d "${evidence_root}"/*/ 2>/dev/null | tail -1 || true)"
  [[ -n "${d}" ]] || die "no hay snapshot previo; corre 'snapshot' primero"
  printf '%s\n' "${d%/}"
}

# ------------------------------------------------------------------ helpers ---

# Sólo lectura: devuelve vacío si la fase aún no tiene ruleset de zona.
find_ruleset_id() {
  local phase="$1"
  cf_result GET "/zones/${ZONE_ID}/rulesets" | jq -r --arg p "${phase}" \
    '.[] | select(.kind == "zone" and .phase == $p) | .id' | head -1
}

# Crea el ruleset vacío de una fase. El cuerpo lleva SÓLO rules — mandar
# "phase" o "kind" hace que la API responda "invalid JSON: unknown field phase".
create_ruleset_for_phase() {
  local phase="$1" id
  id="$(cf_result PUT "/zones/${ZONE_ID}/rulesets/phases/${phase}/entrypoint" \
        '{"rules":[]}' 2>/dev/null | jq -r '.id')" || true
  [[ -n "${id}" && "${id}" != "null" ]] || return 1
  printf '%s\n' "${id}"
}

rule_exists() {
  local ruleset_id="$1" description="$2"
  cf_result GET "/zones/${ZONE_ID}/rulesets/${ruleset_id}" \
    | jq -e --arg d "${description}" '.rules[]? | select(.description == $d)' >/dev/null 2>&1
}

# add_rule <phase> <descripción> <json-de-la-regla>
add_rule() {
  local phase="$1" description="$2" rule="$3" ruleset_id
  ruleset_id="$(find_ruleset_id "${phase}")"

  if [[ -z "${ruleset_id}" || "${ruleset_id}" == "null" ]]; then
    # Un plan no debe escribir: crear el ruleset vacío ya es una mutación.
    if (( ! confirmed )); then
      info "[plan] crearía el ruleset de ${phase} y añadiría: ${description}"
      printf '%s\n' "${rule}" | jq . | sed 's/^/      /'
      return 0
    fi
    # create_ruleset_for_phase corre en subshell: su fallo hay que capturarlo
    # aquí o se seguiría con un id vacío.
    if ! ruleset_id="$(create_ruleset_for_phase "${phase}")"; then
      warn "el token no puede crear el ruleset de ${phase} — regla omitida"
      warn "  → falta el permiso de esa fase, o aplícala desde el dashboard"
      return 0
    fi
  fi

  if rule_exists "${ruleset_id}" "${description}"; then
    ok "ya existe, no se toca: ${description}"
    return 0
  fi
  if (( ! confirmed )); then
    info "[plan] añadiría a ${phase}: ${description}"
    printf '%s\n' "${rule}" | jq . | sed 's/^/      /'
    return 0
  fi
  cf POST "/zones/${ZONE_ID}/rulesets/${ruleset_id}/rules" "${rule}" >/dev/null
  ok "añadida a ${phase}: ${description}"
}

# set_setting <setting_id> <valor-json>
set_setting() {
  local id="$1" value="$2" current
  current="$(cf_result GET "/zones/${ZONE_ID}/settings/${id}" | jq -c '.value')" || {
    warn "no se pudo leer el setting ${id} (¿scope del token?)"; return 0; }
  if [[ "${current}" == "${value}" ]]; then
    ok "${id} ya está en ${value}"
    return 0
  fi
  if (( ! confirmed )); then
    info "[plan] ${id}: ${current} → ${value}"
    return 0
  fi
  cf PATCH "/zones/${ZONE_ID}/settings/${id}" "$(jq -nc --argjson v "${value}" '{value: $v}')" >/dev/null
  ok "${id}: ${current} → ${value}"
}

# ------------------------------------------------------------------ unidades ---

# CAA — ambos certificados vivos (edge y origen) son Let's Encrypt; se listan
# además las CAs que Cloudflare puede rotar en Universal SSL y la que Hostinger
# podría usar como respaldo. Sin CAA, cualquier CA pública puede emitir.
unit_caa() {
  local cas=(letsencrypt.org pki.goog ssl.com digicert.com comodoca.com)
  local existing
  existing="$(cf_result GET "/zones/${ZONE_ID}/dns_records?type=CAA&per_page=100")"
  if [[ "$(jq 'length' <<<"${existing}")" != "0" ]]; then
    ok "ya hay registros CAA, no se tocan"
    return 0
  fi
  local tag ca body
  for ca in "${cas[@]}"; do
    for tag in issue issuewild; do
      body="$(jq -nc --arg n "${ZONE_NAME}" --arg t "${tag}" --arg v "${ca}" \
        '{type:"CAA", name:$n, ttl:1, data:{flags:0, tag:$t, value:$v},
          comment:"Hardening CF 2026-07-29"}')"
      if (( ! confirmed )); then info "[plan] CAA ${tag} ${ca}"; continue; fi
      cf POST "/zones/${ZONE_ID}/dns_records" "${body}" >/dev/null
    done
  done
  body="$(jq -nc --arg n "${ZONE_NAME}" \
    '{type:"CAA", name:$n, ttl:1, data:{flags:0, tag:"iodef", value:"mailto:dmarc@akibara.cl"},
      comment:"Hardening CF 2026-07-29"}')"
  if (( confirmed )); then
    cf POST "/zones/${ZONE_ID}/dns_records" "${body}" >/dev/null
    ok "CAA creado (${#cas[@]} CAs × issue/issuewild + iodef)"
  else
    info "[plan] CAA iodef mailto:dmarc@akibara.cl"
  fi
}

# DNSSEC — activarlo en Cloudflare sólo genera el DS; hay que publicarlo en el
# registrador (Hostinger) o la zona queda a medio firmar y no protege nada.
unit_dnssec() {
  local status
  status="$(cf_result GET "/zones/${ZONE_ID}/dnssec" | jq -r '.status')"
  if [[ "${status}" == "active" ]]; then ok "DNSSEC ya activo"; return 0; fi
  if (( ! confirmed )); then info "[plan] DNSSEC: ${status} → active (+ publicar DS en Hostinger)"; return 0; fi
  local res
  res="$(cf_result PATCH "/zones/${ZONE_ID}/dnssec" '{"status":"active"}')"
  ok "DNSSEC habilitado en Cloudflare. Publica este DS en el registrador:"
  jq -r '"    \(.key_tag) \(.algorithm) \(.digest_type) \(.digest)"' <<<"${res}"
  warn "hasta publicar el DS, DNSSEC NO está protegiendo la zona"
}

# Comprobación idempotente: ya estaba en "on" el 2026-07-29. Se mantiene para
# detectar si alguien lo apaga.
unit_early_hints()  { set_setting early_hints '"on"'; }

# HSTS: un año, SIN includeSubDomains ni preload. Los subdominios de correo
# (autoconfig, autodiscover) no son web y preload es prácticamente irreversible;
# subir a includeSubDomains es una decisión aparte, documentada en el runbook.
unit_hsts() {
  set_setting security_header \
    '{"strict_transport_security":{"enabled":true,"max_age":31536000,"include_subdomains":false,"preload":false,"nosniff":true}}'
}

# Tiered Cache + Smart Topology: gratis en todos los planes. Con el origen en
# Hostinger y el tráfico en Chile, embudo los MISS por un upper tier cercano
# al origen en vez de que cada colo pegue al origen por su cuenta.
unit_tiered_cache() {
  local current
  current="$(cf_result GET "/zones/${ZONE_ID}/argo/tiered_caching" | jq -r '.value')"
  if [[ "${current}" == "on" ]]; then
    ok "Tiered Cache ya activo"
  elif (( confirmed )); then
    cf PATCH "/zones/${ZONE_ID}/argo/tiered_caching" '{"value":"on"}' >/dev/null
    ok "Tiered Cache activado"
  else
    info "[plan] Tiered Cache: ${current} → on"
  fi
  if (( confirmed )); then
    cf PATCH "/zones/${ZONE_ID}/cache/tiered_cache_smart_topology_enable" '{"value":"on"}' >/dev/null
    ok "Smart Topology activado"
  else
    info "[plan] Smart Topology → on"
  fi
}

# Normaliza la query de las URLs de campaña, que hoy generan una entrada de
# caché por clic (cada fbclid es único, así que siempre MISS).
#
# Escrita SIN el operador regex a propósito: `matches` está restringido por
# plan, y una expresión que el plan Free rechace haría fallar el apply entero.
# Con starts_with + not contains funciona en cualquier plan y además es más
# conservadora: si aparece cualquier parámetro funcional, la regla no actúa.
#
# Es rewrite, no redirect: el navegador conserva los parámetros en la barra de
# direcciones, así que GA4 y Meta siguen atribuyendo igual.
unit_qs_normalize() {
  local description="${RULE_PREFIX} normalizar query 100% tracking (sube hit ratio de campañas)"
  local tracking=(fbclid= gclid= dclid= msclkid= ttclid= igshid= mc_cid= mc_eid= _gl= utm_source= utm_medium= utm_campaign= utm_term= utm_content=)
  # Cualquiera de estos en la query significa que cambia el contenido servido:
  # si aparece uno solo, la query se deja intacta.
  local functional=(s= orderby= paged= _buscar= add-to-cart wc-ajax p= page_id product_cat filter_ min_price max_price preview elementor ref= rest_route)

  local starts="" nots="" t f
  for t in "${tracking[@]}"; do
    [[ -n "${starts}" ]] && starts+=" or "
    # starts_with es una FUNCIÓN, no un operador infijo: escribirlo como
    # `campo starts_with "x"` da "Filter parsing error ... expected ComparisonOp".
    # `contains`, en cambio, sí es infijo. Las reglas ya existentes en la zona
    # usan la forma correcta: starts_with(http.request.uri.path, "/wp-content/").
    starts+="starts_with(http.request.uri.query, \"${t}\")"
  done
  for f in "${functional[@]}"; do
    nots+=" and not http.request.uri.query contains \"${f}\""
  done

  local rule
  rule="$(jq -nc --arg d "${description}" --arg e "((${starts})${nots})" '{
    description: $d,
    expression: $e,
    action: "rewrite",
    action_parameters: { uri: { query: { value: "" } } },
    enabled: true
  }')"
  add_rule http_request_transform "${description}" "${rule}"
}

# Los 404 pesan 108 KB y hoy se cachean 1 h bajo prefijos cacheables: una lista
# de URLs inventadas llena la caché.
#
# La fase de respuesta NO sirve: `cache: false` sólo existe en la fase de
# request y la API responde "20250 cannot use setting in this phase". El
# mecanismo correcto es `status_code_ttl` dentro del `edge_ttl` de la regla que
# ya cachea el HTML — es decir, la única unidad que modifica una regla viva.
# Por eso es quirúrgica: sólo añade status_code_ttl y deja el resto intacto.
# Se busca por prefijo, no por igualdad: la unidad edge-ttl-html reescribe el
# sufijo de la descripción ("edge 1h" → "edge 5min") y una comparación exacta
# dejaría de encontrarla después.
readonly CACHE_HTML_RULE_DESC="CACHE HTML guests"

# Devuelve la regla viva que cachea el HTML público, o vacío.
find_cache_html_rule() {
  local rid="$1"
  cf_result GET "/zones/${ZONE_ID}/rulesets/${rid}" \
    | jq -c --arg d "${CACHE_HTML_RULE_DESC}" \
        '.rules[]? | select(.description != null and (.description | startswith($d)))' | head -1
}

unit_cache_404() {
  local rid rule_id current patched
  rid="$(find_ruleset_id http_request_cache_settings)"
  [[ -n "${rid}" ]] || { warn "no encuentro el ruleset de caché"; return 0; }

  current="$(find_cache_html_rule "${rid}")"
  if [[ -z "${current}" ]]; then
    warn "no encuentro ninguna regla que empiece por \"${CACHE_HTML_RULE_DESC}\""
    warn "  → revisa el snapshot y ajusta CACHE_HTML_RULE_DESC en este script"
    return 0
  fi

  if jq -e '.action_parameters.edge_ttl.status_code_ttl' <<<"${current}" >/dev/null 2>&1; then
    ok "la regla de HTML ya define status_code_ttl, no se toca"
    return 0
  fi

  rule_id="$(jq -r '.id' <<<"${current}")"
  # Se conserva todo (expresión, cache, edge_ttl.default) y sólo se añade el
  # TTL corto para 4xx.
  patched="$(jq -c '{
      expression, description, action, enabled,
      action_parameters: (.action_parameters * {
        edge_ttl: (.action_parameters.edge_ttl + {
          status_code_ttl: [{ status_code_range: { from: 400, to: 499 }, value: 10 }]
        })
      })
    }' <<<"${current}")"

  if (( ! confirmed )); then
    info "[plan] añadiría status_code_ttl 4xx=10s a la regla \"${CACHE_HTML_RULE_DESC}\""
    info "       edge_ttl actual: $(jq -c '.action_parameters.edge_ttl' <<<"${current}")"
    return 0
  fi
  cf PATCH "/zones/${ZONE_ID}/rulesets/${rid}/rules/${rule_id}" "${patched}" >/dev/null
  ok "4xx pasan a cachearse 10 s en vez de 1 h (regla \"${CACHE_HTML_RULE_DESC}\")"
  warn "esta unidad modificó una regla existente: para revertirla, restaura su"
  warn "  edge_ttl desde rulesets/http_request_cache_settings.json del snapshot"
}

# LiteSpeed sólo purga Cloudflare en "Purgar todo" (única llamada, en
# purge.cls.php:227). Las purgas por producto no tocan el edge, así que tras
# cambiar precio o stock la PDP sigue servida desde caché hasta que expira el
# Edge TTL. Con 3600 s eso son 60 minutos de precio viejo; con 300 s, 5.
#
# Es un compromiso, no una mejora gratuita: baja algo el hit ratio. Por eso la
# unidad está fuera de ALL_UNITS y el TTL es parametrizable.
: "${AKB_EDGE_TTL:=300}"

unit_edge_ttl_html() {
  local rid rule_id current actual patched nueva_desc
  rid="$(find_ruleset_id http_request_cache_settings)"
  [[ -n "${rid}" ]] || { warn "no encuentro el ruleset de caché"; return 0; }

  current="$(find_cache_html_rule "${rid}")"
  if [[ -z "${current}" ]]; then
    warn "no encuentro ninguna regla que empiece por \"${CACHE_HTML_RULE_DESC}\""
    return 0
  fi

  actual="$(jq -r '.action_parameters.edge_ttl.default // "sin edge_ttl"' <<<"${current}")"
  if [[ "${actual}" == "${AKB_EDGE_TTL}" ]]; then
    ok "el Edge TTL del HTML ya es ${AKB_EDGE_TTL}s"
    return 0
  fi

  # La descripción lleva el TTL escrito ("edge 1h"); si no se actualiza, queda
  # mintiendo sobre lo que hace la regla.
  local etiqueta
  if (( AKB_EDGE_TTL % 3600 == 0 )); then etiqueta="edge $((AKB_EDGE_TTL/3600))h"
  elif (( AKB_EDGE_TTL % 60 == 0 )); then etiqueta="edge $((AKB_EDGE_TTL/60))min"
  else etiqueta="edge ${AKB_EDGE_TTL}s"; fi
  nueva_desc="$(jq -r --arg e "${etiqueta}" '.description | sub("edge [^)]*"; $e)' <<<"${current}")"

  rule_id="$(jq -r '.id' <<<"${current}")"
  # Se conserva todo lo demás, incluido el status_code_ttl que puso cache-404.
  patched="$(jq -c --argjson ttl "${AKB_EDGE_TTL}" --arg desc "${nueva_desc}" '{
      expression, action, enabled,
      description: $desc,
      action_parameters: (.action_parameters * {
        edge_ttl: (.action_parameters.edge_ttl + { default: $ttl })
      })
    }' <<<"${current}")"

  if (( ! confirmed )); then
    info "[plan] Edge TTL del HTML: ${actual}s → ${AKB_EDGE_TTL}s"
    info "       descripción: \"$(jq -r '.description' <<<"${current}")\" → \"${nueva_desc}\""
    info "       status_code_ttl conservado: $(jq -c '.action_parameters.edge_ttl.status_code_ttl // "ninguno"' <<<"${current}")"
    return 0
  fi
  cf PATCH "/zones/${ZONE_ID}/rulesets/${rid}/rules/${rule_id}" "${patched}" >/dev/null
  ok "Edge TTL del HTML: ${actual}s → ${AKB_EDGE_TTL}s (ventana de precio viejo: $((AKB_EDGE_TTL/60)) min)"
  warn "modifica una regla existente: su edge_ttl original está en el snapshot"
}

# www → apex en el edge. Hoy lo resuelve WordPress (x-redirect-by: WordPress),
# o sea un viaje completo al origen para un 301 que Cloudflare da gratis.
unit_redirect_www() {
  local description="${RULE_PREFIX} www → apex en el edge (hoy lo hace WordPress)"
  local rule
  rule="$(jq -nc --arg d "${description}" --arg h "www.${ZONE_NAME}" --arg z "${ZONE_NAME}" '{
    description: $d,
    expression: ("(http.host eq \"" + $h + "\")"),
    action: "redirect",
    action_parameters: {
      from_value: {
        status_code: 301,
        target_url: { expression: ("concat(\"https://" + $z + "\", http.request.uri.path)") },
        preserve_query_string: true
      }
    },
    enabled: true
  }')"
  add_rule http_request_dynamic_redirect "${description}" "${rule}"
}

# --------------------------------------------------------------- plan/apply ---

run_units() {
  local u
  # El IFS=$'\n\t' del preámbulo impide dividir por espacios: sin esta línea el
  # bucle recibe las 8 unidades como una sola cadena y no ejecuta ninguna.
  local IFS=$' \t\n'
  for u in ${ALL_UNITS}; do
    selected "${u}" || continue
    printf '\n\033[1m── %s\033[0m\n' "${u}"
    case "${u}" in
      caa)             unit_caa ;;
      dnssec)          unit_dnssec ;;
      early-hints)     unit_early_hints ;;
      hsts)            unit_hsts ;;
      tiered-cache)    unit_tiered_cache ;;
      qs-normalize)    unit_qs_normalize ;;
      cache-404)       unit_cache_404 ;;
      redirect-www)    unit_redirect_www ;;
    esac
  done
  # Fuera de ALL_UNITS: es un compromiso frescura/rendimiento, se pide explícito.
  if selected edge-ttl-html; then
    printf '\n\033[1m── edge-ttl-html\033[0m\n'
    unit_edge_ttl_html
  fi
}

cmd_plan() { require_deps; require_token; confirmed=0; run_units;
  printf '\n'; info "esto fue un plan; nada se modificó. Aplica con: apply --confirm"; }

cmd_apply() {
  require_deps; require_token
  (( confirmed )) || die "apply exige --confirm"
  new_evidence_dir
  info "respaldando estado previo en ${evidence_dir}"
  cf_result GET "/zones/${ZONE_ID}/dns_records?per_page=200" > "${evidence_dir}/dns_records.pre.json"
  cf_result GET "/zones/${ZONE_ID}/settings" > "${evidence_dir}/settings.pre.json"
  backup_rulesets "${evidence_dir}"
  run_units
  printf '\n'; ok "aplicado. Rollback disponible con --from=${evidence_dir}"
  info "verifica ahora: $0 verify"
}

# Borra sólo lo que este script creó (prefijo AKB: y los CAA con su comentario).
cmd_rollback() {
  require_deps; require_token
  (( confirmed )) || die "rollback exige --confirm"
  local id phase rid reglas
  while IFS=$'\t' read -r id phase; do
    # Mismo motivo que en backup_rulesets: una fase ilegible no debe abortar el
    # rollback de las demás.
    if ! reglas="$(cf_result_try GET "/zones/${ZONE_ID}/rulesets/${id}")"; then
      warn "ruleset ${phase}: el token no puede leerlo — revísalo a mano"
      continue
    fi
    while read -r rid; do
      [[ -n "${rid}" ]] || continue
      cf DELETE "/zones/${ZONE_ID}/rulesets/${id}/rules/${rid}" >/dev/null
      ok "regla eliminada de ${phase}: ${rid}"
    done < <(jq -r --arg p "${RULE_PREFIX}" '.rules[]? | select(.description != null and (.description | startswith($p))) | .id' <<<"${reglas}")
  done < <(cf_result GET "/zones/${ZONE_ID}/rulesets" | jq -r '.[] | select(.kind=="zone") | [.id,.phase] | @tsv')

  while read -r rid; do
    [[ -n "${rid}" ]] || continue
    cf DELETE "/zones/${ZONE_ID}/dns_records/${rid}" >/dev/null
    ok "CAA eliminado: ${rid}"
  done < <(cf_result GET "/zones/${ZONE_ID}/dns_records?type=CAA&per_page=100" \
           | jq -r '.[] | select(.comment != null and (.comment | startswith("Hardening CF"))) | .id')

  warn "los settings (HSTS, TLS, early hints, tiered cache) NO se revierten aquí:"
  warn "compáralos contra settings.pre.json del snapshot y ajústalos a mano."
}

# --------------------------------------------------------------- verificación ---

probe() {
  local label="$1" url="$2"; shift 2
  local hdr out code ttfb
  hdr="$(mktemp)"
  # Una sola petición: un segundo curl para medir el TTFB falsearía el
  # cf-cache-status del primero (lo que fue MISS aparecería como HIT).
  out="$(curl -sS -o /dev/null --max-time 25 -D "${hdr}" "${url}" \
         -H 'User-Agent: Mozilla/5.0 Chrome/126' "$@" -w '%{http_code} %{time_starttransfer}')"
  code="${out%% *}"; ttfb="${out##* }"
  printf '  %-42s http=%s cf=%-8s ttfb=%ss\n' "${label}" "${code}" \
    "$(grep -i '^cf-cache-status' "${hdr}" | tr -d '\r' | cut -d' ' -f2)" "${ttfb}"
  rm -f "${hdr}"
}

cmd_verify() {
  require_deps
  local base="https://${ZONE_NAME}"

  info "caché: público debe dar HIT, privado debe dar DYNAMIC/BYPASS"
  probe "home (DYNAMIC esperado: reseñas frescas)" "${base}/"
  probe "listado /manga/" "${base}/manga/"
  probe "carrito (debe ser DYNAMIC)" "${base}/carrito/"
  probe "checkout (debe ser DYNAMIC)" "${base}/checkout/"
  probe "store api cart (debe ser DYNAMIC)" "${base}/wp-json/wc/store/v1/cart"
  probe "logueado (debe ser DYNAMIC)" "${base}/manga/" -H 'Cookie: wordpress_logged_in_x=a|1|b'
  probe "404 (objetivo: no cachear)" "${base}/manga/no-existe-$$.css"
  probe "campaña ?fbclid (objetivo: HIT)" "${base}/manga/?fbclid=zzz$$"

  printf '\n'; info "redirecciones"
  local via
  via="$(curl -sS -o /dev/null --max-time 25 -D - "https://www.${ZONE_NAME}/" | grep -i '^x-redirect-by' | tr -d '\r' || true)"
  if [[ -n "${via}" ]]; then
    warn "www → apex sigue saliendo del origen (${via}); falta redirect-www"
  else
    ok "www → apex resuelto en el edge"
  fi

  printf '\n'; info "origen expuesto (el hallazgo más grave)"
  local origin_code
  origin_code="$(curl -sS -o /dev/null --max-time 15 -k \
                 --resolve "${ZONE_NAME}:443:${ORIGIN_IP}" "${base}/" -w '%{http_code}' || echo "000")"
  if [[ "${origin_code}" == "403" || "${origin_code}" == "000" ]]; then
    ok "el origen ya no responde a peticiones que no vengan de Cloudflare (http=${origin_code})"
  else
    warn "el origen responde http=${origin_code} saltándose Cloudflare — aplica el bloque .htaccess del runbook"
  fi

  printf '\n'; info "DNS"
  local caa; caa="$(dig +short CAA "${ZONE_NAME}" | wc -l | tr -d ' ')"
  [[ "${caa}" != "0" ]] && ok "CAA publicado (${caa} registros)" || warn "sin CAA"
  local ds; ds="$(dig +short DS "${ZONE_NAME}" | wc -l | tr -d ' ')"
  [[ "${ds}" != "0" ]] && ok "DNSSEC con DS publicado" || warn "sin DS: DNSSEC no está protegiendo la zona"
}

# ---------------------------------------------------------------------- main ---

main() {
  local cmd="${1:-}"; shift || true
  local arg
  for arg in "$@"; do
    case "${arg}" in
      --confirm)   confirmed=1 ;;
      --only=*)    selected_units="${arg#--only=}"; selected_units="${selected_units//,/ }" ;;
      --from=*)    rollback_from="${arg#--from=}" ;;
      *)           die "opción desconocida: ${arg}" ;;
    esac
  done
  case "${cmd}" in
    snapshot) cmd_snapshot ;;
    plan)     cmd_plan ;;
    apply)    cmd_apply ;;
    rollback) cmd_rollback ;;
    verify)   cmd_verify ;;
    *) cat <<EOF
uso: $0 <snapshot|plan|apply|verify|rollback> [--confirm] [--only=a,b] [--from=dir]

unidades: ${ALL_UNITS}
           edge-ttl-html (opcional, no incluida por defecto; AKB_EDGE_TTL=300)

  snapshot  respalda zona, settings, DNS y todos los rulesets con sus reglas
  plan      muestra cada cambio sin tocar nada (por defecto)
  apply     aplica (exige --confirm); respalda antes
  verify    mide caché, redirecciones, origen expuesto y DNS contra producción
  rollback  borra sólo lo creado por este script (reglas "${RULE_PREFIX}" y CAA propios)
EOF
      exit 1 ;;
  esac
}

main "$@"
