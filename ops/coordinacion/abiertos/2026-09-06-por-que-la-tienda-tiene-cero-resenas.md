---
para: EL DUEÑO (decisión) · dev (bis-avenida) para el rescate
de: QA (cowork-banner)
fecha: 2026-09-06
estado: ABIERTO · diagnosticado, no es un defecto del código
módulo: akibara-rewards · Reviews\Invitations
---

# Por qué la tienda tiene 0 reseñas, con el motor encendido y funcionando

Ya sé la respuesta. **No está roto nada.** Es un hueco en el traspaso entre el
plugin viejo y el nuevo, y son pocos pedidos, pero conviene recuperarlos.

## Lo que encontré en las notas de los pedidos

Pedidos 27720, 27722 y 27729, completados por ti el 24 de agosto:

```
2026-08-24 19:07 [system]              CR: se programó un recordatorio de las
                                       valoraciones correctamente para September 1, 2026
2026-08-24 19:07 [Alejandro Fernández] El estado del pedido cambió de Listo para enviar a Completado.
```

**«CR» es CusRev**, el plugin de reseñas que el módulo nuevo viene a reemplazar.
El 24 de agosto quien programaba los recordatorios era CusRev, no nosotros.

Fui a mirar si sigue instalado: **no está.** Ningún plugin de reseñas activo
salvo los nuestros.

## El hueco

CusRev había agendado esos recordatorios **para el 1 de septiembre**. El plugin
se quitó antes de esa fecha, y **sus recordatorios se fueron con él**. El módulo
nuestro no reagenda hacia atrás: sólo actúa cuando un pedido *cambia* a
completado, y esos ya estaban completados desde antes.

Resultado: esos clientes **no recibieron invitación de nadie**. Ni del viejo ni
del nuevo.

Medido en producción, sólo lectura:

```
pedidos completados con cliente registrado          : 4
  sin rastro de invitación (ni agendada ni enviada)  : 4
  clientes distintos sin invitar                     : 4
  rango                                              : 2026-08-27 … 2026-09-01
reseñas en producción                                : 0
```

Contando también los pedidos de invitado, son **11 pedidos completados** en total
en ese tramo. No es una catástrofe; es una oportunidad perdida y se recupera.

## Y el motor nuevo SÍ funciona: está probado en producción

No lo digo por fe. Las invitaciones que sí pasaron por el módulo nuevo dejaron su
rastro:

```
#27955  agendado=2026-09-05 21:07:15   enviado=2026-09-05 21:07:47
#27957  agendado=2026-09-05 21:23:04   enviado=(no, el pedido se canceló antes)
#27958  agendado=2026-09-05 21:24:56   enviado=2026-09-05 21:25:01
```

`enviado` sólo se sella **después de que el correo salió de verdad** (el código
no marca nada si el transporte falla). Así que el camino
`pedido completado → agenda → correo` está andando en producción.

Configuración vigente: `enabled=true`, `trigger=completed`, **`delay_dias=3`**.

Y la compuerta de preventa no está estorbando: de los pedidos completados,
**0 tienen preventa**, así que no bloquea a nadie.

## Lo que propongo, y es decisión tuya

**Un rescate de una sola vez** para los pedidos completados que se quedaron en el
hueco: emitir su invitación ahora, con el mismo módulo, sin tocar código.

Tres cosas que quiero que decidas tú antes de que nadie lo ejecute:

1. **¿Se hace?** Son ~11 correos a clientes reales que compraron entre el 27 de
   agosto y el 1 de septiembre. Es una invitación legítima —compraron— pero llega
   con retraso.
2. **¿Hasta dónde se retrocede?** Yo no iría más atrás del 27 de agosto. Pedirle
   una reseña a alguien que compró en junio se lee como spam.
3. **¿Y los que CusRev ya invitó antes de que lo quitaras?** Si algún cliente ya
   recibió el recordatorio viejo, recibiría el segundo. No tengo cómo saber
   cuáles se enviaron: el plugin se llevó su registro. Con lo que sé, **los del
   1 de septiembre no llegaron a salir**, pero no puedo jurarlo al 100%.

**Yo no lo ejecuto sin tu sí.** Mandar correos a clientes reales no es una prueba
de QA.

## Lo que NO es este ítem

No es «las reseñas están rotas». Las probé enteras hoy en el espejo —formulario,
foto obligatoria, moderación, 500 puntos, sin pago doble— y funcionan. Lo único
malo que encontré ahí es un texto, y está en su propio ítem.

## Nota de método, para mí mismo

Busqué plugins de reseñas con `grep -iE "review|cusrev|ivole"` y me salió
`hostinger-preview-domain`. **P-review**. Casi escribo que había un plugin de
reseñas escondido. Cuarta vez hoy que el instrumento intenta engañarme.
