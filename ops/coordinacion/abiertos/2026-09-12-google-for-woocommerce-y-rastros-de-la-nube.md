---
para: EL DUEÑO (cinco decisiones) · QA (re-medir el mu-plugin nuevo)
de: sesión bis-avenida [d5a6502d] (desarrollo)
fecha: 2026-09-12
estado: esperando-respuesta — arreglo desplegado; decisiones del dueño tomadas y aplicadas el 12-sep (ver §5); QA re-mide
archivos: public_html/wp-content/mu-plugins/akibara-woocommerce-rest-solo-en-la-raiz.php · public_html/wp-content/plugins/akibara-business/tests/MuPlugins/WooRestSoloEnLaRaizTest.php · ops/gla-informe-de-fallo-2026-09-12.md
---

# Google for WooCommerce: SÍ se usa, y el fatal era de clasificación de la petición. Rastros de la tarea de la nube.

## 1. ¿Se está ocupando Google Listings & Ads? Sí, a diario

Medido en producción (sólo lectura), 12-sep:

```
gla_google_connected        1
gla_merchant_id             5664866537     (Merchant Center)
gla_mc_setup_completed_at   2026-08-31     (configurado hace 12 días)
gla_ads_id                  8018443880     (cuenta de Ads enlazada; setup de Ads sin completar, sin campañas)
gla_ads_conversion_action   «[39f9] Google for WooCommerce purchase action», ENABLED, AW-17775366801
gla_enhanced_conversions_enabled 1
gla_target_audience         CL
productos con id de Google  1.639   aprobados 1.444 · parcialmente aprobados 188
última sincronización       2026-09-12 02:24 UTC (gla/jobs/update_products: 2.872 ítems procesados)
```

Es el feed de la tienda a Google Shopping (listados gratuitos, Chile). Quitarlo
retiraría los 1.639 productos de Merchant Center. NO se quita.

Etiquetas en la portada servida: el gtag AW-17775366801 lo imprime SÓLO Google
for WooCommerce (una vez); el G-VXW8CW2FXR (GA4) lo imprime Rank Math
Analytics (una vez). No hay conteo doble. El plugin «Pixel Manager»
(woocommerce-google-adwords-conversion-tracking-tag 1.67.0) está INSTALADO
pero INACTIVO y sin configurar: son ficheros muertos.

## 2. Por qué daba el fatal: no es una configuración nuestra

Cuatro veces (3-sep ×2, 5-sep, 12-sep 03:41 UTC): `Call to a member function
get() on null` en `google-listings-and-ads/src/Proxies/WC.php:257`, desde
`GlobalSiteTag::get_enhanced_conversion_tag()` en `wp_head` de la 404 del tema.

Causa, reproducida a mano en producción con `GET
/akbqa-no-existe-<ts>/wp-json/x` → **500**: `WooCommerce::is_rest_api_request()`
marca como API cualquier URL que CONTENGA `wp-json/` (subcadena), y con eso no
carga la sesión ni el carrito; pero WordPress sólo enruta a la API las rutas
ancladas a la raíz, así que renderiza la 404 del tema con `wp_head`, donde el
plugin lee `WC()->session->get('customer')` sin comprobar que exista. Lo
disparan rastreadores con URLs de ese tipo. Nada mal en nuestra configuración:
es un hueco del plugin (sin null check) sobre una imprecisión de WooCommerce.

Arreglo por hook, desplegado 12-sep ~09:20 UTC: mu-plugin
`akibara-woocommerce-rest-solo-en-la-raiz.php` (filtro
`woocommerce_is_rest_api_request`: sólo es API si la ruta EMPIEZA por
`/wp-json/` o trae `?rest_route=`). Medido después en producción:

```
wp eval  /wp-json/wc/v3/products → API   /no-existe/wp-json/x → sitio
GET /akbqa-no-existe-<ts>/wp-json/x   → 404 (antes 500), sin línea nueva en fatal-errors
GET /wp-json/akibara/v1/search/suggest → 200      GET /wp-json/wc/store/v1/cart → 200
```

Prueba `WooRestSoloEnLaRaizTest` (13 casos) con candado «muere con el tercero»:
si WooCommerce deja de comparar por subcadena, la prueba dice que el mu-plugin
sobra. Texto del reporte para el plugin (es de WooCommerce/Automattic, no de
Google) listo en `ops/gla-informe-de-fallo-2026-09-12.md`; publicarlo lo decide
el dueño.

## 3. Rastros de la tarea de la nube (eliminada por el dueño el 12-sep)

Medido en producción, sólo lectura:

- Contraseña de aplicación **«Barrido semanal (nube)»** del administrador
  ale.fvaras: creada 2026-09-05, ÚLTIMO USO 7 segundos después de crearse
  (la prueba de conexión), IP 160.79.106.131. La tarea nunca volvió a usarla.
  Sigue VIVA: es control total del sitio. → decisión del dueño: revocar.
- Contraseña de aplicación **«Claude Code MCP»** del mismo usuario: creada
  2026-04-28, último uso 2026-06-22. Tres meses sin uso. → decisión: revocar.
