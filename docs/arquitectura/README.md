# Cómo funciona akibara.cl por dentro

Esto es el mapa completo del código propio de la tienda: los cuatro plugins, el tema y los
mu-plugins. Está escrito para alguien que **no conoce nada** del proyecto —ni de WordPress— y que
tiene que entender qué pasa cuando un cliente compra, cuando el dueño guarda un precio o cuando algo
se rompe de madrugada.

No hace falta leerlo entero ni en orden. Abajo está la ruta corta.

---

## Empieza por aquí

- **[01 · Visión general](01-vision-general.md)** — qué es Akibara por dentro, en un solo capítulo, con los siete diagramas maestros. Si sólo vas a leer una cosa, lee esta.
- **[Glosario](glosario.md)** — 332 términos: cada palabra técnica y de negocio que aparece en la documentación.
- **[20 · Hallazgos de QA](20-hallazgos-qa.md)** — qué está roto hoy, qué se arregló y cómo se decidió que algo era un defecto.
- **[21 · Decisiones del dueño](21-decisiones-del-dueno.md)** — lo que no decide quien programa.
- **Versión visual publicada:** https://claude.ai/code/artifact/5c33c5b9-4a6d-4491-9541-4918c851f96e

---

## Antes de nada: qué es cada cosa

**akibara.cl** es una tienda de manga hecha con **WordPress** (el programa que arma y sirve las
páginas) y **WooCommerce** (un añadido de terceros que le pone carrito, pedidos y pagos). Sobre esos
dos, Akibara tiene **seis piezas propias** —y son las únicas que se pueden modificar:

| Pieza | Qué es | Capítulos |
| --- | --- | --- |
| `akibara-business` | El plugin grande: catálogo, series, precios, preventa, correo, SEO, el panel del dueño. | 02 – 07 |
| `akibara-search` | El buscador propio: su índice y su motor de consulta. | 08 – 09 |
| `akibara-rewards` | Akibara Puntos y las reseñas con foto. | 10 – 11 |
| `akibara-cart-checkout-blocks` | Los campos chilenos del checkout: RUT, celular, comuna y puntos Blue Express. | 12 |
| `akibara-gp` (tema) | Todo lo que el cliente ve: cómo se pinta cada página. | 13 |
| 18 *mu-plugins* | Ficheros sueltos que WordPress carga **siempre**, antes que cualquier plugin, y que no se pueden apagar desde el panel. | 14 – 15 |

Todo lo demás (WooCommerce, GeneratePress, Rank Math, LiteSpeed, Blue Express, Mercado Pago, Flow)
es de terceros: se toca **sólo por hooks**, nunca editando su código.

---

## La ruta corta (si sólo vas a leer tres cosas)

1. **[19 · Cómo se hablan entre sí](19-como-se-hablan-los-plugins.md)** — empieza aquí. Es el mapa de
   las costuras: por dónde se pasan los datos las seis piezas. Todas las averías caras del proyecto
   han ocurrido en una costura, no dentro de una pieza.
2. **[17 · Catálogo de datos](17-catalogo-de-datos.md)** — dónde vive cada dato y quién lo escribe.
3. **[16 · Todo lo programado](16-todo-lo-programado.md)** — los 33 trabajos que corren solos, con
   sus horas de Chile. Si algo pasa de madrugada sin que nadie lo toque, está aquí.

---

## Los 18 capítulos

### El plugin grande: `akibara-business`

| # | Capítulo | De qué trata |
| --- | --- | --- |
| 02 | [El núcleo](02-business-nucleo.md) | Cómo arranca el plugin y cómo se enciende cada uno de sus módulos. |
| 03 | [Catálogo y series](03-business-catalogo-y-series.md) | Series, tomos, editoriales y packs: cómo se agrupan los 13.000 productos. |
| 04 | [Disponibilidad, preventa y avisos](04-business-disponibilidad-preventa-y-avisos.md) | Disponible, «a pedido» y preventa; la entrega estimada; «avísame cuando llegue». |
| 05 | [Carrito, checkout y cuenta](05-business-carrito-checkout-y-cuenta.md) | El cajón del carrito, el retiro, la cuenta del cliente y la seguridad. |
| 06 | [El panel del dueño](06-business-panel-descuentos-textos-y-portada.md) | Campañas de descuento, textos, portada, ficha y formularios: todo lo operable sin código. |
| 07 | [SEO, IA, blog y correo](07-business-seo-ia-blog-y-correo.md) | Cómo se hace encontrar la tienda en Google y en los buscadores con IA, y todo el correo que sale. |

### El buscador: `akibara-search`

