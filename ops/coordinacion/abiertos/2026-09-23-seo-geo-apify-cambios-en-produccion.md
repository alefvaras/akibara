---
para: cualquiera — registro de cambios en producción del carril SEO/GEO (sin código)
de: sesión «Análisis Apify SEO y geolocalización» [466172]
fecha: 2026-09-23
estado: abierto — IPs de Perplexity REVERTIDAS (no servían; ticket a Hostinger listo para el dueño); 6 URLs re-rastreadas e indexadas; 301 slam-dunk en Rank Math; siguiente: diseño de enlazado a landings /serie/ (código espera el lote de la sesión de tema)
archivos: (ninguno del repo — cambios en hPanel y en el contenido del post 22641 por la UI oficial)
---

# SEO/GEO: cambios en producción del 2026-09-23 (aprobados por el dueño)

Informe y plan: https://claude.ai/artifact/1RAUfTNi61TydTvpHUSBPd · coordinado con la sesión
«Análisis de tema web y mejoras» (reclamo 2026-09-22-tema-ux-rendimiento-mejoras): ninguno le pisa, y no
se hizo purge all ni se tocaron ajustes globales de LiteSpeed/Cloudflare.

## 1. hPanel → Administrador de IP: permitir a Perplexity

- **Por qué:** Cloudflare GraphQL (24 h, sólo rutas de página): el PerplexityBot real recibe 403 del ORIGEN
  en ~71% (251 frente a 103). Sin regla en WordPress/.htaccess: es la plataforma de Hostinger.
- **Qué:** «Permitir» 32 IPs = los 12 rangos oficiales expandidos (perplexity.com/perplexitybot.json: 18;
  perplexity-user.json: 14). hPanel no acepta CIDR. Nota en cada fila: «PerplexityBot oficial - SEO
  2026-09-23» / «Perplexity-User oficial - SEO 2026-09-23». La fila previa (Permitir 140.209.226.178) intacta.
- **Revertir:** borrar en hPanel las filas con esas notas.
- **Medir (24-sep):** 403 de PerplexityBot en rutas de página → 0 (GraphQL con el token de
  `ops/cloudflare-acceso.md`, filtro `clientRequestPath_like "%/"` y `notlike "%.%"`). Si no bajan, la lista
  no se aplica a tráfico que llega por Cloudflare → ticket a Hostinger.

## 2. Post 22641 `/los-10-mejores-manga-para-empezar-a-leer-en-2026/`: vuelve «2026»

- **Por qué:** el remediado del 20/21-ago quitó «2026» del title/H1, la palabra de todas sus consultas;
  318 → 39 impresiones y 24 → 4 clics (20 d vs 20 d), quiebre el 31-08.
- **Qué (editor Gutenberg + store de Rank Math, sin REST/SQL/WP-CLI de escritura):** post_title y
  rank_math_title «10 mangas para empezar a leer en 2026: selección editorial»; rank_math_description
  «Empieza a leer manga en 2026 con One Piece, Jujutsu Kaisen, Demon Slayer, Blue Lock o Chainsaw Man: 10
  series y por qué elegir cada una.» (las 5 con evidencia GLOBAL: Oricon 2025, Circana BookScan 2025, tiradas
  oficiales — pedido explícito del dueño: «no los que más vende mi tienda»). Slug y focus keyword sin cambios.
- **Preimagen:** `.local-reports/los10-2026-09-23/preimagen.json` (0600).
- **Verificado:** BD (post_modified 2026-09-23 20:34:23) y superficie servida (Cloudflare HIT con title,
  meta, H1 y og:title nuevos). «Solicitar indexación» pedida en GSC.
- **Medir:** impresiones/semana (hoy 10–17; meta ≥60 en 3 semanas).

## 3. Merchant Center: envío — NO se cambia (decisión del dueño 2026-09-23: dejar $3.700)

- La política real es gratis sobre $60.000 y $3.700 fijo debajo (el «0 CLP a CL» es el NOMBRE que genera
  Google for WooCommerce). GLA está en `shipping_rate=manual`: no crea ni pisa la política.
- $3.700 subestima fuera de la RM (pedidos < $60.000 desde marzo: RM $3.100–4.800; regiones $4.300–6.200;
  Antofagasta hasta $10.100). Merchant Center no admite tarifas por región en Chile. Opciones al dueño:
  A) $10.100, B) $6.200 (recomendada), C) dejar $3.700.

## Hallazgos para otras sesiones (no tocados)

- Sinopsis inventada en la ficha 17305 (`/one-punch-man-34-ivrea-espana/`): el tomo 34 real es el clímax
  Saitama vs Garou. 889 fichas modificadas el 2026-09-11 sin registro del origen de las sinopsis.
- «preventas» en `SeoEnhancements.php:1713-1715` y `LlmsManifest::DEFAULT_IDENTITY`: lo corrige la sesión de
  tema/UX en su lote (confirmado por mensaje).

## Acuerdos con la sesión de tema/UX (2026-09-23, por mensaje)

- Paginación `/…/page/1/` (301 en 76 listados): la corrige en `woocommerce/loop/pagination.php` antes de
  empaquetar, con una prueba que falla si reaparece `/page/1/`.
