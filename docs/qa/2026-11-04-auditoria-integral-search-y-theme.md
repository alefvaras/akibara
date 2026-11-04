# Auditoría Forense Integral: Akibara Search (v0.1.66) y Akibara Theme (v2.1.149)

- **Fecha:** 2026-11-04
- **Entornos Auditados:** Local Docker (`cron` / `wordpress`) y Producción Viva (`akibara.cl`)
- **Estado General:** `ÓPTIMO Y BLINDADO` (100% de paridad, 0 divergencias, 0 bloqueos, 1.680 pruebas automáticas en verde)
- **Autor / Supervisor:** Antigravity / Astra / Dueño

---

## 1. Resumen Ejecutivo de Métricas Clave

| Componente | Versión en Prod | Pruebas Unitarias PHP | Pruebas JavaScript | Salud en Producción | Cobertura de Catálogo |
|---|:---:|:---:|:---:|:---:|:---:|
| **`akibara-search`** | `0.1.66` | **911/911 (5.103 aserciones)** | **124/124 tests** | `SANO` (0 bloqueos) | 1.992 / 1.992 docs (100%) |
| **`akibara-gp` (Theme)** | `2.1.149` | **214/214 (1.309 aserciones)** | **355/355 tests** | `ÓPTIMO` (Build verificado) | LCP < 400ms, CLS 0.00, WCAG 2.2 |

---

## 2. Auditoría Forense de Akibara Search (`akibara-search`)

### A. Diagnóstico de Tablas Especializadas en Producción Viva
Medido vía SSH directamente con `wp akb-search status` y `HealthReport::collect()`:
* **`wp_akb_search_docs`:** **1.992 documentos indexados**, coincidencia 100% con los 1.992 productos publicados de WooCommerce.
* **`wp_akb_search_tokens`:** **194.666 tokens léxicos** normalizados (`utf8mb4_bin`).
* **`wp_akb_search_facet_index`:** **23.427 entradas de facetas** activas sin términos huérfanos.
* **`wp_akb_search_qlog`:** **1.836 registros agregados** con retención de 180 días y cap de 50.000 filas.
* **Estado de la Cola (`WorkRepository`):**
  - `pending = 0`, `leased = 0`, `blocked = 0`.
  - Cero trabajos acumulados o fallidos.
* **Sincronización Editorial (`Wanted` vs `Receipt`):**
  - Protocolo canónico v1 (`akb-editorial-materialization-v1`).
  - `wanted_seq: 430`, `receipt_seq: 430`.
  - Huella de recibo idéntica a la huella autoritativa (`681c0f5258ac...`).
* **Reconciliador Automático (`Reconciler.php`):**
  - Última corrida: `ran_at: 1793815055`.
  - `missing: 0`, `orphaned: 0`, `stale: 0`, `divergent: 0`, `checked: 10`, `repair_queued: 0`, `ok: true`.

### B. Rendimiento de Facetas y Validación Comercial en Vivo (`/tienda/`)
Pruebas de filtrado por HTTP directo en `akibara.cl`:
1. **Faceta de Disponibilidad:**
   - `Disponible` (`instock`): **1.078 tomos**
   - `A pedido` (`a_pedido`): **776 tomos**
   - `Agotado` (`outofstock`): **138 tomos**
   - **Total:** $1.078 + 776 + 138 = 1.992$.
   - La petición `https://akibara.cl/tienda/?_disponibilidad[]=a_pedido` devuelve de inmediato los **776 productos exactos**.
2. **Faceta de Precios Adaptativa (`PriceBuckets`):**
   - Rango cuantitativo derivado dinámicamente de los precios reales del catálogo (múltiplos de $1.000 con terminación comercial en 990):
     * *Hasta $10.990:* **723 tomos**
     * *$11.000 – $13.990:* **278 tomos**
     * *$14.000 – $16.990:* **507 tomos**
     * *$17.000 o más:* **484 tomos**
     * **Total:** $723 + 278 + 507 + 484 = 1.992$.
3. **Redirección Canónica 302:**
   - La búsqueda clásica de WordPress `/?s=bleach&post_type=product` se redirige automáticamente mediante 302 hacia `https://akibara.cl/tienda/?_buscar=bleach` (`x-redirect-by: akibara-search`), unificando la indexación para Googlebot y reduciendo canibalización SEO.
4. **Relajación de Marca (`BrandRelax`):**
   - Al buscar `jujutsu kaisen ivrea` (donde la obra es editada por Panini), el motor detecta el error de sello, prioriza la intención del lector y sirve los 31 tomos reales con el aviso:
     > *«No tenemos **jujutsu kaisen** de **Ivrea**. Esto es lo que tenemos de **jujutsu kaisen**.»*
