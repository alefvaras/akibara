# Runbook local de recovery/rollback integrado — Search + Business + Theme + auditor IA

Este documento es el contrato operativo inmutable del rail. No declara un
estado de ejecución mutable: `plan` mantiene el gate cerrado y únicamente un
`final-receipt.json` válido, emitido antes de su sidecar SHA-256, puede acreditar
una corrida concreta.

Este rail demuestra, antes de tocar la base local principal o producción, que
el snapshot bloqueado actual puede recuperarse y que el rollback real del par
`Search 0.1.14 + Business 1.99.16 -> Search 0.1.12 + Business 1.99.10 ->
Search 0.1.14 + Business 1.99.16` converge sin cambiar datos irreversibles. En
cada restore también monta y verifica el Theme candidato 2.1.70 exacto y ejecuta
el auditor de descubribilidad IA contra ese WordPress hermético conservando el
origen canónico público. Las dos repeticiones parten de restores frescos del
mismo dump; nunca se reutiliza una base ya mutada para fabricar un segundo PASS.

El rail no despliega, no usa MCP, no abre puertos, no arranca WordPress/cron
principales y no escribe la DB principal. La única lectura de la DB principal
es el preflight y `mariadb-dump` canónico con WordPress/cron detenidos.

## 1. Dependencias que mantienen el gate cerrado

No ejecutar `run` hasta que se cumplan todas:

1. Search 0.1.14 congelado, revisado y sellado dos veces de forma reproducible;
2. `dist/akibara-search-0.1.14.zip` e inventario idénticos al contrato sellado;
3. Business **1.99.16** congelado/sellado con feed contract 2; el contrato
   Search debe ligar por SHA-256 ese contrato Business exacto. Business 1.99.15
   está rechazado y no es sustituto;
4. ZIP/inventarios rollback Search 0.1.12 y Business 1.99.10 conservan los
   hashes contractuales;
5. el contrato Theme 2.1.70 está sellado y liga candidato 2.1.70 y preimage
   2.1.69: cada rol exige ZIP, inventario, sidecar del ZIP y 103 archivos
   runtime exactos;
6. el motor, política, wrapper, test y runbook del auditor IA v3 conservan los
   SHA-256 sellados; su contrato declara tienda 100 % online, retiro coordinado
   en San Miguel y ninguna dirección pública de tienda física;
7. servicios Docker principales `wordpress` y `cron` en `Exited`, `db` healthy;
8. cero conexiones ajenas a `akibara`, WriteFence e install lock libres;
9. el estado raw sigue siendo exactamente el auditado. Si un boot posterior lo
   cambió, el rail cierra; no se actualiza el hash para acomodar el drift.
10. el script HTTP, el gate MariaDB integrado y las cuatro imágenes Docker
    (MariaDB, WP-CLI, Apache y Node) están ligados por SHA-256/digest al
    contrato sellado.

Preimage auditado:

```text
run_id=8dde2d65-6029-4062-89cf-7fdd5af90c77
raw_state_sha256=b243ddb58271e4dac334ff5df18ca863b352b6119721f3d562c547897838fb0d
protocol=2
phase=BLOCKED
error=legacy_or_unreadable_state
topology=ready_swap
raw={"run_id":"8dde2d65-6029-4062-89cf-7fdd5af90c77","started_at":1785986256,"watermark":"2026-08-06 03:17:36","offset":8,"updated_at":1785988285,"protocol":2,"phase":"BLOCKED","error":"legacy_or_unreadable_state","topology":"ready_swap","heartbeat_at":1785988285}
```

## 2. Dry-run sin Docker ni escrituras

```bash
bash tools/akibara-releases/search-0.1.14-recovery-cycle.sh plan
bash tools/akibara-releases/test-search-0.1.14-recovery-cycle.sh
```

