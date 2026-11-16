# ADR-011: Blindaje Defensivo de Shortcodes Legados, Saneamiento de Portada y Coherencia Transversal de Envíos y Teléfonos

- **Estado:** Aceptado e Implementado en Producción (2026-11-06)
- **Fecha:** 2026-11-06
- **Autor / Supervisor:** Antigravity (`it-tech-lead`)
- **Afecta a:** `akibara-business` (v1.120.28), `akibara-rewards` (v0.4.28), base de datos de producción (`akibara.cl`) y caché edge/LiteSpeed.

---

## 1. Contexto y Hallazgo Forense

Durante la auditoría visual y técnica de producción se detectaron cuatro puntos que requerían resolución y blindaje:

1. **Fuga visual de shortcode en portada (`akibara.cl`):**
   La página de inicio (`post_id = 210`) conservaba un bloque HTML residual de la época de preventas:
   ```html
   <header class="akb-section__header"><h2>A pedido</h2><p class="akb-section__sub">Lo pedimos a la editorial y te avisamos cuando llega</p><a class="akb-section__link" href="/a-pedido/">Ver todos →</a></header>
   [akb_preventas_destacadas count=»8" columns=»4" max_per_serie=»1"]
   ```
   Al haberse retirado la lógica de preventas en PHP (`HomeDestacados.php`) por el Modelo Binario (ADR-010), el tag `[akb_preventas_destacadas]` quedó como shortcode no registrado. WordPress lo renderizó como texto plano crudo con comillas tipográficas, exponiendo deuda técnica a los visitantes.

2. **Umbral de envío gratis estático en fidelización (`Redemption.php`):**
   En `akibara-rewards/src/Points/Redemption.php`, el cálculo de canje de puntos frente a envío gratis utilizaba un valor estático `$free_shipping_threshold = 60000.0;`, desacoplado de la opción viva `akibara_fsp_carrier_threshold` que gobierna `ShippingCopy` y la barra de progreso de envío gratis.

3. **Normalización telefónica duplicada en puntos (`FirstPurchase.php`):**
   En `akibara-rewards/src/Points/FirstPurchase.php`, el chequeo antifraude de primer pedido por número telefónico implementaba una limpieza regex aislada en vez de delegar en el extractor canónico `\Akibara\Business\Utilities\ChileanPhone::extract_digits()`.

4. **Presencia de `onbackorder` en tests de matriz de precios (`PricingSelfTest.php`):**
   La clase de auto-test contenía `assert_backorder_tripartite_state()` que simulaba transiciones tripartitas hacia `onbackorder`, contraviniendo el principio de cero residuos de preventa.

---

## 2. Decisiones Técnicas y Arquitectura

### 2.1 Principio de Neutralización Defensiva de Shortcodes Retirados
Al desincorporar cualquier shortcode que haya sido público en la tienda, inicialmente se registró un stub defensivo para absorber transients o revisiones pendientes:
```php
// HomeDestacados.php (etapa de transición)
add_shortcode( 'akb_preventas_destacadas', '__return_empty_string' );
```
Esto aseguró que durante el cutover ningún post, revisión de Gutenberg o transiente imprimiera texto crudo en la interfaz pública.

> [!NOTE] Evolución en ADR-013 (Retiro Definitivo del Stub)
> En [ADR-013](ADR-013-auditoria-zero-trust-erradicacion-codigo-muerto-y-relevancia-buscador.md), tras la auditoría SQL exhaustiva que certificó que 0 posts, páginas vivas o revisiones en producción contenían `[akb_preventas_destacadas]`, el stub fue eliminado definitivamente del código fuente en cumplimiento de la directiva de no tolerar stubs zombies en el sistema.

### 2.2 Saneamiento Atómico de Contenido y Base de Datos
- Se purgó el bloque residual de «A Pedido» de la portada en producción.
- Se verificó que ninguna página pública en producción ni en local mantenga referencias a preventas o `/a-pedido/`.
- Se ejecutó purga combinada en servidor web (`LiteSpeed Cache`), memoria (`Redis Object Cache`) y CDN (`Cloudflare Edge`).

### 2.3 Coherencia Dinámica de Umbral de Envío Gratis
- Se modificó `Redemption.php` para leer dinámicamente:
  `$threshold = (float) \get_option( 'akibara_fsp_carrier_threshold', 60000.0 );`
  integrado con `ShippingCopy::threshold()`.

### 2.4 Delegación Telefónica Canónica
- Se actualizó `FirstPurchase.php` para delegar la normalización telefónica en `ChileanPhone::extract_digits()`, manteniendo fallback seguro en caso de desacoplamiento de plugins.

### 2.5 Normalización Binaria de `PricingSelfTest`
- Se reemplazó la prueba tripartita por `assert_outofstock_tripartite_state()`, garantizando que ninguna batería de pruebas ensaye o requiera el estado `onbackorder`.

---

## 3. Estado Verificado Post-Despliegue

- **`akibara-business-1.120.28.zip`** desplegado y validado (SHA-256: `ce8a5224...`).
- **`akibara-rewards-0.4.28.zip`** desplegado y validado (SHA-256: `2e879d5d...`).
- **Watchdog de inventario en vivo (`akibara.cl`):**
  - Total productos auditados: 1.992
  - `instock`: 1.078
  - `onbackorder`: 0
  - `outofstock`: 914
- Cero archivos temporales (`.bak`, `.tmp`, `.sql`) en el servidor remoto.
- AST Graphify actualizado a 25.690 nodos y 49.175 conexiones.
