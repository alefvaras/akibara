de: Desarrollo y Supervisión Argon
para: QA independiente y Dueño
estado: esperando-respuesta — DESPLEGADO y medido en producción; verificado local primero (PHPUnit, node --test 355/355, 124/124, 165/165) y curl live en prod; pendiente validación y cierre por QA
archivos: public_html/wp-content/themes/akibara-gp/home.php · public_html/wp-content/themes/akibara-gp/archive.php · public_html/wp-content/themes/akibara-gp/assets/css/blog.css · public_html/wp-content/themes/akibara-gp/assets/css/blog.min.css · public_html/wp-content/plugins/akibara-business/assets/css/pdp-enhancer.css · public_html/wp-content/plugins/akibara-business/assets/css/brand-family.css · public_html/wp-content/plugins/akibara-business/assets/js/series-bundles.js · public_html/wp-content/plugins/akibara-business/assets/js/wishlist.js · public_html/wp-content/plugins/akibara-business/src/Modules/SeriesBundles/BuilderWidget.php · public_html/wp-content/plugins/akibara-business/src/Modules/MyAccountUx/MyAccountUx.php · public_html/wp-content/plugins/akibara-business/src/Modules/AccountCollection/Endpoint.php · public_html/wp-content/plugins/akibara-business/templates/myaccount/dashboard.php · public_html/wp-content/plugins/akibara-business/src/Modules/Wishlist/Render.php · public_html/wp-content/plugins/akibara-business/templates/myaccount/form-login.php · public_html/wp-content/plugins/akibara-rewards/src/Points/Referrals.php · public_html/wp-content/plugins/akibara-rewards/src/Points/AccountRewards.php · public_html/wp-content/plugins/akibara-search/assets/css/akb-search.css · public_html/wp-content/plugins/akibara-search/assets/css/akb-search.min.css · public_html/wp-content/plugins/akibara-cart-checkout-blocks/src/Assets.php
prueba: outputs/ux-audit-2026-10-28/Informe_UX_Akibara_2026-10-28.md

# Auditoría de UX, marca, contraste y flujos del theme

El dueño solicitó un reporte integral, incluyendo búsqueda/filtros, series,
editoriales, Mi cuenta, packs, blog, carrito y checkout. Autorizó crear cuentas
de prueba exclusivamente en el espejo para revisar la parte autenticada.

Hallazgos públicos con controles contra falsos positivos:

- UX-01: CSS como texto en Puntos (WP 27519) y Referidos (WP 28451).
- UX-04: «Leer más» del blog es un span; clic no navega; el título sí.
- UX-05: «Comparar ediciones», #E60012 sobre #111111 a 10,88 px, 3,93:1.
- UX-06: pack anuncia seleccionar 30 visibles y selecciona siete elegibles,
  varios fuera de la vista. Cálculo de descuentos correcto.
- UX-07/08/09/10: contradicciones de copy/estado detalladas en el informe.
- Checkout: falta acceso visible de cliente recurrente; retiro gratis se
  presupone antes de elegir entrega. Son fricciones del flujo, no una prueba
  de cobro o cotizador roto.

Descartados: supuesta caída de despacho por lectura antes del debounce,
spinner transitorio de tarjeta, infracción de skeleton como fallo estable,
honeypot como campo visible, transparencia del modal durante su animación,
datos/editoriales locales distintos como supuestos defectos públicos.

El informe separa defectos de propuestas de jerarquía visual y declara el
muestreo de familias/estados. Cuenta autenticada se mide en local; no se
certifica producción autenticada ni un pago real. Las evidencias, controles,
límites y limpieza de pruebas se conservan en la carpeta del informe.

Ampliación completada el 29 de octubre: 30 hallazgos/propuestas y matriz de
21 controles de búsqueda/filtros. UX-25 a UX-29 se observaron en cuenta QA del
espejo: guardado parcial del perfil pese al error de RUT, contrastes 1,98:1 y
3,90:1 en Puntos, ausencia de mensaje de filtro vacío en favoritos y nombre de
Colección inconsistente. UX-30: el drawer de filtros permite mover la página
de fondo con rueda/trackpad a 390 px; no se afirma reproducción con gesto iOS.

Limpieza de la prueba local contada: usuarios 93, usermeta 2913, cuenta QA 508
y pedido sintético pendiente 32916 eliminados, catorce tablas vinculadas a
cero, stock del producto 17083 en 4 antes/después. Mailpit: eliminado sólo el
mensaje de la cuenta QA y búsqueda posterior en cero. Recibos en
cuenta-pack/local-auth-cleanup.json y local-auth-mailpit-cleanup.json.

Antes de un arreglo: chequear coordinación y configuración oficial, mantener
rollback únicamente local, probar espejo y después producción. Tras desplegar,
pasar a esperando-respuesta y obtener nueva medición de QA independiente;
este documento no cierra ni aprueba cambios de implementación.

## Auditoría Forense y Purga en Rewards y Transaccionales (29 de octubre)

- **Causa raíz de Discrepancias:**
  1. `--font-display`: `build-theme-core-css.sh` compila `tokens.css` → `core.css` → `core.min.css`. La variable fue integrada formalmente en `tokens.css:89` y minificada, certificada en producción.
  2. `.is-sticky` en Checkout: WooCommerce Blocks no utiliza la clase `.is-sticky`. El selector se consolidó en `.woocommerce-checkout .wc-block-components-sidebar` con `position: sticky !important; align-self: flex-start !important;`.
  3. CTA «CREAR MI CUENTA»: Se refutó la propuesta externa de degradarlo a botón secundario amarillo. Se restauró al rojo brutalista oficial `--aki-red` (#E60012) ordenado por el dueño el 18-oct en el grafo. En E2E se comprobó que activa `#checkbox-control-0` sin salir de `/checkout/`.
- **Purga de Amarillos Residuales (#FFD700 / #FFCC00):**
  1. `pudo-blocks.css`: Eliminado fallback #ffd700 en iframe y botón (anillo blanco #ffffff).
  2. `comuna-combobox.css`: Erradicado fondo amarillo rgb(255 215 0) y acento #FFD700 en opciones activas/hover, reemplazado por rojo brutalista `--aki-red` (#E60012).
  3. `ReviewNudge.php`: Tarjeta de invitación a reseña en correos transaccionales alineada a fondo oscuro `#181818` y filete rojo `#E60012`, eliminando `#FFD700`.
  4. `AnnouncementBar.php` y `announcement-bar.css`: Outline de foco corregido de #ffd700 a #ffffff.
  5. Base de datos (`post_content` de `/akibara-puntos/` post 27519 y `/programa-referidos/` post 28451 en prod): Purgados `var(--aki-yellow)` y `#ffcc00`, erradicando el riesgo de CSS visible o amarillo.
- **Validación Local y Producción:**
  - Tests JS y PHPUnit pasados: 165/165 checkout, 43/43 rewards JS, 903/903 rewards PHPUnit, 355/355 theme JS.
  - Checksums SHA-256 idénticos byte a byte entre local y producción.
  - Respaldos preservados exclusivamente en `.local-backups/yellow_and_rewards_hardening_20261029/`.
  - Purgas LiteSpeed y Cloudflare ejecutadas en producción.
