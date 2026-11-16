---
name: akibara-ux-publicidad-identidad
description: Especialista en UX/UI, Publicidad/CRO (Conversión y Copywriting Comercial) e Identidad Social y de Marca («Tu rincón de Akihabara en Chile») para Akibara (akibara.cl). Audita y certifica con modelo Argon High la experiencia de usuario, jerarquía visual, persuasión comercial, prueba social y coherencia de marca en local (Docker) y producción.
---

# Akibara UX, Publicidad (CRO) e Identidad Social Skill

Protocolo especializado de auditoría adversarial y certificación de **UX/UI**, **Publicidad / Conversión (CRO)** e **Identidad Social y de Marca** para Akibara (`akibara.cl`), ejecutado bajo supervisión **Argon High**.

## Cuándo usar esta Skill
- Al evaluar o rediseñar páginas clave de la tienda: Inicio (`/`), Catálogo (`/tienda/`, `/manga/`, `/comics/`), Directorio y páginas puente de Editoriales (`/editoriales/`, `/panini/`, `/ivrea/`), Landings de Serie (`/serie/<slug>/`), Fichas de Producto (PDP disponible y agotada), Sobre Akibara (`/nosotros/`) y Mi Cuenta (`/mi-cuenta/`).
- Al auditar textos comerciales, botones de acción (CTA), compra por lotes en series (`SeriesBatchAtc`), programa de fidelización («Puntos Akibara») y reconocimiento de compras verificadas.
- Antes de aprobar para producción cualquier cambio visual, de copywriting, de navegación o de arquitectura de conversión.
- **Mandato `modern-web-guidance`:** Antes de crear o modificar HTML, CSS o JavaScript de cliente en cualquier componente de tienda o panel, es obligatorio consultar `modern-web-guidance` (`npx -y modern-web-guidance@latest search/retrieve`) y respetar sus patrones estándar (`Baseline Widely Available` + mejora progresiva con `@supports` sin dependencias externas en runtime).

---

## Pilar 1: UX/UI y Jerarquía de Escaneo (Fricción Cero & Mobile-First)

1. **Tarjetas de Catálogo (`ul.products li.product`):**
   - **Escaneo en < 1,5 s:** Eyebrow de editorial en mayúsculas (`.akb-editorial-eyebrow`) $\rightarrow$ Título con nombre en `.akb-title-name` (máx. 3 líneas) y número de tomo en `.akb-vol` fuera del recorte $\rightarrow$ Precio limpio $\rightarrow$ Píldora de disponibilidad (`.akb-avail-hint`) $\rightarrow$ Botón CTA al fondo con área táctil $\ge 44\text{ px}$.
   - **Modelo de Inventario Binario Estricto:** Exclusivamente dos estados: **`Disponible`** (`instock` en bodega física de Santiago) o **`Agotado`** (`outofstock` con botón «Avísame cuando vuelva»). Queda estrictamente prohibida cualquier mención a preventas, reservas o «a pedido».
   - **Insignia "✓ Comprado" en Landings de Serie (`/serie/<slug>/`):** En la experiencia multi-selección (`SeriesBatchAtc` + `EditionCatalog` + `CustomerCollection`), los tomos ya adquiridos por el usuario logueado (incluyendo variantes equivalentes de la misma edición) muestran la insignia `✓ Comprado` (`data-owned="1"`) y se omiten automáticamente de «Seleccionar disponibles» y «Seleccionar tramo», manteniendo la posibilidad de marcado manual para recompra.
   - **Higiene de Portadas y Títulos:** Ningún `alt` de portada puede exponer nombres crudos de archivo (`onepiece110 akibara`, `OYA-2-akibara`, guiones bajos o extensiones); ningún título debe mezclar `, Vol. XX` con el estándar `[Serie] [Edición entre paréntesis] [Nº de 2 dígitos]`.

2. **Ficha de Producto (PDP) y Certidumbre en el Punto de Decisión:**
   - **Compra o Captura de Demanda:** En tomos con stock, el botón principal es **`Añadir al carrito`** (despacho inmediato 24-48 hrs). En tomos sin stock, se despliega el componente **`Avísame cuando vuelva`** (captura de demanda en `wp_akb_bis_subscribers`).
   - **Cero Fricción Manual en PDP:** No recargar la ficha individual con formularios de inventario doméstico ni botones de estantería manual paralela; la autoridad de colección se basa únicamente en compras netas verificadas (`CustomerCollection` + `CollectionOwnership`).

3. **Experiencia en «Mi Cuenta» (`/mi-cuenta/`):**
   - **Muro de Acceso con Propuesta de Valor (`form-login.php`):** El visitante no autenticado debe ver de inmediato por qué le conviene crear cuenta o iniciar sesión (`.akb-account-auth__perks`: *Puntos Akibara* y seguimiento de pedidos), con *Continuar con Google* como acción primaria y retorno garantizado al tomo de origen (`redirect_to`).
   - **Nomenclatura Limpia en Mi Cuenta (`orders`, `akibara-puntos`):** Mantener pestañas esenciales sin sobrecargar la barra móvil en 375 px.

---

## Pilar 2: Publicidad, Persuasión Comercial y CRO (La Promesa contra el Hecho)

1. **Anclaje de Valor y Psicología del Coleccionista:**
   - **Compra Inteligente por Lote y Tramo (`/serie/<slug>/`):** `SeriesBatchAtc` permite seleccionar todos los tomos disponibles o un tramo numérico (`Desde tomo X` hasta `tomo Y`) en toda la serie paginada, excluyendo los ya comprados (`✓ Comprado`) y mostrando incentivos reactivos de Envío Gratis (\$60.000 CLP) y Puntos Akibara para elevar el ticket promedio (AOV).
2. **Integridad Absoluta de las Promesas Comerciales:**
   - **Regla de Reseñas con Puntos:** NUNCA prometer puntos por dejar reseñas sueltas en la ficha de producto. La única vía que otorga puntos es la **invitación por correo con foto obligatoria** (+500 Puntos Akibara, tope 5 reseñas = \$2.500 CLP máx), cuyo enlace vigente es recuperable desde **Mis pedidos** (`/mi-cuenta/orders/`).
   - **Vigencia de Cifras y Enlaces:** Prohibido dejar contadores obsoletos o enlaces internos hacia slugs inexistentes.

---

## Pilar 3: Identidad de Marca y Tono Editorial («Tu rincón de Akihabara en Chile»)

1. **Filosofía y Respeto Otaku:**
   - Tratamiento de igual a igual: apasionado, respetuoso, conocedor y honesto.
   - Enlace canónico interno a serie (`/serie/<slug>/`) y editorial (`/marca/<slug>/`). Prohibidos enlaces externos a webs de editoriales.
2. **Estilo Brutalista Refinado:**
   - Paleta de diseño: `--aki-black`, `--aki-surface`, `--aki-red`, `--aki-yellow`, tipografía `Bebas Neue` para números de tomo (`.akb-vol`) y alto contraste para legibilidad.
