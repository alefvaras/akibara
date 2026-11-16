---
trigger: always_on
description: Reglas obligatorias de catálogo, disponibilidad, inventario y fidelidad visual de Akibara.
---

## Reglas de Catálogo, Fichas de Producto y Recomendaciones (Aprendizajes Críticos)

Estas reglas recogen errores reales corregidos en sesión y son de cumplimiento obligatorio para cualquier agente:

1. **Norma Editorial NO existe en Akibara:**
   - Akibara importa exclusivamente de Ivrea España, Ivrea Argentina, Panini España, Panini Argentina, Planeta Cómic, Milky Way Ediciones, Arechi, Distrito Manga, etc.
   - NUNCA asignar "Norma Editorial" a ningún producto ni mencionarla en descripciones de series. Si una sinopsis externa la nombra, reemplazar por la editorial oficial correspondiente o eliminar la mención.
   - Las descripciones de series deben ser concisas, informativas y resumidas con el tono de Akibara; nunca textos gigantes crudos ni cortes abruptos.

2. **Cero enlaces externos a editoriales en fichas:**
   - NUNCA incluir enlaces externos a webs de editoriales (`panini.es`, `editorialivrea.com`, `planetadelibros.com`, `milkywayediciones.com`, etc.).
   - Todo enlace a serie o editorial DEBE ser canónico e interno: `/serie/<slug>/` y `/marca/<slug>/`.
   - El bloque de enlazado interno en ficha se estandariza con la clase semántica `<p class="akb-pdp-seo-links">`.

3. **Desacople limpio de `_sku` y `_global_unique_id` (GTIN / EAN-13 / ISBN-13):**
   - `_global_unique_id` es la ÚNICA fuente canónica de WooCommerce y Rank Math para identificadores comerciales (`gtin` / `gtin13` / `isbn`; jamás usar campos legacy `_gtin`, `_barcode` ni `_wc_gtin`).
   - En ediciones editoriales individuales con código GS1 válido (13 dígitos con dígito verificador Módulo 10), `_global_unique_id` almacena los 13 dígitos exactos y `_sku` puede coincidir con él para consistencia operativa y búsqueda por código de barras.
   - **PROHIBIDO** copiar sufijos internos (`-1`, `PACK-...`) hacia `_global_unique_id` o asignar el mismo `_global_unique_id` a dos productos publicados distintos (provoca rechazos *Invalid/Duplicate GTIN* en Google Merchant Center). Packs o variantes propias sin código editorial único usan un `_sku` interno único y dejan `_global_unique_id` vacío (`''`).

4. **Portadas de producto: alta resolución desde original y responsivas:**
   - Las portadas deben generarse SIEMPRE a partir de la imagen fuente original en alta resolución (nunca recomprimir sobre un AVIF o WebP ya degradado o reducido).
   - Deben incluir siempre texto alternativo descriptivo (`alt="[Título del Tomo]"`) y usar el estándar `sizes="auto, (max-width: 350px) 100vw, 350px"`.

5. **Especificaciones estructuradas en el panel (`.akb-pdp-specs-grid`):**
   - Datos como "Edición Oficial", "Coleccionismo", "Autor", "Editorial", "Serie" y "Géneros" deben vivir como atributos estructurados en la grilla de especificaciones (`.akb-pdp-specs-grid`), NUNCA sueltos como texto desordenado o párrafos arbitrarios en la descripción que rompan la consistencia visual con el resto del catálogo.

6. **Tachado de precios en tarjetas de catálogo y recomendaciones:**
   - NUNCA usar selectores genéricos como `.single-product .product .price` porque contaminan las tarjetas de `.related` y `.up-sells` con tamaños de 28px. Acotar estrictamente a `.single-product .summary .price`.
   - Para el precio anterior tachado (`del`), suprimir el tachado nativo del navegador (`text-decoration: none`) y usar un pseudo-elemento centrado al 50%: `del::after { top: 50%; height: 1.5px; }` en `.woocommerce ul.products li.product .price del`, `.woocommerce div.product .related .price del` y `.woocommerce div.product .up-sells .price del`.

7. **Encadenamiento inmediato de Upsells (`UpsellsAutofill`) al subir nuevos tomos:**
   - Los tomos recién creados nacen con `_upsell_ids` vacío (`[]`) y el tomo anterior inmediato tampoco se entera solo de su nuevo hermano.
   - Si no se ejecuta el relleno, «También te recomendamos…» no aparece en esos tomos.
   - Tras subir tomos nuevos, ejecutar SIEMPRE `wp akibara:upsells-fill` para encadenar la secuencia y purgar la caché de las fichas afectadas.

