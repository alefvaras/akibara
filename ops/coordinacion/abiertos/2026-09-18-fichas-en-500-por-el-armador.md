---
de: sesión bis-avenida [8c4c125d] (desarrollo)
para: QA · y el dueño, porque hubo caída
estado: abierto — corregido, desplegado y medido; falta que QA re-mida
archivos: public_html/wp-content/plugins/akibara-business/src/Modules/SeriesBundles/BuilderWidget.php public_html/wp-content/plugins/akibara-business/tests/Modules/SeriesBundles/LaPlantillaNoLeeVariablesFantasmaTest.php
prueba: tools/akibara-business/vendor/bin/phpunit -c tools/akibara-business/phpunit.xml.dist --filter LaPlantillaNoLeeVariablesFantasma
---

# Las fichas de producto respondieron 500 durante 4 min 20 s

**Cuándo:** 2026-09-18, de 05:05:11 a 05:09:31 UTC (02:05–02:09 hora de Chile).
**Qué vio el cliente:** todas las fichas de producto con el armador «Arma tu pack»
respondieron HTTP 500. La página se pintaba entera —el error ocurre con el buffer
ya lleno— pero el estado era 500, así que buscadores y navegadores la trataban como
rota. 37 fatales registrados en `wc-logs/fatal-errors-2026-09-18-*.log`.
El resto del sitio (portada, /a-pedido/, carrito) siguió en 200.

**Causa:** `BuilderWidget::build_html()` leía `$has_preventa_real`, una variable que
`builder_view_model()` calculaba pero NO publicaba en su array. En PHP 8 eso vale
`null` y `pack_notice( bool )` lanza `TypeError`. El defecto llevaba meses ahí,
inalcanzable: la línea vive detrás de `if ( $has_preventa )`, y con el interruptor
de fábrica («los tomos a pedido no suman para el descuento») esos tomos ni siquiera
se pintaban, así que la bandera era siempre `false`.

**Qué lo destapó:** el arreglo del hallazgo S-c87d5321 —que la ficha ofrezca los
mismos tomos que la landing de la serie— encendió esa rama por primera vez.

**Por qué no lo vieron las pruebas ni el espejo:** 3.472 pruebas unitarias en verde,
porque ninguna renderiza `build_html()`. Y el espejo NO tenía ninguna serie con el
armador activo (`akb_series_bundles_byp` sin fila), mientras producción lo tiene en
decenas de series. El espejo respondía 200 a todas las fichas porque el armador
nunca se pintaba.

## Qué se hizo

1. **Revertido a 1.116.19** en cuanto se midió el 500 (la tienda volvió a 200 de
   inmediato).
2. Arreglado: la bandera viaja en el modelo de vista y la plantilla la saca de ahí.
3. **Candado nuevo** `LaPlantillaNoLeeVariablesFantasmaTest`: junta lo que
   `build_html()` asigna y lo que usa, y falla si usa algo que no definió. Verificado
   por mutación (quitando el arreglo, la prueba falla nombrando la variable).
4. **Reproducido en el espejo** sembrando el armador en una serie mixta: sin el
   arreglo, 500; con el arreglo, 200 y 11 tarjetas. Lo sembrado se retiró
   (`akb_series_bundles_byp` y `akb_series_bundles_enabled` vuelven a no existir).
5. Desplegado **1.116.21** y medido en producción: ficha de una serie mixta con el
   armador en 200, 23 tarjetas (19 disponibles + 4 a pedido, que antes se escondían),
   los 4 con `data-discount-eligible="0"`, el aviso del pack y la frase nueva.
   Ocho fichas al azar: 200. Sin fatales nuevos desde las 05:09:31.

## Lo que QA tiene que re-medir

- Las fichas con armador en las series que el dueño tenga activas, no sólo la 364.
- Que agregar un tomo «a pedido» desde la ficha lo mete de verdad en el carrito
  (ahora el endpoint ya no exige elegibilidad promocional, igual que el de la landing).
- Que el descuento por cantidad sigue contando SÓLO los tomos disponibles.

## Para la próxima

**El espejo no tiene la configuración de producción, y eso convierte «verde en local»
en una promesa vacía para todo lo que dependa de un interruptor.** Antes de desplegar
algo que cambia una rama gateada por configuración, hay que sembrar esa configuración
en el espejo y RENDERIZAR la página, no sólo correr las pruebas unitarias.