`plan` debe imprimir `PRODUCTION_GATE=CLOSED`. El test reemplaza `docker` por
un centinela que falla si se intenta contactar el daemon; sólo hace validación
estática, `bash -n`, `php -l`, JSON y el plan sin escrituras.
También ejecuta fixtures negativos case/acento para el `EXCEPT`, reportes CHECK
incompletos/no-OK y el test aislado del guard local de IndexNow; ninguno abre
servicios ni red.

## 3. Ejecución local autorizada después del sello

Desde `/Users/alefvaras/Documents/akibara`:

```bash
bash tools/akibara-releases/search-0.1.14-recovery-cycle.sh run \
  --expected-run-id 8dde2d65-6029-4062-89cf-7fdd5af90c77 \
  --expected-state-sha256 b243ddb58271e4dac334ff5df18ca863b352b6119721f3d562c547897838fb0d
```

No cambiar esos argumentos sin una nueva auditoría bajo freeze. La salida y
todos los artefactos quedan con permisos privados bajo:

```text
.local-backups/search-0.1.14-recovery/
```

## 4. Backup local-only

Antes del dump el rail vuelve a exigir el freeze dual y usa `COUNT(*)`, nunca
`information_schema.TABLE_ROWS`. Exige 91 tablas base, todas InnoDB, y compara
el estado raw completo.

El stream exacto es:

```text
mariadb-dump
  --single-transaction --quick --skip-lock-tables --skip-comments
  --hex-blob --default-character-set=utf8mb4
  --routines --events --triggers --order-by-primary
  --databases akibara
| gzip -n -9 > archivo.sql.gz.partial
```

Después exige `gzip -t`, SHA-256 del gzip y del SQL descomprimido, modo `0600`
y rename atómico sin `.partial`. El directorio usa `0700` y el proceso `umask
077`. No se crea backup, dump, script o temporal remoto.

Las superficies protegidas se capturan por SQL puro V2 antes y después del dump;
en esa misma fase candidata deben ser byte-idénticas. El fingerprint materializa
también options ausentes y, para las presentes, `option_id`, valor y `autoload`,
por lo que ausencia, cadena vacía, identidad o bytes distintos no colisionan.
El par derivado `akb_search_precio_buckets`/`akb_search_precio_mode` usa una
captura separada de nombre real, presencia, SHA-256 del valor raw y `autoload`;
en el preimage debe conservar exactamente buckets SHA
`bbf68fbe0a982bd89e4bc0d1c2e011779029484e88f6a0d926b8f8b8fd4fb7c3`
con `autoload=off` y mode ausente. El TSV completo, incluida su LF final, queda
anclado por SHA
`0df1d76c5d89f28087d4595fa4e64117ce526c39a97ccba23de7581cc9a719fc`;
la forma normalizada (`autoload=no`, mode `auto/no`) queda anclada por
`2c75951b6f1df45cd8e2eb7fd5e0ffae9fc8586df4b160406fbb6ee903825a05`.

## 5. Restore hermético y prueba canónica

Cada iteración crea una red Docker `--internal`, sin puertos, y una MariaDB
pinneada:

```text
mariadb@sha256:a794d9eb009e20de605858a11f32f63b4075cbd197c650436f0e3b457e4caed7
```

`/var/lib/mysql` vive en tmpfs de 4 GiB. Al boot sólo se entrega
`MARIADB_ROOT_PASSWORD`: no se entrega `MARIADB_DATABASE`, para que el dump
recree exactamente `akibara` con `utf8mb4/utf8mb4_uca1400_ai_ci`. Después del
restore se repite el dump canónico y ambos SHA deben ser byte-idénticos al
backup. También se prueban `CHECK TABLE`, `SHOW CREATE TABLE`, inventario exacto
`wp_akb_search_*`, conteos reales y las superficies protegidas. Cada reporte
CHECK debe contener exactamente una fila `status/OK` por cada tabla inventariada;
un solo OK, una tabla faltante o cualquier warning/error cierran el rail.

Sólo entonces se crea un usuario aleatorio limitado a `akibara.*`. WP-CLI usa:

