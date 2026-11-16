# Promoción productiva integrada: Business 1.99.16 + Search 0.1.14 + Theme 2.1.70

Este runbook trata ambos plugins y el Theme como una sola unidad de release. El
par candidato es `akibara-business 1.99.16 + akibara-search 0.1.14`; el rollback
del par es `akibara-business 1.99.10 + akibara-search 0.1.12`. El Theme candidato
es `akibara-gp 2.1.70` y su rollback exacto es `2.1.69`. Nunca se activa WordPress
con un par de plugins mixto. Durante todos los cambios de plugin, Search permanece
inactivo.

La transición productiva se divide deliberadamente en dos fases. Primero se
promueve y valida el par Business/Search manteniendo `akibara-gp 2.1.69` intacto;
después, y sólo con el witness candidato del par validado, se despliega Theme
2.1.70 mediante el MCP oficial de Hostinger. La unidad de confirmación final sigue
siendo integrada: no se declara el release promovido hasta validar las tres
versiones, la auditoría IA pública y las purgas.

El script `ops/akibara-search-0.1.14-production-promotion.sh` no muta producción.
Valida localmente, prepara directorios exactos y comprueba evidencia material. La
operación remota se hace únicamente por la UI oficial indicada o por el MCP
oficial cuyos argumentos quedan congelados en `handoff.json`.

## 1. Condiciones que mantienen producción cerrada

No iniciar una promoción si falta cualquiera de estas condiciones:

- los tres contratos están `sealed`, se enlazan por SHA-256 y congelan los
  catorce artefactos: ZIP/inventario de candidato y rollback de Business/Search,
  más ZIP/inventario/sidecar de candidato y rollback del Theme;
- cada contrato separa `seal_requirements` (bytes/código que se congelan antes
  del sello) de `promotion_requirements` (receipts que sólo pueden existir luego
  del sello); el rail rechaza fusionar u omitir cualquiera de las dos;
- el orden contractual de caché es exactamente
  `litespeed-ui -> hostinger-mcp -> cloudflare-mcp`;
- los contratos declaran la purga LiteSpeed por UI, con evidencia persistida y
  sin aceptar un booleano autoafirmativo como recibo;
- `local-gate` tiene un recibo v3 fresco y sidecar 0600 para el digest de los tres
  contratos;
- ese recibo enlaza un `final-receipt.json` de recovery v2 real, también 0600,
  con sidecar válido, dos fresh restores, HTTP smoke hermético, integración
  MariaDB en baseline/candidato, Theme 2.1.70 activo y auditoría IA v3;
- preflight productivo fresco demuestra el par rollback activo y los inventarios
  exactos de ambos plugins;
- hay acceso a WordPress UI, Hostinger UI, Hostinger MCP y Cloudflare MCP. No se
  reemplaza una UI requerida con código ad hoc.

Business y Search son targets de la primera fase. Por eso Business **no**
pertenece al inventario protegido durante ese cambio. Los runtimes protegidos en
la primera fase son:

- `akibara-cart-checkout-blocks`;
- `bluex-for-woocommerce`;
- tema `akibara-gp` 2.1.69 exacto.

El Theme deja de ser protegido sólo después de validar el witness candidato del
par y abrir la segunda fase. En esa fase los plugins pasan a ser protegidos y no
se modifica ninguno de ellos.

No se crea respaldo remoto. Los ZIP, inventarios, recibos, rollback y cualquier
respaldo permanecen bajo `.local-backups/` o `dist/` en el Mac local. No se suben
ZIP, `.bak`, dumps, `.partial` ni directorios temporales al servidor.

## 2. Gate local aislado

Con los contratos ya sellados:

```bash
bash ops/akibara-search-0.1.14-production-promotion.sh verify-artifacts
bash ops/akibara-search-0.1.14-production-promotion.sh local-gate
```

El gate invalida primero cualquier recibo anterior. Ejecuta dos pasadas de:

- contrato estático de promoción;
- release/package Search;
- release/package Business;
- verificación exacta de ambos packagers;
- prueba estática del recovery;
- contrato estático y package reproducible del Theme;
- contrato funcional del handoff Theme fase 2, incluidos receipts de cierre y
  revocación, ejecutado dos veces;