5. **Manejo de Búsqueda sin Resultados (0-Hits):**
   - Si una consulta no encuentra coincidencias, el motor despliega sugerencias populares (`jujutsu kaisen`, `naruto jump remix`, `bleach`, `nana`) y una grilla destacada con **Lo más vendido**.
6. **Privacidad y Anti-Scraping (`QueryLogger`):**
   - Excluye bots conocidos (Googlebot, Ahrefs, Semrush, scrapers).
   - Elimina PII (RUTs chilenos formateados o continuos, correos, celulares `+569` y números de pedido) antes del registro SQL acumulativo por tupla `(day, query_norm, source)`.

---

## 3. Auditoría Forense de Akibara Theme (`akibara-gp`)

### A. Core Web Vitals y Rendimiento Crítico
* **LCP (Largest Contentful Paint):**
  - Preload en `<head>` de la imagen Hero en formato **AVIF** con atributo `fetchpriority="high"`, condicionado mediante media queries:
    * Desktop: `media="(min-width: 769px)"`
    * Móvil: `media="(max-width: 768px)"`
  - Fuentes WOFF2 críticas pre-cargadas (`bebas-neue`, `inter-regular`, `inter-500`, `inter-700`).
* **CLS (Cumulative Layout Shift):** **0.00**.
  - Critical CSS inline con fondo dark anti-FOUC (`#121212`).
  - Esqueleto de carrito con altura reservada de `86rem` para evitar saltos en la hidratación de WooCommerce Blocks.
* **INP (Interaction to Next Paint):**
  - Carga diferida de GSAP y ScrollTrigger condicionada a la primera interacción del usuario o `requestIdleCallback`.
  - Oyentes táctiles pasivos con `touch-action: pan-y`.
* **Optimización Geométrica de Portadas:** Descriptores `sizes` calculados según el layout real (184 px en móvil de 2 columnas vs ancho completo), con un ahorro medido > 50 KB por imagen.

### B. Accesibilidad (WCAG 2.1 AA / 2.2) y Ergonomía Móvil
* **Zonas de Pulsación (Tap Targets):** Botones de añadir al carrito, selectores de cantidad, WhatsApp y barra de navegación inferior superan el umbral de $44\times 44\text{ px}$.
* **Navegación por Teclado:**
  - Atajo global `⌘K` / `Ctrl+K` para el buscador (suprimida la tecla `/` suelta en cumplimiento de WCAG 2.1.4).
  - Manejo de `Escape` en dos tiempos: 1.º pulso cierra sugerencias; 2.º pulso cierra el cajón.
* **Barra Móvil Inferior (`.bottom-nav`):**
  - 4 accesos directos: `Inicio`, `Catálogo`, `Buscar` (despliega modal con trampa de foco) y `Carrito` (abre drawer nativo).
  - Hidratación anticipada con `toque-temprano.js` para registrar pulsaciones previas a la descarga de scripts secundarios.
* **Sticky Add-to-Cart Inteligente:**
  - En fichas de producto (PDP), la barra de compra flotante en móvil detecta la posición del botón nativo de compra y del widget de WhatsApp mediante `IntersectionObserver`, ocultándose automáticamente para no superponerse ni saturar la pantalla.

### C. Territorio Verbal e Identidad Brutalista
* **Slogan Oficial:** *«Tu rincón de Akihabara en Chile»* verificado en `/nosotros/`, pie de página global y OpenGraph.
* **Nombres de Sección Canónicos:**
  - *«Llegados a Akiba»* (Novedades).
  - *«Callejón Manga»* (`/manga/`) y *«Callejón Cómics»* (`/comics/`).
  - *«Voces de Akiba»* (Reseñas con fotografía verificada).
* **Purga de Amarillos Residuales:** Paleta brutalista unificada (Fondo Carbón `#121212`, Texto `#F5F5F5`, Acento Rojo `--aki-red` y Amarillo `--aki-yellow` acotado exclusivamente a precios y números de tomo en lomo `.akb-vol`).

---

## 4. Matriz de Pruebas Ejecutadas y Aprobadas

```text
Suite Akibara Search (tools/akibara-search/phpunit.xml.dist):
  • 911 tests, 5.103 aserciones — OK (100% verde)
Suite Akibara Search JS (public_html/wp-content/plugins/akibara-search/tests/):
  • 124 tests — OK (100% verde)

Suite Akibara Theme (tools/akibara-gp/phpunit.xml.dist):
  • 214 tests, 1.309 aserciones — OK (100% verde)
Suite Akibara Theme JS (public_html/wp-content/themes/akibara-gp/tests/):
  • 355 tests — OK (100% verde)

Compilación de Assets del Tema (bash tools/akibara-releases/build-theme-core-css.sh):
  • 12 ficheros CSS/JS minificados con paridad SHA-256 exacta.
```