```text
wordpress@sha256:909702ad806b574fafa50894808205724a2a78dc5a3e193c09c85f86322d89eb
```

La red sigue internal, `public_html` va read-only y los árboles Search/Business
se superponen read-only desde sus ZIP sellados. Las credenciales aleatorias no
se imprimen ni se escriben en el repositorio. El runtime se declara
`WP_ENVIRONMENT_TYPE=local`, carga el guard local de IndexNow y no depende de
ninguna red externa; la red internal sigue siendo la barrera física. Antes del
restore se ejecuta además el test aislado del guard y su archivo/hash están
ligados al contrato sellado.

La imagen HTTP es también inmutable:

```text
wordpress@sha256:b65f6fb4d8b669974d4e8d6f204bc5a11d67f4877473ba5ee97af0efd39e3d19
```

## 6. Recovery inicial revisable

El primer WP-CLI usa `--skip-plugins --skip-themes` y carga manualmente Search
0.1.14, de modo que ningún hook Search pueda mutar el preimage antes del gate.
El archivo dedicado
`tools/akibara-releases/search-0.1.14-recovery-proof.php` exige:

1. runtime exacto 0.1.14;
2. topología física `ready_swap` y estado raw/run ID exactos;
3. par derivado de precio en fase preimage exacta, revalidado también bajo el
   WriteFence y pegado al DROP;
4. install lock libre y ownership del WriteFence esperado;
5. igualdad live ↔ `_new` en ambos sentidos mediante `EXCEPT`: docs omite sólo
   `indexed_at`; tokens/facets comparan todas sus columnas;
6. una segunda revalidación de fence, estado, topología y contenido pegada al
   DDL;
7. único DROP autorizado:
   `Install::drop_shadow_tables([$fence, 'assert_owned'])`;
8. eliminación directa exclusivamente de:
   `akb_search_rebuild_state`, `akb_search_pending_rebuild`,
   `akb_search_rebuild_ids`, `akb_search_rebuild_live_writes`;
9. liberación verificada del fence antes de `Install::maybe_install()`;
10. `clean_pre`, par de precio todavía preimage, work físico válido, DB 1.7.0,
   sello `1.7.0-exact-1` y request
   pending v2 `schema_migration` con gracia real de 300 segundos.

El DDL no es transaccional. Ante cualquier fallo se descarta el contenedor y
se restaura de nuevo el backup; nunca se intenta adivinar una topología parcial.
Así el rail completo es reejecutable e idempotente sin convertir un estado
ambiguo en éxito.

Contraejemplos que deben cerrar:

- borrar sólo state dejando `ready_swap`;
- perder una sola tabla `_new`;
- cambiar un byte del estado raw;
- perder el fence antes del DROP;
- live y shadow con cualquier diferencia distinta de `docs.indexed_at`.

## 7. Gracia y drenaje acotado

El rail espera el `not_before` persistido; no adelanta los 300 segundos. Un boot
normal consume el pending y, mientras existe un run durable, el shell selecciona
exclusivamente IDs con:

```sql
hook = 'akb_search_rebuild_batch' AND status = 'pending'
```

Cada ID se relee y valida antes de ejecutar:

```text
wp action-scheduler action run <id ...>
```

La identidad depende de la generación y se valida antes de ejecutar cada ID:

- Search 0.1.14 exige args exactos `[run_id, baseline|repair]`, grupo
  `akibara-search`, status `pending`, `claim_id=0` y `extended_args=null`;
- el rollback histórico Search 0.1.12 sólo conoce `{"offset": n}`. En ese tramo
  el rail exige esa única clave, entero no negativo idéntico a `state.offset`,
  `run_id` presente en el estado, mismo grupo/status, `claim_id=0` y
  `extended_args=null`.

El validador queda además ligado a la versión exacta del árbol Search, por lo que
la identidad legacy no puede usarse para aceptar un action 0.1.14 ni viceversa.
Hay cotas de 64 rondas/512 IDs.

