---
para: EL DUEÑO
de: QA (cowork-banner)
fecha: 2026-09-06 · actualizado 10:0x UTC
estado: índice vivo — reemplaza la versión anterior
---

# Lo que espera tu palabra, y lo que ya no

## YA DECIDISTE HOY — nada que hacer

| asunto | tu decisión | estado |
|---|---|---|
| Puntos descontados por reembolso | **No se le cuenta al cliente** | CERRADO. Comprobé antes de cerrarlo: su página de puntos ya muestra «Anulación del pedido #30685 −400». Retiré mi recomendación; tenías razón. |
| El fallo del mailer en producción | **Que espere a dev** | Abierto por decisión tuya, no por descuido. No lo toco. |

## YA NO NECESITAN DECISIÓN — los resolví o se cayeron solos

| asunto | por qué ya no |
|---|---|
| Los 321 precios «a pedido» más caros que la campaña | **Tu cartel ya dice «con stock».** El descuento está anunciado con su límite, así que no hay engaño. Retiré mi recomendación de igualarlos. |
| En qué comunas sale el retiro en San Miguel | **Está bien configurado.** Verificado como invitado: gratis en la Región Metropolitana y solo ahí. Mi medición anterior estaba contaminada por la dirección del Maule de esa cuenta. |
| El carrito al eliminar un producto | **No hace falta cambiar nada.** Ya atenúa la fila y bloquea el doble clic; mejor que un spinner. |

## ESPERAN TU PALABRA — por orden de lo que yo miraría primero

**1. Los dos clientes de Kagurabachi — HECHO, y ya sé el día exacto.** Dev
devolvió las dos filas a pendiente y yo verifiqué el correo que van a recibir:
asunto «Salió el tomo 8 de Jujutsu Kaisen», encabezado «Novedades de tu
colección». Ya no dice «el SIGUIENTE tomo», que era la frase falsa.

**Corrijo la fecha que te di.** Te dije el 12 de septiembre; medido a fondo es el
**domingo 13 a las 06:29 de la mañana**. El tope de un correo por semana no
cuenta desde que se creó la fila, sino desde el último envío a ese cliente
(5-sep 18:41 UTC): quedan libres el 12 a las 18:41, y la corrida diaria es a las
06:29, así que el 12 por la mañana todavía están topados y les toca el 13.

**No necesito nada de ti aquí**, salvo que quieras que salga antes: forzar la
corrida es un correo real a dos personas reales y eso lo decides tú.

**2. La dirección de un tercero en la cuenta de administración.** Ya ha causado
dos problemas en una noche: prellena el checkout con datos ajenos y produjo una
medición falsa mía sobre el retiro. **Límpiala tú**; ninguno de los dos toca datos
personales de un cliente.

**3. El copy cuando salen varios tomos de golpe — YA ESTÁ HECHO, retiro la
pregunta.** Lo probé de verdad: sembré dos tomos de una misma serie y corrí el
aviso. Esto es lo que le llega al cliente, palabra por palabra:

> **Salieron 2 tomos de Jujutsu Kaisen**
> Salieron 2 tomos nuevos de una serie que estás siguiendo.
> Jujutsu Kaisen — tomos 998 y 999
> Tomo 999: Disponible → *(enlace)*

Nombra los dos tomos y dice cuántos son. **No necesitas escribir nada.**

Lo único que puedes querer decidir: **enlaza sólo el tomo más nuevo**. Quien
quiera el anterior tiene que buscarlo. Es defendible (un correo, un botón), pero
si prefieres dos enlaces, es un cambio pequeño.

**4. Los dos cupones caducados.** `akbvip10` (10%) caducó el 25 de julio,
`akbgift10` en 2020 y está al 0%. Ninguno se ha usado nunca. O renuevas la fecha
del VIP o quitas la caja de cupón del carrito: hoy pide un código que no existe.

**5. La edición especial de Demon Slayer 23 ($49.990).** Está fuera de la serie,
así que no aparece en `/serie/demon-slayer.../` — donde entra justo quien la
compraría. Puede ser deliberado (evita dos productos con el tomo 23); necesita que
dev confirme si añadirla rompe la colección.

**6. Textos menores, cuando tengas rato.** «Retiro sin costo en Metro San Miguel
**(Santiago)**» · una línea en `/a-pedido/` explicando qué significa · un aviso en
Mi colección de que las compras muy recientes tardan unos minutos en aparecer.

## LO QUE ESPERA A DEV, NO A TI

- **El fallo del mailer** (decidido: espera). Único roto en producción.
- El aviso de lista de espera cuando un tomo pasa a «a pedido»: hoy dos clientes
  esperan un correo que llegará justo cuando el precio suba.
- El aviso de «pedidos anteriores» en Mi cuenta, que sale sin haber nada que buscar.
- El parche de los rebuilds innecesarios: **le dejé la fixture en rojo** que pidió.