| # | Capítulo | De qué trata |
| --- | --- | --- |
| 08 | [El índice](08-search-indice-y-rebuild.md) | Cómo se construye y se reconstruye el índice, y por qué un rebuild mal disparado deja el buscador caído. |
| 09 | [La consulta](09-search-consulta-facetas-y-sugerencias.md) | Qué pasa cuando el cliente escribe: motor, facetas, sugerencias y el panel que lo gobierna. |

### Puntos y reseñas: `akibara-rewards`

| # | Capítulo | De qué trata |
| --- | --- | --- |
| 10 | [Akibara Puntos](10-rewards-akibara-puntos.md) | Cómo se ganan, se canjean y caducan los puntos. |
| 11 | [Reseñas con foto](11-rewards-resenas.md) | Invitación, moderación, premio en puntos y el carrusel. |

### Checkout y tema

| # | Capítulo | De qué trata |
| --- | --- | --- |
| 12 | [Campos chilenos y PUDO](12-checkout-blocks-campos-chilenos-y-pudo.md) | RUT, celular, comuna y los puntos de retiro de Blue Express dentro del checkout por bloques. |
| 13 | [El tema `akibara-gp`](13-tema-akibara-gp.md) | Todo lo que el cliente ve, pantalla por pantalla. |

### Los mu-plugins

| # | Capítulo | De qué trata |
| --- | --- | --- |
| 14 | [Abilities, Blue Express, Brevo, Clarity](14-mu-plugins-abilities-bluex-brevo-clarity.md) | El veto de abilities y la superficie MCP, la integración de envíos, el correo de marketing y la analítica. |
| 15 | [Correo, LiteSpeed, claves y guardas](15-mu-plugins-correo-litespeed-claves-y-guardas.md) | El transporte SMTP y su centinela, la caché, las claves de proveedores y los guardarraíles. |

### Los transversales (cortan todas las piezas)

| # | Capítulo | De qué trata |
| --- | --- | --- |
| 16 | [Todo lo programado](16-todo-lo-programado.md) | Los 33 trabajos automáticos: WP-Cron, Action Scheduler y diferidos, con horas de Chile. |
| 17 | [Catálogo de datos](17-catalogo-de-datos.md) | Options, tablas, metas y transients: qué guarda cada una y quién la opera. |
| 18 | [Fronteras con terceros](18-fronteras-con-terceros.md) | Dónde termina el código propio y empieza WooCommerce, Blue Express, Brevo, Mercado Pago, Flow, Rank Math, LiteSpeed, Cloudflare, Clarity y Google. |
| 19 | [Cómo se hablan entre sí](19-como-se-hablan-los-plugins.md) | Las cinco formas en que las piezas se pasan datos, y qué se rompe si falta una. |

---

## Los diagramas

En [`diagramas/`](diagramas) hay **131**, y cada uno está **dos veces con el mismo nombre**:

- el **`.svg`** es la imagen ya dibujada — doble clic y se abre en cualquier navegador;
- el **`.mmd`** es su fuente en texto (formato *Mermaid*), que es lo que se edita.

Si se cambia un `.mmd`, hay que volver a generar su `.svg`:

```bash
mmdc -i diagramas/<fichero>.mmd -o diagramas/<fichero>.svg -b transparent
```

Los nombres dicen de qué son: `-piezas` es el mapa de componentes, `-caso-` es un recorrido concreto
de punta a punta, `-estados-` es una máquina de estados y `-cron` es lo que corre solo.

---

## Qué fiabilidad tiene esto

- **Se leyó un snapshot congelado de lo que está publicado en producción**, no el árbol de trabajo: el
  árbol mezcla cambios a medias. Las versiones leídas son `akibara-business` 1.116.5,
  `akibara-search` 0.1.60, `akibara-cart-checkout-blocks` 2.2.17, `akibara-rewards` 0.3.14, el tema
  `akibara-gp` 2.1.119 y los 18 mu-plugins de producción.
- **Cada afirmación técnica lleva su `fichero:línea`.** Si el código cambia, la cita queda vieja: la
  verdad es el código, no este documento.
- Los recuadros **«⚠ Hoy no cuadra»** dentro de los capítulos no son opiniones: son contradicciones
  medidas entre lo que el código promete y lo que hace.
- Mientras se escribía esto se hizo QA en paralelo: **478 sospechas** revisadas con tres lentes
  (código, reproducción en el espejo y política), de las que 245 se refutaron y 150 se confirmaron.
  El registro vivo de ese trabajo está en el tablero de coordinación, en
  `ops/coordinacion/abiertos/2026-09-11-diagrama-arquitectura-y-qa-integral.md`.

Este índice se escribió el 2026-09-13.