8. **Gobierno Nativo de WooCommerce en Inventario y Precios:**
   - WooCommerce gobierna el inventario y los precios de forma 100% nativa: el código de Akibara jamás fuerza `_manage_stock`, `_backorders`, `_stock` ni borra `_sale_price`, y permite actualizar precios en cualquier momento sin bloqueos de `OrderLockGuard` (WooCommerce ya congela el precio histórico en `wp_woocommerce_order_itemmeta`).
   - El dueño de la tienda administra el inventario y los precios en WooCommerce (incluyendo si decide usar `onbackorder` o promociones de precio en cualquier estado). Nuestro código solo LEE el estado de stock de WooCommerce para mostrar badges visuales, alimentar `akibara-search` y el botón de `BackInStock`.
   - El guardrail de inventario (`python3 ops/guardrail-inventario-akibara.py`) certifica la coherencia de catálogo sin forzar ni alterar las decisiones de stock del dueño.

9. **Higiene de Atributos de Producto (`_product_attributes`):**
   - `product_brand` es una taxonomía nativa de WooCommerce; NUNCA debe agregarse al array `_product_attributes`. Si se añade, la interfaz administrativa muestra un campo desalineado llamado "product_brand".
   - Los atributos obligatorios del array `_product_attributes` son únicamente: `pa_pais`, `pa_autor`, `pa_genero`, `pa_serie`.

10. **Géneros Obligatorios (`pa_genero`):**
    - Todo tomo de manga o cómic debe tener sus géneros asignados en la taxonomía `pa_genero` y vinculados en `_product_attributes`. Nunca dejarlo vacío (`[]`).

11. **Categorías y Demografía (`product_cat`):**
    - Todo manga debe incluir obligatoriamente su categoría principal `Manga` Y su demografía correspondiente (`Shonen`, `Seinen`, `Shojo`, etc.).
    - Todo cómic debe incluir su categoría `Cómics` Y su línea (`Vertigo`, `Independiente`, etc.).

12. **Normalización de Nombres de Autor (`pa_autor`):**
    - Los autores japoneses deben registrarse con caracteres latinos estándar sin macrones (ej. `Soichiro Yamamoto`, `Kentaro Miura`, `Eiichiro Oda`, `Nobuhiro Watsuki`). Nunca crear duplicados con tildes, palos arriba o diacríticos (`ō`, `ū`).

13. **SEO Rank Math Estricto en Fichas (Puntaje >= 70/100):**
    - La palabra clave principal en `rank_math_focus_keyword` DEBE coincidir exactamente con el título del producto (ej. `Takagi-san, experta en bromas pesadas 14`) y complementarse con palabras clave secundarias (`Takagi-san 14, manga Takagi-san, Karakai Jozu no Takagi-san, Soichiro Yamamoto`).
    - El título SEO debe iniciar con la palabra clave principal.
    - El contenido debe incluir un subtítulo `<h2>` que contenga la palabra clave principal.
    - PROHIBIDO incluir el nombre de la editorial (ej. `Ivrea España`, `Panini España`, `Planeta Cómic`) en `rank_math_description`.
    - La meta descripción debe tener una longitud estricta de 140 a 155 caracteres para asegurar barra verde en Rank Math (< 160 caracteres).

14. **Descripción Corta Obligatoria (`post_excerpt`):**
    - Todo tomo creado o sincronizado en WooCommerce DEBE incluir obligatoriamente una descripción corta (`post_excerpt`). NUNCA dejar `post_excerpt` vacío (`''`).
    - Formato estándar: Un párrafo conciso, directo y cautivador (entre 160 y 300 caracteres) iniciando con el título o tomo en negrita (`<strong>[Título del Tomo]</strong>`), explicando el gancho argumental o conflicto específico de ese volumen, y cerrando con mención de edición oficial o despacho a todo Chile.
    - Este campo es indispensable porque nutre la vista previa rápida (`quick-view`), el summary superior de la ficha de producto junto al precio y los carritos emergentes.