- tests Node del Theme: 31/31;
- PHPUnit del Theme: 18 tests y 52 assertions;
- tests unitarios del auditor de descubribilidad IA;
- PHPUnit Search, integración Search/Business y PHPUnit Business dentro de una
  imagen PHP fijada por digest, `--network none`, filesystem read-only y `/tmp`
  efímero.

Después invoca **una vez** el recovery rail. Esa única invocación ya realiza dos
restores frescos internos. No se permiten `qa-search.sh`, `qa-search-mariadb.sh`,
`docker compose` ni consultas al WordPress/MariaDB principal desde este gate.

El recovery debe emitir exactamente una línea:

```text
Evidencia local: /ruta/absoluta/.local-backups/search-0.1.14-recovery/run-.../final-receipt.json
```

El consumidor recalcula el SHA del recibo y su sidecar. Además exige:

- `schema: akibara-search-recovery-cycle-v2`, `result: PASS`;
- `production_mutated:false` y `main_database_mutated:false`, acompañados por
  `source_database_verification` material, incluido el SHA preimage del par
  `akb_search_precio_buckets`/`akb_search_precio_mode`;
- dos ciclos exactos, iteraciones 1 y 2, con `fresh_restore:true`, limpieza
  Docker, ciclo de par exacto, Search inactivo en midpoints mixtos/listos y los
  hashes de ambos drenajes live (`initial` y `forward`);
- `cycles[].derived_price_pair`: dos checkpoints preimage y ocho normalizados,
  el mismo SHA normalizado en ambos ciclos, preservación exacta a través de
  rollback 0.1.12, forward, HTTP y MariaDB, y prueba estricta de que el valor v2
  almacenado coincide con la derivación del índice; ambos SHA deben ser los
  snapshots canónicos sellados en `derived_price_pair_transition`;
- `cycles[].protected_fingerprint_transition`: snapshot completo candidato
  `2663d214…315a`, rollback `5197e730…0c5c`, nueve checkpoints H32 y tres H31;
  una sola fila de feed contract cambia H32 ↔ H31 preservando `option_id`, nombre,
  presencia y `autoload`, y la transición ocurre con Search inactivo;
- `cycles[].http_smoke`: receipt semántico v1, imagen Apache fijada por digest,
  red interna, cero puertos publicados, `localhost:8080`, sin redirects, hashes
  de logs/script/receipt y fingerprint protegido idéntico antes/después;
- `cycles[].integrated_mariadb`: modos `baseline` y `candidate`, provider activo,
  reconciler sin divergencia y hashes de receipt/logs/script;
- `cycles[].theme_quality`: candidato Theme 2.1.70 activo desde el árbol exacto,
  package de 103 archivos, Node 31/31, PHPUnit 18/52 y red externa deshabilitada;
- `cycles[].ai_discoverability`: auditoría v3 sobre el origen canónico
  `https://akibara.cl/` usando sólo el fetch interno de Docker, redirects
  fail-closed y cero dependencia de red externa;
- `release_binding`: SHA de los tres contratos, los catorce hashes materiales,
  hashes del código de evidencia —incluidos `compose.yaml`, el packager Search y
  `package-runtime.sh`— y las cuatro imágenes fijadas, incluida Node por digest;
- `release-binding-initial.tsv` como archivo regular 0600, con cabecera y cierre
  exactos, 37 entradas `FILE` únicas y los bytes locales aún idénticos; su SHA
  debe coincidir con `initial_snapshot_sha256` y el sidecar final renombrado es
  el marcador de finalización;
- el fingerprint protegido de cada HTTP smoke coincide con el fingerprint de la
  base fuente verificada.

No reinterpretar un PASS del test estático como receipt recovery: el handoff sólo
se construye si existe el receipt de la corrida real.

## 3. Configuración privada y handoff

Crear fuera del repositorio un archivo 0600:

```text
HOSTINGER_DOMAIN=akibara.cl
HOSTINGER_USERNAME=USUARIO_REAL
CLOUDFLARE_ACCOUNT_ID=ACCOUNT_ID_REAL
```

Revisar primero el plan sin mutación y luego construir el handoff:

```bash
bash ops/akibara-search-0.1.14-production-promotion.sh plan --config /ruta/privada/promotion.conf
bash ops/akibara-search-0.1.14-production-promotion.sh handoff --config /ruta/privada/promotion.conf
```

`handoff.json` contiene:

- los dos contratos y sus SHA;
- dos targets exclusivos;
- cuatro árboles locales extraídos sin ZIP residual, con versión, tree digest,
  inventory SHA y conteo exacto;
