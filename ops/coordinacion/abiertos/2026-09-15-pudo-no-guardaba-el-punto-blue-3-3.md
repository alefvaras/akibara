---
para: cualquiera — defecto que impedía vender con retiro en punto
de: sesión de rendimiento y QA 2026-09-15 (Opus 5)
fecha: 2026-09-15
estado: esperando-respuesta — business 1.116.13 DESPLEGADO el 2026-09-15 y verificado en producción (elegir punto funciona en Android, iPhone y computador); falta el pedido de prueba completo, que necesita permiso del dueño
archivos: public_html/wp-content/plugins/akibara-business/src/Integrations/Bluex/Pudo/PudoSelectionStore.php · public_html/wp-content/plugins/akibara-business/tests/Integrations/Bluex/Pudo/PudoSelectionStoreTest.php · public_html/wp-content/plugins/akibara-business/akibara-business.php
---

# Retiro en Punto Blue Express: el punto elegido no se guardaba (desde Blue 3.3)

Pedido del dueño: «prueba PUDO en checkout de bluexpress» (2026-09-15). Probado en producción sin crear
pedidos (carrito de invitado, correo de pruebas ale.fvaras@gmail.com, carrito vaciado al terminar).

## Qué se vio

- La caja ofrece el retiro: San Miguel/Providencia/Puente Alto $3.150; Concepción/Valparaíso $4.350;
  Puerto Montt/Punta Arenas/Arica $5.250. Con calles que Blue no ubica («Calle de prueba 100», «Los Pensamientos
  1234» en San Miguel) Blue no ofrece el retiro (su vista previa necesita un punto cercano a la calle).
- **Elegir un punto válido no quedaba:** agencia 9422 guardada y, en la MISMA respuesta de la Store API, envío
  elegido `bluex-ex21` (domicilio); el front borraba el punto. **Ningún pedido con retiro en punto** desde el
  28-ago (Blue 3.3.1 se instaló el 31-ago; los ajustes de Blue faltaron hasta el 13-sep).
- Algunos puntos que muestra el mapa de Blue no existen para su API: agencia 5672 («Nancy Gutierrez», Gran
  Avenida 4348) → `bxgeo` responde `"pickupInfo": "no se encontro el id: 5672"`. Su propio validador también la
  rechaza. **Es de Blue** (inconsistencia entre su mapa y su API): para reportarles.

## Causa

Blue 3.3 exige `bluex_agency_validated_id` == `bluex_agency_id` en sesión (`get_selected_agency()`, cotización,
validación al crear el pedido). `PudoSelectionStore::hydrate()` no la escribía → Blue cotizaba vista previa sin
agencia → `is_valid_quoted_rate()` quitaba la tarifa → WooCommerce caía a domicilio.

## Arreglo (business 1.116.13)

`hydrate()` escribe `bluex_agency_validated_id` y `bluex_pudo_selected_quote_valid=false` (lo valida el cálculo
de Blue); no escribe `bluex_agency_city/state` (Blue cotizaría otro destino que nuestra cotización); `clear()`
borra las cuatro. Pruebas nuevas, verificadas por mutación; suite 3.311 con 3 semillas.

## Correcciones de la revisión independiente (también en 1.116.13)

- Bultos iguales a Blue 3.3.1 (`wc_get_dimension`/`wc_get_weight`, `ceil`, sin lo que no se despacha): la 3.2
  cortaba decimales y el catálogo tiene 2.741 medidas con decimales (tomo 17 × 11,5 × 1,5 cm).
- `bluex_pudo_selected_quote_valid` pasa a `false` sólo al cambiar de agencia (antes se pisaba en cada recálculo).
- La comparación tarifa↔cotización suma el recargo de la instancia de Blue (`get_fee`), latente: hoy sin recargo.
- `hydrate()` borra `bluex_agency_city/state` dejados por el camino clásico de Blue.
- Suite 3.315 con 3 semillas; mutaciones cazadas (sin la marca, sin recargo, bultos 3.2); PHPCS limpio; dos
  vueltas de revisión (la segunda sin bloqueos; quedan huecos de prueba de severidad baja para 1.116.14).

## Medido en producción tras desplegar (2026-09-15)

| Caso | Punto | Resultado |
| --- | --- | --- |
| Android, sin buscar | Checkpoint (9422, Santiago) | guardado, envío `bluex-pudo:112` $3.150 |
| iPhone WebKit, sin buscar | Checkpoint (9422) | guardado, envío en retiro |
| Computador, sin buscar | Checkpoint (9422) | guardado, envío en retiro |
| Android, buscando «San Miguel» | Emporio San Miguel (4041, Ana Luisa 1137) | guardado, envío en retiro |

Tienda en 200, sin fatales nuevos, sin fallos nuevos en `akibara-pudo`.

## Pendiente

- Pedido de prueba completo (transferencia bancaria, ale.fvaras@gmail.com, cancelar después): sólo con permiso.
- Reportar a Blue: su mapa ofrece la agencia 5672 y su API responde «no se encontro el id: 5672».
- 1.116.14: huecos de prueba de la segunda revisión (fee real por instancia, `needs_shipping`, unidades, bultos vacíos).

## Pruebas pendientes, cerradas en local para business 1.116.14 (2026-09-15)

- `PickupPointProviderTest`: lo que no necesita envío no viaja como bulto; medidas y peso ausentes caen a
  10/10/10 cm y 0,01 kg como Blue 3.3.1; un paquete vacío no se cotiza (no llama a Blue).
- `PudoSelectionStoreTest`: limpiar y volver a elegir la MISMA agencia exige cotización nueva.
- Suite PUDO: 103 pruebas OK. Siguen sin prueba unitaria la conversión de unidades (`wc_get_dimension`,
  `wc_get_weight`) y `shipping_method_fee()` con una instancia real: dependen de WooCommerce cargado y en la
  suite `function_exists()`/`class_exists()` dan verdadero por contagio; se cubren en el pedido de prueba.