Una vez ausentes `state` y `pending`, el materializado editorial todavía puede
conservar deuda `live/pending` legítima: el primer `init` la persiste antes del
rebuild y Action Scheduler sólo la despierta. El rail la drena únicamente con
un ID por vez de:

```sql
hook = 'akb_search_pump_live'
AND status = 'pending'
AND group_slug = 'akibara-search'
```

Antes de cada ejecución relee hook, status, grupo, claim, prioridad, args,
`extended_args`, schedule, fecha y attempts. El runtime debe ser Action
Scheduler 3.9.3 con `ActionScheduler_DBStore`. Sólo acepta las parejas exactas
`[]` + schedule async/null o `{"barrier":"editorial_discount"}` + schedule
single/simple no recurrente. También prueba en MariaDB que tanto
`scheduled_date_gmt` como el menor `work.available_at` ya vencieron.

Después del CLI, el mismo ID debe quedar `complete`, conservar identidad,
incrementar `attempts` exactamente en uno y mantener `state/pending` ausentes.
Cada pump con deuda transforma entre 1 y 25 filas exactas de `pending` a
`acked`, sin cambiar el total ni dejar errores, leases, bloqueos o estados
desconocidos. Si la deuda ya llegó a cero pero quedan wakes duplicados válidos,
el rail los consume uno a uno como no-op probado; cualquier mutación del ledger
en esa fase falla. La cota conjunta es 128 pumps explícitos.

Está prohibido ejecutar `wp cron`, rebuild CLI, el runner general de Action
Scheduler o un drenaje por grupo. Si existe state o pending sin un batch
pendiente se falla cerrado; el drenaje live exige ambos ausentes y nunca invoca
Reconciler.

El gate v2 final exige `state/pending` ausentes, `clean_pre`, work
pending/leased/blocked = 0, error vacío, IDs docs exactamente iguales al
universo elegible no vacío, y receipts coherentes de generación, purge,
fingerprint semántico y materialización editorial. Las filas `acked` del ledger
live deben igualar ese universo. El receipt `akb_search_last_rebuild` se valida
como evidencia histórica autocontenida: shape exacto, `DONE`, cobertura exacta
y target determinístico `akb-search-<run_id>-<semantic_fingerprint>`. El primer
elemento del historial debe ser una copia exacta y también debe existir el
receipt editorial inmutable de ese run/revisión, ligado a la misma generación,
fingerprint y cobertura. No se
iguala su generación histórica con la vigente: las escrituras live ya
acreditadas deben haber publicado y supersedido esa generación, mientras los
receipts de purge/semántica/editorial vigentes se validan por separado.

## 8. Ciclo rollback/forward pareado exacto

Sobre el mismo restore de cada iteración:

1. converger el par Search 0.1.14 + Business 1.99.16 y acreditar gate v2;
2. acreditar que Search está activo en ese par, ejecutar
   `wp plugin deactivate akibara-search` y volver a acreditar que 0.1.14 quedó
   inactivo antes de cambiar cualquier árbol;
3. montar Business 1.99.10 manteniendo Search 0.1.14 inactivo y, con
   `--skip-plugins --skip-themes`, acreditar el midpoint mixto exacto; arrancar
   Business con Search aún inactivo y capturar el snapshot rollback completo;
4. montar Search 0.1.12, volver a comprobar el par rollback completo todavía
   inactivo, recapturar el mismo snapshot rollback y sólo entonces ejecutar
   `wp plugin activate akibara-search`;
5. comprobar el par rollback activo, dejar que el primer boot migre 1.7.0 →
   1.6.0, drenar sólo IDs pending con el envelope legacy
   `{"offset": state.offset}` exacto y exigir DB 1.6.0, state/pending ausentes,
   `clean_pre`, Business 1.99.10 e IDs docs = elegibles exactos; no se acepta la
   tolerancia histórica de 0,98;