## LO QUE CERRAMOS HOY ENTRE LOS DOS

Reseña con foto obligatoria · el tomo 1 del buscador · los avisos de serie que
mentían · el atasco del espejo · el gate MariaDB en rojo · el correo del ajuste
manual · el canje que recortaba en silencio · LiteSpeed cacheando el carrito ·
«arma tu pack» que no se apagaba · deshacer un movimiento de puntos.

---

## RESPONDIDAS por el dueño — 12:0x UTC

| pregunta | su decisión | qué pasa ahora |
|---|---|---|
| ¿Quién cierra el hueco del formulario de contacto? | primero **«que espere a dev»**, y el 11-sep **«lo cierro yo ahora»** | **HECHO y verificado el 11-sep.** Ver abajo. |
| El aviso de «pedidos anteriores» en Mi cuenta | **Ocultarlo cuando no hay nada que reclamar** | Pasa a dev con la medición: 0 reclamables / 1 pedido ya en la cuenta. |
| Lista de espera al pasar a «a pedido» | **Sólo avisar cuando haya stock real** | **El código actual ya hace eso.** QA retira el hallazgo: no era un defecto, era la política del dueño. |

### El único cabo que deja la tercera decisión, y es suyo

El disparo se queda como está. Pero el botón que pulsa el cliente dice
**«Avísame cuando vuelva»**, y para él «volver» significa «poder comprarlo».

```
Frieren 09 y 10 · a pedido · comprables desde el 2-sep
Abraham se suscribió el 2-sep · no ha recibido nada · correcto según la decisión
```

**Propuesta de una palabra: «Avísame cuando llegue».** Así la promesa y el
disparo dicen lo mismo, y quien se suscribe espera lo que de verdad va a pasar.
Es texto suyo; QA sólo señala que hoy no coinciden.

---

## 2026-09-11 · El formulario de contacto: cerrado, y una corrección mía

Llevaba cinco días parado y me autorizaste a cerrarlo yo. Está desplegado y
verificado contra producción: quien teclee `juan perez@gmail.com` o
`muñoz@gmail.com` ahora lee **«Email inválido»** y puede corregirlo, en vez de
leer «Mensaje recibido» mientras su consulta se guarda bajo la dirección de otra
persona. El registro de contacto siguió en **1 entrada antes y después** de los
cuatro intentos: ninguno escribió ni mandó nada.

**Y la corrección: te lo pinté más grave de lo que era.** Te dije «el defecto
vivo que te queda de cara al cliente». Fui a medirlo antes de tocar nada:

| población | direcciones | que el sitio reescribiría en silencio |
|---|---|---|
| clientes registrados | 78 | **0** |
| correos de facturación de pedidos | 88 | **0** |
| registro del formulario de contacto | **1** (la mía, la del otro día) | 0 |

En tu clientela nadie tiene ñ ni tildes en su correo, y el formulario no ha
recibido ni un mensaje real. Era un defecto **latente**: real, pero no estaba
comiéndose mensajes. El arreglo valía igual —es una línea y el formulario no
valida en el navegador, así que esa era la única puerta— pero la urgencia la puse
yo, no los números. Te lo digo porque si te cuento las cosas más grandes de lo
que son, la próxima vez que te diga «esto es urgente» no vas a poder creerme.

**Sigue sin necesitar tu palabra nada de esto.** Lo que sí espera tu decisión son
los puntos de más arriba.

---

## 2026-09-11 (tarde) · Tres cosas nuevas que sólo puedes decidir tú

### 1. Tu campaña está ENCENDIDA con una ventana que venció hace cinco días

```
akibara_taxonomy_discount_enabled  = yes
ventana                            = 2026-09-05 a 2026-09-06     (hoy es 11-sep)
```

**Nadie está pagando mal**: lo medí, 0 de 400 productos con stock tienen precio
rebajado por campaña. El código respeta la fecha de fin aunque el interruptor siga
en «sí». Pero si tú creías que hay campaña corriendo, no la hay desde el día 6.

Decide: ¿apagas el interruptor, o le pones fechas nuevas? Si vas a hacer campaña,
dime las fechas y el porcentaje y lo dejo puesto.

### 2. El aviso de «pedidos anteriores»: te contradije a medias

Está en `resueltos/2026-09-06-mi-cuenta-avisa-de-pedidos-que-no-existen.md` con el
detalle. Resumen: tu decisión («ocultarlo si no hay nada») era correcta —sobra
para 74 de 78 cuentas—, pero hacerlo literal convertía la pantalla en un lector de
datos ajenos. Lo apagué para todos y puse una línea discreta con el mismo enlace.

**Lo que pierdes:** los 4 clientes que sí tienen pedidos que reclamar ven una
línea en vez de una caja destacada. Si prefieres lo contrario, dímelo.

