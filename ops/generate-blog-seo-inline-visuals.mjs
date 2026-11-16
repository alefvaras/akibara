#!/usr/bin/env node

/**
 * Build deterministic, code-native editorial diagrams for Akibara blog posts.
 *
 * Source of truth: this file. The SVG sources remain editable and are rendered
 * to lightweight WebP assets for WordPress. No network access or generative
 * model is involved.
 */

import { createHash } from 'node:crypto';
import { execFileSync } from 'node:child_process';
import { mkdirSync, readFileSync, rmSync, statSync, writeFileSync } from 'node:fs';
import { dirname, join, relative, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = dirname(fileURLToPath(import.meta.url));
const ROOT = resolve(HERE, '..');
const OUTPUT = join(ROOT, 'output', 'blog-seo-inline-visuals-20260726');
const SVG_DIR = join(OUTPUT, 'svg');
const WIDTH = 1200;
const HEIGHT = 675;
const MAX_WEBP_BYTES = 150 * 1024;
const FONT_FILE = process.env.AKB_INLINE_VISUAL_FONT
  || execFileSync('fc-match', ['-f', '%{file}', 'Arial'], { encoding: 'utf8' }).trim();

const COLORS = {
  background: '#0b0b0c',
  card: '#171719',
  cardAlt: '#202023',
  border: '#35353a',
  red: '#e50914',
  white: '#f4f4f5',
  gray: '#b7b7bd',
  muted: '#8d8d95',
};

const assets = [
  {
    post_id: 22353,
    slug: 'sistema-magia',
    role: 'sistema_de_magia',
    layout: 'path',
    eyebrow: 'ATELIER OF WITCH HAT',
    title: 'La magia tiene reglas',
    subtitle: 'Un sistema basado en dibujo, intención y consecuencias.',
    items: [
      ['1', 'Aprender', 'La magia no es un don innato: puede estudiarse.'],
      ['2', 'Dibujar', 'Los hechizos nacen de sellos y glifos.'],
      ['3', 'Combinar', 'La forma del símbolo cambia el resultado.'],
      ['4', 'Responder', 'Cada decisión mágica tiene consecuencias.'],
    ],
    alt: 'Diagrama del sistema de magia de Atelier of Witch Hat: aprender, dibujar glifos, combinar símbolos y asumir consecuencias.',
    caption: 'La magia de Atelier of Witch Hat se construye con reglas visibles, creatividad y consecuencias narrativas.',
  },
  {
    post_id: 22353,
    slug: 'cronologia-manga-anime',
    role: 'cronologia_manga_anime',
    layout: 'timeline',
    eyebrow: 'ATELIER OF WITCH HAT',
    title: 'Manga y anime: cronología',
    subtitle: 'Estado editorial verificado al 26 de julio de 2026.',
    items: [
      ['2016', 'Comienza el manga', 'Publicación de Kamome Shirahama.'],
      ['Abr. 2026', 'Estreno del anime', 'Adaptación animada de la obra.'],
      ['Jul. 2026', '16 tomos en Japón', 'La historia continúa abierta.'],
      ['Antes de comprar', 'Revisar la edición', 'Stock y tomos cambian por mercado.'],
    ],
    alt: 'Cronología de Atelier of Witch Hat desde el inicio del manga en 2016 hasta el anime y los 16 tomos japoneses verificados en julio de 2026.',
    caption: 'El manga comenzó en 2016; el anime se estrenó en abril de 2026 y la serie seguía abierta con 16 tomos japoneses.',
  },
  {
    post_id: 22639,
    slug: 'mapa-anime-pelicula-lectura',
    role: 'mapa_anime_pelicula_a_capitulo_tomo',
    layout: 'path',
    eyebrow: 'CHAINSAW MAN',
    title: 'Dónde continuar la lectura',
    subtitle: 'El punto cambia según la adaptación que terminaste.',
    items: [
      ['Anime', 'Temporada 1', 'Adapta los capítulos 1–38.'],
      ['Sigue', 'Capítulo 39', 'Está incluido en el tomo 5.'],
      ['Película', 'Arco de Reze', 'Llega hasta el capítulo 52.'],
      ['Sigue', 'Capítulo 53', 'Está incluido en el tomo 7.'],
    ],
    alt: 'Mapa para continuar Chainsaw Man: tras el anime, capítulo 39 del tomo 5; tras la película de Reze, capítulo 53 del tomo 7.',
    caption: 'Tras la temporada 1 se continúa en el capítulo 39 del tomo 5; tras la película de Reze, en el capítulo 53 del tomo 7.',
  },
  {
    post_id: 22639,
    slug: 'cronologia-lectura',
    role: 'cronologia_anime_pelicula_manga',
    layout: 'timeline',
    eyebrow: 'CHAINSAW MAN',
    title: 'Del anime al manga',
    subtitle: 'Una ruta breve para evitar repetir demasiado contenido.',
    items: [
      ['2022', 'Anime: temporada 1', 'Capítulos 1–38.'],
      ['Punto A', 'Tomo 5', 'Retoma desde el capítulo 39.'],
      ['Película', 'Arco de Reze', 'Adaptación hasta el capítulo 52.'],
      ['Punto B', 'Tomo 7', 'Retoma desde el capítulo 53.'],
    ],
    alt: 'Cronología para pasar del anime y la película de Chainsaw Man al manga en los capítulos 39 y 53.',
    caption: 'Identificar la última adaptación vista evita comprar un tomo que repita demasiado material.',
  },
  {
    post_id: 22642,
    slug: 'matriz-seinen-tono',
    role: 'matriz_seinen_por_tono',
    layout: 'matrix',
    eyebrow: 'MANGA SEINEN',
    title: 'Elige por tono, no por etiqueta',
    subtitle: 'Seinen admite géneros, ritmos e intensidades muy distintos.',
    items: [
      ['Histórico + aventura', 'Golden Kamuy', 'Reparto coral, cocina, humor y violencia.'],
      ['Histórico + reflexión', 'Vinland Saga', 'Guerra, propósito y cambios de ritmo.'],
      ['Thriller + tensión', 'Monster', 'Investigación, memoria y decisiones éticas.'],
      ['Fantasía + oscuridad', 'Berserk', 'Violencia gráfica, trauma y temas adultos.'],
    ],
    alt: 'Matriz de manga seinen por tono con Golden Kamuy, Vinland Saga, Monster y Berserk.',
    caption: 'La demografía seinen reúne tonos muy diferentes; conviene elegir por afinidad y revisar advertencias de cada obra.',
  },
  {
    post_id: 22642,
    slug: 'demografia-seinen',
    role: 'demografia_seinen',
    layout: 'cards',
    eyebrow: 'MANGA SEINEN',
    title: 'Demografía no es género',
    subtitle: 'Cuatro claves para recomendar con precisión.',
    items: [
      ['Origen editorial', 'Describe el público objetivo de la revista japonesa.'],
      ['No define el género', 'Puede ser acción, romance, historia o vida cotidiana.'],
      ['No fija una edad legal', 'La orientación se revisa obra por obra.'],
      ['Contenido primero', 'Comprueba violencia, sexualidad y temas sensibles.'],
    ],
    alt: 'Infografía que explica que seinen es una demografía editorial, no un género ni una clasificación legal de edad.',
    caption: '“Seinen” describe una demografía editorial; el tono y la edad orientativa deben comprobarse en cada obra.',
  },
  {
    post_id: 23332,
    slug: 'mapa-historico-cultural',
    role: 'mapa_historico_cultural',
    layout: 'path',
    eyebrow: 'GOLDEN KAMUY',
    title: 'Historia, cultura y ficción',
    subtitle: 'Cómo leer el contexto sin confundir manga con fuente académica.',
    items: [
      ['Lugar', 'Hokkaido', 'Tras la Guerra Ruso-Japonesa.'],
      ['Motor', 'Búsqueda de oro', 'Un mapa une a varios bandos.'],
      ['Perspectiva', 'Conocimiento ainu', 'Asirpa guía decisiones y territorio.'],
      ['Límite', 'Es una ficción', 'Contrasta después con voces especializadas.'],
    ],
    alt: 'Mapa histórico-cultural de Golden Kamuy: Hokkaido, búsqueda de oro, conocimiento ainu y distinción entre ficción y fuente académica.',
    caption: 'Golden Kamuy usa contexto histórico y cultural dentro de una ficción; no reemplaza fuentes especializadas sobre el pueblo ainu.',
  },
  {
    post_id: 23332,
    slug: 'guia-arcos',
    role: 'guia_de_arcos_sin_spoilers',
    layout: 'timeline',
    eyebrow: 'GOLDEN KAMUY',
    title: 'Ruta de lectura sin spoilers',
    subtitle: 'Una aventura completa en 31 tomos.',
    items: [
      ['Inicio', 'Alianza', 'Sugimoto y Asirpa unen objetivos.'],
      ['Búsqueda', 'Mapa fragmentado', 'La pista del oro mueve el viaje.'],
      ['Escalada', 'Bandos en conflicto', 'Militares, fugitivos y veteranos.'],
      ['Cierre', 'Historia completa', 'Los 31 tomos cierran la aventura.'],
    ],
    alt: 'Guía sin spoilers de los arcos de Golden Kamuy: alianza, búsqueda del mapa, conflicto entre bandos y cierre en 31 tomos.',
    caption: 'La historia avanza desde una alianza inicial hacia una búsqueda coral y concluye en 31 tomos.',
  },
  {
    post_id: 23333,
    slug: 'deluxe-vs-estandar',
    role: 'comparacion_deluxe_estandar',
    layout: 'table',
    eyebrow: 'SLAM DUNK',
    title: 'Deluxe y estándar no se numeran igual',
    subtitle: 'Compara la edición antes de completar tu colección.',
    headers: ['Criterio', 'Edición Deluxe', 'Edición estándar'],
    rows: [
      ['Colección completa', '20 tomos', '31 tomos'],
      ['Orden correcto', 'Del 1 al 20', 'Del 1 al 31'],
      ['Antes de mezclar', 'Comparar ISBN', 'Comparar ISBN'],
      ['Regla segura', 'Mantener edición', 'Mantener edición'],
    ],
    alt: 'Tabla comparativa de Slam Dunk: edición Deluxe completa en 20 tomos y edición estándar en 31 tomos.',
    caption: 'Slam Dunk Deluxe se completa en 20 tomos y la edición tradicional en 31; no conviene mezclar numeraciones.',
  },
  {
    post_id: 23333,
    slug: 'cronologia-lectura',
    role: 'cronologia_manga_pelicula_edicion',
    layout: 'timeline',
    eyebrow: 'SLAM DUNK',
    title: 'Orden recomendado',
    subtitle: 'Manga primero; película como complemento.',
    items: [
      ['Paso 1', 'Comienza en tomo 1', 'Conoce al equipo Shohoku desde el inicio.'],
      ['Paso 2', 'Sigue una edición', 'Deluxe o estándar, sin mezclar.'],
      ['Paso 3', 'Completa historia', '20 Deluxe o 31 estándar.'],
      ['Después', 'Mira la película', 'Otra mirada; no reemplaza el manga.'],
    ],
    alt: 'Cronología recomendada de Slam Dunk: comenzar en el tomo 1, mantener una edición, completar el manga y ver la película como complemento.',
    caption: 'La película aporta una perspectiva distinta, pero el manga construye el recorrido completo de Shohoku.',
  },
  {
    post_id: 23334,
    slug: 'flujo-preventa',
    role: 'flujo_de_preventa',
    layout: 'path',
    eyebrow: 'PREVENTAS DE MANGA',
    title: 'Cómo funciona una preventa',
    subtitle: 'Comprar antes no significa despacho inmediato.',
    items: [
      ['1', 'Revisar la ficha', 'Editorial, tomo, ISBN y fecha estimada.'],
      ['2', 'Reservar', 'El producto entra en tu pedido.'],
      ['3', 'Esperar el ingreso', 'La fecha logística puede cambiar.'],
      ['4', 'Despacho', 'Sale cuando el pedido está completo.'],
    ],
    alt: 'Flujo de una preventa de manga: revisar la ficha, reservar, esperar el ingreso y despachar el pedido completo.',
    caption: 'Una preventa reserva una edición antes de su ingreso; la fecha indicada es estimada, no una promesa exacta.',
  },
  {
    post_id: 23334,
    slug: 'linea-tiempo-preventa',
    role: 'linea_temporal_de_preventa',
    layout: 'timeline',
    eyebrow: 'PREVENTAS DE MANGA',
    title: 'Del pedido al despacho',
    subtitle: 'Los pedidos mixtos esperan a que todos los productos estén disponibles.',
    items: [
      ['Compra', 'Pedido confirmado', 'La preventa queda asociada a la orden.'],
      ['Espera', 'Fecha estimada', 'Editorial y logística pueden ajustarla.'],
      ['Ingreso', 'Producto en bodega', 'Se verifica el pedido completo.'],
      ['Salida', 'Despacho', 'Sale al ingresar todos los artículos.'],
    ],
    alt: 'Línea temporal de una preventa de manga desde la compra hasta el despacho, incluyendo la espera por pedidos mixtos.',
    caption: 'Si mezclas stock y preventa, el despacho comienza cuando todos los artículos de la orden están disponibles.',
  },
  {
    post_id: 23336,
    slug: 'matriz-regalo',
    role: 'matriz_regalo_presupuesto_edad',
    layout: 'matrix',
    eyebrow: 'MANGA PARA REGALAR',
    title: 'Presupuesto y edad: decide con contexto',
    subtitle: 'La edad orienta; las advertencias de cada obra confirman.',
    items: [
      ['Acotado + lector nuevo', 'Un tomo 1 o una obra corta y accesible.'],
      ['Amplio + lector nuevo', 'Dos o tres tomos consecutivos de la misma edición.'],
      ['Acotado + coleccionista', 'El volumen exacto que sigue en su colección.'],
      ['Amplio + coleccionista', 'Deluxe u omnibus, tras comprobar compatibilidad.'],
    ],
    alt: 'Matriz para regalar manga según presupuesto, experiencia del lector y revisión de edad orientativa.',
    caption: 'El presupuesto ayuda a elegir el formato; la edad y los temas sensibles deben revisarse en la edición concreta.',
  },
  {
    post_id: 23336,
    slug: 'selector-regalo',
    role: 'selector_de_regalo',
    layout: 'path',
    eyebrow: 'MANGA PARA REGALAR',
    title: 'Selector rápido de regalo',
    subtitle: 'Cuatro preguntas evitan errores de edición y tono.',
    items: [
      ['1', '¿Qué le gusta?', 'Género, tono y series favoritas.'],
      ['2', '¿Ya colecciona?', 'Busca el siguiente tomo exacto.'],
      ['3', '¿Es adecuado?', 'Revisa edad y temas sensibles.'],
      ['4', '¿Es compatible?', 'Confirma editorial, formato e ISBN.'],
    ],
    alt: 'Selector de manga para regalar con preguntas sobre gustos, colección, edad orientativa y compatibilidad de edición.',
    caption: 'Un buen regalo coincide con los gustos de la persona y con la edición que ya colecciona.',
  },
  {
    post_id: 24975,
    slug: 'tabla-panini-ivrea',
    role: 'tabla_panini_ivrea',
    layout: 'table',
    eyebrow: 'PANINI VS IVREA',
    title: 'No hay un ganador universal',
    subtitle: 'La comparación correcta se hace título por título y mercado por mercado.',
    headers: ['Criterio', 'Qué comparar', 'Por qué importa'],
    rows: [
      ['País', 'España, Argentina u otro', 'Define el catálogo disponible'],
      ['Traducción', 'Variante y terminología', 'Afecta continuidad'],
      ['Formato', 'Tamaño, páginas y cubierta', 'Afecta la colección'],
      ['Edición', 'ISBN y capítulos', 'Evita saltos o duplicados'],
    ],
    alt: 'Tabla para comparar ediciones de Panini e Ivrea por país, traducción, formato, ISBN y capítulos.',
    caption: 'Panini e Ivrea publican ediciones distintas según país y obra; el ISBN y los capítulos permiten comparar con precisión.',
  },
  {
    post_id: 24975,
    slug: 'formatos-edicion',
    role: 'guia_de_formatos',
    layout: 'cards',
    eyebrow: 'PANINI VS IVREA',
    title: 'Formatos que no debes confundir',
    subtitle: 'El mismo número de tomo puede reunir contenidos diferentes.',
    items: [
      ['Tankobon / B6', 'Ediciones individuales con medidas propias.'],
      ['2 en 1 / 3 en 1', 'Reúnen varios volúmenes originales.'],
      ['Maximum / Deluxe', 'Formato mayor y numeración específica.'],
      ['Antes de mezclar', 'Compara ISBN, índice, páginas y dimensiones.'],
    ],
    alt: 'Guía visual de formatos de manga: tankobon, B6, 2 en 1, 3 en 1, Maximum y Deluxe.',
    caption: 'La numeración de un omnibus, Maximum o Deluxe no equivale necesariamente a la edición individual.',
  },
  {
    post_id: 24978,
    slug: 'matriz-romance',
    role: 'matriz_romance_por_tono',
    layout: 'matrix',
    eyebrow: 'MANGAS DE ROMANCE',
    title: 'Elige la historia por su tono',
    subtitle: 'Romance no siempre significa una lectura ligera.',
    items: [
      ['Rápido y verbal', 'Kaguya-sama', 'Comedia de estrategia romántica.'],
      ['Melancólico', 'Ao Haru Ride', 'Reencuentro y crecimiento escolar.'],
      ['Intenso y sensible', 'Nana', 'Drama adulto; revisa advertencias.'],
      ['Ligero y cotidiano', 'Wotakoi', 'Relación laboral y aficiones.'],
    ],
    alt: 'Matriz de mangas de romance por tono: Kaguya-sama, Ao Haru Ride, Nana y Wotakoi.',
    caption: 'El tono permite distinguir comedia estratégica, melancolía escolar, drama adulto y romance cotidiano.',
  },
  {
    post_id: 24978,
    slug: 'selector-romance',
    role: 'selector_de_romance',
    layout: 'cards',
    eyebrow: 'MANGAS DE ROMANCE',
    title: '¿Qué romance buscas?',
    subtitle: 'Una quinta opción completa el mapa de tonos.',
    items: [
      ['Comedia estratégica', 'Kaguya-sama · rápido y verbal.'],
      ['Reencuentro escolar', 'Ao Haru Ride · melancólico.'],
      ['Drama adulto', 'Nana · intenso y sensible.'],
      ['Crecimiento gradual', 'Kimi ni Todoke · tierno y pausado.'],
    ],
    alt: 'Selector de manga de romance según gusto: comedia, reencuentro escolar, drama adulto o crecimiento gradual.',
    caption: 'Elige por el tipo de relación y la intensidad emocional, y luego confirma la edición y sus advertencias.',
  },
  {
    post_id: 22638,
    slug: 'mapa-anime-tomo',
    role: 'mapa_anime_a_capitulo_tomo',
    layout: 'table',
    eyebrow: 'JUJUTSU KAISEN',
    title: '¿En qué tomo continuar?',
    subtitle: 'Puntos de lectura verificados según la adaptación terminada.',
    headers: ['Terminaste', 'Continúa en', 'Referencia'],
    rows: [
      ['Temporada 1', 'Tomo 9', 'Tomos 1–8 adaptados'],
      ['Temporada 2', 'Tomo 17', 'Juego de la Colonia'],
      ['T3, parte 1', 'Cap. 181 · tomo 21', 'Tras Sendai'],
      ['Película 0', 'Tomo 0 y luego 1', 'Precuela + serie'],
    ],
    alt: 'Tabla para continuar Jujutsu Kaisen: tomo 9 tras temporada 1, tomo 17 tras temporada 2 y capítulo 181 del tomo 21 tras temporada 3 parte 1.',
    caption: 'El punto de continuidad depende de la temporada; empezar un tramo antes ayuda a recuperar transiciones.',
  },
  {
    post_id: 24977,
    slug: 'conviene-preventa',
    role: 'conviene_no_conviene_preventa',
    layout: 'compare',
    eyebrow: 'PREVENTA DE MANGA',
    title: 'Cuándo conviene y cuándo no',
    subtitle: 'La clave es comparar la edición que quieres con el plazo que necesitas.',
    left: {
      title: 'Sí conviene',
      items: ['Buscas una edición específica.', 'Puedes esperar una fecha estimada.', 'Confirmaste tomo, editorial e ISBN.'],
    },
    right: {
      title: 'No conviene',
      items: ['Es un regalo con fecha cercana.', 'Necesitas despacho inmediato.', 'Aún no sabes qué edición coleccionas.'],
    },
    alt: 'Comparación de cuándo conviene una preventa de manga y cuándo elegir un producto disponible.',
    caption: 'La preventa sirve para reservar una edición específica si puedes esperar; no es adecuada cuando necesitas despacho inmediato.',
  },
  {
    post_id: 23323,
    slug: 'anime-a-42-tomos',
    role: 'anime_a_42_tomos',
    layout: 'path',
    eyebrow: 'MY HERO ACADEMIA',
    title: 'Del anime a la colección completa',
    subtitle: 'El manga terminó en 42 tomos y 430 capítulos.',
    items: [
      ['Anime', 'Historia principal', 'La temporada final concluyó en 2025.'],
      ['Ruta íntegra', 'Comienza en el tomo 1', 'Dibujo y transiciones originales.'],
      ['Colección', '42 tomos', 'La serie está completa.'],
      ['Final', 'Volumen 42', 'Incluye material ampliado del epílogo.'],
    ],
    alt: 'Ruta de My Hero Academia desde el anime hasta la colección completa de 42 tomos y el epílogo del volumen final.',
    caption: 'Aunque el anime concluyó la historia, los 42 tomos conservan el dibujo, las transiciones y material ampliado del final.',
  },
  {
    post_id: 23324,
    slug: 'cronologia-arcos',
    role: 'cronologia_de_arcos',
    layout: 'timeline',
    eyebrow: 'BLUE LOCK',
    title: 'Arcos y tomos de Blue Lock',
    subtitle: 'La competencia escala por fases; los rangos son aproximados.',
    items: [
      ['Tomos 1–5', 'Primera Selección', 'Ingreso al programa y eliminaciones.'],
      ['Tomos 5–12', 'Segunda y Tercera', 'Roles, duelos y top players.'],
      ['Tomos 12–17', 'Arco U-20', 'Partido que redefine el proyecto.'],
      ['Tomos 17+', 'Neo Egoist League', 'Competencia con figuras mundiales.'],
    ],
    alt: 'Cronología de arcos de Blue Lock desde la Primera Selección hasta la Neo Egoist League.',
    caption: 'Blue Lock avanza desde selecciones internas hacia el arco U-20 y la Neo Egoist League.',
  },
  {
    post_id: 22610,
    slug: 'ediciones-berserk',
    role: 'comparacion_de_ediciones',
    layout: 'compare',
    eyebrow: 'BERSERK MANGA',
    title: 'Qué aporta la edición Maximum',
    subtitle: 'Una colección compacta que exige mantener su propia numeración.',
    left: {
      title: 'Maximum',
      items: ['Reúne dos tomos japoneses.', 'Formato grande.', 'Tapa blanda con sobrecubierta.'],
    },
    right: {
      title: 'Antes de comprar',
      items: ['Confirma editorial y país.', 'Revisa ISBN y volumen.', 'No mezcles numeraciones.'],
    },
    alt: 'Comparación de la edición Maximum de Berserk y las comprobaciones necesarias antes de comprar.',
    caption: 'Maximum Berserk reúne dos tomos japoneses por volumen; conviene continuar siempre dentro de la misma edición.',
  },
  {
    post_id: 22610,
    slug: 'mapa-lectura-berserk',
    role: 'mapa_de_lectura',
    layout: 'timeline',
    eyebrow: 'BERSERK MANGA',
    title: 'Mapa de arcos principales',
    subtitle: 'Lee desde el tomo 1 y sigue el orden de publicación.',
    items: [
      ['Tomos 1–14', 'Guerrero + Dorada', 'Presentación y origen del conflicto.'],
      ['Tomos 14–21', 'Convicción', 'Consecuencias y persecución.'],
      ['Tomos 22–35', 'Halcón Milenario', 'El viaje y el grupo se amplían.'],
      ['Tomos 35+', 'Elfhelm y presente', 'La historia sigue abierta.'],
    ],
    alt: 'Mapa de lectura de Berserk por arcos y rangos de tomos, desde Guerrero Negro hasta la continuidad actual.',
    caption: 'Berserk debe leerse en orden; los nombres de arco ayudan a ubicar etapas, no a saltarse volúmenes.',
  },
  {
    post_id: 23326,
    slug: 'continuidad-anime-manga',
    role: 'continuidad_anime_manga',
    layout: 'path',
    eyebrow: 'HUNTER X HUNTER',
    title: 'Dónde sigue después del anime',
    subtitle: 'El contenido posterior a la Elección es exclusivo del manga.',
    items: [
      ['Anime 2011', '148 episodios', 'Llega al arco de la Elección.'],
      ['Continúa', 'Capítulo 340', 'Comienza en el tomo 33.'],
      ['Solo manga', 'Continente Oscuro', 'Viaje y nuevas amenazas.'],
      ['Solo manga', 'Guerra de Sucesión', 'Intriga política en desarrollo.'],
    ],
    alt: 'Continuidad de Hunter x Hunter desde el anime de 2011 al capítulo 340 del tomo 33 y los arcos exclusivos del manga.',
    caption: 'Después del anime de 2011, la lectura continúa en el capítulo 340, al comienzo del tomo 33.',
  },
  {
    post_id: 23326,
    slug: 'razones-leer-manga',
    role: 'razones_para_leer_el_manga',
    layout: 'cards',
    eyebrow: 'HUNTER X HUNTER',
    title: 'Por qué continuar en el manga',
    subtitle: 'No es solo “más historia”: cambia la escala del relato.',
    items: [
      ['Contenido exclusivo', 'Continente Oscuro y Sucesión no están en el anime.'],
      ['Estrategia', 'El Nen se explica con reglas y decisiones precisas.'],
      ['Voz autoral', 'El dibujo y la puesta en página son de Togashi.'],
      ['Estado abierto', 'Revisa fuentes oficiales antes de asumir fechas.'],
    ],
    alt: 'Cuatro razones para leer Hunter x Hunter manga: contenido exclusivo, estrategia, voz de Togashi y estado editorial abierto.',
    caption: 'El manga continúa la historia y conserva la explicación estratégica y la puesta en página de Yoshihiro Togashi.',
  },
  {
    post_id: 23335,
    slug: 'serie-precuela-secuela',
    role: 'serie_precuela_secuela',
    layout: 'path',
    eyebrow: 'ORDEN PARA LEER MANGA',
    title: 'Serie, precuela, secuela y spin-off',
    subtitle: 'No existe una regla universal para todas las franquicias.',
    items: [
      ['Base', 'Serie principal', 'El punto de entrada más claro suele ser el tomo 1.'],
      ['Antes', 'Precuela', 'Comprueba si la editorial indica un orden.'],
      ['Después', 'Secuela', 'Verifica qué historia necesita como antecedente.'],
      ['Aparte', 'Spin-off', 'Revisa si funciona de forma independiente.'],
    ],
    alt: 'Mapa para ordenar una serie principal, precuela, secuela y spin-off de manga.',
    caption: 'El orden de publicación es una base segura, pero la guía oficial de cada franquicia puede establecer otra ruta.',
  },
  {
    post_id: 23335,
    slug: 'formatos-lectura',
    role: 'formatos_y_orden_de_lectura',
    layout: 'table',
    eyebrow: 'ORDEN PARA LEER MANGA',
    title: 'Cada formato sigue su numeración',
    subtitle: 'No mezcles colecciones por el número impreso en la portada.',
    headers: ['Formato', 'Cómo seguirlo', 'Qué comprobar'],
    rows: [
      ['Tankobon', '1, 2, 3…', 'Editorial e ISBN'],
      ['Omnibus', 'Dentro de esa colección', 'Tomos incluidos'],
      ['Deluxe', 'Dentro de esa edición', 'Capítulos y medidas'],
      ['Tomo único', 'Historia completa', 'Edición y contenido'],
    ],
    alt: 'Tabla del orden de lectura para formatos tankobon, omnibus, Deluxe y tomo único.',
    caption: 'Tankobon, omnibus y Deluxe pueden distribuir los capítulos de forma distinta; mantén una misma edición.',
  },
  {
    post_id: 24976,
    slug: 'matriz-seinen-recomendados',
    role: 'matriz_tono_extension_advertencias',
    layout: 'matrix',
    eyebrow: 'MANGAS SEINEN RECOMENDADOS',
    title: 'Tono y advertencias primero',
    subtitle: 'La extensión y el contenido se revisan en la edición concreta.',
    items: [
      ['Thriller', 'Monster / 20th Century Boys', 'Tensión, investigación y líneas temporales.'],
      ['Historia', 'Vinland Saga / Vagabond', 'Violencia, reflexión y ritmo cambiante.'],
      ['Aventura coral', 'Golden Kamuy', 'Violencia, humor y memoria de guerra.'],
      ['Psicología u horror', 'Punpun / Homunculus', 'Temas intensos; revisar advertencias.'],
    ],
    alt: 'Matriz de mangas seinen recomendados por tono, extensión y necesidad de advertencias de contenido.',
    caption: 'Elige seinen por tono y ritmo; antes de comprar, confirma extensión, estado y advertencias de la edición.',
  },
  {
    post_id: 24976,
    slug: 'selector-seinen',
    role: 'selector_de_seinen',
    layout: 'cards',
    eyebrow: 'MANGAS SEINEN RECOMENDADOS',
    title: 'Selector por afinidad',
    subtitle: 'Una recomendación útil parte de lo que quieres sentir y explorar.',
    items: [
      ['Thriller', 'Monster · investigación y tensión moral.'],
      ['Historia y filosofía', 'Vinland Saga o Vagabond · ritmo cambiante.'],
      ['Fantasía oscura', 'Berserk · contenido muy explícito.'],
      ['Aventura coral', 'Golden Kamuy · humor, historia y violencia.'],
    ],
    alt: 'Selector de manga seinen según afinidad: thriller, historia, fantasía oscura o aventura coral.',
    caption: 'La afinidad de tono es más útil que tratar “seinen” como un género único.',
  },
  {
    post_id: 25045,
    slug: 'tabla-japon-espanol',
    role: 'tabla_japon_espanol_fecha_corte',
    layout: 'table',
    eyebrow: 'TOMOS DE HIKARU',
    title: 'Japón y español: fecha de corte',
    subtitle: 'Estado verificado al 26 de julio de 2026.',
    headers: ['Edición', 'Tomos verificados', 'Estado'],
    rows: [
      ['Japón · KADOKAWA', '9 tomos', 'En publicación'],
      ['Español · Milky Way', '8 tomos', 'En publicación'],
      ['Akibara', 'Consultar la serie', 'Stock variable'],
      ['Regla', 'No mezclar ediciones', 'Comparar ISBN'],
    ],
    alt: 'Tabla de tomos de El verano en que Hikaru murió: 9 en Japón y 8 en español al 26 de julio de 2026.',
    caption: 'Al 26 de julio de 2026 había 9 tomos en Japón y 8 en la edición española de Milky Way.',
  },
  {
    post_id: 25045,
    slug: 'cronologia-hikaru',
    role: 'cronologia_editorial',
    layout: 'timeline',
    eyebrow: 'TOMOS DE HIKARU',
    title: 'Cronología editorial',
    subtitle: 'Los mercados avanzan con calendarios distintos.',
    items: [
      ['Inicio', 'Lee desde el tomo 1', 'La historia sigue una secuencia.'],
      ['4 jun. 2026', 'Tomo 9 en Japón', 'Publicado por KADOKAWA.'],
      ['26 jul. 2026', '8 tomos en español', 'Catálogo de Milky Way.'],
      ['Próxima revisión', 'Serie abierta', 'Confirma la ficha antes de comprar.'],
    ],
    alt: 'Cronología editorial de los tomos de Hikaru: tomo 9 japonés en junio de 2026 y ocho tomos en español en julio de 2026.',
    caption: 'La diferencia entre Japón y español refleja calendarios editoriales distintos; la serie continúa abierta.',
  },
];

function escapeXml(value) {
  return String(value)
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&apos;');
}

function wrapWords(value, maxChars, maxLines = 3) {
  const words = String(value).trim().split(/\s+/);
  const lines = [];
  let current = '';

  for (const word of words) {
    const candidate = current ? `${current} ${word}` : word;
    if (candidate.length <= maxChars || !current) {
      current = candidate;
      continue;
    }
    lines.push(current);
    current = word;
  }
  if (current) lines.push(current);

  if (lines.length > maxLines) {
    throw new Error(`Text requires ${lines.length} lines (max ${maxLines}): ${value}`);
  }
  return lines;
}

function textBlock(value, x, y, options = {}) {
  const {
    className = 'body',
    maxChars = 42,
    maxLines = 3,
    lineHeight = 31,
    anchor = 'start',
  } = options;
  const lines = wrapWords(value, maxChars, maxLines);
  const tspans = lines
    .map((line, index) => `<tspan x="${x}" dy="${index === 0 ? 0 : lineHeight}">${escapeXml(line)}</tspan>`)
    .join('');
  return `<text class="${className}" x="${x}" y="${y}" text-anchor="${anchor}">${tspans}</text>`;
}

function renderHeader(asset) {
  const titleLines = wrapWords(asset.title, 43, 2);
  const titleSvg = titleLines
    .map((line, index) => `<tspan x="64" dy="${index === 0 ? 0 : 52}">${escapeXml(line)}</tspan>`)
    .join('');
  const subtitleY = titleLines.length === 1 ? 138 : 156;
  const subtitle = textBlock(asset.subtitle, 64, subtitleY, {
    className: 'subtitle',
    maxChars: 78,
    maxLines: titleLines.length === 1 ? 2 : 1,
    lineHeight: 29,
  });
  return `
    <text class="eyebrow" x="64" y="42">${escapeXml(asset.eyebrow)}</text>
    <text class="title" x="64" y="96">${titleSvg}</text>
    ${subtitle}
  `;
}

function renderCards(asset, showConnectors = false) {
  const xPositions = [64, 608];
  const yPositions = [198, 400];
  const parts = [];

  asset.items.forEach((item, index) => {
    const [badgeOrTitle, maybeTitle, maybeBody] = item;
    const isThreePart = item.length === 3;
    const title = isThreePart ? maybeTitle : badgeOrTitle;
    const body = isThreePart ? maybeBody : maybeTitle;
    const x = xPositions[index % 2];
    const y = yPositions[Math.floor(index / 2)];
    const badgeWidth = isThreePart ? Math.min(190, Math.max(54, badgeOrTitle.length * 10 + 28)) : 8;
    const badge = isThreePart
      ? `<rect x="${x + 22}" y="${y + 20}" width="${badgeWidth}" height="34" rx="17" fill="${COLORS.red}"/>
         <text class="badge" x="${x + 22 + (badgeWidth / 2)}" y="${y + 44}" text-anchor="middle">${escapeXml(badgeOrTitle)}</text>`
      : `<rect x="${x + 22}" y="${y + 20}" width="8" height="36" rx="4" fill="${COLORS.red}"/>`;
    const titleX = isThreePart ? x + 38 + badgeWidth : x + 48;
    const titleChars = isThreePart
      ? Math.max(12, Math.floor((x + 504 - titleX) / 15))
      : 30;
    parts.push(`
      <rect x="${x}" y="${y}" width="528" height="184" rx="12" fill="${index % 2 ? COLORS.cardAlt : COLORS.card}" stroke="${COLORS.border}"/>
      ${badge}
      ${textBlock(title, titleX, y + 47, { className: 'card-title', maxChars: titleChars, maxLines: 2, lineHeight: 31 })}
      ${textBlock(body, x + 24, y + 112, { className: 'body', maxChars: 34, maxLines: 3, lineHeight: 30 })}
    `);
  });

  if (showConnectors) {
    parts.push(`
      <path d="M592 290 H608 M600 282 L608 290 L600 298" fill="none" stroke="${COLORS.red}" stroke-width="4"/>
      <path d="M1144 382 V400 M1136 392 L1144 400 L1152 392" fill="none" stroke="${COLORS.red}" stroke-width="4"/>
      <path d="M608 492 H592 M600 484 L592 492 L600 500" fill="none" stroke="${COLORS.red}" stroke-width="4"/>
    `);
  }
  return parts.join('');
}

function renderTimeline(asset) {
  const xPositions = [76, 362, 648, 934];
  const parts = [
    `<line x1="108" y1="318" x2="1062" y2="318" stroke="${COLORS.border}" stroke-width="8" stroke-linecap="round"/>`,
  ];
  asset.items.forEach(([date, title, body], index) => {
    const x = xPositions[index];
    parts.push(`
      <circle cx="${x + 82}" cy="318" r="18" fill="${COLORS.background}" stroke="${COLORS.red}" stroke-width="8"/>
      <rect x="${x}" y="202" width="260" height="94" rx="10" fill="${COLORS.card}" stroke="${COLORS.border}"/>
      ${textBlock(date, x + 18, 232, { className: 'date', maxChars: 16, maxLines: 1 })}
      ${textBlock(title, x + 18, 269, { className: 'timeline-title', maxChars: 19, maxLines: 1 })}
      <rect x="${x}" y="345" width="260" height="174" rx="10" fill="${COLORS.cardAlt}" stroke="${COLORS.border}"/>
      ${textBlock(body, x + 18, 389, { className: 'body', maxChars: 16, maxLines: 4, lineHeight: 32 })}
    `);
  });
  return parts.join('');
}

function renderTable(asset) {
  const left = 64;
  const top = 198;
  const width = 1072;
  const colWidths = [292, 380, 400];
  const headerH = 66;
  const rowH = 78;
  const parts = [
    `<rect x="${left}" y="${top}" width="${width}" height="${headerH + asset.rows.length * rowH}" rx="12" fill="${COLORS.card}" stroke="${COLORS.border}"/>`,
  ];
  let x = left;
  asset.headers.forEach((header, index) => {
    parts.push(`<rect x="${x}" y="${top}" width="${colWidths[index]}" height="${headerH}" fill="${index === 0 ? COLORS.red : COLORS.cardAlt}"/>`);
    parts.push(textBlock(header, x + 20, top + 42, { className: 'table-header', maxChars: index === 0 ? 18 : 27, maxLines: 1 }));
    x += colWidths[index];
  });
  asset.rows.forEach((row, rowIndex) => {
    const y = top + headerH + rowIndex * rowH;
    parts.push(`<line x1="${left}" y1="${y}" x2="${left + width}" y2="${y}" stroke="${COLORS.border}" stroke-width="2"/>`);
    let cellX = left;
    row.forEach((cell, cellIndex) => {
      if (cellIndex > 0) {
        parts.push(`<line x1="${cellX}" y1="${y}" x2="${cellX}" y2="${y + rowH}" stroke="${COLORS.border}" stroke-width="2"/>`);
      }
      parts.push(textBlock(cell, cellX + 20, y + 47, {
        className: cellIndex === 0 ? 'table-key' : 'table-body',
        maxChars: cellIndex === 0 ? 20 : 29,
        maxLines: 1,
      }));
      cellX += colWidths[cellIndex];
    });
  });
  return parts.join('');
}

function renderCompare(asset) {
  const columns = [
    { x: 64, data: asset.left },
    { x: 608, data: asset.right },
  ];
  return columns.map(({ x, data }, columnIndex) => {
    const rows = data.items.map((item, index) => `
      <circle cx="${x + 40}" cy="${310 + index * 84}" r="9" fill="${COLORS.red}"/>
      ${textBlock(item, x + 68, 319 + index * 84, { className: 'compare-body', maxChars: 31, maxLines: 2, lineHeight: 31 })}
    `).join('');
    return `
      <rect x="${x}" y="198" width="528" height="338" rx="12" fill="${columnIndex ? COLORS.cardAlt : COLORS.card}" stroke="${COLORS.border}"/>
      <rect x="${x}" y="198" width="528" height="74" rx="12" fill="${columnIndex ? COLORS.card : COLORS.red}"/>
      ${textBlock(data.title, x + 28, 247, { className: 'compare-title', maxChars: 28, maxLines: 1 })}
      ${rows}
    `;
  }).join('');
}

function renderSvg(asset) {
  let content = '';
  if (asset.layout === 'cards' || asset.layout === 'matrix') content = renderCards(asset);
  if (asset.layout === 'path') content = renderCards(asset, true);
  if (asset.layout === 'timeline') content = renderTimeline(asset);
  if (asset.layout === 'table') content = renderTable(asset);
  if (asset.layout === 'compare') content = renderCompare(asset);
  if (!content) throw new Error(`Unknown layout: ${asset.layout}`);

  return `<?xml version="1.0" encoding="UTF-8"?>
<svg xmlns="http://www.w3.org/2000/svg" width="${WIDTH}" height="${HEIGHT}" viewBox="0 0 ${WIDTH} ${HEIGHT}" role="img" aria-labelledby="title description" font-family="Arial">
  <title id="title">${escapeXml(asset.title)}</title>
  <desc id="description">${escapeXml(asset.alt)}</desc>
  <rect width="${WIDTH}" height="${HEIGHT}" fill="${COLORS.background}"/>
  <rect width="${WIDTH}" height="10" fill="${COLORS.red}"/>
  <path d="M0 176 H1200 M0 607 H1200" stroke="${COLORS.border}" stroke-width="2"/>
  <g opacity="0.12" stroke="${COLORS.muted}" stroke-width="1">
    <path d="M32 0 V675 M1168 0 V675"/>
  </g>
  <style>
    text { font-family: Arial; fill: ${COLORS.white}; }
    .eyebrow { font-size: 20px; font-weight: 700; letter-spacing: 3px; fill: ${COLORS.red}; }
    .title { font-size: 46px; font-weight: 800; fill: ${COLORS.white}; }
    .subtitle { font-size: 25px; font-weight: 400; fill: ${COLORS.gray}; }
    .badge { font-size: 18px; font-weight: 800; fill: ${COLORS.white}; }
    .card-title { font-size: 29px; font-weight: 700; fill: ${COLORS.white}; }
    .body { font-size: 29px; font-weight: 400; fill: ${COLORS.gray}; }
    .date { font-size: 25px; font-weight: 800; fill: ${COLORS.red}; }
    .timeline-title { font-size: 27px; font-weight: 700; fill: ${COLORS.white}; }
    .table-header { font-size: 30px; font-weight: 800; fill: ${COLORS.white}; }
    .table-key { font-size: 28px; font-weight: 700; fill: ${COLORS.white}; }
    .table-body { font-size: 28px; font-weight: 400; fill: ${COLORS.gray}; }
    .compare-title { font-size: 31px; font-weight: 800; fill: ${COLORS.white}; }
    .compare-body { font-size: 31px; font-weight: 400; fill: ${COLORS.gray}; }
    .footer { font-size: 19px; font-weight: 700; letter-spacing: 2px; fill: ${COLORS.muted}; }
  </style>
  ${renderHeader(asset)}
  ${content}
  <rect x="64" y="629" width="34" height="6" rx="3" fill="${COLORS.red}"/>
  <text class="footer" x="114" y="640">AKIBARA · GUÍA EDITORIAL</text>
</svg>
`;
}

function sha256(path) {
  return createHash('sha256').update(readFileSync(path)).digest('hex');
}

function dimensions(path) {
  const output = execFileSync('magick', ['identify', '-format', '%w %h', path], { encoding: 'utf8' }).trim();
  const [width, height] = output.split(/\s+/).map(Number);
  return { width, height };
}

function validateInventory() {
  if (assets.length !== 32) {
    throw new Error(`Expected 32 assets, found ${assets.length}`);
  }
  const keys = new Set();
  for (const asset of assets) {
    const key = `${asset.post_id}:${asset.role}`;
    if (keys.has(key)) throw new Error(`Duplicate post/role: ${key}`);
    keys.add(key);
    if (!asset.alt || !asset.caption) throw new Error(`Missing alt/caption: ${key}`);
  }
}

function main() {
  validateInventory();
  rmSync(OUTPUT, { recursive: true, force: true });
  mkdirSync(SVG_DIR, { recursive: true });

  const manifestAssets = [];
  for (const asset of assets) {
    const base = `${asset.post_id}-${asset.slug}`;
    const svgPath = join(SVG_DIR, `${base}.svg`);
    const webpPath = join(OUTPUT, `${base}.webp`);
    writeFileSync(svgPath, renderSvg(asset), 'utf8');

    execFileSync('magick', [
      '-font', FONT_FILE,
      '-background', COLORS.background,
      svgPath,
      '-strip',
      '-quality', '82',
      '-define', 'webp:method=6',
      '-define', 'webp:thread-level=1',
      webpPath,
    ], { stdio: 'pipe' });

    const webpStats = statSync(webpPath);
    const size = dimensions(webpPath);
    if (size.width !== WIDTH || size.height !== HEIGHT) {
      throw new Error(`Unexpected dimensions for ${base}: ${size.width}x${size.height}`);
    }
    if (webpStats.size >= MAX_WEBP_BYTES) {
      throw new Error(`${base} exceeds 150 KB: ${webpStats.size} bytes`);
    }

    manifestAssets.push({
      post_id: asset.post_id,
      file: relative(OUTPUT, webpPath),
      role: asset.role,
      alt: asset.alt,
      caption: asset.caption,
      sha256: sha256(webpPath),
      bytes: webpStats.size,
      dimensions: `${size.width}x${size.height}`,
      source_svg: relative(OUTPUT, svgPath),
      source_svg_sha256: sha256(svgPath),
    });
  }

  const manifest = {
    schema_version: 1,
    generated_on: '2026-07-26',
    generator: relative(ROOT, fileURLToPath(import.meta.url)),
    asset_count: manifestAssets.length,
    post_count: new Set(manifestAssets.map((asset) => asset.post_id)).size,
    format: 'WebP',
    dimensions: `${WIDTH}x${HEIGHT}`,
    max_webp_bytes: MAX_WEBP_BYTES,
    palette: COLORS,
    font_family: 'Arial',
    assets: manifestAssets,
  };
  writeFileSync(join(OUTPUT, 'manifest.json'), `${JSON.stringify(manifest, null, 2)}\n`, 'utf8');

  const largest = [...manifestAssets].sort((a, b) => b.bytes - a.bytes)[0];
  process.stdout.write(`Generated ${manifest.asset_count} assets for ${manifest.post_count} posts.\n`);
  process.stdout.write(`Largest WebP: ${largest.file} (${largest.bytes} bytes).\n`);
}

main();
