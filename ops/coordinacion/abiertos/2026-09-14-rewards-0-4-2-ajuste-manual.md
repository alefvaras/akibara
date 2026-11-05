# rewards 0.4.2 — el ajuste manual avisa antes de premiar dos veces un pedido

- **Abierto:** 2026-09-14 · **estado:** probado en el espejo, en revisión independiente, sin desplegar
- **Reclama:** `public_html/wp-content/plugins/akibara-rewards/src/Points/AdminAdjust.php`,
  `src/Points/Ledger.php` (`last_credit()`), `tests/Points/AjusteManualPorPedidoTest.php`,
  `tests/Support/FakeWpdb.php`, `akibara-rewards.php` (0.4.2)

## Decisiones del dueño (2026-09-14)

1. **No se premian historias de Instagram**: se vuelve difícil de controlar. Verificado en
   producción: ni la página del programa (`/akibara-puntos/`) ni Mi cuenta lo mencionan (0
   apariciones en el texto visible). Se quitó también de la ayuda de la herramienta manual y
   del manual del dueño.
2. **La herramienta de ajuste manual se conserva** para compensaciones y premios futuros, con
   la mejora barata: campo opcional «Pedido» y aviso antes de premiar dos veces.

## Contraste con la competencia (código leído)

Nadie verifica publicaciones en redes: **WPLoyalty** acredita al hacer clic en compartir y
declara que Facebook, X e Instagram no permiten verificar («honour system»); **Points and
Rewards for WooCommerce** también premia el clic (visitar perfil, compartir); **YITH** gratuito
no premia redes y el ajuste manual lo reserva a su versión de pago.

## Qué hace

- Con pedido, el asiento va a `ref_type = order_adjust` (no `order`, que concilia Accrual).
- Antes de otorgar: si ese pedido ya tiene un crédito manual, NO se asienta y avisa cuándo,
  cuánto y con qué nota; si el pedido es de otro cliente, pide confirmar; si no existe, error.
- Con «Confirmo» se aplica. El formulario vuelve relleno cuando falta confirmar.

## Verificado

- PHPUnit rewards **526** rc=0 (también `--order-by=random`), PHPCS rc=0.
- 4 mutaciones (sin aviso de repetido, sin aviso de ajeno, sin ligar al pedido, sin validar
  existencia): las 4 en rojo; código restaurado.
- **Espejo, con cliente y pedidos reales** (#30742 / #30734): los cinco casos se comportan como
  arriba, el formulario repinta con escapes correctos, y lo sembrado se deshizo (saldo exacto).
- Pendiente: revisión independiente, despliegue y medición en producción.

## DESPLEGADO 2026-09-14 · rewards 0.4.2 en producción

- ZIP 41 ficheros, sha256 `96004caa…`. `wp` reporta 0.4.2, mtime real, correo sano.
- **Tres vueltas de revisión independiente** antes de desplegar. Lo que cazó, y se cerró:
  - **M1**: «Confirmo» era un sí suelto que saltaba los dos avisos a la vez y no estaba
    atado a los valores revisados.
  - **R1** (de mi propio arreglo de M1): con pedido ajeno **y** ya premiado, los dos avisos
    se turnaban para siempre y el movimiento no se podía aplicar nunca. Ahora las
    confirmaciones se ACUMULAN (`avisos_unidos()`).
  - **M2**: un F5 sobre el POST decía «este pedido ya recibió puntos» e invitaba a
    confirmar; confirmarlo al cruzar el minuto acreditaba dos veces. La lectura de
    idempotencia se movió delante de los avisos del pedido.
  - **M3**: un reembolso se rechazaba con el mensaje falso «no es de este cliente» y, si se
    confirmaba, quedaba con el id del reembolso. Ahora se exige pedido de compra; también
    se rechazan papelera y borrador de caja.
  - **R2**: la confirmación vive en un transient del servidor y se consume al usarla, así
    que reenviar el formulario desde el historial ya no vale.
  - Bajas: primer número del campo pedido, recorte de la nota sin partir caracteres ni
    perder el pedido, y textos de Instagram fuera de pruebas y del capítulo 10.
- **Verificado**: PHPUnit 535 rc=0 (también aleatorio), PHPCS rc=0, ocho mutaciones en rojo,
  y en el espejo con datos reales: doble envío, confirmación del aviso equivocado, pedido
  ajeno, papelera, borrador y un **reembolso creado y borrado de verdad** (con HPOS hay que
  borrarlo por CRUD: `wp_delete_post` no lo elimina).
- **Medido en producción tras desplegar**: versión 0.4.2, el formulario trae el campo
  Pedido, la casilla de confirmar sólo aparece con aviso, sin menciones a Instagram, y
  Mi cuenta y la página del programa en 200.
