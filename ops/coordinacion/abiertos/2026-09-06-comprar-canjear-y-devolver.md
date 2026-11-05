---
para: EL DUEÑO (decisión) · dev sólo para la nota duplicada
de: QA (cowork-banner)
fecha: 2026-09-06
estado: abierto — OBSERVACIÓN de negocio; el código hace lo que decidió hacer
módulo: akibara-rewards · Points\Accrual (reembolsos)
---

# Comprar, canjear los puntos y devolver el pedido: el cliente se queda con el valor

Faltaba probar el reembolso **total** (sólo tenía el parcial) y, sobre todo, el
caso feo: **qué pasa si el cliente ya se gastó los puntos antes de devolver.**

## Reembolso total, sin haber gastado nada: correcto

```
compra $25.000  → +500 puntos
reembolso total → saldo 500 → 0
libro: #836  delta=-500  saldo_despues=0  order_reversal
```

Y fíjate en el detalle: el reembolso **parcial** escribe `order_refund` y el
**total** escribe `order_reversal`. Son movimientos distintos con nombres
distintos. Está bien pensado.

`total_earned` se queda en 500: lo ganado en la vida no baja. Correcto para los
escalones.

## El caso feo: gastar antes de devolver

```
1) compra $25.000            → puntos = 500
2) el cliente canjea 500     → puntos = 0
3) reembolso TOTAL           → puntos = 0     (no queda negativo)
```

Y en el pedido:

```
_akb_rewards_points_earned = 0
nota: «Puntos Akibara: 0 puntos retirados por la anulación del pedido.»
```

O sea que **el sistema sí intenta la reversión**, calcula que sólo puede quitar
0 porque el saldo está a cero, lo escribe en la nota y sigue. **No deja el saldo
en negativo**, que es lo que había que comprobar antes que nada. Tampoco cobra
dos veces: en el caso sin gasto hubo **un solo** apunte de reversión.

## Lo que te toca decidir

El resultado neto es que **quien compra, canjea y devuelve se queda con el valor
de los puntos**. Con la tasa de hoy son 500 puntos por un pedido de $25.000.

Las dos lecturas son legítimas:

- **Dejarlo así.** Dejar a alguien con saldo negativo es hostil, y el que devuelve
  suele tener un motivo real. El coste es pequeño y el gesto es bueno.
- **Dejar el saldo en negativo** hasta que lo compense con la siguiente compra.
  Es lo que hacen algunos programas de puntos, y cierra el agujero.

**Yo lo dejaría como está**, con una condición: que sepas que existe. Si algún
día ves devoluciones repetidas del mismo cliente, mira si no está cobrando
puntos por el camino. Hoy, con el volumen que tienes, no es un problema: es una
línea en un cuaderno.

## Una cosa menor para dev

La nota **se escribe dos veces**:

```
- Puntos Akibara: 0 puntos retirados por la anulación del pedido.
- Puntos Akibara: 0 puntos retirados por la anulación del pedido.
```

La reversión se dispara por dos caminos (el reembolso y el cambio de estado a
«Reembolsado»). **No cobra dos veces** —eso lo comprobé, hay un solo apunte— así
que es ruido en las notas, no un defecto de dinero. Pero ensucia el historial del
pedido y hace dudar a quien lo lea.

## El espejo quedó como estaba

Cuatro pedidos y reembolsos de prueba borrados, apuntes del libro borrados,
puntos del cliente 229 a 0, opciones `akb_qa_*` eliminadas, stock devuelto.
Verificado: `puntos=0 ganados=0 libro_usuario=0 pedidosQA=0 opcionesQA=0`, y el
libro global de vuelta en sus 21 apuntes de siempre.
