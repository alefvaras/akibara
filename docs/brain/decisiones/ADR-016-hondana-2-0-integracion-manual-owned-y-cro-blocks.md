# ADR-016: Hondana 2.0 — Integración de Estantería Manual, Cache-Safe Invalidation y Bloques CRO en Carrito y Checkout

- **Estado:** Histórico. La presentación fue sustituida por instrucciones posteriores del dueño; ver `docs/entregas/mi-coleccion-local-2026-11-12.md`. No usar controles públicos ni Hondana como especificación vigente. El nuevo candidato solo está en local.
- **Fecha:** 2026-11-07
- **Autor / Supervisor:** Antigravity (`it-tech-lead` / Argon High)
- **Afecta a:** `akibara-business` (v1.120.36), `akibara-cart-checkout-blocks` (v2.2.37), `akibara-gp` (v2.1.152), `docs/brain/CONTEXTO_VIVO.md`, `notbooklm/01-B-MODULO-COLECCION-Y-ESTANTERIA-FLUJO-COMPLETO.md`, `tests/Modules/AccountCollection/account-collection-dom.test.js`.

---

## 1. Contexto y Diagnóstico

Tras la erradicación del módulo obsoleto «Arma tu pack» y el despliegue del asistente inicial de colección en `ADR-015`, la interacción de coleccionistas reales en producción reveló cuatro puntos críticos de fricción arquitectónica y de experiencia de usuario:

1. **Inconsistencia de Recomendaciones y Digest Editorial:**
   - Cuando un coleccionista marcaba manualmente tomos que ya poseía físicamente (`_akb_collection_manual_owned`), el módulo `SmartCartRecommender` seguía sugiriéndole esos mismos tomos en el carrito lateral y en los bloques de venta cruzada porque su caché interna de historial de usuario (`_transient_akb_user_hist_{user_id}`) no se invalidaba.
   - Similarmente, `SeriesFollow\DigestRunner` corría el riesgo de alertar sobre lanzamientos o tomos que el cliente ya había registrado en su estantería personal.

2. **Degradación por N+1 Queries en Marcado Masivo (`shelve_range`):**
   - El endpoint `akb_shelve_range` («Tengo hasta el tomo X») ejecutaba consultas individuales para verificar taxonomías de serie y datos de producto por cada volumen del rango, generando picos de queries en series largas (ej. One Piece o Detective Conan).

3. **Colisión de Tokens de Caché con LiteSpeed / Cloudflare:**
   - La generación de tokens de invalidación basada en marcas de tiempo con coma flotante (`(string) microtime(true)`) presentaba inconsistencias de precisión entre entornos y producía claves transientes largas e impredecibles (`_transient_akb_cust_coll_{user_id}_{gen}`).
   - Cuando LiteSpeed Cache o Cloudflare servían páginas de archivo o PDP cacheadas, el atributo en línea `cfg.gen` quedaba desfasado respecto a la cookie `akb_owned_gen` que el navegador del usuario autenticado ya poseía, disparando recargas AJAX redundantes en cada vista.

4. **Fricción de Conversión en Visitantes Invitados y Touch Targets Móviles:**
   - Si un visitante no autenticado intentaba marcar tomos en su estantería, el diálogo de login no preservaba la intención del usuario tras autenticarse, perdiendo el tomo que deseaba registrar.
   - En el carrito lateral nativo de `akibara-cart-checkout-blocks`, los controles numéricos y la barra de progreso de envío gratuito requerían mayor ergonomía táctil en pantallas móviles ($\ge 36\text{px}$) y contraste visual brutalista refinado.

---

## 2. Decisiones Técnicas Implementadas

### 2.1 Refactor Cero Deuda en `AccountCollection` (`akibara-business 1.120.36`)

1. **Clave Transiente Canónica v3:**
   - Se migró el almacenamiento en caché de tomos consolidados al formato sellado y hasheado:
     ```php
     $transient_key = 'akb_collection_owned_all_v3_' . $user_id . '_g' . md5( (string) $gen );
     set_transient( $transient_key, $all_owned, DAY_IN_SECONDS );
     ```
   - Este esquema previene desbordamientos de nombres de opción en `wp_options` y aísla atómicamente cada generación.

2. **Rotación Canónica mediante UUIDv4:**
   - Se estandarizó la emisión de tokens de generación en `CollectionResolver` y `CollectionBadges` utilizando `wp_generate_uuid4()`, eliminando cualquier dependencia de `microtime(true)`.

3. **Optimización Anti N+1 con `_prime_post_caches`:**
   - En `CollectionResolver::shelve_range()`, se incorporó la llamada nativa:
     ```php
     _prime_post_caches( $product_ids, [ 'pa_serie' ] );
     ```
     permitiendo precalentar en una única consulta indexada todos los posts y sus términos de taxonomía antes de asociar los tomos a la estantería del usuario.

### 2.2 Sincronización Transversal con `SmartCartRecommender` y `SeriesFollow`