- Relacionados: Recommendations con orden **determinista sembrado por ficha** (no `ORDER BY RAND()` diario);
  línea base de enlaces de entrada medida hoy en prod: máximo 291 (Dandadan 01), mediana 26 por ficha,
  15 por landing de serie, 31/111 landings con <10.
- Tras su despliegue: esta sesión repite el rastreo en prod (`scratchpad/seo-apify/crawl.mjs`, 1 req/s,
  UA AkibaraQA) y compara contra esa línea base.
- «preventas» en metas/identidad y $55.000 del docblock: los corrige ella en su lote.

## Medición mensual programada (aprobada por el dueño)

Tarea local `seo-geo-mensual-akibara` (scheduled-tasks), día 24 a las 10:00 (el crédito de Apify se renueva el 23; movida desde el día 5 el 26-sep): Apify google.cl + 5 preguntas a
IAs cada mes (≈US$2,2), Semrush + Keyword Planner + rastreo completo en ene/abr/jul/oct; Search Console y
Cloudflare. Entradas fijas y línea base en `.local-reports/seo-geo-mensual/` (0700). Solo lectura.

## Indexación pedida en Search Console (aprobada por el dueño, 2026-09-23 ~21:15 CL)

«Solicitar indexación» por la UI de GSC (confirmado «Se ha solicitado la indexación») para:
/los-10-mejores-manga-para-empezar-a-leer-en-2026/ · /serie/el-chico-que-me-gusta-no-es-un-chico/ (último
rastreo 2026-06-23) · /serie/nana/ (07-07) · /serie/to-love-ru/ (07-07) · /serie/dan-da-dan/ («rastreada, sin
indexar», 23-07) · /serie/la-nobleza-de-las-flores/ («rastreada, sin indexar», 04-07).
Medir: `lastCrawlTime` en ≤7 días (inspect_url) y cuota de cada landing en la búsqueda de su serie.
Control único programado: `perplexity-403-control-20260924` (24-sep 12:00).

## Control 2026-09-26 (manual; la tarea programada del 24-sep falló al arrancar por un error del modelo)

Cloudflare GraphQL, PerplexityBot, sólo rutas de página, por día UTC:
24-sep 33×403 origen / 16×200 · 25-sep 48 / 12 · 26-sep 85 / 13. Los 403 vienen de IPs **oficiales**
(18.97.9.96–103, dentro de las permitidas en hPanel) y las mismas IPs reciben 200 a ratos → límite de
frecuencia de la plataforma Hostinger que **no respeta el Administrador de IP**. Veredicto: la lista no sirve.
Propuesta al dueño: revertir las 32 filas (no aportan) y abrir ticket a Hostinger con esta evidencia.

Indexación (inspect_url 26-sep): las 6 URLs re-rastreadas entre 23-sep 23:37 y 24-sep 00:15 UTC y todas
«Enviada e indexada» (dan-da-dan y la-nobleza estaban «Rastreada: actualmente sin indexar»).

Despliegue de la sesión de tema/UX verificado en lo que toca a SEO (26-sep, business 1.117.0, tema 2.1.143,
search 0.1.75): paginación sin `/page/1/`, metas de series sin «preventas», identidad del llms.txt con «a pedido».

## Rastreo post-despliegue (26-sep 16:05–16:50 UTC, 2.613 URLs) y un arreglo

- Enlaces internos de entrada por ficha: máximo 333 (Blue Lock 01; el 23-sep informé 291 = Dandadan 01, que no era
  el máximo) → 179 (One Piece 01); Dandadan 01 291 → 109; p90 55 → 90; mediana 26 → 30. Enlaces a `/page/1/`
  715 → 0; redirecciones internas 74 → 1. Landings /serie/ sin cambio (mediana 15; 29 de 109 con <10): siguiente palanca.
- Regresiones: ninguna. 2.609 URLs comparables: sólo cambian title/meta de /serie/one-piece/page/2-3/ («de 5» →
  «de 3», esperado con 48 por página); 0 noindex nuevos; 0 URLs del sitemap perdidas. /serie/walking-cat/ dio 522
  una vez (transitorio; hoy 200).
- **Arreglo (regla «bug encontrado = bug arreglado»):** `/serie/slam-dunk/` daba 404 (ya antes del despliegue; el
  término no existe) y la enlazan 3 guías, entre ellas la de «Los 10». Redirección 301 creada en **Rank Math →
  Redirecciones** (UI): `serie/slam-dunk` (exacta) → `https://akibara.cl/serie/slam-dunk-deluxe/`. Revertir:
  borrar esa fila en Rank Math.

## Reversión hPanel (26-sep, aprobada por el dueño con «sigue»)

Borradas las 32 filas «Perplexity… SEO 2026-09-23» del Administrador de IP. Verificado tras recargar: queda sólo
la fila original «Permitir 140.209.226.178». Ticket para Hostinger: `.local-reports/seo-geo-mensual/ticket-hostinger-perplexitybot.md`.
Trampa de la UI: tras 2–3 borrados, el diálogo de confirmación puede mostrar otra IP (diálogo viejo oculto);
se confirmó cada borrado leyendo sólo el diálogo VISIBLE y recargando la página entre tandas.