15. **Títulos Agotados por Reimpresión / Editorial (`outofstock`):**
    - Si una obra o tomo específico figura en las planillas oficiales de la editorial o distribuidora (ej. Pujol & Amadó, Fondo Ivrea España, listas semanales de reposición) como «Temporalmente agotado», «Agotado por reimpresión» o sin disponibilidad para pedido:
    - NUNCA asignarle estado de reserva ni preventa (`onbackorder`).
    - DEBE marcarse estrictamente como agotado: `_stock_status = 'outofstock'`, `_backorders = 'no'`, `_manage_stock = 'no'`, `_stock = null` (o `0`), eliminando `_sale_price` y dejando `_price = _regular_price`.
    - Debe reindexarse inmediatamente en `akibara-search` (`wp akb-search reindex <id>`) para que el badge «Agotado» y el filtro de disponibilidad reflejen fielmente la situación comercial real de la editorial.

16. **Doble Chequeo Independiente de Atributos de Serie y Producto (`pa_serie` vs Producto Individual):**
    - Tras cualquier alta, actualización o sincronización de catálogo, es OBLIGATORIO realizar un doble chequeo con un agente o proceso independiente, NUNCA utilizando el mismo patrón o script de validación que ejecutó la inserción (para evitar sesgos de confirmación).
    - El chequeo debe auditar en producción que:
      1. Todos los atributos del tomo (`pa_serie`, `pa_autor`, `pa_genero`, `pa_pais`, `product_brand`, `product_cat`) coincidan al 100% con los tomos hermanos de la misma serie y no existan desalineaciones.
      2. La taxonomía de la serie (`pa_serie`) en WooCommerce asocie y liste correctamente al nuevo tomo.
      3. La página pública de la serie (`/serie/<slug>/`) sirva correctamente el producto con su título, portada, estado de stock y precio sin discrepancias con la ficha individual (PDP).