6. acreditar el par rollback activo, desactivar Search 0.1.12 y comprobar su
   inactividad antes del cambio forward;
7. montar y arrancar Business 1.99.16 manteniendo Search 0.1.12 inactivo,
   acreditar ese midpoint mixto; después montar Search 0.1.14, comprobar el par
   candidato completo todavía inactivo y capturar el snapshot candidato exacto;
8. sólo entonces reactivar Search, comprobar el par candidato activo, migrar
   1.6.0 → 1.7.0, respetar otra gracia
   real de 300 s, drenar el hook y repetir todo el gate v2 con Business 1.99.16.

Se repite el ciclo completo desde otro restore fresco. El receipt incluye hashes
de los dos midpoints mixtos y de los dos pares listos, todos con Search inactivo.
Está prohibido cargar o activar Search 0.1.12 con Business 1.99.16 o Search
0.1.14 con Business 1.99.10.

El uso de WP-CLI en este rail es exclusivamente una prueba local hermética. En
producción, la desactivación y reactivación de Search se ejecutan y verifican en
la UI oficial de plugins de WordPress; los ZIP se despliegan por el transporte
oficial declarado en el contrato.

## 9. Theme, HTTP, auditor IA y gate MariaDB, sin tocar main

Antes del recovery de cada restore, el rail ejecuta el contrato estricto del
Theme, verifica otra vez su paquete reproducible y corre 31 pruebas Node y 18
pruebas PHPUnit/52 assertions con `--network none`. También ejecuta con Node
sin red el test del auditor IA. El árbol Theme candidato procede del ZIP
sellado; sus tests fuente están igualmente ligados por SHA-256. Como el ZIP
excluye `tests`, Node/PHPUnit usan un harness separado: primero copia y revalida
los 103 archivos candidato, luego agrega exactamente las 18 fuentes selladas.
No se usa un bind mount anidado bajo el árbol productivo read-only y el harness
nunca se monta en WP-CLI ni Apache. El preimage
2.1.69 se desempaqueta y valida para rollback, pero el runtime integrado monta
exclusivamente el candidato 2.1.70 y comprueba en la DB que sea el child theme
activo antes del recovery, antes del HTTP y después del HTTP.

La imagen Node inmutable es:

```text
node@sha256:1b2479dd35a99687d6638f5976fd235e26c5b37e8122f786fcd5fe231d63de5b
```

Después del `gate-v2` forward de cada restore fresco, y antes de destruir su
red, el rail inicia un Apache desechable en esa misma red `--internal`. No usa
la red de Compose ni `-p/--publish`; `HostConfig.PortBindings` debe estar vacío.
El root filesystem, `public_html`, los dos plugins y el Theme candidato quedan
read-only.
Sólo son escribibles por `tmpfs`:

```text
/tmp                       256 MiB
/run/apache2                16 MiB
/var/lock/apache2            8 MiB
/var/lib/php/sessions       64 MiB
```

Se omite deliberadamente `docker-entrypoint.sh`: el checkout ya contiene
`wp-config.php` y Apache arranca mediante `apache2-foreground`, evitando que el
entrypoint intente copiar o modificar el docroot. Un `auto_prepend_file`
generado por el rail define `DISABLE_WP_CRON=true` y
`WP_HTTP_BLOCK_EXTERNAL=true`. La barrera primaria sigue siendo la red internal.

Un segundo contenedor one-shot, con la misma imagen pinneada, ejecuta
`tools/akibara-releases/search-0.1.14-http-smoke.php`. Resuelve sólo el nombre
interno del Apache y envía la solicitud por ese origen local; el
`auto_prepend_file` presenta a WordPress el host y HTTPS canónicos de
`akibara.cl`, de modo que HTML, schema y canonicals se evalúan como producción
sin salir de la red internal. cURL no usa proxy, no sigue redirects, acepta sólo
HTTP y limita cada cuerpo a 8 MiB. Acredita con respuestas 200:

