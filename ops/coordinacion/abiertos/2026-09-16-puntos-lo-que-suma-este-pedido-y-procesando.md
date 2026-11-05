de: sesión bis-avenida [8c4c125d] (desarrollo)
para: QA · y el dueño para lo que decida
estado: esperando-respuesta — DESPLEGADO en producción el 2026-09-17 (rewards 0.4.6, tema 2.1.131, checkout-blocks 2.2.20) y verificado allí; revisión independiente cerrada con 15 hallazgos, todos aplicados. Lo cierra QA tras re-medirlo
archivos: public_html/wp-content/plugins/akibara-rewards/{akibara-rewards.php,assets/js/checkout-blocks.js,src/Blocks/CheckoutIntegration.php,src/Points/{Accrual.php,PdpNotice.php,ProgramPage.php},src/Support/{Options.php,OrderStates.php,Schema.php,SettingsPage.php},tests/**} · public_html/wp-content/plugins/akibara-cart-checkout-blocks/{akibara-cart-checkout-blocks.php,assets/css/cart-checkout-blocks.css,tests/cart-checkout-blocks.test.js} · public_html/wp-content/themes/akibara-gp/{style.css,assets/css/trust.css} · contenido de las páginas 27519 (Akibara Puntos), 3 (Términos) y 210 (Inicio)
prueba: suite de rewards 570 en verde · 31 pruebas JS (`node --test tests/js/*.test.js`) · `tools/akibara-releases/qa-theme.sh` rc=0 · barrido Playwright de 40+ escenarios en el espejo (carrito, checkout, panel, portada, móvil 390/360) con cero errores de consola · pedido real en el espejo que acredita al pasar a «Procesando» y revierte al cancelar · en producción: JS servido byte a byte igual al del repo, payload sin `value_label`, portada y páginas verificadas

# «Este pedido suma N puntos», y la acreditación pasa a «Procesando»

Tres encargos del dueño del 2026-09-16, en una sola tanda porque tocan las mismas piezas.

## 1. Lo que gana el cliente, visible antes de pagar (rewards 0.4.4)

- El payload del Store API del carrito lleva `earn_now`, `redeem_value` y `account_url`. `earn_now`
  sale de `Calculator::points_for()` sobre `award_base_for_cart()` **menos el canje aplicado**
  (`cart_redeemed_gross()`, espejo de `Redemption::granted_gross()` sobre los fees): lo pagado con
  puntos no vuelve a acumular, igual que al acreditar.
- La caja del cliente registrado dice «⭐ Este pedido suma N puntos = $N para tus próximas compras —
  en ésta no se descuentan», **en las cuatro caras**, incluida la del saldo 0, que antes no se
  pintaba en absoluto.
- El gancho del invitado dice ahora «En total **hasta** $X para **tus próximas compras**»: el bono es
  uno por persona y el tope por pedido rara vez deja gastarlo de una vez.
- En `/carrito/` el gancho ofrece un enlace a Mi cuenta (ahí no existe la casilla «Crear una cuenta»).

## 2. La acreditación, al confirmarse el pago

Decisión del dueño: *«debería ser cuando pasa por procesando, ahí se acredita un pago»*.

- `Options::DEFAULT_AWARD_STATUS` pasa a `processing` (el campo del panel manda igual que antes).
- `OrderStates::is_at_or_after()` + `Accrual::on_status_changed()`: acredita al entrar al estado
  configurado **o a cualquier estado pagado posterior**. Sin esto, un pedido que salta a «Completado»
  no acreditaba nunca, y cambiar el estado en el panel dejaba huérfanos los pedidos ya en curso.
- Efecto colateral bueno: `shipping-progress` lo registra el plugin de Blue Express, no WooCommerce.
  Con `processing` la acreditación deja de depender de un tercero.

## 3. La portada

La tira de garantías cambia «Manga 100 % original / Ediciones en español» por «Akibara Puntos ·
1 punto = $1 en tu próxima compra», con icono de moneda con estrella y enlace a `/akibara-puntos/`
(única tarjeta enlazada; el CSS nuevo vive en `trust.css`, tema 2.1.131). El peso sale del panel con
`[akb_puntos_resumen solo="valor_punto"]`.

## Defectos ajenos arreglados de paso

| Dónde | Qué decía | Qué dice ahora |
| --- | --- | --- |
| Página **Términos y condiciones** | «los puntos **no vencen**» | Vencen a 12 meses (frase viva del panel) |
| Página **Términos y condiciones** | canje «**sin mínimo ni máximo**» | Mínimo 100 puntos, carrito desde $50.000, tope 50 % / $5.000 (frase viva) |
| Página **Términos** | no nombraba reseñas, referidos ni cumpleaños | Los nombra y remite a la página del programa |
| Página **Akibara Puntos** | «Se acreditan al quedar Listo para enviar», escrito a mano | `solo="estado_acreditacion"` y `solo="acreditacion"`, leídos del panel |
| Página **Akibara Puntos**, paso 3 | «hasta el límite de tu saldo», sin nombrar el tope | La frase viva del canje, con los dos topes |
| `Options.php` | comentario que decía «acá no hay piso de carrito» | Hay piso desde el 2026-09-13; el comentario lo dice |
| `ProgramPage.php` | un docblock huérfano sobre un método que ya no estaba | Retirado |

## Medido en el espejo (lo que preguntó el dueño)

| Escenario | Promete | Acredita |
| --- | --- | --- |
| 6 tomos a $10.000 **en oferta a $6.000** = $36.000 | 720 | **720** |
| + despacho de pago $3.990 (total $39.990) | 720 | **720** — el envío no puntúa |
| + cupón 10 % (total $32.400) | 648 | base = productos con IVA tras cupón |
| Pedido a «Procesando» | — | 660 + 500 de bienvenida; al cancelarlo, todo vuelve |

Contrastado contra producción: en los pedidos reales con cupón 27974, 27749 y 27747,
`award_base_for_order()` = total − envío al peso.

## Lo que encontró la revisión independiente (y cómo quedó)

| # | Hallazgo | Arreglo |
| --- | --- | --- |
| BLOQUEANTE | Cambiar el default no mueve a una tienda donde el panel ya guardó `shipping-progress` — y el guardado escribe TODAS las options | Migración one-shot en `Schema` 1.3.0, que sólo mueve el valor VIEJO de fábrica; en producción la option no existía, en el espejo sí |
| MEDIO | El correo decía «tu pedido ya está en camino» al confirmarse el pago | «ya está confirmado» |
| MEDIO | La página prometía «o a un estado posterior» también con estados sin posteriores | Sólo se dice dentro de `OrderStates::PAID` |
| MEDIO | `wc_get_page_permalink()` cae a la PORTADA: el enlace del carrito nunca salía vacío | `account_url()` exige página de Mi cuenta **y** registro abierto; y sólo viaja para invitados |
| MEDIO | Con el bono apagado, el invitado veía «$2.400» sin la aclaración | El sufijo «para tus próximas compras — en ésta no se descuentan» pasa a la línea del pedido |
| MEDIO | `earn_now` ignoraba la vigencia del programa, que sí bloquea la acreditación | `Accrual::program_open_now()` gobierna `earn_now`, `earn_points` y `earn_amount` |
| MENOR | `?? $fee->amount` inalcanzable y peligroso (importe sin IVA) | Sólo `total + tax` |
| MENOR | La resta del canje duplicada; el parámetro de `award_base_for_cart()` sin llamador | Se usa el parámetro |
| MENOR | `account_url` costaba consultas para quien nunca lo lee | Sólo invitados (2 consultas con caché fría, 0 con caliente) |
| MENOR | El hover del badge no cambiaba nada visible y usaba tokens inexistentes | Hover en el fondo y en el `small`; tokens reales |
| MENOR | El comentario de `trust.css` describía mal su carga | Corregido (la page 210 **es** la portada) |
| MENOR | `is_at_or_after()` nacía con dos copias vivas de la misma idea | `Referrals` e `Invitations` pasan por ella |
| MENOR | Guardar el panel con Blue Express apagado movía el estado en silencio | Se conserva lo guardado y se avisa en pantalla |
| MENOR | `value_label` y `redeem_value` eran el mismo número en dos formas | `value_label` fuera del payload |
| Frágil | El orden de hooks 10/20 que sostiene la base no estaba probado | Prueba nueva que lo fija |

## Retoques del dueño tras verlo en producción (0.4.5)

- **Fuera «— en ésta no se descuentan»**, en sus tres apariciones (la caja del registrado y las dos
  líneas del gancho). Lo que dice que los puntos no son para este pedido queda en «para tus próximas
  compras», que va ahora también en la línea del pedido del invitado.
- **«Crear mi cuenta» quedaba con doble marco**: botón dorado sobre transparente con borde de 2 px
  dentro de la caja de borde dorado, a 15 px de ella. Pasa a botón SÓLIDO (#FFD700 con texto #0a0a0a,
  sin borde, `box-sizing: border-box`, margen superior 14 px). Medido antes y después en carrito y
  checkout, a 1440 y a 390: el botón alinea al píxel con la lista de viñetas.

## Tercera tanda del dueño (0.4.6 + checkout-blocks 2.2.19)

- **La ficha de producto dice cuánto valen los puntos**: «★ Gana 480 Puntos Akibara = $480 con este
  tomo creando tu cuenta — más 500 de bienvenida = $500 en tu primera compra, uno por persona». La
  conversión sale de `Options::points_label()`, junto al cambio del panel, para que ninguna superficie
  la multiplique por su cuenta.
- **«Añadir cupones» cortaba el anillo de foco.** Causa: `.wc-block-components-panel__button` llevaba
  `margin-block: -13px` (arriba **y** abajo) para ganar área táctil; el negativo de abajo subía el
  contenido del panel esos 13 px y el anillo de foco del campo (6 px de `box-shadow`) quedaba bajo el
  título. Medido en el checkout: solape de **+16 px** antes, **−5 px** después. El negativo pasa a ir
  sólo arriba y el contenido abierto gana `padding-block: 8px 2px`. Prueba nueva en
  `tests/cart-checkout-blocks.test.js` (115 en verde).

### Auditoría de foco, tras la pregunta del dueño («¿está en otras partes donde se aplica el cupón?»)

Sonda que enfoca **todos** los controles de `/carrito/` y `/checkout/` (inputs, selects, botones,
enlaces y `[role=button]`), mide el grosor real del anillo y comprueba dos cosas: si algún ancestro
con `overflow` recortante lo corta, y si otro elemento lo tapa (`elementFromPoint` en sus bordes).
Cuatro pasadas: carrito y checkout, a 1440 y a 390.

| Superficie | Controles | Anillos cortados |
| --- | --- | --- |
| checkout 1440 | 52 | 0 |
| checkout 390 | 37 | 0 |
| carrito 1440 | 48 | 0 |
| carrito 390 | 25 | 0 |

Sólo aparece «Realizar el pedido» con 3 px de invasión por arriba: es el contenedor del resumen
(`position: static`, fondo transparente), no un elemento que tape — confirmado con captura, el anillo
se ve entero por los cuatro lados. Las dos páginas de cupón son de BLOQUES (`woocommerce/cart` y
`woocommerce/checkout`), no hay shortcode clásico en ninguna, y Mi cuenta no tiene formulario de cupón.

**Segundo defecto, encontrado por esa auditoría:** el campo del cupón se pintaba **blanco** en el
carrito y oscuro en el checkout (medido: `#fff` con texto `#2b2d2f` vs `rgb(0 0 0 / 10%)` con texto
blanco). El bloque Checkout lleva la clase `has-dark-controls` de Woo y el Carrito no. Igualado con una
regla propia que gana por especificidad (0,0,4,1 contra 0,0,3,1 de Woo), sin `!important`, con prueba.

> **Cruce de coordinación:** `akibara-cart-checkout-blocks` figura reclamado en
> `2026-09-11-diagrama-arquitectura-y-qa-integral` (sesión akibara-eb, última señal 2026-09-12, sin
> actividad desde entonces). El cambio es una regla CSS acotada, con prueba y desplegada; queda
> anotado aquí por si esa sesión vuelve.

## Pendiente

1. **QA cierra**: re-medir en producción el checkout con sesión iniciada (aquí sólo se verificó el payload y la superficie servida: en producción Cloudflare se come el `Set-Cookie` del HTML público y `?add-to-cart` no deja carrito de invitado).
2. Vigilar el primer pedido real que entre a «Procesando» y comprobar que acredita y manda el correo nuevo.