- La del iPhone (app WooCommerce, último uso 2026-09-07) es del dueño: se queda.
- Usuarios administradores extra: **qa_bot** (2026-05-21; 16 entradas a su
  nombre, así que borrarlo exige reasignarlas: `wp user delete 174
  --reassign=1`) y **testadmin** (2026-05-31; sin contenido). Sin contraseñas
  de aplicación. Son de rondas de QA anteriores, puestos por una persona
  (memoria «admin temporal vivo en producción»). → decisión: borrarlos o
  bajarlos de rol.
- Metas `_akb_sweep_incentivo` (74 productos) y `_akb_sweep_prev_stock_status`
  (66): rastro de lo que el barrido cambió, sirven para revertir. Se quedan.
- Sin options, sin acciones en Action Scheduler ni cron con «sweep/barrido».
  (`akb_preventa_vencida_sweep` y `akb_taxonomy_discount_expiry_sweep` son
  nuestros crons, no el barrido.) Sin crontab en el hosting.
- mu-plugin **`akibara-catalogo-abilities.php`** (ability
  `akibara/disponibilidad-set`, con las seis guardas): sin la tarea nadie lo
  llama. Exige credencial de administrador, así que no está abierto al
  público, pero es código muerto hoy. → decisión: si el dueño quiere rehacer
  el barrido de disponibilidad con la regla nueva (reserva = lista × 0,95),
  se queda y yo escribo el prompt; si no, se retira junto con
  `ops/barrido-disponibilidad.php` y `ops/dump-disponibilidad.php`.
- En este Mac: ninguna tarea de barrido (sólo Clarity semanal y dos únicas ya
  corridas); LaunchAgents borrados el 12-sep.

## Para QA

Re-medir el mu-plugin nuevo: una URL con `/wp-json/` en medio devuelve 404 sin
fatal; la API (`/wp-json/wc/store/v1/cart`, suggest) sigue en 200; el carrito y
el checkout cargan sesión con normalidad. Cierra tú.

## 4. Lo que dice la documentación oficial (leída el 12-sep; fuentes en el informe del agente)

- El plugin no arma un fichero de feed: manda los productos por la Merchant
  API (desde 3.8.0) con jobs de Action Scheduler; cada edición se sincroniza al
  instante y, como Google caduca los productos a los 30 días, un job diario
  los reenvía (`resubmit_expiring_products`). Con eso salen gratis en la
  pestaña Shopping; Chile está en la lista oficial de países con listados
  gratuitos y pestaña Shopping (support.google.com/merchants/answer/160637).
- Desconectar cuentas deja los listados y las campañas vivos en Google (sólo
  revoca el acceso). DESACTIVAR cancela los jobs: nada se borra en Google, pero
  sin reenvío los productos caducan a los 30 días. Desinstalar sólo borra datos
  locales si `WC_GLA_REMOVE_ALL_DATA` está en `true` en wp-config.php; no toca
  Google (woocommerce.com/document/google-for-woocommerce/get-started/disconnect/).
- Enhanced conversions = datos del comprador hasheados (SHA-256) para casar
  conversiones con cuentas Google. Se apagan en Ajustes del plugin, casilla
  «Send Enhanced Conversions data to Google Ads». Hoy están encendidas para una
  cuenta de Ads sin campañas: decisión del dueño si las deja.
- En el repositorio (`develop`, 2026-09-09) el código sigue SIN guardia y no
  existe ningún issue sobre este fatal ni entrada en el changelog 3.9.x: vale
  la pena reportarlo. El README manda el soporte al foro de wordpress.org o al
  chat de WooCommerce.com; el repo tiene plantilla de bug report. Existe además
  el filtro oficial `woocommerce_gla_disable_gtag_tracking` que apaga la
  etiqueta entera (no hace falta: el mu-plugin corrige la causa y conserva la
  medición).
- Etiquetas: Google recomienda UN solo `gtag/js?id=` por página con un
  `gtag('config')` por destino; acá hay dos cargadores (AW- de GLA y G- de Rank
  Math). No es doble conteo (son ids distintos), sí una carga redundante; el
  plugin sólo comparte cargador con «Google Analytics for WooCommerce», que no
  usamos. Dato, no defecto.

## 5. 2026-09-12 · decisiones del dueño («acepto tus recomendaciones») aplicadas en producción

- Revocadas las dos contraseñas de aplicación de ale.fvaras: «Barrido semanal
  (nube)» y «Claude Code MCP». Queda sólo la del iPhone.
- Borrado `testadmin` (176, sin contenido) y `qa_bot` (174) con `--reassign=1`:
  sus 15 productos publicados y 6 adjuntos (entre ellos el favicon, 24903)
  pasaron al dueño; 0 filas huérfanas. Único administrador: ale.fvaras.
- Pixel Manager (inactivo): SE QUEDA por decisión del dueño.
- Barrido de disponibilidad: el dueño aceptó la recomendación; el mu-plugin de
  guardas se conserva y se alinea con la regla nueva (reserva 5 %, y por
  defecto SIN tocar precios), con el prompt de la tarea de la nube escrito en
  `ops/`. La credencial nueva la crea el dueño en su perfil al crear la tarea.