- suggest real de `one piece`, forma de items y límite de siete;
- paridad OPM búsqueda/alias/serie y acotación por tomo;
- paridad fuzzy `atalier`/`atelier` y sinónimo `AOT`/frase;
- títulos OPM, unidad Manga `tomo`, pack igual a un tomo;
- toolbar y botón Filtrar en cero resultados.

El receipt HTTP conserva únicamente estados, conteos, tamaños y hashes SHA-256;
nunca cuerpos de catálogo. Un redirect, JSON/HTML divergente, body vacío o
receipt duplicado cierra el rail. El fingerprint protegido se compara antes y
después del HTTP.

Con Apache todavía activo, un contenedor Node one-shot ejecuta el auditor IA v3
usando dos orígenes separados: `--base-url https://akibara.cl/` como identidad
canónica y `--fetch-base-url http://<apache-interno>/` como transporte. No
publica puertos y la política de redirects es manual/fail-closed, por lo que no
puede escapar por un 3xx. El reporte debe ser JSON v3, read-only, declarar que
no garantiza rankings y cerrar con `status=pass`, `exit_code=0`, cero fallos y
todos sus checks en pass/skip. La identidad comercial exigida es tienda online
sin local público; sólo se permite mencionar retiro coordinado en San Miguel,
nunca una dirección residencial o tributaria como storefront.

Sobre el mismo MariaDB en tmpfs, el rail crea y elimina dos schemas fixture por
iteración (`baseline` y `candidate`), cada uno con usuario aleatorio limitado a
su schema. Ejecuta el gate real
`public_html/wp-content/plugins/akibara-search/tests/live-write-ledger-integration.php`
con el par candidato montado read-only. Candidate debe emitir explícitamente
los PASS del provider Business `ACTIVE` y del Reconciler real con cero missing,
orphaned, stale, orphan facets, divergent, repair y mutación lógica. Ningún
schema fixture se crea en el MariaDB persistente principal.

## 10. Superficies protegidas en cada etapa

El fingerprint SQL puro V2 se valida contra el hash exacto de su fase completa;
no se excluye la opción de contrato Business. Todas las demás filas deben ser
idénticas y cualquier diferencia ajena a la transición H32 ↔ H31 cierra el rail:

- qlog completo;
- Arrival postmeta `_akb_onbackorder_at` y `_akb_first_instock_at`;
- options de sinónimos, stopwords, fuzzy, SKU fastpath, configuración de
  facetas, novedad, popular, categoría predeterminada,
  indexing, suggest, facets y TTL REST;
- `akb_search_audit_log`;
- options fuente editoriales, incluidos feed contract, decimales y zona horaria;
- termmeta `_akb_taxonomy_discount_pct`;
- catálogo de atributos WooCommerce completo;
- option `rank_math_indexnow_log`, que no puede crecer ni cambiar durante ningún
boot, migración, rebuild, HTTP, gate integrado o rollback local;
- presencia/ausencia, `option_id`, bytes y `autoload` de cada option.

La única transición permitida dentro de este snapshot completo es
`akibara_business_search_feed_contract_version`: exactamente una fila, con
`option_id=1320696`, nombre, presencia y `autoload=off` preservados; sólo cambia
`option_value` de `H32` a `H31` y vuelve a `H32`. El snapshot candidato completo
es `2663d2144a094779535360d489a1f868f67d386a6a0d86e415637cd37472315a` y el
rollback completo es
`5197e7307fcfe75676c76f26b6ef3bc3f9e7339975594216c2879c716f080c5c`.
El rollback se deriva del candidato por una sola sustitución de fila exacta;
fila ausente/duplicada, H31 preexistente, otro `option_id`, otro `autoload` o un
solo byte adicional distinto abortan.