- recibo local y recovery receipt enlazados por ruta y SHA;
- envelopes separados `deploy_business` y `deploy_search` para candidato y
  rollback, ambos por
  `mcp__hostinger_hosting__hosting_deployWordpressPlugin`;
- operaciones UI de Search, mantenimiento y LiteSpeed;
- Hostinger cache MCP y Cloudflare MCP con `{account_id,code}` exactos;
- la secuencia ordenada de 15 checkpoints.

Este handoff es intencionalmente exclusivo para el par de plugins: no contiene
un deploy del Theme y exige que 2.1.69 permanezca byte-exacto durante sus 15
checkpoints. El handoff Theme se genera por separado y sólo consume un
`candidate-verified-receipt.json` válido del par.

Validar el archivo antes de utilizarlo:

```bash
bash ops/akibara-search-0.1.14-production-promotion.sh verify-handoff --handoff /ruta/handoff.json
```

El validador rechaza archivos adicionales, symlinks, ZIP residuales, un solo
plugin, cualquier inventario divergente o una política de receipt modificada.

## 4. Preflight productivo material

Crear `preflight-evidence.json` v3 y su sidecar dentro del `evidence.root` del
handoff. Cada descriptor es `{path,sha256}` y apunta a una captura regular local.
El conjunto es cerrado; no admite capturas faltantes ni adicionales:

- respuesta Cloudflare que enlaza account ID, zone ID y `akibara.cl`;
- texto e imagen de WordPress UI con Business 1.99.10 + Search 0.1.12 activos;
- inventarios exactos baseline de Business y Search;
- inventario de los tres runtimes protegidos;
- fingerprint comercial y fingerprint persistente de superficies Search sin
  transición de fase. Esta captura productiva excluye únicamente
  `akibara_business_search_feed_contract_version`; el receipt recovery local
  conserva esa opción dentro del snapshot completo y prueba H32 ↔ H31 ↔ H32;
- listados UI de `public_html` y `public_html/wp-content/plugins`, más imagen,
  mostrando ambos directorios y ningún temporal/respaldo/ZIP.

Validar antes de la primera mutación:

```bash
bash ops/akibara-search-0.1.14-production-promotion.sh verify-preflight \
  --handoff /ruta/handoff.json \
  --evidence-dir /ruta/evidence
```

Sólo el `preflight-verified-receipt.json` generado por el validador abre la
ventana operativa. No editar ni regenerar el handoff después de este punto.

## 5. Promoción candidata: orden obligatorio

Ejecutar y capturar en este orden exacto:

1. `active-baseline`: Business 1.99.10 + Search 0.1.12, Search activo,
   mantenimiento apagado.
2. `maintenance-on`: activar mantenimiento por Hostinger UI.
3. `search-deactivated-ui`: desactivar Search por WordPress UI.
4. `search-inactive-verified`: comprobar por UI que Search está inactivo.
5. `business-deployed`: desplegar Business 1.99.16 con el MCP y los args del
   handoff.
6. `mixed-midpoint-search-inactive-verified`: Business 1.99.16 + Search 0.1.12,
   con Search inactivo. No cargar WordPress con Search activo en este estado.
7. `search-deployed`: desplegar Search 0.1.14 con su envelope MCP separado.
8. `ready-pair-search-inactive-verified`: verificar 1.99.16 + 0.1.14 aún con
   Search inactivo.
9. `search-activated-ui`: activar Search por WordPress UI.
10. `active-pair-health-verified`: comprobar el par activo, búsqueda, `/manga/`,
    inventarios y convergencia mientras mantenimiento sigue encendido.
11. `litespeed-ui-purged`: Purge All por la UI de LiteSpeed y recapturar la UI
    persistida. No sirve una nota manual `success:true`.
12. `hostinger-cache-purged`: ejecutar Hostinger cache MCP. El único receipt
    aceptado es el resultado contractual exacto `Request accepted`.
13. `maintenance-off`: apagar mantenimiento por Hostinger UI y verificarlo.
14. `cloudflare-purged`: purgar Cloudflare por MCP después del binding validado.
15. `final-evidence-cleanup-verified`: capturar salud pública, admin-status,
    inventarios, fingerprints y listados remotos sin artefactos temporales.

