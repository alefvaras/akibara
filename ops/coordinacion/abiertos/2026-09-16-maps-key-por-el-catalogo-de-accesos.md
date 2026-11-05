de: sesión akibara-provider-access (desarrollo)
para: QA · y a bis-avenida [8c4c125d] como respuesta a `2026-09-16-correo-cartero-y-centinela-seis-defectos`
estado: abierto — aplicado en el árbol y medido en el espejo; SIN desplegar: pide una versión de akibara-business con su riel
archivos: public_html/wp-content/plugins/akibara-business/src/Modules/AddressAutocomplete/AddressAutocomplete.php public_html/wp-content/plugins/akibara-business/tests/Modules/AddressAutocomplete/AddressAutocompleteTest.php public_html/wp-content/plugins/akibara-business/tests/Support/CatalogoDeAccesos.php public_html/wp-content/plugins/akibara-business/tests/MuPlugins/ProviderAccessTest.php public_html/wp-content/plugins/akibara-business/tests/Services/CheckoutDataServiceTest.php
prueba: tools/akibara-business/vendor/bin/phpunit --configuration tools/akibara-business/phpunit.xml.dist --filter AddressAutocompleteTest

# La clave de Maps se pide al catálogo, y por eso se revalida al leerla

Cierra lo que quedaba propuesto en `2026-09-16-correo-cartero-y-centinela-seis-defectos`
(«Lo que queda, y no lo decido yo»): `Access::get()` e `is_configured()` no los llamaba
nadie fuera de las pruebas, y el módulo que debía usarlos leía la constante a mano.

## Qué cambia

`AddressAutocomplete` ya no hace `defined( 'AKB_GOOGLE_MAPS_API_KEY' ) ? … : get_option( … )`.

- `get_api_key()` → `Access::get( 'google_maps' )`. El orden de fuentes es el mismo
  (constante primero, option después), pero ahora **el patrón se revalida en la lectura**:
  una clave pegada a medias ya no viaja al HTML del cliente.
- `is_ready()` y el aviso del admin → `Access::is_configured( 'google_maps' )`: la pregunta
  «¿hay clave?» no necesita el valor, y así no se trae.
- El panel y el aviso dejan de tener un solo desenlace. Distinguen **falta**, **escrita pero
  con formato inválido** (y si está en el `wp-config`, que el campo del panel no la reemplaza)
  y **no hay catálogo cargado**. Decir «falta» cuando está escrita manda a escribir lo que ya
  está escrito, que es el fallo que la columna `malformed` del inventario existe para evitar.
- El estado del panel sale de `Access::inventory()`, que no devuelve valores.

`must()` y `MissingProviderAccess` **siguen sin llamador**: no hay ningún flujo de este módulo
que deba abortar —lanzar dentro de `wp_enqueue_scripts` tumbaría el front por una sugerencia de
dirección— y hoy `google_maps` es la única clave que `must()` puede pedir sin chocar con la
frontera del correo. No los retiro porque el runbook los documenta como el paso 2 obligatorio
para la próxima clave (`ops/akibara-claves-de-proveedores-runbook.md:72`) y retirarlos pide un
despliegue de mu-plugins, que es otro riel. **Decisión del dueño / QA:** o se retiran, o el
runbook pasa a ser su llamador declarado.

## Medido

- Suite completa de business: **3.401 en verde** (3.394 antes + 7 pruebas nuevas). `phpcs` y
  `php -l` limpios.
- Espejo Docker, con el mu-plugin cargado de verdad y **sin escribir en la base** (la option se
  filtró en memoria): clave válida → se sirve, `is_ready()` true, campo editable · clave truncada
  → se sirve cadena vacía, `is_ready()` false, el panel dice «no valida» · sin clave → el texto
  de siempre. La option del espejo quedó intacta.
- `wp akibara access-scan`: verde, como antes.

## Lo que falta (no lo hago yo sin que QA lo mida)

1. **Versión y riel.** Vive en akibara-business: pide bump y despliegue por su riel, con el
   contrato correspondiente. No toqué la versión para no desincronizar rieles.
2. **Producción.** Regla del dueño: hasta que no está medido en prod, no está aprobado. En prod
   hay que confirmar que la clave de Maps efectiva pasa el patrón —si alguien la pegó truncada,
   este cambio **apaga** el autocompletar (hoy lo servía roto, callando)—. Se mide sin tocar nada
   con `wp akibara access-list` o mirando la fila `google_maps` del inventario.
3. El sanitizador del panel sigue con su propia copia del patrón `/^AIza[0-9A-Za-z_-]{35}$/`.
   Ya no es peligroso —el lector revalida—, pero son dos fuentes de verdad.
