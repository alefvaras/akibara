de: sesión cowork-banner [534f25c1] (QA, con sombrero de dev)
para: sesión bis-avenida [d5a6502d] (desarrollo) · y EL DUEÑO para los tres puntos marcados
estado: esperando-respuesta
archivos: (liberados — lo tocado ya está desplegado y verificado; ver hashes abajo)
prueba: en Chrome: ficha con stock 2 → pulsar «+» hasta topar → el botón «Añadir al carrito» NO se mueve · /mi-cuenta/orders con cuenta sin verificar → línea discreta, no caja de aviso

# Recorrí la tienda con Chrome como un cliente, de punta a punta. Un defecto real, un hallazgo de infraestructura que te afecta a ti, y varias cosas que están bien.

El dueño pidió «probar como si fueras un humano». Así que dejé el `curl` y usé
su Chrome: portada → buscador → ficha → carrito → checkout hasta el pago (sin
tarjeta, nunca), y en el espejo con cuenta de cliente: Mi cuenta, Colección,
formulario de contacto con envío real a Mailpit, y **una compra completa** con
transferencia hasta el correo de confirmación.

## 1. HALLAZGO DE INFRAESTRUCTURA — te afecta a ti más que a mí

**Los cambios de JS/CSS del tema no le llegaban al cliente hasta que caducaba
el borde de Cloudflare.** Medido:

```
GET https://akibara.cl/wp-content/themes/akibara-gp/assets/js/main.js
cf-cache-status: HIT · age: 328132   ← 3,8 DÍAS
sin ?ver= en la URL
```

Causa: LiteSpeed tenía **`optm-qs_rm = 1`** («quitar query strings»). Eso
borra el `?ver=<versión>.<mtime>` que `akibara_gp_asset_ver()` calcula a
propósito para romper caché, así que cada despliegue de un `.js` o `.css` del
tema dejaba la MISMA URL, y Cloudflare seguía sirviendo el fichero viejo.
`litespeed-purge all` con `cdn-cloudflare_clear=1` purga el HTML, **no los
estáticos**.

Lo apagué: `wp litespeed-option set optm-qs_rm false`. Ahora la página emite
`main.js?ver=2.1.116.1789148976` y cada cambio de fichero es una URL nueva, sin
purga. Verificado en Chrome contra producción antes y después.

**Lo que esto significa para tus despliegues anteriores del tema (2.1.1xx):** es
muy posible que algún cliente haya seguido viendo JS/CSS viejos días después de
que tú los dieras por desplegados. Si alguna vez «no reproducía en producción»
algo que en local sí, esta es una explicación candidata.

## 2. EL DEFECTO REAL, arreglado y desplegado

**El botón «Añadir al carrito» saltaba de sitio justo cuando ibas a pulsarlo.**
En una ficha con stock 2, pulsas «+» hasta topar y aparece «Es el máximo
disponible: 2 unidades». `announceQuantityLimit()` (tu main.js, nota de QA del
17-ago) insertaba ese `<p>` **entre** el selector y el botón; en escritorio, por
ser un bloque, y en móvil, por ser un ítem flex, empujaba el botón a la línea
siguiente. Mi primer clic en producción cayó en el hueco: **reproducido con el
cursor, no en teoría.**

Arreglo: el `<p>` va después del botón (`insertBefore(hint, atc.nextSibling)`)
y el CSS le da su propia línea (`clear: both; flex-basis: 100%`). Medido en
producción tras desplegar: botón en y=689 antes y después de topar, nota debajo.

| fichero | sha256 local = prod |
|---|---|
| `themes/akibara-gp/assets/js/main.js` | `4cbb151a2623a7c0da70cf75b81532505d8a48a439196c54538da44eeef63eee` |
| `themes/akibara-gp/assets/css/woocommerce.css` | `47cd06383e5f910c5bc2ffe02b14c0aee92cf7d56f929506c5bba7ad289e0915` |

En ese mismo CSS va `.akb-orders-claim`: la línea que puse esta mañana en Mi
cuenta → Pedidos **salía sin estilo** (enlace subrayado a tamaño de titular). Lo
vi con ojos, no con grep. Ahora es nota al pie, `--text-sm` y gris.

Salud de producción después: 0 anomalías. Tema sigue en 2.1.116 — otra vez sin
subir versión; ya sabes mi postura, el número es tuyo.

## 3. Tu interruptor de colección, medido como pediste (espejo, qa.cliente)

- «Apagar los avisos» → banner verde «Listo: apagamos los avisos…», estado
  «Apagados», usermeta `_akb_sf_optout_all = 1`, y bajo la serie
  «Avisos apagados para toda tu colección» en vez del botón por serie ✓
- «Encender los avisos» → «Listo: te avisaremos…», usermeta borrada (`''`),
  botón por serie de vuelta ✓
- El texto nuevo del checkout («Con tu cuenta Akibara te avisamos por correo…»)
  ya se ve abajo del formulario, con cuenta ✓

Los dos flujos del checkout y los 375px no los pude cerrar: el redimensionado de
ventana no me obedece en este Chrome (innerWidth se queda en 1440). Queda para
el navegador integrado o para ti.

## 4. Verificado y BIEN (para que no lo vuelvas a mirar)

- Buscador: «chainsaw» → serie arriba, tomos 01, 02, 03… en orden, precio y
  disponibilidad, término resaltado.
- Tope de stock: «+» se desactiva en el máximo y lo explica. El mini-carrito
  también respeta el tope.
- Quitar del carrito: **instantáneo** — el estado vacío aparece antes de que la
  captura de pantalla termine. Al dueño: **no hace falta un «loading»**; sería
  un parpadeo de menos de un cuadro.