17. **Extracción e Integración Exhaustiva de Atributos Editoriales de Serie:**
    - Al crear o sincronizar una serie investigando en las webs oficiales de las editoriales (Ivrea, Panini, Milky Way, Distrito Manga, etc.), es OBLIGATORIO extraer minuciosamente todos los metadatos editoriales de la ficha técnica oficial y registrarlos tanto en la taxonomía como en los textos de presentación:
      1. **Estado en Japón y Conteo de Tomos Publicados:** Si la obra original sigue en curso, registrar cuántos tomos van publicados a la fecha en Japón (ej. «En curso – 21 tomos publicados»).
      2. **Estado de la Edición Local (España / Argentina):** Registrar cuántos tomos van publicados o previstos en la edición importada (ej. «En curso – 1 tomo publicado»).
      3. **Formato y Equivalencia Editorial:** Especificar si la edición difiere del tankobon individual estándar (ej. «Cada tomo de la edición española contiene dos tomos de la edición original japonesa», «Tomo doble 2 en 1», «Formato B6 con sobrecubierta e inclusión de páginas a color»).
      4. **Próximo Lanzamiento / Calendario:** Capturar el próximo tomo anunciado y su mes de salida (ej. «Tomo #2 durante el mes de diciembre»).
      5. **Desglose de Roles Creativos (Guion vs Arte):** Separar con precisión al autor de la historia original o novela del dibujante o ilustrador (ej. «Autor: Natsu Hyuuga», «Dibujo: Minoji Kurata»), asignando a ambos en la taxonomía `pa_autor`.
      6. **Demografía Oficial:** Asignar la demografía correspondiente (`Seinen`, `Shonen`, `Shojo`, etc.) en `product_cat` y en la ficha descriptiva.
      7. **Metadatos Técnicos de Taxonomía (`wp_termmeta` de `pa_serie`):**
         - Los campos nativos de serie son suficientes y NO deben alterarse en código:
           * `_akb_serie_estado`: Clave cerrada canónica (`completa`, `en_publicacion`, `en_pausa`, `cancelada`). **IMPORTANTE:** Siempre con guion bajo (`en_publicacion`), NUNCA con guion medio (`en-publicacion`).
           * `_akb_serie_tomos_obra`: Total de tomos en Japón (ej. `21`).
           * `_akb_serie_estado_edicion`: Clave cerrada canónica (`completa`, `en_publicacion`, `al_dia`, `en_pausa`, `cancelada`).
           * `_akb_serie_tomos_edicion`: Total de tomos de la edición (ej. `1`).
      8. **Ficha Técnica Estructurada 100% Dinámica en SeriesFacts (Cero Duplicación Estática):**
         - La descripción de la serie (`description` de `pa_serie`) debe contener **exclusivamente** la sinopsis cautivadora argumental y presentación comercial con el tono de Akibara (gancho narrativo, demografía contextualizada y mención de despacho a todo Chile).
         - **PROHIBIDO** incrustar viñetas o bloques HTML estáticos con conteos de tomos o fichas técnicas en `description` (ej. `<strong>Estado en España:</strong> En curso (X tomos publicados)`), ya que quedan obsoletos y contradicen al catálogo cada vez que se publica o repone un nuevo volumen.
         - Toda la ficha técnica estructurada (autores, editorial, estado de la obra en Japón, estado de la edición, tomos en catálogo, disponibilidad de stock y último tomo publicado) se delega íntegra y automáticamente al módulo **`SeriesFacts`**, que la renderiza en tiempo real con datos vivos y verdad absoluta para el cliente.

18. **Blindaje de Inventario y Gobierno Nativo de WooCommerce:**
    - **Prohibición Absoluta de Volcado Local $\rightarrow$ Producción:** NUNCA volcar estados de inventario (`_stock`, `_stock_status`, `_manage_stock`) desde la base de datos de Docker local hacia producción. La base local es exclusivamente para pruebas, desarrollo y staging; sus cantidades no reflejan la bodega física real.
    - **Gestión Nativa de Stock:** WooCommerce administra nativamente los estados de inventario (`instock`, `outofstock`, `onbackorder`). El código de Akibara no impone mutaciones destructivas ni borra precios de oferta.
    - **Validación Pre-Despliegue y Watchdog:** Ejecutar SIEMPRE `python3 ops/guardrail-inventario-akibara.py --target prod` para certificar la salud e integridad de los datos en vivo.

19. **Consistencia Visual de Placas Editoriales y Fidelidad de Marca (Placa Blanca Unificada):**
    - En el sistema de diseño oscuro de Akibara, TODAS las placas contenedoras de logotipos de editoriales (`.akb-brand-card__logo`, `.akb-brands-home__logo`, `.akb-archive-hero__logo`) deben mantener el fondo blanco unificado (`var(--aki-white)`), ángulo de sesgo (`-5° skew`) y sombra brutalista (`3px 3px 0 rgba(0, 0, 0, 0.35)`).
    - Esto garantiza la Ley de Similitud (Gestalt) y coherencia rítmica en la grilla y carruseles, evitando que una placa con fondo oscuro se mimetice o parezca un elemento desalineado/roto.
    - La identidad corporativa de cada sello se expresa en el color oficial de su logotipo/wordmark en su versión positiva (ej. Devir en azul marino `#1B264F`, Planeta Cómic en `#005CA9`, Milky Way en `#0084C7`, Arechi en `#27336A`, Ivrea en `#ED1C3B`/`#4C6BA4`, Ovni y Distrito en `#000000`), respetando el manual oficial de cada marca sobre fondo claro.

20. **Integridad Estructural y Descripción Obligatoria de Términos de Marca (`product_brand`):**
    - Todo término creado o actualizado en la taxonomía `product_brand` DEBE incluir de forma obligatoria su descripción canónica (`description`) bajo la fórmula estandarizada de Akibara:
      `"[Nombre de Marca] reúne en esta página los títulos catalogados de esta editorial en Akibara. Revisa el formato, el país de edición, el precio y la disponibilidad de cada producto. Las opciones y el costo de entrega se muestran al ingresar tu dirección en el checkout."`
    - PROHIBIDO dar de alta una editorial dejando `description` vacío (`''`), ya que provoca una regresión visual en la grilla de `/editoriales/`, omitiendo el bloque `.akb-brand-card__desc` y desalineando las tarjetas respecto al resto del catálogo curado.

21. **Blindaje contra Regresiones por Especificidad CSS en Componentes SVG y Sprites:**
    - Al aplicar estilos a elementos SVG dentro de contenedores (`.akb-brand-card__logo`, `.akb-brands-home__logo`, `.akb-archive-hero__logo`, etc.), la clase específica de la variante o marca (ej. `.akb-brand-card__logo .akb-publisher-logo--devir`) DEBE tener al menos la misma especificidad que la regla base contenedora (ej. `.akb-brand-card__logo .akb-publisher-logo`).
    - Nunca confiar en selectores de clase única simple (`.akb-publisher-logo--slug`) cuando el contenedor ya define una regla compuesta con clase y descendiente (`.akb-brand-card__logo .akb-publisher-logo { color: var(--aki-black); }`), ya que la especificidad mayor (0,2,0 vs 0,1,0) anula el color de la editorial en tiempo de renderizado.






