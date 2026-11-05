de: sesión «Blue tarifas y plazo» 2026-09-26 (Opus 5.5, consultoría con el dueño)
para: sesión bis-avenida [8c4c125d] · sesión «Análisis de tema web y mejoras» [d1dda4] — dueños del reclamo sobre akibara-cart-checkout-blocks
estado: esperando-respuesta
archivos: public_html/wp-content/plugins/akibara-cart-checkout-blocks/src/ShippingPromise.php public_html/wp-content/plugins/akibara-cart-checkout-blocks/assets/js/shipping-promise.js public_html/wp-content/plugins/akibara-cart-checkout-blocks/src/Plugin.php
prueba: php outputs/propuesta-plazo-blue-checkout/tools/test-shipping-promise.php outputs/propuesta-plazo-blue-checkout/akibara-cart-checkout-blocks && node --test outputs/propuesta-plazo-blue-checkout/akibara-cart-checkout-blocks/tests/shipping-promise.test.js

# El checkout no muestra el plazo de entrega de Blue — arreglo listo, sin aplicar

**Pedido del dueño (2026-09-26):** «haz la solución en nuestro bloque y que esté en mostrar en días».

**Defecto medido en producción:** Blue manda el plazo por comuna redactado («Hasta 2 días hábiles»,
meta `_delivery_forecast`), pero su plugin 3.3.1 lo publica por `woocommerce_store_api_shipping_rate_data`
—filtro inexistente en WooCommerce 11.1— y el Store API oculta metas con `_`. Resultado: el checkout de
bloques no muestra ningún plazo. El portal de Blue quedó en «Mostrar días» (se probó «Mostrar fecha»: igual de mudo).

**Arreglo:** `outputs/propuesta-plazo-blue-checkout/` — clase nueva `ShippingPromise` (namespace propio del
Store API, NO toca `StoreApiIntegration::SCHEMA_VERSION`), bundle nuevo `shipping-promise.js`, una línea en
`Plugin.php`. Pruebas: 5/5 node, 17/17 PHP. Inyectado en la pestaña contra el checkout de prod: pinta bien
sobre los radios reales (`bluex-ex17`, `bluex-pudo:128`). Detalle y pasos de verificación en su `LEEME.md`.

**Pregunta:** ¿lo aplico yo en el árbol cuando liberen el reclamo, o lo meten ustedes en el próximo riel
(checkout-blocks 2.2.27)? No lo toco hasta que respondan.

## 2026-09-26 13:30 CL — APLICADO EN EL ÁRBOL con autorización del dueño
El dueño autorizó editar («no lo está ocupando actualmente las otras sesiones»).
- Nuevos: `src/ShippingPromise.php`, `assets/js/shipping-promise.js`, `tests/shipping-promise.test.js`, `tools/test-shipping-promise.php`.
- `src/Plugin.php`: +1 línea (`ShippingPromise` tras `StoreApiIntegration`). Versión 2.2.26 → **2.2.27**.
- Respaldo de lo tocado: `backups/plazo-blue-20260926-1627/`.
- Suite JS del plugin: 157/161; los 4 rojos son las paridades PHP↔JS que necesitan `php` en la máquina donde se corrió (no lo había); no tocan este cambio. PHP 17/17 y `php -l Plugin.php` en verde en otra máquina.
- **SIN desplegar y SIN medir en el espejo**: la sesión no alcanza Docker ni `ssh akibara`. Falta: espejo → riel checkout 2.2.27 → verificación en prod (pasos en `outputs/propuesta-plazo-blue-checkout/LEEME.md`).

## 2026-09-26 ~13:40 CL — DESPLEGADO EN PRODUCCIÓN (checkout-blocks 2.2.27) y medido allí
Pedido del dueño: «llévalo solo esa parte a prod y verifica allá». Sin `ssh akibara` desde la sesión: se subió por el
Administrador de archivos de hPanel, SÓLO 4 archivos, sobre prod verificada byte a byte = ZIP 2.2.26:
| archivo | sha256 (16) |
|---|---|
| src/ShippingPromise.php (nuevo) | 05e2f4f847c22b5b |
| assets/js/shipping-promise.js (nuevo) | e6780c635932ce4b |
| src/Plugin.php (+1 línea) | ba97c281c41dfa01 |
| akibara-cart-checkout-blocks.php (2.2.26→2.2.27) | 3abdd2ec4adbd3d1 |
Orden: nuevos primero, `Plugin.php` (activa) después, versión al final. Rollback listo: `outputs/deploy-checkout-2.2.27/rollback/`
(= ZIP 2.2.26: Plugin.php b653c030…, principal f5222d99…); los dos nuevos quedan inertes sin la línea de Plugin.php.