### 3. Cualquiera puede crear cuenta con el correo de otro, y quedar dentro

No es un defecto de tu sitio: es cómo funciona WooCommerce con registro abierto.
Comprobado hoy en el código real de producción (11.1.0):

- el alta de /mi-cuenta/ **no pide contraseña** (`generate_password = yes`)
- y autentica en el acto (`class-wc-form-handler.php:1283`)

O sea alguien escribe el correo de un desconocido, entra, y a esa persona le llega
un correo con una contraseña que no pidió. **Sus pedidos siguen protegidos** —
verlos exige confirmar el correo desde su propia bandeja, y eso no se puede
saltar. Hoy hay 44 correos de invitado sin cuenta en esa situación.

Si te molesta, se arregla pidiendo contraseña en el alta (una casilla de
WooCommerce). El coste es un paso más para quien se registra. **Tú decides**: yo no
toco ajustes de tu tienda.

---

## 2026-09-11 (noche) · Del recorrido como cliente: dos decisiones pequeñas y un dato

1. **¿Tope de unidades en «a pedido»?** Hoy nada impide reservar 50 tomos de
   una serie a precio de reserva ($480.000 en mi prueba). ¿Quieres tope por
   línea? Dime el número y lo pongo.
2. **¿Una línea en el correo de pedido con retiro** diciendo que le escribes
   para acordar día y hora en Metro San Miguel? Hoy el correo no lo dice; la
   portada sí.
3. **Dato, no decisión:** quitar del carrito es instantáneo — el «loading» que
   preguntaste no hace falta, sería un parpadeo de menos de un cuadro.

Detalle: `abiertos/2026-09-11-recorrido-como-cliente-en-chrome.md`.

---

## 2026-09-11 (noche) · El retiro ya se explica, y un incidente que tienes que saber

**Hecho, en producción:** el correo del pedido, la página de gracias y Mi cuenta
ahora explican el retiro en Metro San Miguel: «Te escribimos en cuanto tu pedido
esté listo y coordinamos ahí mismo el día y la hora que te acomode», con enlace
directo a WhatsApp y a contacto@akibara.cl, con el número de pedido ya puesto en
el mensaje. Sólo aparece en pedidos de retiro contigo — no en despacho a
domicilio ni en retiro en punto Blue Express, que ese lo coordina el
transportista.

**El tope de «a pedido» queda sin tope**, como dijiste: en la práctica nadie
compra más de dos. Retirado de esta lista.

**Y lo que tienes que saber: tiré la tienda abajo unos dos o tres minutos**
(21:11-21:14 UTC, o sea 18:11-18:14 en Chile). Subí un fichero que traía código
a medio hacer del otro agente. Lo restauré y la tienda está sana, y en el
registro de errores los dos únicos fatales son de mi propia consola, no de
visitantes — pero no puedo probar que nadie viera una página en blanco. Si
alguien te escribe diciendo que la tienda no cargaba a esa hora, fue esto.
Detalle y la regla que lo evita: `abiertos/2026-09-11-tire-produccion-abajo-dos-minutos.md`.

---

## 2026-09-12 (madrugada) · Barrida de deuda: lo hecho sin preguntarte y las dos cosas que sí son tuyas

**Hecho y en producción (nada de esto era decisión de negocio):** business
1.116.1 (enlace «Pedidos y preventa» del Dashboard que caía en el propio
Dashboard; portada JPG escrita en el acto para Brevo), search 0.1.59 (al filtrar
por faceta se perdía «Menor precio»; medido en akibara.cl), tema 2.1.118 (pie de
los correos de texto plano salía «AkibaraBartolo Soto 3700»), mu-plugin que
calla las 100+ líneas diarias de Blue Express por cotizaciones sin comuna (el
cliente ve lo mismo), espacio que faltaba en el texto de Transferencia bancaria,
gate del tema que llevaba semanas en rojo, sondas que aceptaban URL basura, y el
análisis estático en cero en buscador, tema, rewards y mu-plugins (business en
curso). Detalle en cada asunto.

**Lo que sí espera tu palabra:**

1. **iPhone real, ficha de producto:** la barra de compra respeta el
   `safe-area-inset-bottom` con una reserva de franja + 8 px. Lo medí en
   Chromium (inset 0) y en el simulador no cuenta como iPhone real. Si tienes el
   teléfono a mano: abre una ficha agotada, baja hasta los relacionados y prueba
   que el último botón no quede bajo la barra.
2. **Una copia de seguridad vieja vive en producción:**
   `wp-content/mu-plugins/.akibara-provider-access.php.old-1788174146` (23 de
   agosto). WordPress no la carga (no termina en `.php`), así que es inerte, pero
   no es nuestra costumbre dejar respaldos en el docroot. No borro en producción
   lo que no creé; si quieres retirarla:
   `ssh akibara 'rm ~/domains/akibara.cl/public_html/wp-content/mu-plugins/.akibara-provider-access.php.old-1788174146'`.


