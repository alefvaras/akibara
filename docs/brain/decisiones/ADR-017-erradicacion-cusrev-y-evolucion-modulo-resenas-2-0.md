# ADR-017: Erradicación de Legado CusRev y Evolución 2.0 del Módulo de Reseñas

- **Estado:** Aceptado y Desplegado en Producción (`akibara-rewards v0.4.35`, `akibara-business v1.120.39`, `akibara-gp v2.1.153`).
- **Fecha:** 2026-11-07
- **Autores:** Célula de Ingeniería de Akibara (`it-tech-lead`, `it-backend-dev`, `it-ux-frontend`, `it-qa-auditor`).
- **Contexto:** Auditoría forense post-desinstalación de *Customer Reviews (`CusRev`)*, corrección del bug de pedidos multi-tomo, módulo proactivo en Mi Colección y pulido visual Neo-Brutalista.

---

## 1. Contexto y Problemas Detectados

1. **Residuos y Deuda Técnica de `CusRev` (`ivole_*` / `cr_*`):**
   - Existían metadatos huérfanos en `wp_commentmeta`: `ivole_review_image2` (1 fila en prod, 3 en local), constantes muertas `META_REMOTE_IMAGE` (`ivole_review_image` con 0 filas), filtros anti-doble pago basados en `cr_coupon_code` (0 filas) y títulos de reseña en `cr_rev_title` (0 filas).
   - En código persistían shims de compatibilidad con `CR_Reviews` desinstalado, comprobaciones de `ivole_enable_moderation` y la ruta de reescritura `/cusrev/<token>/`.
   - En `wp_wc_orders_meta` existían 7 filas en producción y 2 en local con el texto prohibido de preventa (`_akb_rewards_invite_reason = 'Esperando productos a pedido o preventa disponibles para entregar.'`), visible para el dueño en el panel de invitaciones.
   - En `Invitations.php`, `new AdminOverview()` se instanciaba en cada request público del frontend.

2. **Defecto Crítico de Pedidos Multi-Tomo (Token `#13` / Pedido `#28030`):**
   - Cuando un cliente con un pedido de varios mangas (61,1% de los pedidos) abría `/reseña/<token>/` y enviaba la reseña de su primer tomo, `claim_token()` sellaba inmediatamente `used_at = NOW()`.
   - Esto quemaba el token para los demás tomos del pedido y, adicionalmente, si el cliente visitaba la ficha individual (PDP) de sus otros tomos comprados, el formulario voluntario le negaba los +500 Puntos Akibara.

3. **Falta de Descubribilidad Proactiva:**
   - No existía un módulo en `/mi-cuenta/coleccion/` ni en `/mi-cuenta/rewards/` que mostrara cuántos tomos comprados tenía el cliente pendientes de reseñar con foto por +500 pts c/u.

4. **Regresiones Visuales y CSS:**
   - El carrusel de reseñas del home ocultaba la insignia `✓ Opinión verificada` justamente en las reseñas con foto debido a un ternario invertido (`$has_photo ? '' : ...`).
   - Los formularios de reseña y avisos de cumpleaños tenían estilos genéricos desalineados del Neo-Brutalismo Otaku Refinado en Dark Mode.

---

## 2. Decisiones de Arquitectura Adoptadas

1. **Migración Atómica e Idempotente de Claves Canónicas:**
   - Migración de `ivole_review_image2` $\rightarrow$ `_akb_review_image_id` (`Media::META_IMAGE_ID`).
   - Migración de `cr_rev_title` $\rightarrow$ `_akb_review_title` (`AdminReviews::META_TITLE`).
   - Erradicación total de `META_REMOTE_IMAGE`, subqueries SQL `NOT EXISTS (meta_key = 'cr_coupon_code')`, y compuertas muertas.
   - Purgado de las 7 filas de preventa en producción en `wp_wc_orders_meta`.
   - Restricción de `new AdminOverview()` exclusivamente a contextos donde `is_admin()` sea verdadero.

2. **Servicio Centralizado de Elegibilidad Zero-N+1 (`ReviewEligibility.php`):**
   - Creación de `Akibara\Rewards\Reviews\ReviewEligibility`: resuelve en 1 sola consulta indexada contra `wp_comments` qué productos ya fueron valorados por un cliente.
   - `unreviewed_order_items( \WC_Order $order )`: determina qué ítems del pedido faltan por reseñar (`pending`) y cuáles ya fueron reseñados (`reviewed`).
   - El token en `wp_akb_rewards_review_forms` mantiene `used_at = NULL` mientras existan tomos pendientes (`count($pending) > 0`). Solo se sella `used_at = NOW()` cuando se ha reseñado el 100% de los tomos elegibles del pedido.
   - En `/reseña/<token>/`, el formulario muestra el selector radio de los tomos pendientes y lista con un badge verde los tomos ya reseñados. La pantalla de agradecimiento invita a continuar con el siguiente tomo con un botón directo.
   - Reabierto el Token `#13` en producción (Pedido `#28030`), permitiendo al comprador reseñar su segundo tomo (*Jujutsu Kaisen 06*).

3. **Módulo de Reseñas en Mi Colección (`akibara-business` + AJAX):**
   - Creación de `CollectionReviewAjax.php` con endpoint seguro `wp_ajax_akb_rewards_submit_collection_review`.
   - Pre-cálculo de tomos reseñados en `CustomerCollection.php` sin consultas N+1 en el loop.
   - Incorporación de botón `📸 Reseñar (+500 pts)` en cada tomo comprado de `/mi-cuenta/coleccion/` y píldora `📸 N por reseñar` en el acordeón de cada serie.
   - Inserción de `<dialog class="akb-collection-review-modal">` con selector de estrellas, textarea, previsualización instantánea de foto y despacho asíncrono.
   - Callout banner `.akb-rw-pending-callout` en `/mi-cuenta/rewards/`.

4. **Pulido Visual Neo-Brutalista y UI del Dueño:**
   - Carrusel de reseñas: `✓ Opinión verificada` siempre visible en tarjetas con y sin foto. Estética Neo-Brutalista con bordes de 2px, sombras duras `4px 4px 0 #000` y esquinas netas (`border-radius: 0`).
   - `Comentarios → Crear reseña`: reemplazado el campo numérico ciego de producto por un buscador AJAX en vivo (`#akb_rw_product_search`), permitiendo al dueño buscar por título o SKU y seleccionar el tomo en 1 clic. Conservación de formulario por 60s en caso de error.
   - Tabla de invitaciones en el panel del dueño: muestra el progreso real `(X de Y tomos reseñados)`.

---

## 3. Verificación y Resultados

- **Local Docker (`localhost:8080`):** Migración ejecutada, paridad de opciones (`akb_rewards_nudge_email_ids`), 14/14 tests DOM harness de colección en verde, 898/898 tests PHPUnit en verde, y `npm test` al 100%.
- **Producción (`akibara.cl`):** Paquetes sellados y desplegados mediante `ops/akibara-deploy-runtime.sh` con verificación SHA-256 byte a byte. Base de datos migrada vía WP-CLI eval. Token `#13` verificado sirviendo *Jujutsu Kaisen 06*. Ficha PDP renderizando foto con `_akb_review_image_id`. Purga de LiteSpeed y Cloudflare completada.