1. **Invalidación de Recomendador (`SmartCartRecommender::flush_user_history_cache`):**
   - En `CollectionResolver::mark_manual_owned()`, tras actualizar `_akb_collection_manual_owned` y rotar la generación del usuario, se ejecuta:
     ```php
     if ( class_exists( '\Akibara\Business\Modules\SmartCartRecommender' ) ) {
         \Akibara\Business\Modules\SmartCartRecommender::flush_user_history_cache( $user_id );
     }
     ```
   - Esto garantiza que ningún producto marcado manualmente en la estantería vuelva a ser recomendado en el carrito lateral ni en la PDP.

2. **Filtrado Editorial en Notificaciones:**
   - Se conectó la verificación de pertenencia a serie en `SeriesFollow\DigestRunner` con la API unificada de posesión, evitando despachar alertas por correo de tomos que el usuario ya declaró tener en su biblioteca.

### 2.3 Resiliencia Frontend a Page Cache e Hidratación (`collection-badges.js` & `account-collection.js`)

1. **Precedencia de Cookie de Generación:**
   - En el cliente, la resolución del token de invalidación adopta la precedencia estricta:
     ```javascript
     const activeGen = getGenCookie() || cfg.gen || '';
     ```
   - Si la página HTML proviene de la caché pública de LiteSpeed (llevando un `cfg.gen` antiguo), la cookie fresca `akb_owned_gen` prevalece inmediatamente, evitando descargas innecesarias y leyendo de `localStorage` en 0 ms.

2. **Persistencia y Replay de Intención de Invitado (`akb_shelf_guest_intent_v1`):**
   - Al interactuar como visitante con los botones de estantería o selectores de rango, la acción se guarda en `sessionStorage['akb_shelf_guest_intent_v1']`.
   - Al volver de la autenticación en `/mi-cuenta/?redirect_to=...`, el módulo ejecuta `replayGuestIntent()` procesando de inmediato el marcado manual o rango (`executeShelveRange`) en segundo plano sin intervención adicional del usuario.

3. **Activación Inmediata de Batch Add To Cart:**
   - En `/serie/[slug]/`, al marcar cualquier tomo (`ownedCount > 0`), el botón `⚡ Agregar los X tomos disponibles al carrito` se activa y actualiza dinámicamente su conteo restando los tomos poseídos, permitiendo al coleccionista adquirir la brecha restante en 1 clic.

### 2.4 Ergonomía Visual y CRO en `akibara-cart-checkout-blocks 2.2.37`

1. **Touch Targets Móviles en Drawer de Carrito:**
   - Altura mínima de 38px en selectores de cantidad y botones de acción rápida en dispositivos móviles, cumpliendo holgadamente el estándar de accesibilidad WCAG y Google Core Web Vitals.

2. **Barra de Progreso de Envío Gratuito Nativa:**
   - Contenedor `.akb-cart-shipping-bar` con altura estructural de 8px, fondo brutalista oscuro y relleno dinámico dorado/esmeralda según el umbral de \$60.000 CLP certificado en `ADR-012`.

---

## 3. Verificación y Cobertura Automatizada

1. **DOM Test Harness (`account-collection-dom.test.js`):**
   - Cobertura ampliada a 13/13 pruebas unitarias DOM en entorno `happy-dom` sin navegadores pesados ni mocks ad-hoc:
     1. Modal no-bloqueante para invitados al interactuar con estantería.
     2. Hidratación 0 ms desde `localStorage` con token de generación coincidente.
     3. Activación dinámica de Batch ATC en archivo de serie según tomos faltantes.
     4. Adición atómica al carrito (`akb_batch_add_to_cart`) en Mi Cuenta.
     5. Banner de serie completa al poseer todos los tomos publicados.
     6. Manejo resiliente de fallos de red restaurando estado del botón.
     7. Restauración de foco accesible al cerrar modales con tecla Escape.
     8. Prevención de clics concurrentes (`aria-busy` / `disabled`).
     9. Visualizador de lomos («Lomos que hablan») con scroll suave y pulso visual.
     10. Marcado masivo por rango (`akb_shelve_range`) y actualización de estado.
     11. Precedencia de cookie `getGenCookie() || cfg.gen` ante página cacheada.
     12. Guardado y repetición de intención de invitado (`replayGuestIntent`).
     13. Invalidación cruzada con `SmartCartRecommender` al modificar la estantería.

2. **Suite PHPUnit Akibara Business:**
   - 3.597 pruebas, 11.136 aserciones ejecutadas en Brain Monkey y PHPUnit 10, con 0 errores y 0 advertencias.

3. **Validación Adversarial E2E en Producción Real (`akibara.cl`):**
   - Certificación en vivo en cuenta real de cliente:
     - Marcado de Tomo 22 de Jujutsu Kaisen en `/serie/jujutsu-kaisen/`.
     - Actualización instantánea del lomo a verde esmeralda y decremento de Batch ATC de 8 a 7 tomos disponibles.
     - Incremento del contador en `/mi-cuenta/` de 2 a 3 tomos.
     - Visualización en `/mi-cuenta/coleccion/` y posterior desmarcado con botón `[✕ Quitar]`.
     - Retorno íntegro del contador a 2 tomos y restauración 100% verificada contra baseline.
