de: sesión «Análisis de tema web y mejoras» [d1dda4] (desarrollo, orquestando agentes)
para: cualquiera — AVISO DE ALCANCE
estado: esperando-respuesta — TODO DESPLEGADO (search 0.1.76, business 1.117.1, tema 2.1.144 el 2026-09-27); código liberado; lo cierra QA tras re-medir
archivos: docs/arquitectura
prueba: bash tools/test-clases-aisladas.sh gp && (cd tools/akibara-e2e && npm test)

# Mejoras de UX, diseño y rendimiento del tema (2026-09-22)

Orden del dueño (2026-09-22): «incluye los cambios y mejoras y pruébalas en espejo, llévalas a prod
y pruébalas allá también» · «puedes hacer un cambio profundo si es necesario, no seas modesto».

Base: auditoría `ops/informe-tema-ux-rendimiento-2026-09-22.md` (93 hallazgos verificados).
Base de código verificada idéntica a producción: tema 2.1.142, business 1.116.40, search 0.1.74
(`diff -rq` árbol vs `dist/*.zip`, sin diferencias salvo dotfiles y tests).

**Relevo de reclamos:** `2026-09-15-velocidad-cache-de-fichas-y-etiqueta-ads` y
`2026-09-19-qa-a-cero-carriles-paralelos` reclamaban ficheros de business/search/tema; el 2026-09-22
no había ninguna otra sesión Claude viva en la máquina (ListAgents vacío) y sus versiones ya están
superadas en producción. Esta sesión toma esos árboles mientras dure este asunto.

**Decisión del dueño registrada (2026-09-22):** orden inicial del catálogo → «mostrar los disponibles
primero, pero ojo que también tenemos un ordenamiento de las series» (las landings de serie conservan
Tomo 1 → N).

**Pendiente del dueño (sin código):** añadir `/wp-content/litespeed/` a la Cache Rule 9 de Cloudflare
(el clasificador bloqueó la edición desde su Chrome). Detalle en la sección 2.A del informe.

**2026-09-23:** implementación en el espejo terminada (tema T2-T7, business/search B1-B5, integración I1; T1 lo cortó un
falso positivo del filtro de la API y su diff lo revisó un agente aparte). Se amplía el reclamo a
akibara-rewards (PdpNotice a prioridad 31-32; rótulo de akb_rewards_carousel_title) y a
akibara-cart-checkout-blocks (INP de campo 1,1 s en #billing-address_1; botón de quitar tapado por «Ahorra $X»;
z-index 100000 de pudo-blocks.css), ambos idénticos a producción (rewards 0.4.17, blocks 2.2.25). Orden de despliegue
obligatorio: search 0.1.75 ANTES que el tema; business con el tema o antes.

**2026-09-26 — listo para desplegar tras la revisión pre-despliegue.** ZIP en dist/: akibara-search-0.1.75,
akibara-business-1.117.0, akibara-cart-checkout-blocks-2.2.26, akibara-rewards-0.4.18, akibara-gp-2.1.143
(qa-theme.sh 2.1.143 PASS; cada ZIP = su árbol). Orden de despliegue: search → business → checkout → rewards →
tema (el gate del tema declara los acoples). Punto de restauración local: .local-backups/deploy-20260925-tema-ux/
(505 options, menu_order de 1.647 productos, los 5 ZIP de prod). Tras desplegar: purga LiteSpeed + Cloudflare,
«Recalcular la vitrina ahora», e2e de sólo lectura en prod, visual y comportamiento contra una base de prod
tomada justo antes, y métricas. Bajos conocidos que van al lote de seguimiento: VERIF-Z-1..4 (wfE.json).

**2026-09-26 06:08-06:11 UTC — DESPLEGADO.** Con ops/akibara-deploy-runtime.sh, en orden search → business → checkout →
rewards → tema; paridad 61/61, 351/351, 31/31, 47/47, 143/143; claves y correo sanos en cada paso. Revisión pre-despliegue
(10 lentes Fable+Opus, doble refutación): 0 medios/altos confirmados. Tras desplegar: 11 páginas clave con el código
esperado (404 donde toca), suggest 200, índice 1.648 acked sin deuda, recálculo de la vitrina completado 06:09:26 UTC,
superficie servida con .min ?ver=2.1.143.* (borde ya refrescado). Pendiente del DUEÑO: regla 9 de Cloudflare
(/wp-content/litespeed/). Seguimiento: VERIF-Z-1..4 y los bajos de la revisión pre-despliegue (predeploy.json).

**2026-09-26 (tarde) — checkout-blocks LIBERADO de este reclamo.** La sesión «Blue tarifas y plazo» desplegó
checkout-blocks **2.2.27** (plazo de Blue en el checkout, `ShippingPromise`) con autorización del dueño. Este lote
no vuelve a tocar checkout-blocks. Empaquetado `dist/akibara-cart-checkout-blocks-2.2.27.zip` desde el árbol y
verificado 33/33 contra producción: CUALQUIER despliegue o rollback de checkout parte de 2.2.27, nunca de 2.2.26.

**2026-09-27 13:53-13:54 UTC — seguimiento DESPLEGADO: business 1.117.1 (352/352) y tema 2.1.144 (143/143).** Revisión
pre-despliegue Fable+Opus: 4 lentes aptas, 0 medios/altos. Decisiones delegadas del dueño aplicadas en prod como options
(antes no existían; revertir = borrarlas): akb_analytics_after_load=yes (medido: gtag y Clarity salen como text/plain y se
cargan tras `load`; GA4 envía g/collect a ~2 s y Clarity collect), akb_wishlist_guest_prompt=yes,
akb_reco_serie_agotada=completar; el 301 de LandingPageSize se mantiene. **business y tema LIBERADOS** para la sesión
SEO (2026-09-26-seo-serie-en-miga-y-vitrina). Este asunto sigue reclamando sólo akibara-search (0.1.76 en revisión),
docs/arquitectura y las specs negocio-busqueda-*.

**2026-09-27 — search 0.1.76 DESPLEGADO** (61/61, suggest 200, índice 1.648 acked sin deuda). akibara-search LIBERADO. Queda sólo docs/arquitectura (bajos de redacción).

**2026-09-27 — Cloudflare regla 9 ARREGLADA** (+ `/wp-content/litespeed/`): bundles sin `no-store`, `max-age` de un año, HIT. Pendiente del dueño cerrado.

**2026-09-27 — hero móvil de la portada optimizado (autorizado por el dueño).** La diapositiva 1 usaba
final-principal-mobile.avif 768×1376 (76.355 B) y sólo se ven ~907 px de alto (caja alto/ancho ≈ 1,18 en 320-600 px,
object-position center 30 %). Recorte a 768×916 desde la fila 138 (misma zona visible en todo móvil) + AVIF q65 s0:
final-principal-mobile-v2.avif, 53.860 B (−29 %), SSIM 0,994; adjunto 28032; `akibara_hero_settings.slides[0].mobile_id`
25169 → 28032 (respaldo de la option: .local-backups/deploy-20260925-tema-ux/hero-option-antes-20260927.json).
Medido en prod: se descarga sólo la v2 (precarga alineada), encuadre idéntico en 360/390/430.
Cierre del día: Graphify actualizado (rc 0; colisión `JS` en tests/min-assets.test.js renombrada a CARPETA_JS), e2e
prod @prod 55/55, comportamiento prod 30 superficies OK, línea base visual de prod renovada, revisión GA4/RUM programada
para el 2026-10-04 (tarea «akibara-revision-ga4-rum-lote-tema»).
