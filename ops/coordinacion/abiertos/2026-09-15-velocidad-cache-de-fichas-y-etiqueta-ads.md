---
para: cualquiera — aviso de alcance
de: sesión de rendimiento 2026-09-15 (Opus 5, la misma línea que business 1.116.10-12 y tema 2.1.126-129)
fecha: 2026-09-15
estado: esperando-respuesta — business 1.116.14 + search 0.1.65 desplegados el 2026-09-15 (~18:00 CL) y medidos en producción; dos revisiones independientes (la segunda sin altas, sus medias y bajas aplicadas). Lo cierra QA tras re-medir la primera noche.
archivos: public_html/wp-content/plugins/akibara-business/src/Modules/CatalogOrder/CatalogOrder.php · public_html/wp-content/plugins/akibara-business/tests/Modules/CatalogOrder/CatalogOrderScheduleTest.php · public_html/wp-content/plugins/akibara-search/src/EditorialDiscountPublication.php · public_html/wp-content/plugins/akibara-search/tests/PageCacheGateTest.php · public_html/wp-content/plugins/akibara-search/akibara-search.php · public_html/wp-content/plugins/akibara-business/src/Modules/SiteSpeed · public_html/wp-content/plugins/akibara-business/tests/Modules/SiteSpeed · public_html/wp-content/plugins/akibara-business/src/Plugin.php · public_html/wp-content/plugins/akibara-business/src/Modules/AdminPanel/AdminPanel.php · public_html/wp-content/plugins/akibara-business/akibara-business.php
---

# Velocidad: caché de fichas hasta medianoche, recalentado nocturno y etiqueta de Google Ads

Decisión del dueño (2026-09-15), tras `ops/informe-rendimiento-2026-09-15.md`:

1. **Caché hasta medianoche + recalentar.** Medido: 38 de 39 fichas al azar llegaron con la caché fría
   (TTFB mediana 0,95 s); con LiteSpeed caliente el origen responde en 10 ms. Sólo las FICHAS pasan de 1 h a
   «hasta la medianoche de la tienda» (lo que cambia por día —«Quedan N días»— cambia a medianoche y LiteSpeed
   ya purga la ficha al cambiar su stock o precio). Los listados siguen en 1 h: LiteSpeed no los purga al
   cambiar un producto. La ficha muestra otros tomos («Arma tu pack», relacionados): se etiqueta por serie y se
   purga la serie cuando cambia el precio o el stock de un tomo.
2. **Etiqueta de Google Ads apagada**, con interruptor en el panel (cuenta de Ads sin campañas).
3. Mercado Pago en el carrito: es del plugin de terceros; no se toca (dueño).
4. Diferir hojas de estilo: medido sin beneficio (A/B 3 corridas), NO se aplica.

## Local (2026-09-15)

- search 0.1.65: suite 689 en verde con 3 semillas; pruebas nuevas de la ficha hasta medianoche (incluido
  el cambio de hora de septiembre y abril) verificadas por mutación; PHPCS limpio; `qa-search.sh` PASS.
- business 1.116.13: suite 3.351 con 3 semillas; 4 mutaciones cazadas (sin corte de las 06:00, sin freno
  por lentitud, enganche tardío del filtro de Ads, sin deduplicar la purga); PHPCS limpio.
- Espejo: la ficha sale con `X-LiteSpeed-Tag: …akb_serie.435`. Corrida manual del recalentado contra el
  contenedor: 50 fichas en 20 s, 0 errores (sin servidor LiteSpeed en local todas cuentan «sin caché»).
  **Defecto cazado ahí:** un objeto `Hooks` compartido entre las dos peticiones hacía que Requests
  parseara dos veces la respuesta (92 errores); corregido con uno por petición y candado de fuente.
- `qa.sh` business: el gate E2E del selector de series falló una vez («selector tiene target 44x44»)
  corriendo en paralelo con el QA del buscador; pasó dos veces seguidas solo. Se relanzó `qa.sh` completo.

## Cambio de diseño por el hallazgo del celular (2026-09-15, tarde)

