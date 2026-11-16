# QA de navegador — CUATRO agentes Playwright (órdenes del dueño 2026-08-30/31)

**Premisa sellada**: esto lo opera una persona con CERO conocimiento de
programación — todo configurable desde paneles, como los plugins de
terceros. Los agentes verifican esa premisa, no solo el código.

Agentes: 1 CLIENTE · 2 DUEÑO-configurando · 3 RENDIMIENTO · 4 PLUGINS-PROPIOS.

**Cuándo corre**: en LOCAL, cuando las superficies 2b estén terminadas
(formulario de reseña por token + caja de canje del checkout). Es la
compuerta FINAL antes del cutover — sin estos dos agentes en verde, no se
despliega el reemplazo de los terceros. Se monta sobre el riel de sondas ya
existente (browser-probe, chromium+webkit, 375/844/1440).

## Agente 1 — CLIENTE (emula a una persona real, móvil primero)

1. **Descubrimiento**: busca un tomo en el buscador (con y sin resultados —
   la búsqueda sin resultado debe quedar en el radar del dueño); abre la
   ficha y VE el aviso «Gana N Puntos Akibara» con la matemática correcta.
2. **Registro**: crea cuenta CON cumpleaños (día/mes) desde el formulario de
   registro; verifica el gancho de invitado en la ficha antes de registrarse.
3. **Compra**: agrega al carrito, checkout completo; el pedido pasa a «Listo
   para enviar» → verifica en Mi cuenta → Puntos Akibara el saldo (2% + bono
   500), el historial y el correo consolidado ÚNICO en Mailpit.
4. **Reseña**: recibe el mail de invitación (Mailpit), entra por el TOKEN,
   sube reseña CON foto; verifica que queda pendiente (no publicada); tras
   la aprobación del dueño (agente 2), verifica los 500 puntos, el correo
   del premio y su avance si rige «cada N».
5. **Canje**: fija canje en Mi cuenta (pide más del saldo → se topa), ve el
   descuento EXACTO en el carrito/checkout, paga, verifica captura y saldo;
   prueba «Quitar canje».
6. **Referido**: copia su enlace, segunda sesión de navegador como invitado
   → registra cuenta nueva → compra → el padrino verifica sus 500.
7. **Cumpleaños**: (con la fecha puesta en hoy vía CLI) verifica el correo
   de saludo y el saldo +1.000.
8. **Puntos didácticos y vencimiento**: en Mi cuenta → Puntos Akibara abre
   «¿Cómo funcionan mis puntos?» y verifica que CADA monto del bloque (compra,
   bono, reseña, referido, cumpleaños, meses de vida) coincide con la
   configuración vigente del dueño — nada hardcodeado; con puntos viejos
   sembrados vía CLI, ve la línea «⏳ N puntos vencen pronto» y el correo de
   aviso en Mailpit con CTA de canje.
9. **Colecciones + canje**: desde el bloque didáctico sigue «Ver mi colección»
   a Mi cuenta → Colección, identifica el tomo faltante de una serie que
   compró, lo agrega al carrito y canjea puntos en ese pedido (el descuento
   acerca el tomo). Verifica saldo e historial después.
10. **Casuísticas hostiles**: reseña sin foto (no premia), reseña repetida
   del mismo tomo (no re-paga), canje con saldo gastado en otra pestaña,
   volver atrás en el checkout, carrito vaciado, y puntos ya vencidos que
   intenta canjear (el saldo real manda).

## Agente 2 — DUEÑO (emula la operación de la tienda en wp-admin)

1. **Configura TODO** en WooCommerce → Puntos y Reseñas: cambia montos
   (reseña, cumpleaños, referidos, bono), frecuencia «cada N», topes,
   umbral del carrusel, meses de vida de los puntos y días del aviso — y
   verifica que cada cambio SE REFLEJA en el front (la promesa de la ficha,
   el correo, la compuerta del carrusel, el bloque didáctico de Mi cuenta);
   con 0 meses, la línea de vencimiento desaparece y nada vence.