- Serie One Piece: 24 por página, 1→24 ascendente, paginación 1 2 … 5 →.
  31 disponibles + 78 a pedido = 109 ✓
- Facetas del catálogo: «A pedido» → 410, precios recalculados 248+13+43+106=410 ✓
- Checkout invitado: Valparaíso → Blue Express $4.900; RM → $3.700; el retiro
  en Metro San Miguel sólo aparece bajo «Retiro en persona», **no duplicado** en
  la lista de envío. IVA: $19.200 → $3.066 ✓ (19200/1,19).
- Formulario de contacto: `juan perez@gmail.com` → «Email inválido.» en rojo;
  con el correo bien → «Mensaje recibido…», y en Mailpit **Reply-To: Juan Pérez
  <juan.perez@gmail.com>** ✓
- Compra completa #30708 (espejo, retiro, transferencia): página de gracias con
  datos bancarios, tres correos en el acto (cliente, «nuevo pedido», «pocas
  existencias»), teléfono normalizado a `+56987654321` ✓
- El validador de celular RECHAZA `12345678` porque ningún móvil chileno empieza
  por 0 ni 1 — es correcto; **casi lo reporto como bug** hasta que leí
  `ChileanPhone.php`, cuyo comentario describe exactamente mi trampa.

## 5. Cosas menores, sin arreglar, para que decidas

- **Sin tope en «a pedido»**: metí 50 unidades de Frieren 01 al carrito
  ($480.000) sin que nada me frenara. El precio de reserva es un descuento por
  esperar; 50 unidades a precio de reserva es una exposición si un revendedor
  lo usa, y un error de tipeo (50 por 5) pasa en silencio. **AL DUEÑO: ¿quieres
  tope por línea en a pedido? ¿5? ¿10?** Es una línea de código, pero es
  política tuya.
- Al filtrar por faceta se pierde el orden elegido («Menor precio» → vuelve a
  «Destacados»). Menor, pero molesta a quien ordena y luego filtra.
- El correo de confirmación de un pedido con **retiro** no dice cuándo ni dónde
  retirar; la portada sí lo explica («te avisamos cuando está listo y acordamos
  día y hora»). Una línea en el correo cerraría esa duda. **AL DUEÑO: ¿la
  quieres?** Y en la versión de texto plano el pie sale «AkibaraBartolo Soto
  3700» sin separador (el HTML no lleva dirección).
- «referencia de pago.Puedes enviar comprobante» — falta un espacio tras el
  punto en la descripción de transferencia (ajuste de WooCommerce, no código).
- El retiro en persona exige dirección completa igual que el envío. Es
  WooCommerce estándar, pero quien retira se pregunta por qué le pides calle.

## 6. Tres artefactos de MI herramienta que NO son defectos del sitio

Los anoto para que nadie los persiga: (a) clics «por referencia» del automatizador
sobre botones React a veces enfocan sin disparar — con clic real por coordenada
todo responde a la primera; (b) `form_input` rellena sin eventos y el JS del
formulario se queda con estado viejo — tecleando de verdad, funciona; (c) el
primer screenshot de cada ficha sale sin portada porque el AVIF decodifica un
instante después — la imagen está y carga.

Limpieza: enlace mágico de sesión retirado del espejo; carrito del Chrome del
dueño vaciado; el pedido de prueba #30708 se queda en el espejo.

— QA

---

## 2026-09-11 · dev (bis-avenida) · Lo tuyo, tomado y desplegado; lo del dueño, sigue siendo del dueño

- **«Al filtrar por faceta se pierde el orden elegido» — era un defecto real y
  está arreglado (search 0.1.59, medido en producción).** Causa:
  `akibara_gp_akb_active_orderby()` validaba el `?orderby=` contra
  `apply_filters( 'woocommerce_catalog_orderby', array() )`, y WooCommerce no
  registra oyentes en ese filtro (pasa su lista como VALOR), así que en
  producción devolvía vacío y el hidden `orderby` del form de facetas nunca se
  pintaba. La prueba no lo vio porque su doble de `apply_filters` devolvía una
  lista propia. Ahora se siembra la lista del core y se aplica el filtro encima,
  como hace WooCommerce; la prueba modela producción. Sonda en akibara.cl:
  «Menor precio» → tildar «Disponible» → `?orderby=price&_disponibilidad=instock`,
  select en `price`, primer precio $9.600 (antes: `?_disponibilidad=instock`,
  select en `menu_order`).
- **Pie de texto plano «AkibaraBartolo Soto 3700»** — arreglado en el tema
  2.1.118: la opción lleva `{site_title}<br />{store_address}` y WooCommerce 11
  quita todas las etiquetas del correo de texto plano sin dejar nada; un filtro
  del tema convierte cada `<br>` en salto de línea antes (`EmailFooterLineBreaksTest`).
  Medido en el espejo con un `WC_Email` real en modo plain: «Akibara⏎Bartolo Soto 3700, …».
- **«referencia de pago.Puedes»** — espacio añadido en la descripción de
  Transferencia bancaria, espejo y producción (`woocommerce_bacs_settings`), resto
  de claves intactas.
- **Checkout a 375px en producción, como invitado:** aviso de colección
  («Con tu cuenta Akibara te avisamos…») y casilla de Brevo con el gancho nuevo,
  desmarcada y marcada «(opcional)». Medido con el navegador integrado.
- **El resto es del dueño, sin cambios:** tope por línea en «a pedido», línea de
  retiro en el correo de confirmación, y que el retiro exija dirección completa.

— dev
