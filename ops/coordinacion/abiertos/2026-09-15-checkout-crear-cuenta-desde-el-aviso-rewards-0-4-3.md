# rewards 0.4.3 — el aviso «crea tu cuenta» del checkout marca la casilla

- **Abierto:** 2026-09-15 · **estado:** **esperando-respuesta** — desplegado en producción el 2026-09-15 (ZIP `7a6f4bfe…`, paridad 41/41) y medido allá; dos vueltas de revisión independiente (Explore, sólo lectura), la segunda sin hallazgos altos ni medios. Lo cierra QA tras re-medirlo.
- **Reclama:** `public_html/wp-content/plugins/akibara-rewards/{akibara-rewards.php,assets/js/checkout-blocks.js,src/Blocks/CheckoutIntegration.php}`,
  `plugins/akibara-rewards/tests/js/gancho-lleva-a-la-casilla-de-cuenta.test.js` (nuevo),
  `docs/arquitectura/10-rewards-akibara-puntos.md` (+ diagrama `.mmd`), `docs/para-el-dueno/borrador-03-puntos-resenas-checkout.md`.

## Por qué

El dueño, desde su teléfono: el aviso que dice «Marca "Crear una cuenta"» debería llevarlo arriba y marcarla.

Medido en producción (390 × 844, Chromium y WebKit): la casilla nativa «Crear una cuenta con Akibara» está a
515 px y el aviso, dentro del resumen del pedido que en el celular va al final, a 2.805 px. Tres pantallas de
distancia. En escritorio no pasa: el resumen lateral es fijo y queda al lado.

## Qué cambió

- El aviso trae un botón **«Crear mi cuenta»**: marca la casilla de WooCommerce con un clic real sobre el input
  (React recibe su `onChange`, casilla y store quedan iguales), la centra en pantalla (salto sin animación con
  `prefers-reduced-motion`), le pone el foco y la resalta en amarillo 2,4 s.
- Marcada, el aviso dice «✓ Crearemos tu cuenta con este pedido», «Te llegará un correo para elegir tu contraseña»
  (sólo si WooCommerce genera la contraseña: hoy sí) y ofrece «Prefiero comprar sin cuenta», que la desmarca.
- Sin invitados o sin registro en el checkout (ajustes de WooCommerce) no hay casilla: el aviso no ofrece botón.
  Tampoco en /carrito/, que monta el mismo aviso (`context: woocommerce/cart`): allí queda el título genérico. Hasta
  0.4.2 el carrito decía «Marca "Crear una cuenta"» sin casilla que marcar.
- Si la tienda pidiera contraseña en el checkout (`generatePassword` apagado), el aviso marcado manda al campo en vez
  de prometer el correo.
- Rebote de 700 ms: un doble toque no marca y desmarca.
- **Deuda previa:** el campo `max_pct` del Store API, que nadie leía, sale del payload y del esquema; dos docblocks
  descolocados vuelven a su función.
- **Revertido por la revisión independiente:** quitar el encolado del JS en el carrito (el doc 10 lo daba por inerte).
  En WC 11.1 el bloque Carrito sí monta el plugin: medido en prod, /carrito/ muestra el aviso. La prueba de contrato
  exige ahora los dos encolados y el doc 10 corrige el hallazgo falso.

## Verificación

- Local: node 11/11 (las pruebas nuevas ejecutan el script con dobles y pulsan el botón), PHPUnit rewards 535 OK,
  PHPCS limpio.
- Espejo con Playwright (Chromium y WebKit móvil, escritorio, movimiento reducido): marca, foco, casilla en vista,
  resalte que se retira, confirmación, desmarcar con teclado (anillo de foco visible), doble clic sin
  desincronizar, clic directo en la casilla actualiza el aviso, cero errores de consola.
- Producción (2026-09-15, tras el despliegue): JS servido por Cloudflare = paquete (sha256 `cb2edfb7…`,
  `?ver=0.4.3.1789501313`). Checkout con Jujutsu Kaisen 22 en el carrito, sin pedido: Chromium móvil y escritorio OK;
  WebKit móvil OK en lo funcional (marca, store sincronizado, foco, teclado, doble toque). Sus errores de consola
  son del navegador de prueba contra Clarity y Brevo (`b.clarity.ms/collect`, `in-automate.brevo.com`, iframes de
  otro origen): el JS de rewards no hace peticiones. /carrito/ en Chromium y WebKit: título genérico, 0 botones.
  Carritos de prueba vaciados.
- Datos confirmados: correo «Nueva cuenta» habilitado (default `yes`); editor de correos por bloques apagado (se
  usa la plantilla del tema con «Crear mi contraseña»); aviso «Ya hay una cuenta registrada con…» traducido.
- Queda de baja severidad, aceptado: Safari < 15 ignora `preventScroll` (el foco salta sin animación; sin error).
