# Gate de CORREOS — spec de la fase (orden del dueño 2026-09-01)

Mandato textual: «que el mail se vea correcto en diferentes dispositivos,
la paleta de color, el branding, que cuando uno compre visualmente sea
llamativo y no esté corrido el CSS… la persona puede dejar de recibir ese
mail de series: verifica cómo lo hace, TODO».

## Inventario de correos a verificar (cada uno, HTML + plain)

| # | Correo | Cómo generarlo en local |
|---|--------|--------------------------|
| 1 | Aviso «Sigue esta serie» (1 serie) | siembra de producto nuevo en serie seguida + digest |
| 2 | Aviso «Sigue esta serie» (2+ series, «+N series más») | dos siembras + digest |
| 3 | Vuelta al stock (BIS) | suscripción + flip a instock |
| 4 | Invitación a reseñar (token) | pedido completado + invitación |
| 5 | Premio de reseña (+500) | aprobar reseña con foto |
| 6 | Cumpleaños (+1.000) | fecha de cumpleaños hoy + corrida |
| 7 | «Puntos esperándote» (registro) | registro nuevo |
| 8 | Aviso de vencimiento | ledger con puntos por vencer + corrida |
| 9 | Recordatorio preventa (T-7 / cambio de fecha) | pedido preventa + corrida |
| 10 | «Tu preventa ya llegó» (ReadyToShip) | flip ready |
| 11 | Pedido en proceso / completado (Woo core) | compra real del gate |
| 12 | Restablecer contraseña / nueva cuenta (Woo core) | registro |

## Verificación VISUAL por correo (Playwright sobre el HTML de Mailpit)

- Render a 375px (móvil), 600px (cliente de correo típico) y 900px:
  CERO overflow horizontal, nada cortado, botones CTA completos y táctiles.
- PALETA/BRANDING: colores computados de la casa (rojo #E60012 en CTA,
  dark/blanco según plantilla, amarillo solo acento), logo/nombre Akibara
  presente, tipografía con fallback digno (los clientes de correo NO cargan
  webfonts: medir con la fallback real, no con Inter).
- ROBUSTEZ DE CLIENTE DE CORREO: estilos críticos INLINE (Gmail recorta
  <style> y clippea >102KB — medir peso del HTML), width máx ~600-640px
  centrado, imágenes con alt y dimensiones, SIN dependencias externas rotas.
- ENLACES: TODOS absolutos (https://…, cero rutas relativas — regresión del
  hallazgo /coleccion/), y cada CTA lleva a donde dice.
- El PLAIN existe, es legible y trae los mismos enlaces.
- El correo de COMPRA (nº11) además: «visualmente llamativo» = jerarquía
  clara (número de pedido, items, total, CTA), branding de la casa aplicado
  — si sale con el look Woo por defecto, es HALLAZGO (el wrap viejo de
  mu-plugins muere en el cutover: verificar qué lo reemplaza).

## Flujo de BAJA del aviso de series — «cómo lo hace, TODO»

1. Botón nativo del cliente de correo: cabeceras List-Unsubscribe +
   List-Unsubscribe-Post: One-Click presentes; simular el POST one-click
   de Gmail (POST al URL de la cabecera con body List-Unsubscribe=One-Click,
   SIN cookies) → la baja debe aplicar sin pantalla intermedia.
2. Enlace de baja POR SERIE del cuerpo: visible y comprensible → GET =
   página de confirmación brandeada (no muta) → botón → «Listo» (muta).
3. Enlace de baja GLOBAL de la cabecera del cuerpo.
4. POST del mismo token repetido → página neutra, sin duplicar ni 500.
5. Token corrupto → página neutra, CERO mutación.
6. EFECTO REAL: tras la baja de la serie A, sembrar tomo nuevo de A y de
   B → el digest escribe SOLO por B. (El correo de B no menciona A.)
7. REVERSIÓN: toggle «Avisarme» en /mi-cuenta/coleccion/ re-activa →
   nuevo tomo de A vuelve a avisar.
8. Cada pantalla del flujo (confirmación, listo, neutra) también se mide
   visual: branding, móvil 375px, botón táctil, sin CSS corrido.

## Reglas

Las de la casa: en serie, espejo restaurado con prueba antes=después,
cero términos tocados, sondas re-ejecutables en gate-bordes/, evidencia
numérica o no cuenta. Los correos del inventario que no puedan generarse
en local llevan NO-MEDIBLE-LOCAL con la razón exacta.

## Configurabilidad EN VIVO (añadido 2026-09-01, pregunta del dueño)

No basta que el campo exista: (a) cambiar el ASUNTO del aviso de serie
desde WooCommerce → Correos (pantalla real, como el dueño) → generar un
aviso → el correo llega con el asunto nuevo y sus placeholders resueltos;
(b) cambiar un monto en Puntos y Reseñas → el próximo correo del carril
lo refleja; (c) APAGAR el carril desde su interruptor → el evento que
antes enviaba correo ya NO envía nada (contar en Mailpit); re-encender →
vuelve. Restaurar todos los valores al default medido ANTES.

(d) ESTADO DISPARADOR de la invitación (campo nuevo del 2026-09-01):
verificar el <select> SERVIDO en la pantalla con los estados reales del
sitio; elegir «Procesando» → un pedido que ENTRA a processing agenda la
invitación (ver la acción en Action Scheduler); devolver a «Completado» →
el mismo tránsito ya no agenda hasta completar. La compuerta de preventa
manda SIEMPRE por encima del estado elegido (verificar con pedido
preventa: no invita aunque el estado dispare). Restaurar el default.

## Corrección del inventario (pasada 3, 2026-09-01)

El nº 7 «Puntos esperándote (registro)» NO es un correo por DISEÑO: la
promesa vive en superficie web (gancho del checkout para invitados y
Mi cuenta) y la decisión sellada fue «NO se paga por registrarse» — un
correo de bienvenida con puntos prometidos quedó como idea de LOTE 2
(mencionar el bono en el correo de cuenta nueva). El inventario efectivo
del gate son 11 correos + esa superficie web.

## Fase 3 CERRADA (2026-09-01) — sonda re-ejecutable

Los siete arreglos de correo de esta fase están medidos sobre lo SERVIDO,
no sobre el árbol, por dos sondas que se vuelven a correr en cualquier
momento (cwd = `tools/akibara-releases/rewards-qa/`, donde vive
`node_modules`):

```bash
# 1. Generar de verdad los cuatro correos (siembra reversible: los objetos
#    viajan EN MEMORIA y lo único persistente —la usermeta «ya avisé este
#    mes»— se restaura al terminar).
docker compose run --rm -T cli wp eval-file - --path=/var/www/html \
  < enviar-cuatro-correos.php

# 2. Medir: multipart + plain legible + contraste computado del CTA.
node sonda-correos-contraste.mjs      # 41/41 checks

# 3. Pliegue móvil de /tienda/ (umbral de lazy).
node sonda-lazy-tienda.mjs
```

`sonda-correos-contraste.mjs` mide el color **computado** con Chromium, no
el markup: el defecto que cerró era `-webkit-text-fill-color` heredado del
wrapper pisando al `color:#ffffff` del botón — 1,23:1, y en el HTML se leía
«blanco». Hoy: **4,80:1** en los cuatro correos.

Dos pasos de DB acompañan al código y NO viajan en ningún ZIP
(`woocommerce_email_base_color` y el `email_type` multipart de los correos
de Woo core): están en `ops/runbook-cutover-migracion-datos.md`, FASE 2 · 11.