Cada checkpoint lleva timestamp, estado exacto, texto UI crudo e imagen propios.
No se reutiliza una captura entre checkpoints. El texto debe demostrar dominio,
versiones, activación de Search y estado de mantenimiento. La purga LiteSpeed debe
mostrar materialmente la acción y su persistencia.

Los receipts de deploy son dos archivos distintos con forma exacta:

```json
{"tool":"...","request":{},"response":{}}
```

No se acepta prosa como éxito. El timeout normalizado de 120 segundos es sólo
`ACK-loss`; puede concluir promoción únicamente después de validar el par activo,
ambos inventarios exactos y el witness bundle completo. Un timeout distinto, un
error anidado o un envelope ausente cierra la promoción.

## 6. Witness bundle candidato

`candidate-evidence.json` v3 debe incluir exactamente:

- timeline de 15 checkpoints;
- `business_deploy_response` y `search_deploy_response` separados;
- `hostinger_cache_response` y `cloudflare_response`;
- HAR con Search 200 y `/manga/` 200;
- inventarios candidato exactos de ambos plugins;
- runtime protegido, commerce y superficies Search sin transición de fase
  idénticos al preflight. La opción controlada de feed contract no forma parte de
  esa comparación productiva genérica porque cambia legítimamente entre pares;
- admin-status con topología `clean_pre`, `rebuild:null`, cero trabajo/deuda y
  `docs == eligible > 0`;
- listados finales de webroot/plugins e imagen de limpieza.

Validar:

```bash
bash ops/akibara-search-0.1.14-production-promotion.sh verify-witnesses \
  --handoff /ruta/handoff.json \
  --evidence-dir /ruta/evidence \
  --purpose candidate
```

Sin `candidate-verified-receipt.json` no hay promoción confirmada.

## 7. Transición Theme después del witness del par

La fase 2 sólo se abre si `candidate-verified-receipt.json` del par sigue fresco,
su sidecar es válido y el rail vuelve a validar el handoff, el manifest y todos
los bytes de la fase 1. Preparar el handoff exclusivo Theme:

```bash
bash ops/akibara-theme-2.1.70-production-promotion.sh plan \
  --config /ruta/privada/promotion.conf
bash ops/akibara-theme-2.1.70-production-promotion.sh handoff \
  --config /ruta/privada/promotion.conf
bash ops/akibara-theme-2.1.70-production-promotion.sh verify-handoff \
  --handoff /ruta/theme-handoff.json
```

El árbol candidato y el rollback se extraen localmente desde los artefactos
sellados. Nunca se sube un ZIP. El handoff congela exactamente esta llamada MCP:

```json
{"activate":true,"domain":"akibara.cl","slug":"akibara-gp","themePath":"/ruta/absoluta/al/arbol/exacto"}
```

Antes de mutar producción, crear `theme-preflight-evidence.json` y sidecar 0600
con el binding Cloudflare; texto e imagen UI que prueben Theme 2.1.69 y el par
1.99.16/0.1.14 activos; inventarios exactos de los tres componentes; runtime no
objetivo, fingerprints, convergencia Search; y listados de `public_html`,
`public_html/wp-content/plugins` y `public_html/wp-content/themes`, más imagen de
limpieza. Los listados no pueden contener ZIP, dump, backup, `.partial`, `.old`
ni temporales. Validar:

```bash
bash ops/akibara-theme-2.1.70-production-promotion.sh verify-preflight \
  --handoff /ruta/theme-handoff.json \
  --evidence-dir /ruta/theme-evidence
```

Sólo `theme-preflight-verified-receipt.json` abre la ventana. Después ejecutar y
capturar los once checkpoints candidatos exactos:

1. `active-theme-baseline`;
2. `maintenance-on` por Hostinger UI;
3. `theme-deployed` por el MCP oficial con los argumentos congelados;
4. `active-theme-target-verified` por WordPress Theme UI;
5. `protected-pair-health-verified` con Business/Search activos e inventarios
   idénticos a la fase 1;
6. `litespeed-ui-purged`, con evidencia UI persistida;
7. `hostinger-cache-purged` por MCP;
8. `maintenance-off`, verificado antes de Cloudflare;
9. `cloudflare-purged` por MCP y binding exacto;
10. `public-ai-audit-passed` desde `https://akibara.cl/` en modo estricto;
11. `final-evidence-cleanup-verified` sobre las tres rutas remotas.