Las barandas de LiteSpeed 1.3.0 (desplegadas, ver `2026-09-15-litespeed-el-celular-no-guardaba-la-cache.md`) fijan
`cache-mobile` en false: celular y escritorio comparten UNA copia por URL. El recalentador de business 1.116.14 pedía
cada ficha con `UA_DESKTOP` y `UA_MOBILE`; la segunda pasada ya no guarda nada nuevo y duplica la carga. Antes de
desplegar: una sola pasada, con `Accept` que incluya `image/avif,image/webp` (la variante `+webp` es la que ven los
clientes), y pruebas ajustadas.

## Segunda versión (business 1.116.14), tras la primera revisión independiente

Nota: el número 1.116.13 lo tomó el arreglo de retiro en punto (desplegado); esta entrega sale como 1.116.14.

- **Vitrina a las 00:05** (antes 05:00): su `litespeed_purge_all` borraba cada madrugada lo recién calentado. El
  recalentado empieza a las 00:20. Día del cambio de horario de septiembre: vitrina 01:05, recalentado 01:20.
- **Una petición por ficha, dos fichas a la vez**, `Accept` con `image/avif,image/webp`. Medido en prod: una
  sola copia sirve con y sin webp en el Accept; certificado Let's Encrypt valida con el `ca-bundle.crt` de
  WordPress por `--connect-to 127.0.0.1` (HIT 8 ms). Si cURL no acepta `CONNECT_TO`, falla en vez de salir
  por Cloudflare.
- Frena ante tanda > 8 s, 5xx o sin respuesta. Pasada manual que cierra ya de noche abre la nocturna.
  Apagar el interruptor desagenda también en el primer guardado (`add_option_*`).
- **Purga reescrita** (`SeriesCacheTags`): `akb_serie.<tt_id>` + `akb_muestra.<id>` por tarjeta; purga en
  `woocommerce_before_product(_variation)_object_save` según `get_changes()` (cubre REST, WP-CLI,
  importaciones y rebajas programadas), estado de stock, cantidad ≤ 1, `set_object_terms` de `pa_serie` y
  publicación; más `litespeed_purge_post` de la ficha. NO `woocommerce_update_product`: corre en cada venta.
- Comentarios corregidos (GLA registra en `plugins_loaded:20`; `@return` con `timezone`; motivo del no-store
  en el último minuto del día).
- Local: business PHPUnit 3.368 OK; search 689 OK; PHPCS limpio. Espejo, corrida real con transporte cURL:
  82 fichas en 20,6 s, 0 errores (sin servidor LiteSpeed en local todas cuentan «sin caché»); estado limpiado.

## Producción (2026-09-15, ~18:00 CL)

- ZIP business `fbf1f62f…` (322/322) y search `7f4cbf00…` (58/58); sin fatales; portada, tienda, ficha, carrito,
  serie, mi cuenta y sugeridor en 200.
- Ficha: `x-litespeed-cache-control: public,max-age=21499` a las 18:00:43 → vence 23:59:02 (margen 60 s).
  Etiquetas: `akb_serie.378` + siete `akb_muestra.<id>` (tarjetas). /tienda/: `max-age=3600`.
- Etiqueta de Google Ads (`AW-17775366801`) ausente de la portada.
- Recalentado real desde WP-CLI: 50 fichas guardadas en 20,1 s, 0 errores; esas fichas dan `hit` en 10 ms con
  agente de iPhone y 0,23–0,28 s de TTFB por Cloudflare desde fuera. La pasada manual sigue sola por la cola.
- Action Scheduler: `akibara_catalog_order_daily` re-anclada a 03:05 UTC (00:05 CL); `akibara_business_cache_warm`
  pendiente.
- Segunda revisión, lo aplicado: `TIMEOUT` 15 → 10 s (peor caso de corrida 20 + 10 = 30 s); «Se cortó a las %02d:00»
  desde `END_HOUR`; comentario de prioridad de GLA en la prueba. Informativo: si CONNECT_TO fallara en la 2.ª petición
  de una tanda, el primer handle de cURL queda sin cerrar hasta el fin del proceso.
- Falta: re-medir tras la primera noche (pasada nocturna 00:20, TTFB de fichas al azar por la mañana) y observar la
  purga por venta real.
