# mu-plugin barandas de LiteSpeed 1.3.0 — el celular no guardaba la caché de página

- **Abierto:** 2026-09-15 · **estado:** **esperando-respuesta** — desplegado en producción el 2026-09-15 (md5 `b5026e70…`, paridad byte a byte) y medido allá; revisión independiente (Explore, sólo lectura) sin hallazgos altos, sus medios y bajos aplicados. Lo cierra QA tras re-medirlo.
- **Reclama:** `public_html/wp-content/mu-plugins/akibara-litespeed-guardrails.php`, `tools/test-litespeed-guardrails.php`.
- **Relacionado:** `2026-09-15-velocidad-cache-de-fichas-y-etiqueta-ads.md` (el recalentador de business 1.116.14 pedía
  cada ficha dos veces, con agente de escritorio y de celular; con esto la segunda pasada sobra).

## Por qué

Se detectó mientras se probaba rewards 0.4.3. Medido en el origen (`--connect-to 127.0.0.1`):

- Agente de celular sobre una página no guardada: `x-litespeed-cache-control: no-cache` una y otra vez en la portada,
  la ficha, `/politica-de-privacidad/` y `robots.txt`. El HTML termina en «Page uncached by LiteSpeed Cache».
- Agente de escritorio: miss 0,65 s y después hit 10 ms. Luego el celular recibe hit **de esa misma copia**.

La causa está en LiteSpeed 7.9.1:

- `cache-mobile` está en 1 con `cache-mobile_rules` vacío, así que el `.htaccess` no tiene `### marker MOBILE`.
- Sin ese bloque ninguna visita lleva `ismobile` en el vary, y `Control::finalize()` hace `set_nocache( 'is mobile' )`
  a todo celular.
- Resultado: la caché de una página dependía de que antes pasara alguien desde un computador.

Descartado en el camino:

- La compuerta de precios del buscador autoriza la caché: token válido, ventana cerrada, TTL 3.600, revalidación final
  correcta. Leído en producción sólo en memoria, sin escribir.
- No hay deuda viva: `akb_search_work` todo en `acked`, 1.643 documentos igual al recibo.
- `debug-disable_all` está en 0 y no hay exclusiones por agente, cookie o rol.

## Qué cambia

Baranda nueva `litespeed_conf_load_option_cache-mobile` → false, igual que las otras. Con la opción apagada `finalize()`
sale sin marcar nada y celular y escritorio comparten una sola copia, que es lo que ya pasaba con la copia guardada
por el escritorio y lo que hace Cloudflare. El HTML no depende del dispositivo: Joinchat sólo usa `wp_is_mobile()`
para su QR, que está apagado; Mercado Pago lo usa en el checkout, que no se cachea; nadie filtra
`litespeed_is_mobile`.

## Verificación

- Local: `php tools/test-litespeed-guardrails.php` PASS (seis barandas y contraste con `control.cls.php`).
- Línea base en producción, antes de desplegar (2026-09-15 ~16:50). Seis fichas al azar, cada una pedida dos veces
  seguidas con agente de celular: **las 12 respuestas con `no-cache`**, entre 0,34 y 0,53 s de servidor
  (assassination-classroom-11, one-piece-62, one-piece-68, black-clover-14, haikyuu-6, maximum-berserk-17). La lista
  está en `~/akb-base-urls.txt`, fuera del docroot; se borra al re-medir.
- Producción después del despliegue (2026-09-15 ~17:05): valor efectivo de `cache-mobile` = 0 y `.htaccess` sin
  `### marker MOBILE`. Las mismas seis fichas: **iPhone 1ª `miss` (`public,max-age=3600`) → iPhone 2ª `hit` → Android
  `hit` → computador `hit`**, las seis. Portada y /tienda/ desde el celular salen de caché; /carrito/, /checkout/ y
  /mi-cuenta/ siguen en `no-cache`. Sin errores fatales en el log. Lista temporal borrada.
- Revisión independiente, lo aplicado: el consejo para el futuro estaba al revés (llenar la lista de agentes CON la
  baranda puesta mezclaría copias: hay que quitar la baranda y llenar la lista, las dos cosas); la pantalla de ajustes
  sigue mostrando el interruptor encendido con aviso de filtro; `.htaccess` y QUIC.cloud no pasan por el filtro
  («Restablecer ajustes» escribiría el bloque móvil: mirar el marcador tras tocar LiteSpeed); la prueba contrasta
  ahora también que `Root::conf()` siga disparando `litespeed_conf_load_option_{id}`; líneas citadas de
  `optimize.cls.php` al día (7.9.1: `:279` y `:281`).