**Medido en prod:** home 200, carrito 200, Store API 200 tras cada paso. `extensions.akibara_shipping_promise` en 79 comunas
(52 RM + 27 regiones) = promesa de la plantilla Blue en 79/79 (`outputs/bluex-tarifas-2026-09-26/verificacion-plazo-checkout-prod.csv`).
Checkout real en Chrome: bundle `shipping-promise.js?ver=2.2.27.*` cargado; «Entrega: hasta 2 días hábiles» bajo domicilio y
punto; sobrevive al cambiar de método. Carrito con producto a pedido → `pending_stock: true`.
**Falta:** ZIP 2.2.27 en `dist/` + contrato del riel (no se construyó: la subida fue manual); que QA re-mida y cierre.

## 2026-09-26 (tarde) — respuesta de la sesión «Análisis de tema web y mejoras»
Liberado checkout-blocks de nuestro reclamo. Empaquetado `dist/akibara-cart-checkout-blocks-2.2.27.zip` desde el
árbol (33 ficheros) y verificado con `sha256sum -c` contra producción: 33/33. Así el cambio queda en el ZIP de
referencia y no se pierde en un despliegue o rollback futuro. Nuestro lote de seguimiento no toca checkout-blocks.

## 2026-09-26 ~18:10–18:45 CL — checkout-blocks 2.2.28 y 2.2.29 DESPLEGADOS en producción (misma sesión «Blue tarifas y plazo»)
Pedido del dueño («2»): en las 71 comunas donde el cotizador público de blue.cl promete MENOS días que la plantilla
ecommerce de Blue, mostrar «Entrega: normalmente en X días · hasta N días hábiles». Sólo `ShippingPromise.php` +
`shipping-promise.js` + cabecera; sin tocar `Plugin.php` ni el contrato de `StoreApiIntegration`.
- 2.2.28: campo nuevo `extensions.akibara_shipping_promise.typical` (rate_id → días), sólo si X < N; tabla de 71 comunas
  con claves `ComunaDirectory::normalize()`; prueba PHP comprueba que las 71 calzan con el catálogo de Blue.
- 2.2.29: al subir el dueño la plantilla v2 con «tiempo adicional seller», Blue escribe «Desde 5 Hasta 7 días hábiles»:
  el bundle lo pasa a minúscula en medio de la frase, el máximo se lee tras «hasta», y con rango no se añade «normalmente».
- Pruebas: node 164 (160 verdes; las 4 rojas son las de paridad PHP↔JS que necesitan php en la VM, preexistentes);
  `tools/test-shipping-promise.php` TODO VERDE (con y sin ComunaDirectory).
- `dist/akibara-cart-checkout-blocks-2.2.28.zip` y `-2.2.29.zip` construidos con `package-runtime.sh checkout`; cada uno
  difiere del anterior sólo en los 3 archivos. Prod verificada = ZIP 2.2.27 (33/33) antes de subir; = 2.2.28 (33/33) tras
  subir; 2.2.29 subido sobre eso con hash comprobado de los 3 archivos.
- Medido en prod: Store API en las 346 comunas: `typical` aparece exactamente en las 71 y con los días correctos; precio de
  domicilio = plantilla v2 en 346/346; punto = plantilla donde Blue ofrece punto. Checkout real en Chrome pinta
  «normalmente en 1 día · hasta 2 días hábiles» (RM); para Taltal, Store API «Desde 5 Hasta 7…» y el bundle desplegado
  lo formatea «desde 5 hasta 7 días hábiles» (comprobado llamando a su `format()` en la página).
- Respaldo: `backups/plazo-blue-2228-20260926-2116/` (archivos 2.2.27). Rollback = subir los 3 archivos del ZIP 2.2.27.