---

## 2026-09-12 (madrugada, segunda ronda) · Lo que decidiste y cómo va

| Decisión | Estado |
| --- | --- |
| Demon Slayer 23 (Edición Especial) dentro de la serie | en curso: dev revisa qué consume el número de tomo (colección, avisos, packs, landing) y deja el marcador de «edición especial» antes de asignarle la serie |
| Un tomo a pedido no cuesta más que uno disponible de la misma serie | en curso: precio de reserva ≤ precio con campaña vigente, medido en producción antes de cortar |
| Topes en los campos de Puntos y guarda de razón puntos/peso | en curso, en akibara-rewards |
| Textos menores | «(Santiago)» aplicado en la barra tras medir que cabe en 360 px; la intro de «a pedido» ya estaba servida; el aviso de compras recientes en Mi colección va como texto editable en Textos |
| Copia `.old` en mu-plugins de producción | retirada (copia local guardada por si acaso); la portada siguió en 200 |
| iPhone real, barra de compra | verificado por ti: se ve bien. Cerrado |
| Cupón `akbgift10` | en la papelera (era del 18-jul-2026 con fecha puesta en 2020, 0 %, sin usos). `akbvip10` y la caja de cupón siguen esperando tu palabra |
| Registro sin contraseña | respondido: no se recomienda añadir contraseña; ver la respuesta en el chat |
| IVA del despacho | decidido: se queda en cero en el pedido; el IVA de Blue lo paga en su factura. Cerrado |
| Texto «(Santiago)» en la barra | aplicado en producción tras medir que cabe en una línea a 360 y 375 px |

## 2026-09-12 07:40 UTC · dev (bis-avenida) · lo que quedó decidido y UNA pregunta nueva

Aplicado y en producción: Demon Slayer 23 (Edición Especial) dentro de la
serie, como variante que no cuenta como tomo (business 1.116.5); topes de
puntos con el techo editable desde el panel (rewards 0.3.14); la campaña se
apaga sola (cron diario); textos de Mi colección sin prometer un plazo («tu tomo
se suma cuando el pago quede confirmado: en las transferencias eso puede
demorar, porque las revisamos a mano»; editable en Textos → «Mi cuenta ·
Colección»).

PREGUNTA NUEVA (regla simétrica de las variantes): si un cliente tiene la
edición especial del 23, ¿Mi colección debe darle por tenido el 23 normal (y no
ofrecérselo como faltante)? Hoy NO: la especial es «otra versión» y el 23
normal sigue apareciendo como faltante si no lo tiene. Es decisión de negocio;
el código admite las dos.

RECORDATORIO (sin novedad): la tarea programada de la nube que toca precios no
está en este Mac ni en Rutinas de claude.ai; si la ves en tu app, elimínala.

## 2026-09-12 08:10 UTC · dev (bis-avenida) · variantes y ediciones paralelas: cómo queda, con la corrección de mi nota anterior

Mi nota de las 07:40 decía «hoy no» para la regla simétrica y estaba MAL: el
código de 1.116.5 SÍ la aplica dentro de una misma serie (prueba
`CollectionResolverTest::test_tener_solo_la_variante_tapa_el_hueco_de_ese_tomo`).
Cómo se comporta hoy, medido en el código:

- Ediciones PARALELAS (Berserk normal vs Maximum Berserk / «edición absoluta»)
  son series `pa_serie` distintas: cada una se completa por su cuenta. Es lo que
  el dueño quiere (textual: «Berserk edición absoluta es diferente a la normal,
  el cliente puede querer completar las dos»). Sin cambio.
- Una ESPECIAL SUELTA dentro de la serie (Demon Slayer 23 Edición Especial,
  marcada `edicion_especial`): aparece en la landing y en el catálogo de la
  serie, no cuenta como tomo 24, y en Mi colección tener SÓLO la especial cubre
  el 23 (no se ofrece el 23 normal como faltante); tener el 23 normal no ofrece
  la especial como faltante. Encaja con lo que dijo el dueño («casos como Demon
  Slayer o un artbook no tendrán secuela»): no hay segunda colección que
  completar. Sin cambio salvo que él diga lo contrario.
- ARTBOOKS: fuera de toda serie; no tocan ninguna colección.

También confirmado por el dueño (textual): «el tomo se prueba confirmado por
proceso completado y en proceso». Coincide con
`CustomerCollection::purchase_statuses()`: `wc-processing`, `wc-completed` y
`wc-shipping-progress`; `wc-on-hold` (transferencia sin confirmar) no cuenta.
Sin cambio.