2. **Modera**: aprueba la reseña del cliente (Comentarios), verifica el
   pago; la des-aprueba y re-aprueba (no re-paga); crea una reseña de ADMIN
   con foto obligatoria y verifica que NO se auto-premia y que recalcula el
   rating del producto.
3. **Carrusel**: siembra hasta 7 (no aparece) y 8 (aparece) reseñas
   elegibles; verifica cero `<script>` y fotos lazy en el home servido.
4. **Radar del buscador**: ve «Buscados y no encontrados» en el Centro de
   Mando (la búsqueda fallida del agente 1 debe estar), la DESCARTA, verifica
   que desaparece de ambas superficies, la restaura.
5. **Pedidos por país**: cambia el día de corte de un país en Productos →
   Atributos → País y verifica la frase «Entra en el pedido que cierra el X»
   en una ficha a-pedido servida; intenta una tanda a medias (rechazo con
   aviso).
6. **Interruptores de un clic**: apaga cada carril (compra, bono, reseñas,
   cumpleaños, referidos, vencimiento) y verifica que DESAPARECE al instante
   de la ficha, Mi cuenta, la página pública y los correos — sin tocar
   montos; re-enciende y verifica que los montos guardados vuelven.
7. **Página pública de puntos**: tras cambiar montos/vencimiento en Ajustes,
   abre /akibara-puntos/ servida y verifica que la tabla «Todas las formas de
   ganar» y la frase de vencimiento reflejan la config nueva (shortcode), y
   que NO quedan montos viejos en el texto estático.
8. **Panel de salud**: revisa que el Centro de Mando no tenga alertas nuevas
   y que el error_log local no haya crecido durante TODO el recorrido.

## Agente 3 — RENDIMIENTO (orden 2026-08-31)

1. Por cada superficie clave (home, /tienda/, ficha, /a-pedido/, página de
   puntos, Mi cuenta rewards, form de reseña): TTFB, LCP y CLS en móvil
   emulado, con caché FRÍA y caliente (flush entre lados — la caché caliente
   miente). Presupuestos: LCP < 2.5s, CLS < 0.1 en local.
2. La sonda visual de textos corridos (qa-visual.sh) corre como parte de
   este agente: 0 problemas es el único verde.
3. Sin regresiones de peso: cero jQuery/Slick nuevos; los bundles de las
   superficies nuevas se miden y quedan anotados.

## Agente 4 — PLUGINS PROPIOS (orden 2026-08-31)

1. Recorre los paneles de administración de los CUATRO plugins first-party
   (business, search, rewards, cart-checkout-blocks) como lo haría el dueño:
   cada configuración visible se cambia, se guarda y se verifica su efecto
   en el front — sin tocar código ni base de datos.
2. Verifica que NINGÚN texto del modelo viejo quede en paneles («Preventas»
   a secas donde rige «Pedidos y preventa» / «A pedido»).
3. error_log limpio tras TODO el recorrido; el barrido de código muerto
   (grep del manifiesto de baja) devuelve solo documentación.

## Reglas de ejecución

- Docker local SIEMPRE; espejo restaurado al final (datos QA borrados, SPAR/
  CusRev reactivados hasta el cutover real).
- Mailpit es el testigo de TODOS los correos (invitación, premio, cumpleaños,
  consolidado): asunto, monto y branding (banner + paleta).
- Cada agente falla RUIDOSAMENTE con screenshot + URL + paso; el runner es
  `qa-rewards.sh [cliente|dueno|rendimiento|plugins|todos]` y corre los 4 EN
  SERIE — jamás dos agentes a la vez contra el mismo local (mutan options,
  purgan caché y se pisan; medido 2026-08-31).
- El agente RENDIMIENTO incorpora la sonda visual (qa-visual-sonda.mjs) y
  presupuestos LCP<2.5s/CLS<0.1/TTFB<800ms del lado caliente.
- Primer sueldo del gate (2026-08-31): cazó un Array-to-string en la tarjeta
  nueva del Centro de Mando y el comment_author_IP ausente del form de
  reseña — ambos corregidos en el acto.