Por ciclo se exigen tres checkpoints H31: dos con Search inactivo antes de
activarlo (`rollback-after-business-switch` y `rollback-before-activation`) y uno
después de `gate-v1`. El forward exige H32 antes de activar Search y otra vez
después de la migración y de `gate-v2`; restore, recovery, primer v2, HTTP y el
gate MariaDB también deben coincidir exactamente con H32.

No se incluye el índice derivado en este contrato de fase: sus tablas deben cambiar por
un rebuild verificado y converger por IDs/receipts. Esto reemplaza el antiguo
booleano demasiado amplio `search_data_fingerprint_must_remain_identical=false`.

El par `akb_search_precio_buckets`/`akb_search_precio_mode` tampoco pertenece al
fingerprint no derivable, pero no queda libre: tiene un gate de transición
exacta. En fuente, post-dump, freeze final, restore y post-recovery debe seguir
en fase preimage (mismo SHA raw, buckets `autoload=off`, mode ausente con
semántica AUTO). El rebuild v2 debe normalizar atómicamente al mismo SHA raw,
buckets `autoload=no`, mode raw `auto` y mode `autoload=no`; además el array
serializado debe ser canónico e idéntico con `===` a
`PriceBuckets::derive_from_index()`. Rollback v1, forward, HTTP y el gate
MariaDB integrado deben preservar exactamente el par normalizado. Alias de
case, duplicados, bytes, ausencia o autoload distintos cierran el rail.

## 11. Resultado, ligadura y siguiente gate

Un PASS termina en `final-receipt.json` local con los hashes de backup, redump,
ambos ciclos, Theme, auditor IA, HTTP, MariaDB integrado, midpoints pareados
inactivos y fingerprints. El receipt queda ligado a los tres IDs/contratos de
release, archive/inventory de candidate y preimage de Search/Business, y
ZIP/inventario/sidecar de candidate y preimage del Theme. También liga
rail/proof/runbooks/tests/smoke/gate integrado y los IDs de las cuatro imágenes.
Antes de usar esos archivos, el rail captura un manifiesto inmutable de 39
líneas: header, exactamente 37 archivos y footer. Los 37 archivos son 23
contratos/evidencias y 14 artefactos: ocho ZIP/inventarios del par más seis
ZIP/inventario/sidecar del Theme. `compose.yaml`, el packager que se carga como
librería y `package-runtime.sh` tienen además ruta y SHA-256 propios en el
contrato sellado. Lo compara antes y después de cada ciclo, tras el freeze final
y de nuevo pegado al receipt/sidecar; `validate_contracts` se repite después del
sidecar todavía en `.partial`. El receipt liga el SHA-256 de esa foto inicial;
sólo después de la última comparación se renombran primero el JSON y luego el
sidecar, cuyo rename es el marcador de completitud. El trap elimina cualquier
receipt final incompleto si el proceso no alcanza `run_success=1`. Así un reseal
o cambio concurrente no puede dejar un PASS acreditable con bytes distintos.
El trap elimina exclusivamente contenedores/redes
con el label aleatorio del run. Antes de emitir cada receipt PASS verifica que
no quede contenedor con ese label ni la red efímera exacta. No elimina la
evidencia local.

Después de ambos ciclos el rail vuelve a exigir el freeze de main y compara
fingerprint protegido, schema/conteos Search, estado raw y un dump canónico
completo contra el backup inicial; también revalida el par derivado en fase
preimage. Sólo esa evidencia permite incluir
`main_database_mutated:false`; no es una autoafirmación. El receipt y su sidecar
`final-receipt.json.sha256` quedan en modo `0600`, y el sidecar se emite después
de cerrar el JSON.

Ese PASS **no autoriza producción ni afirma que main local esté reparada**. Sí
cierra HTTP/QA sobre un clon hermético sin exigir una mutación previa de main.
Los gates de paquete y promoción deben recalcular el sidecar, validar toda la
ligadura y exigir exactamente dos ciclos antes de continuar. Graphify sigue
siendo el último paso, después de verificar producción pública.