Cada checkpoint requiere texto crudo e imagen propios, timestamp estrictamente
creciente, versiones 1.99.16/0.1.14 protegidas, Theme y mantenimiento exactos.
El resultado exacto de timeout de 120 segundos del deploy es sólo `ACK-loss`:
jamás prueba una versión. Cualquier fallo explícito se rechaza; incluso con ACK o
ACK-loss siempre son obligatorios Theme activo, inventario 103/103, inventarios
Business/Search intactos y witness completo.

`theme-candidate-evidence.json` debe sumar envelopes de deploy, Hostinger cache y
Cloudflare; inventarios; fingerprints; estado durable Search; HAR 200 de portada,
`/manga/` y Search; auditoría IA v3; y los tres listados finales sin residuos. La
auditoría ocurre después de Cloudflare y exige tienda online sin ubicación física
pública, sin dirección exacta, `LocalBusiness`, `Place`, mapa ni retiro abierto.

```bash
bash ops/akibara-theme-2.1.70-production-promotion.sh verify-witnesses \
  --handoff /ruta/theme-handoff.json \
  --evidence-dir /ruta/theme-evidence \
  --purpose candidate
```

Esto emite `theme-candidate-verified-receipt.json` y un
`integrated-close-receipt.json` todavía con `graphify_allowed:false`. Aún no
autoriza actualizar Graphify.

Si el Theme falla, usar exclusivamente el árbol rollback 2.1.69 del mismo handoff
y sus diez checkpoints `operations.rollback`; no revertir el par confirmado salvo
incompatibilidad material. Validar con `--purpose rollback`. El rail emite
`theme-rollback-verified-receipt.json` y
`integrated-close-revocation-receipt.json`, ambos manteniendo Graphify prohibido.
La segunda fase nunca se declara exitosa por el ACK del transporte.

## 8. Rollback simétrico del par

Ante cualquier fallo, no intentar reparar con Search activo. Mantener o reactivar
mantenimiento y ejecutar la misma secuencia de 15 checkpoints con estos estados:

- baseline activo: Business 1.99.16 + Search 0.1.14;
- desactivar Search por UI;
- desplegar Business 1.99.10;
- demostrar midpoint 1.99.10 + 0.1.14 con Search inactivo;
- desplegar Search 0.1.12;
- demostrar par rollback listo aún inactivo;
- activar Search por UI y validar par rollback activo;
- purgar `LiteSpeed UI -> Hostinger MCP`;
- apagar mantenimiento;
- purgar Cloudflare;
- capturar evidencia y limpieza final.

Usar exclusivamente los paths `operations.rollback` del mismo handoff. Validar:

```bash
bash ops/akibara-search-0.1.14-production-promotion.sh verify-witnesses \
  --handoff /ruta/handoff.json \
  --evidence-dir /ruta/evidence \
  --purpose rollback
```

## 9. Cierre integrado

Antes de cerrar, confirmar por los tres listados remotos que permanecen los
directorios finales `akibara-business`, `akibara-search` y `akibara-gp`, sin ZIP,
dump, backup, `.partial`, `.old` ni copia temporal. El rollback y todos los
receipts se conservan sólo localmente.

Revalidar desde cero el handoff, la evidencia candidata, sus sidecars, el receipt
de fase 1, el receipt Theme y la ausencia de rollback/revocación posterior:

```bash
bash ops/akibara-theme-2.1.70-production-promotion.sh verify-close \
  --handoff /ruta/theme-handoff.json \
  --evidence-dir /ruta/theme-evidence
```

Únicamente `integrated-close-verified-receipt.json` con
`graphify_allowed:true` es el marcador de cierre integrado. El receipt pendiente,
un PASS de suite, una respuesta MCP o una auditoría aislada no lo sustituyen.

Registrar por separado uno de estos resultados:

- **promovido**: existen receipts validados del par y Theme, auditoría IA pública
  en PASS, purgas confirmadas y las tres versiones exactas visibles;
- **rollback completado**: existe receipt rollback validado;
- **par promovido, Theme pendiente**: estado transitorio explícito; no equivale a
  release integrado promovido y no autoriza actualizar Graphify;
- **cerrado sin mutación**: ningún deploy/UI productivo comenzó;
- **no confirmado**: hubo mutación pero no existe un witness validado; escalar y
  no actualizar Graphify.

Graphify se actualiza únicamente después de verificar ese receipt final y de
confirmar que no existe una revocación más nueva.
