#!/usr/bin/env node

import { createHash } from 'node:crypto';
import { readFile, writeFile } from 'node:fs/promises';
import path from 'node:path';

const root = process.cwd();
const reportDir = path.join(root, '.local-reports', 'blog-audit-20260818');
const snapshotPath = path.join(reportDir, 'production-snapshot.json');
const outputPath = path.join(reportDir, 'first-batch-remediation.json');
const hashPath = path.join(reportDir, 'first-batch-remediation.sha256');
const expectedSnapshotSha256 = '21e2bf67c54d563720a2a89643e8c918873f126aee17859ffea3badaf35b8610';

const snapshotBuffer = await readFile(snapshotPath);
const snapshotSha256 = createHash('sha256').update(snapshotBuffer).digest('hex');
if (snapshotSha256 !== expectedSnapshotSha256) {
  throw new Error(`Snapshot drift: expected ${expectedSnapshotSha256}, got ${snapshotSha256}`);
}

const snapshot = JSON.parse(snapshotBuffer.toString('utf8'));
const sourceById = new Map(snapshot.posts.map((post) => [post.post_id, post]));

const posts = [
  {
    post_id: 22353,
    post_title: 'Atelier of Witch Hat: magia, arte y edición en español',
    post_excerpt: 'Coco aprende que la magia se dibuja. Revisamos la historia, los premios, el anime y la diferencia entre los tomos japoneses y el catálogo de Akibara.',
    rank_math_title: 'Atelier of Witch Hat: historia, tomos y anime',
    rank_math_description: 'Conoce la historia de Atelier of Witch Hat, sus premios, el anime de 2026 y cuántos tomos hay en Japón y en el catálogo de Akibara.',
    focus_keyword: 'atelier of witch hat,kamome shirahama,manga fantasía',
    featured_image_alt: 'Coco dibujando un sello mágico en Atelier of Witch Hat',
    post_content: `<!-- wp:paragraph -->
<p><strong>Atelier of Witch Hat</strong>, de Kamome Shirahama, sigue a Coco, una niña que descubre que la magia no depende de un don de nacimiento: se produce al dibujar símbolos con tinta y herramientas precisas. Ese hallazgo la lleva al taller de Qifrey y también pone a su familia en peligro. La serie combina aprendizaje, secretos y decisiones éticas sin convertir la magia en una simple lista de poderes.</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2 class="wp-block-heading">De qué trata</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Coco creció creyendo que solo ciertas personas podían hacer magia. Cuando ve trabajar a Qifrey, entiende que el conocimiento está oculto, no ausente. Un hechizo mal trazado petrifica a su madre y convierte su aprendizaje en algo concreto: dominar el oficio es la única vía que tiene para intentar repararlo.</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p>Shirahama usa esa premisa para hablar de quién puede acceder al conocimiento, quién decide sus límites y qué responsabilidad tiene quien lo domina. El dibujo también forma parte del relato: los sellos deben ser legibles para el lector y los errores visuales tienen consecuencias dentro de la historia.</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2 class="wp-block-heading">Tomos, premios y anime</h2>
<!-- /wp:heading -->

<!-- wp:list -->
<ul class="wp-block-list"><!-- wp:list-item -->
<li><strong>Japón:</strong> Kodansha publicó el tomo 16 el 23 de abril de 2026.</li>
<!-- /wp:list-item --><!-- wp:list-item -->
<li><strong>Akibara:</strong> el corte de catálogo usado para esta revisión registra 15 tomos de la edición de Milky Way. Esto no es el total japonés y el stock puede cambiar.</li>
<!-- /wp:list-item --><!-- wp:list-item -->
<li><strong>Premios:</strong> la edición en inglés compartió el Eisner 2021 a mejor edición estadounidense de material internacional de Asia y obtuvo el Harvey 2020 a mejor manga.</li>
<!-- /wp:list-item --><!-- wp:list-item -->
<li><strong>Anime:</strong> la adaptación de Bug Films comenzó el 6 de abril de 2026; el sitio oficial anunció una segunda temporada en julio.</li>
<!-- /wp:list-item --></ul>
<!-- /wp:list -->

<!-- wp:heading -->
<h2 class="wp-block-heading">Qué lector puede disfrutarlo</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Puede funcionar para quien busca fantasía de aprendizaje, un sistema de magia visual y conflictos que no se resuelven solo con fuerza. También es una entrada amable al manga para lectores que prefieren la exploración y el trabajo artesanal por sobre el combate continuo.</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p>Los tomos disponibles se consultan en la <a href="https://akibara.cl/serie/atelier-of-witch-hat/">página de Atelier of Witch Hat en Akibara</a>. La disponibilidad visible allí es la referencia vigente para comprar, no la cifra histórica de este artículo.</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2 class="wp-block-heading">Fuentes oficiales consultadas</h2>
<!-- /wp:heading -->

<!-- wp:list -->
<ul class="wp-block-list"><!-- wp:list-item -->
<li><a href="https://www.kodansha.co.jp/comic/products/0000425341" target="_blank" rel="noopener">Kodansha: ficha del tomo 16</a>.</li>
<!-- /wp:list-item --><!-- wp:list-item -->
<li><a href="https://tongari-anime.com/" target="_blank" rel="noopener">Sitio oficial del anime</a>.</li>
<!-- /wp:list-item --><!-- wp:list-item -->
<li><a href="https://www.comic-con.org/awards/eisner-awards/past-recipients/past-recipenties-2020s/" target="_blank" rel="noopener">Comic-Con: ganadores de los Eisner</a> y <a href="https://www.harveyawards.com/en-us/about/news/announcing-the-2020-harvey-awards-winners.html" target="_blank" rel="noopener">Harvey Awards: ganadores de 2020</a>.</li>
<!-- /wp:list-item --></ul>
<!-- /wp:list -->`,
    changelog: [
      'Separó los 16 tomos japoneses de los 15 registrados en el catálogo de Akibara.',
      'Sustituyó elogios absolutos por una descripción concreta del dibujo y los temas.',
      'Actualizó premios y anime con enlaces a sus organismos y sitio oficial.',
      'Eliminó lenguaje promocional y afirmaciones de stock permanente.'
    ]
  },
  {
    post_id: 22607,
    post_title: 'Cómo empezar a leer One Piece: orden y ediciones',
    post_excerpt: 'One Piece se lee desde el tomo 1. Esta guía explica cómo elegir entre tomos individuales y recopilatorios 3 en 1 sin confundir la numeración.',
    rank_math_title: 'Cómo empezar a leer One Piece: orden y ediciones',
    rank_math_description: 'Aprende por dónde empezar One Piece, cómo se ordena el manga y qué cambia entre la edición individual de Ivrea y la colección 3 en 1.',
    focus_keyword: 'empezar a leer one piece,one piece manga,ediciones one piece',
    featured_image_alt: 'Luffy y la tripulación del Sombrero de Paja en alta mar',
    post_content: `<!-- wp:paragraph -->
<p><strong>One Piece se empieza en el tomo 1 y se lee en orden.</strong> No hay una precuela obligatoria ni una ruta alternativa que simplifique la historia. La pregunta útil no es dónde saltar, sino qué edición te acomoda y cuánto espacio quieres dedicarle a una serie extensa.</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2 class="wp-block-heading">Qué cuenta One Piece</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Eiichiro Oda sigue el viaje de Monkey D. Luffy, que sale al mar para encontrar el tesoro One Piece y convertirse en Rey de los Piratas. Cada integrante de los Sombrero de Paja se suma con una historia y un objetivo propios. El relato alterna islas con conflictos cerrados y una trama de fondo que se construye desde los primeros tomos.</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2 class="wp-block-heading">Cuántos tomos hay</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Shueisha publicó el <strong>tomo 115 en Japón el 3 de julio de 2026</strong>. En marzo, con la salida del tomo 114, el portal oficial informó que la serie superó los 600 millones de ejemplares en circulación mundial. “En circulación” describe la tirada distribuida; no equivale a ventas comprobadas al público.</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2 class="wp-block-heading">Edición individual o 3 en 1</h2>
<!-- /wp:heading -->

<!-- wp:list -->
<ul class="wp-block-list"><!-- wp:list-item -->
<li><strong>Ivrea Argentina:</strong> tomos individuales con numeración equivalente a la edición japonesa. Sirve para avanzar de a un volumen y reemplazar un tomo con facilidad.</li>
<!-- /wp:list-item --><!-- wp:list-item -->
<li><strong>Planeta 3 en 1:</strong> cada volumen reúne aproximadamente tres tomos originales. Ocupa menos unidades en la estantería, pero su numeración no coincide con la edición individual.</li>
<!-- /wp:list-item --></ul>
<!-- /wp:list -->

<!-- wp:paragraph -->
<p>No conviene alternar ambas ediciones sin revisar qué tomos originales contiene cada recopilatorio. Si ya empezaste una colección, continuar en el mismo formato evita capítulos repetidos o ausentes.</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2 class="wp-block-heading">Una forma sencilla de avanzar</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Prueba con los primeros tres tomos individuales o con el primer 3 en 1. Ese tramo presenta a Luffy, Zoro y Nami y permite evaluar el humor, la aventura y el ritmo sin asumir toda la colección de una vez. Si vienes del anime, volver al inicio mantiene escenas y detalles de Oda que no siempre pasan igual a pantalla.</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p>Revisa los tomos individuales en <a href="https://akibara.cl/serie/one-piece/">One Piece en Akibara</a> y la colección recopilatoria en <a href="https://akibara.cl/serie/one-piece-no/">One Piece 3 en 1</a>. El número de productos visibles y su disponibilidad cambian con las reposiciones.</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2 class="wp-block-heading">Fuentes oficiales consultadas</h2>
<!-- /wp:heading -->

<!-- wp:list -->
<ul class="wp-block-list"><!-- wp:list-item -->
<li><a href="https://www.shueisha.co.jp/books/items/contents.html?isbn=978-4-08-885130-3" target="_blank" rel="noopener">Shueisha: ficha del tomo 115</a>.</li>
<!-- /wp:list-item --><!-- wp:list-item -->
<li><a href="https://one-piece.com/news/78258/index.html" target="_blank" rel="noopener">ONE PIECE.com: 600 millones en circulación</a>.</li>
<!-- /wp:list-item --><!-- wp:list-item -->
<li><a href="https://www.ivrea.com.ar/titulo/one-piece/" target="_blank" rel="noopener">Ivrea Argentina: ficha de la edición individual</a>.</li>
<!-- /wp:list-item --></ul>
<!-- /wp:list -->`,
    changelog: [
      'Actualizó Japón a 115 tomos y precisó que 600 millones corresponde a circulación, no ventas.',
      'Explicó la incompatibilidad de numeración entre tomos individuales y recopilatorios 3 en 1.',
      'Retiró afirmaciones grandilocuentes y promesas de inventario.',
      'Reemplazó enlaces de búsqueda por páginas de serie estables.'
    ]
  },
  {
    post_id: 22610,
    post_title: 'Berserk: orden de lectura, arcos y continuación',
    post_excerpt: 'Berserk se lee desde el tomo 1. Revisamos sus arcos, la edición Maximum y cómo continúa la serie con Studio Gaga y supervisión de Kouji Mori.',
    rank_math_title: 'Berserk: orden de lectura, arcos y continuación',
    rank_math_description: 'Guía de Berserk con orden de lectura, 43 tomos japoneses, edición Maximum y los roles correctos de Studio Gaga y Kouji Mori.',
    focus_keyword: 'berserk manga,orden de lectura berserk,maximum berserk',
    featured_image_alt: 'Guts con la Dragon Slayer en el mundo de Berserk',
    post_content: `<!-- wp:paragraph -->
<p><strong>Berserk se lee desde el tomo 1 y en orden de publicación.</strong> Los primeros capítulos muestran a Guts como el Espadachín Negro; después, la Edad de Oro vuelve a su pasado y explica su relación con Griffith y la Banda del Halcón. Saltarse esa apertura cambia el efecto de varias revelaciones.</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2 class="wp-block-heading">Estado de la obra</h2>
<!-- /wp:heading -->

<!-- wp:list -->
<ul class="wp-block-list"><!-- wp:list-item -->
<li><strong>Japón:</strong> Hakusensha publicó el tomo 43 el 29 de agosto de 2025. La serie sigue abierta.</li>
<!-- /wp:list-item --><!-- wp:list-item -->
<li><strong>Edición Maximum:</strong> los 21 volúmenes de Panini España reúnen los tomos japoneses 1 al 42.</li>
<!-- /wp:list-item --><!-- wp:list-item -->
<li><strong>Dato de difusión:</strong> las cifras editoriales se expresan como ejemplares en circulación, no como copias vendidas. Por eso retiramos la antigua etiqueta “50 millones vendidos”.</li>
<!-- /wp:list-item --></ul>
<!-- /wp:list -->

<!-- wp:heading -->
<h2 class="wp-block-heading">Quién continúa Berserk</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Kouji Mori <strong>no fue asistente de Kentaro Miura</strong>. Es su amigo de la infancia y colega mangaka. Mori supervisa la continuación usando los episodios que Miura le relató; <strong>Studio Gaga</strong>, formado por antiguos asistentes y aprendices de Miura, realiza el manga. Los créditos oficiales son: obra original de Kentaro Miura, manga de Studio Gaga y supervisión de Kouji Mori.</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p>Hakusensha también aclaró el límite de ese trabajo: <strong>no quedó un <em>name</em> —el guion gráfico del manga— con el que pudiera reconstruirse un manuscrito completo de Miura</strong>. El equipo se guía por las conversaciones que mantuvo con él, sus notas de concepto y sus diseños de personajes. Mori se comprometió a usar solo los episodios y diálogos que recuerda con claridad, sin añadir material para rellenar vacíos.</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2 class="wp-block-heading">Arcos de lectura</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Los límites entre arcos pueden variar un capítulo según la guía consultada, por lo que conviene tratarlos como rangos aproximados:</p>
<!-- /wp:paragraph -->

<!-- wp:list -->
<ul class="wp-block-list"><!-- wp:list-item -->
<li><strong>Espadachín Negro:</strong> apertura de la serie y presentación del conflicto.</li>
<!-- /wp:list-item --><!-- wp:list-item -->
<li><strong>Edad de Oro:</strong> pasado de Guts, Griffith y Casca.</li>
<!-- /wp:list-item --><!-- wp:list-item -->
<li><strong>Condena:</strong> consecuencias del Eclipse y la Torre de la Convicción.</li>
<!-- /wp:list-item --><!-- wp:list-item -->
<li><strong>Halcón Milenario:</strong> guerra, viaje y expansión del componente fantástico.</li>
<!-- /wp:list-item --><!-- wp:list-item -->
<li><strong>Fantasia:</strong> comienza alrededor del tomo 35 y continúa más allá del 43; no termina en el 42.</li>
<!-- /wp:list-item --></ul>
<!-- /wp:list -->

<!-- wp:heading -->
<h2 class="wp-block-heading">Qué edición elegir</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p><a href="https://akibara.cl/serie/maximum-berserk/">Maximum Berserk</a> reúne dos tomos japoneses por volumen y permite leer del 1 al 42 en 21 libros. La <a href="https://akibara.cl/berserk-cuantos-tomos-tiene-y-que-edicion-comprar/">comparación de ediciones de Berserk</a> explica también la Master Edition. No mezcles numeraciones sin comprobar la equivalencia.</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p>La serie incluye violencia gráfica, violencia sexual y otros contenidos para público adulto. Esa advertencia importa más que la etiqueta demográfica “seinen”.</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2 class="wp-block-heading">Fuentes oficiales consultadas</h2>
<!-- /wp:heading -->

<!-- wp:list -->
<ul class="wp-block-list"><!-- wp:list-item -->
<li><a href="https://www.hakusensha.co.jp/information/63802/" target="_blank" rel="noopener">Hakusensha: anuncio y créditos de la continuación</a>.</li>
<!-- /wp:list-item --><!-- wp:list-item -->
<li><a href="https://www.hakusensha.co.jp/comicslist/75530/" target="_blank" rel="noopener">Hakusensha: ficha del tomo 43</a>.</li>
<!-- /wp:list-item --><!-- wp:list-item -->
<li><a href="https://www.panini.es/media/paniniFiles/Ndp-Maximum-Berserk21-ediciones.pdf" target="_blank" rel="noopener">Panini España: ficha de Maximum Berserk 21</a>.</li>
<!-- /wp:list-item --></ul>
<!-- /wp:list -->`,
    changelog: [
      'Corrigió 42 a 43 tomos en Japón y dejó claro que la serie continúa.',
      'Eliminó la cifra mal rotulada como ventas y distinguió circulación editorial.',
      'Corrigió a Kouji Mori: amigo de la infancia y supervisor, no asistente de Miura.',
      'Aclaró que no quedó un guion gráfico completo y que la continuación se apoya en conversaciones, notas y diseños de Miura.',
      'Atribuyó el dibujo a Studio Gaga y evitó rangos de arcos falsamente exactos.',
      'Retiró la etiqueta valorativa heredada de contenido, extracto y metadatos.'
    ]
  },
  {
    post_id: 22638,
    post_title: 'Jujutsu Kaisen: en qué tomo continuar después del anime',
    post_excerpt: 'La temporada 2 termina en el capítulo 137: continúa en el tomo 16, capítulo 138. Incluimos también los puntos de continuación de las temporadas 1 y 3.',
    rank_math_title: 'Jujutsu Kaisen: en qué tomo continuar tras el anime',
    rank_math_description: 'La temporada 2 de Jujutsu Kaisen termina en el capítulo 137: sigue en el tomo 16, capítulo 138. Revisa también temporadas 1 y 3.',
    focus_keyword: 'continuar jujutsu kaisen,en qué tomo jujutsu kaisen',
    featured_image_alt: 'Yuji Itadori frente a las ruinas de Shibuya en Jujutsu Kaisen',
    post_content: `<!-- wp:paragraph -->
<p><strong>Si terminaste la temporada 2 de Jujutsu Kaisen, continúa en el tomo 16, capítulo 138.</strong> La temporada cierra en el capítulo 137, a mitad de ese volumen. Empezar en el tomo 17 hace que te saltes cinco capítulos.</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2 class="wp-block-heading">Punto de continuación por temporada</h2>
<!-- /wp:heading -->

<!-- wp:table -->
<figure class="wp-block-table"><table><thead><tr><th>Lo último que viste</th><th>Dónde seguir</th><th>Por qué</th></tr></thead><tbody><tr><td>Temporada 1</td><td>Tomo 8, capítulo 64</td><td>El anime termina en el capítulo 63; el tomo 8 reúne los capítulos 62 al 70.</td></tr><tr><td>Temporada 2</td><td>Tomo 16, capítulo 138</td><td>El anime termina en el 137; el tomo 16 reúne los capítulos 134 al 142.</td></tr><tr><td>Temporada 3, primera parte</td><td>Tomo 21, desde el capítulo 181</td><td>El cierre animado llega al tramo 180/181; empezar en el tomo 22 omite la transición.</td></tr></tbody></table></figure>
<!-- /wp:table -->

<!-- wp:paragraph -->
<p>La continuación animada ya está anunciada: el sitio oficial publicó el 19 de junio de 2026 el avance de la <strong>temporada 4, <em>Juego de la Colonia: segunda parte</em></strong>. El anuncio indica que sigue en producción y no entrega una fecha de estreno; cualquier día o mes que circule fuera de esa fuente sigue sin confirmación oficial.</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2 class="wp-block-heading">Qué viene después de Shibuya</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>El capítulo 138 todavía no abre el Juego de la Colonia. Primero vienen el arco de Exterminio de Itadori y la Preparación Perfecta. El Juego de la Colonia comienza en el capítulo 159, dentro del tomo 18. Esta distinción evita comprar un volumen pensando que contiene el inicio de un arco que en realidad llega después.</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p>Si solo quieres leer el enfrentamiento final de Shinjuku, empieza en el <strong>tomo 25, capítulo 222</strong>. El tomo 26 comienza en el capítulo 228 y deja fuera el arranque de ese arco.</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2 class="wp-block-heading">Cuántos tomos tiene el manga</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>La serie principal terminó en <strong>30 tomos</strong>. El corte de catálogo de Akibara registra los 30 volúmenes de Panini Argentina. Puedes revisar la colección y la disponibilidad actual en <a href="https://akibara.cl/serie/jujutsu-kaisen/">Jujutsu Kaisen en Akibara</a>.</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p>El <strong>tomo 0 del manga</strong>, centrado en Yuta Okkotsu, no aparece en ese corte de catálogo. El producto llamado <em>Jujutsu Kaisen 0 Movie Edition</em> que sí figura en la tienda es una <strong>novela</strong>; no reemplaza el volumen 0 del manga.</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2 class="wp-block-heading">Fuentes oficiales consultadas</h2>
<!-- /wp:heading -->

<!-- wp:list -->
<ul class="wp-block-list"><!-- wp:list-item -->
<li><a href="https://www.shueisha.co.jp/books/search/search.html?seriesid=37298" target="_blank" rel="noopener">Shueisha: listado oficial de los 30 tomos</a>.</li>
<!-- /wp:list-item --><!-- wp:list-item -->
<li><a href="https://jujutsukaisen.jp/" target="_blank" rel="noopener">Sitio oficial del anime de Jujutsu Kaisen</a>.</li>
<!-- /wp:list-item --><!-- wp:list-item -->
<li><a href="https://jujutsukaisen.jp/news/20260619_01.php" target="_blank" rel="noopener">Sitio oficial: anuncio y avance de la temporada 4</a>.</li>
<!-- /wp:list-item --><!-- wp:list-item -->
<li><a href="https://www.shueisha.co.jp/books/search/search.html?titleauthor=%E8%8A%A5%E8%A6%8B+%E4%B8%8B%E3%80%85" target="_blank" rel="noopener">Shueisha: obra de Gege Akutami, incluido el tomo 0</a>.</li>
<!-- /wp:list-item --></ul>
<!-- /wp:list -->`,
    changelog: [
      'Corrigió temporada 2: tomo 16, capítulo 138, no tomo 17.',
      'Corrigió temporada 1 a tomo 8, capítulo 64, y temporada 3 a tomo 21.',
      'Añadió la temporada 4 anunciada y evitó atribuirle una fecha de estreno aún no publicada.',
      'Separó Exterminio, Preparación Perfecta y Juego de la Colonia.',
      'Corrigió el inicio de Shinjuku al tomo 25, capítulo 222.',
      'Aclaró 30 tomos y distinguió el manga 0 de la novela disponible.'
    ]
  },
  {
    post_id: 22639,
    post_title: 'Chainsaw Man: en qué tomo seguir después del anime',
    post_excerpt: 'La temporada 1 continúa en el tomo 5, capítulo 39. Si ya viste la película de Reze, el primer capítulo sin adaptar es el 53, en el tomo 7.',
    rank_math_title: 'Chainsaw Man: en qué tomo seguir tras el anime',
    rank_math_description: 'Tras la temporada 1 de Chainsaw Man, sigue en el tomo 5, capítulo 39; después de la película de Reze, continúa en el tomo 7, capítulo 53.',
    focus_keyword: 'continuar chainsaw man,chainsaw man tomo 5,chainsaw man manga',
    featured_image_alt: 'Denji transformado en Chainsaw Man junto a Pochita',
    post_content: `<!-- wp:paragraph -->
<p><strong>Después de la temporada 1 de Chainsaw Man, sigue en el tomo 5, capítulo 39.</strong> El anime adapta los capítulos 1 al 38: cubre los tomos 1 al 4 y casi la primera mitad del tomo 5. Por eso conviene saber que una parte del volumen será repetida.</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2 class="wp-block-heading">Qué parte del tomo 5 ya viste</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>El tomo 5 reúne los capítulos 35 al 43. La temporada 1 utiliza los capítulos 35, 36, 37 y parte del 38 en su episodio final. Si quieres evitar cualquier salto, lee el tomo completo; si prefieres continuar justo después del anime, comienza en el capítulo 39.</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2 class="wp-block-heading">Después de la película de Reze</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Si ya viste <em>Chainsaw Man – La película: Arco de Reze</em>, continúa en el <strong>tomo 7, capítulo 53</strong>. Shueisha confirma en el índice del volumen que ese tomo comienza con el capítulo 53.</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p>La web oficial de la película no publica una tabla de equivalencias entre metraje y capítulos. El punto anterior sale de comparar su final con el índice editorial del manga; dejamos explícito el método para no presentar esa equivalencia como una declaración de la productora.</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2 class="wp-block-heading">Cómo se divide el manga</h2>
<!-- /wp:heading -->

<!-- wp:list -->
<ul class="wp-block-list"><!-- wp:list-item -->
<li><strong>Parte 1, Seguridad Pública:</strong> capítulos 1 al 97, recopilados en los tomos 1 al 11.</li>
<!-- /wp:list-item --><!-- wp:list-item -->
<li><strong>Parte 2, Academia:</strong> capítulos 98 al 232, recopilados en los tomos 12 al 24.</li>
<!-- /wp:list-item --></ul>
<!-- /wp:list -->

<!-- wp:paragraph -->
<p>La obra terminó en Japón con <strong>24 tomos</strong>. Shueisha publicó el volumen 24 el 4 de junio de 2026 y su ficha lo presenta como el cierre. La edición de Ivrea Argentina tiene 23 tomos publicados en el corte de esta revisión; el tomo 24 aún no aparece como publicado allí.</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2 class="wp-block-heading">Qué edición hay en Akibara</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>El snapshot de Akibara registra la edición de Ivrea Argentina del tomo 1 al 23. Eso describe títulos catalogados, no stock inmediato: algunos pueden estar agotados o en preventa. Consulta la <a href="https://akibara.cl/serie/chainsaw-man/">página de Chainsaw Man</a> antes de armar el pedido.</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p>Leer desde el inicio sigue teniendo sentido aunque hayas visto el anime. Fujimoto usa silencios, encuadres y cambios de ritmo propios de la página; no es contenido “extra”, sino otra puesta en escena de la misma historia.</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2 class="wp-block-heading">Fuentes oficiales consultadas</h2>
<!-- /wp:heading -->

<!-- wp:list -->
<ul class="wp-block-list"><!-- wp:list-item -->
<li><a href="https://books.shueisha.co.jp/items/contents.html?isbn=978-4-08-885051-1" target="_blank" rel="noopener">Shueisha: tomo 24 y cierre de la serie</a>.</li>
<!-- /wp:list-item --><!-- wp:list-item -->
<li><a href="https://www.ivrea.com.ar/titulo/chainsaw-man/" target="_blank" rel="noopener">Ivrea Argentina: estado de la edición</a>.</li>
<!-- /wp:list-item --><!-- wp:list-item -->
<li><a href="https://chainsawman.dog/" target="_blank" rel="noopener">Sitio oficial del anime</a>.</li>
<!-- /wp:list-item --><!-- wp:list-item -->
<li><a href="https://www.chainsawman.dog/movie_reze/" target="_blank" rel="noopener">Sitio oficial de la película del arco de Reze</a>.</li>
<!-- /wp:list-item --><!-- wp:list-item -->
<li><a href="https://www.shueisha.co.jp/books/items/contents.html?jdcn=08882328881780315501" target="_blank" rel="noopener">Shueisha: índice oficial del tomo 7, que comienza en el capítulo 53</a>.</li>
<!-- /wp:list-item --></ul>
<!-- /wp:list -->`,
    changelog: [
      'Precisó que el anime cubre casi la mitad del tomo 5, no solo sus primeras páginas.',
      'Añadió la continuación tras la película de Reze: tomo 7, capítulo 53, identificada mediante comparación editorial.',
      'Actualizó la obra japonesa a 24 tomos completos y la edición Ivrea a 23 publicados.',
      'Corrigió la Parte 2 a tomos 12-24 y capítulos 98-232.',
      'Separó catálogo de stock y eliminó la falsa promesa de serie completa en Akibara.',
      'Retiró encabezados y frases formularias.'
    ]
  },
  {
    post_id: 22640,
    post_title: 'Dandadan: en qué tomo continuar después del anime',
    post_excerpt: 'Tras la temporada 2 de Dandadan, la historia sigue en el tomo 9, capítulo 72. Comparamos además los tomos publicados en Japón, Ivrea y Akibara.',
    rank_math_title: 'Dandadan: en qué tomo continuar tras el anime',
    rank_math_description: 'Después de la temporada 2 de Dandadan, continúa en el tomo 9, capítulo 72. Japón tiene 24 tomos publicados y la temporada 3 llegará en 2027.',
    focus_keyword: 'dandadan tomo 9,continuar dandadan,dandadan manga',
    featured_image_alt: 'Momo Ayase y Okarun frente a una aparición de Dandadan',
    post_content: `<!-- wp:paragraph -->
<p><strong>Si terminaste la temporada 2 de Dandadan, continúa en el tomo 9, capítulo 72.</strong> La adaptación llega al capítulo 71. Empezar desde el comienzo del tomo 9 repite un tramo corto, pero evita perder la transición entre el cierre animado y el arco siguiente.</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2 class="wp-block-heading">Qué cuenta Dandadan</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Momo Ayase cree en fantasmas, mientras Ken Takakura —Okarun— cree en extraterrestres. Una apuesta los obliga a comprobar ambas ideas y los mete en una cadena de encuentros paranormales. Yukinobu Tatsu combina acción, comedia, romance adolescente y relatos de criaturas sin separar cada tono en compartimentos.</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2 class="wp-block-heading">Dónde seguir después de cada temporada</h2>
<!-- /wp:heading -->

<!-- wp:list -->
<ul class="wp-block-list"><!-- wp:list-item -->
<li><strong>Después de la temporada 1:</strong> tomo 5, capítulo 34.</li>
<!-- /wp:list-item --><!-- wp:list-item -->
<li><strong>Después de la temporada 2:</strong> tomo 9, capítulo 72.</li>
<!-- /wp:list-item --></ul>
<!-- /wp:list -->

<!-- wp:paragraph -->
<p>Si prefieres una continuidad visual limpia, leer el volumen completo es la opción más sencilla. El anime puede reorganizar transiciones o ajustar el final de un episodio, mientras el tomo conserva la secuencia de publicación.</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2 class="wp-block-heading">Qué se sabe de la temporada 3</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>La temporada 3 está confirmada para <strong>2027</strong> y Science SARU continúa a cargo de la producción. El anuncio oficial no fija mes ni día de estreno; por ahora, 2027 es la precisión máxima respaldada por la fuente.</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2 class="wp-block-heading">Japón, Ivrea y catálogo de Akibara</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Al 18 de agosto de 2026, Shueisha había publicado <strong>24 tomos en Japón</strong>; el volumen 24 salió el 4 de junio. El tomo 25 ya tiene ficha, pero su lanzamiento está programado para el 4 de septiembre de 2026, así que todavía no corresponde contarlo como publicado. La ficha vigente de Ivrea Argentina registra <strong>23 tomos publicados</strong>. El snapshot de Akibara usado para este trabajo contiene 22 tomos catalogados; esa cifra no representa el total de la obra ni el avance actual de Ivrea.</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p>La serie sigue abierta. En lugar de prometer una cantidad fija de capítulos “por delante”, conviene consultar la editorial y la <a href="https://akibara.cl/serie/dan-da-dan/">página de Dandadan en Akibara</a>, donde se ve el stock y las preventas del momento.</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2 class="wp-block-heading">Fuentes oficiales consultadas</h2>
<!-- /wp:heading -->

<!-- wp:list -->
<ul class="wp-block-list"><!-- wp:list-item -->
<li>Shueisha: <a href="https://www.shueisha.co.jp/books/items/contents.html?isbn=978-4-08-885125-9" target="_blank" rel="noopener">tomo 24, publicado el 4 de junio de 2026</a>, y <a href="https://www.shueisha.co.jp/books/items/contents.html?isbn=978-4-08-885188-4" target="_blank" rel="noopener">tomo 25, programado para el 4 de septiembre</a>.</li>
<!-- /wp:list-item --><!-- wp:list-item -->
<li><a href="https://www.ivrea.com.ar/titulo/dandadan/" target="_blank" rel="noopener">Ivrea Argentina: estado de publicación</a>.</li>
<!-- /wp:list-item --><!-- wp:list-item -->
<li><a href="https://anime-dandadan.com/news/2673/" target="_blank" rel="noopener">Sitio oficial: temporada 3 confirmada para 2027</a>.</li>
<!-- /wp:list-item --></ul>
<!-- /wp:list -->`,
    changelog: [
      'Fijó la continuación de la temporada 2 en tomo 9, capítulo 72.',
      'Confirmó 24 tomos japoneses publicados al 18 de agosto de 2026 y separó el tomo 25, programado para septiembre.',
      'Añadió la temporada 3 confirmada para 2027 sin inventar un mes o día de estreno.',
      'Separó 24 tomos japoneses, 23 de Ivrea y 22 catalogados en Akibara.',
      'Eliminó la promesa imprecisa de cientos de capítulos por delante.',
      'Reemplazó urgencia comercial por instrucciones de lectura y verificación de stock.'
    ]
  },
  {
    post_id: 22641,
    post_title: '10 mangas para empezar a leer: selección editorial',
    post_excerpt: 'Diez puntos de entrada al manga elegidos por claridad, ritmo y variedad. No es un ranking de popularidad: cada recomendación responde a un tipo de lector.',
    rank_math_title: '10 mangas para empezar a leer: selección editorial',
    rank_math_description: 'Una selección editorial de 10 mangas para comenzar, con criterios claros, advertencias de extensión y opciones según género y tipo de lector.',
    focus_keyword: 'mangas para empezar,primer manga,recomendaciones manga',
    featured_image_alt: 'Una persona eligiendo su primer manga entre varios géneros',
    post_content: `<!-- wp:paragraph -->
<p>Esta lista reúne <strong>diez mangas que pueden funcionar como primera lectura</strong>. No usa Google Trends, ventas ni búsquedas para ordenar las obras. Es una selección editorial basada en cuatro criterios: una premisa comprensible, un primer tomo representativo, lectura clara y variedad de tonos.</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p>El número no indica calidad ni popularidad. La elección depende de lo que ya disfrutas en novelas, cine, series o deporte.</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2 class="wp-block-heading">Diez puertas de entrada</h2>
<!-- /wp:heading -->

<!-- wp:list {"ordered":true} -->
<ol class="wp-block-list"><!-- wp:list-item -->
<li><strong><a href="https://akibara.cl/serie/demon-slayer-kimetsu-no-yaiba/">Demon Slayer</a>:</strong> acción, drama familiar y una historia cerrada en 23 tomos. Funciona si valoras un objetivo claro desde el inicio.</li>
<!-- /wp:list-item --><!-- wp:list-item -->
<li><strong><a href="https://akibara.cl/serie/my-hero-academia/">My Hero Academia</a>:</strong> superhéroes, vida escolar y crecimiento de un reparto amplio; completa en 42 tomos.</li>
<!-- /wp:list-item --><!-- wp:list-item -->
<li><strong><a href="https://akibara.cl/serie/chainsaw-man/">Chainsaw Man</a>:</strong> acción, horror y humor negro. Terminó en 24 tomos en Japón; no es una colección corta.</li>
<!-- /wp:list-item --><!-- wp:list-item -->
<li><strong><a href="https://akibara.cl/serie/slam-dunk/">Slam Dunk</a>:</strong> básquet, comedia y progreso deportivo. No hace falta conocer el deporte antes de leer.</li>
<!-- /wp:list-item --><!-- wp:list-item -->
<li><strong><a href="https://akibara.cl/serie/jujutsu-kaisen/">Jujutsu Kaisen</a>:</strong> combates con reglas y un tono más oscuro; completa en 30 tomos.</li>
<!-- /wp:list-item --><!-- wp:list-item -->
<li><strong><a href="https://akibara.cl/serie/blue-lock/">Blue Lock</a>:</strong> fútbol planteado como competencia individual. Su ritmo se parece más a un torneo de eliminación que a una crónica deportiva.</li>
<!-- /wp:list-item --><!-- wp:list-item -->
<li><strong><a href="https://akibara.cl/serie/one-piece/">One Piece</a>:</strong> aventura extensa y mundo acumulativo. Es adecuada si buscas una lectura de largo recorrido, no una serie para terminar pronto.</li>
<!-- /wp:list-item --><!-- wp:list-item -->
<li><strong><a href="https://akibara.cl/serie/hunter-x-hunter/">Hunter × Hunter</a>:</strong> aventura que cambia de registro y privilegia la estrategia. Sigue abierta y su publicación es irregular.</li>
<!-- /wp:list-item --><!-- wp:list-item -->
<li><strong><a href="https://akibara.cl/serie/maximum-berserk/">Berserk</a>:</strong> fantasía oscura para lectores adultos. Incluye violencia gráfica y sexual; no es una recomendación general para menores.</li>
<!-- /wp:list-item --><!-- wp:list-item -->
<li><strong><a href="https://akibara.cl/serie/atelier-of-witch-hat/">Atelier of Witch Hat</a>:</strong> fantasía de aprendizaje con atención al dibujo, el oficio y las reglas de la magia.</li>
<!-- /wp:list-item --></ol>
<!-- /wp:list -->

<!-- wp:heading -->
<h2 class="wp-block-heading">Qué elegir según el tiempo disponible</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Demon Slayer y Chainsaw Man se leen con ritmo, pero tienen 23 y 24 tomos respectivamente: llamarlas “cortas” confunde velocidad con extensión. Si buscas una colección breve, considera <a href="https://akibara.cl/tienda/?s=akira&amp;post_type=product">Akira</a> en 6 tomos o <a href="https://akibara.cl/tienda/?s=parasyte&amp;post_type=product">Parasyte</a> en 8. Para una serie larga y cerrada, My Hero Academia; para acompañar una publicación abierta, One Piece o Hunter × Hunter.</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2 class="wp-block-heading">Cómo probar sin sobrecomprar</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Empieza con el tomo 1 y, si el formato lo permite, añade el 2. Un primer volumen debe mostrarte el tono, la lectura de derecha a izquierda y el tipo de conflicto. El stock no forma parte de los criterios de esta lista; se comprueba en cada página de serie al momento de pedir.</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2 class="wp-block-heading">Fuentes oficiales consultadas</h2>
<!-- /wp:heading -->

<!-- wp:list -->
<ul class="wp-block-list"><!-- wp:list-item -->
<li><a href="https://www.shueisha.co.jp/books/items/contents.html?isbn=978-4-08-884349-0&amp;mode=1" target="_blank" rel="noopener">Shueisha: tomo 42 de My Hero Academia</a>.</li>
<!-- /wp:list-item --><!-- wp:list-item -->
<li><a href="https://books.shueisha.co.jp/items/contents.html?isbn=978-4-08-885051-1" target="_blank" rel="noopener">Shueisha: tomo final 24 de Chainsaw Man</a>.</li>
<!-- /wp:list-item --><!-- wp:list-item -->
<li><a href="https://www.shueisha.co.jp/books/search/search.html?seriesid=35172" target="_blank" rel="noopener">Shueisha: tomos de Hunter × Hunter</a> y <a href="https://one-piece.com/comics/index.html" target="_blank" rel="noopener">ONE PIECE.com: tomos de la serie</a>.</li>
<!-- /wp:list-item --></ul>
<!-- /wp:list -->`,
    changelog: [
      'Definió la pieza como selección editorial y no como ranking de Trends, ventas o búsquedas.',
      'Expuso criterios de inclusión y retiró jerarquías de popularidad no demostradas.',
      'Corrigió la etiqueta de colección corta aplicada a Demon Slayer y Chainsaw Man.',
      'Añadió Akira y Parasyte como alternativas realmente breves.',
      'Incluyó advertencia de contenido adulto para Berserk y retiró fórmulas persuasivas.'
    ]
  },
  {
    post_id: 22642,
    post_title: 'Manga seinen: qué significa y por dónde empezar',
    post_excerpt: 'Seinen indica el público editorial de una revista, no un género ni una garantía de contenido adulto. Revisamos seis series y cómo elegir una.',
    rank_math_title: 'Manga seinen: qué significa y por dónde empezar',
    rank_math_description: 'Entiende qué significa seinen, por qué no es un género y con qué series comenzar: Berserk, Vagabond, Monster, Golden Kamuy y más.',
    focus_keyword: 'manga seinen,qué es seinen,seinen para empezar',
    featured_image_alt: 'Selección de manga seinen de fantasía, historia y suspenso',
    post_content: `<!-- wp:paragraph -->
<p><strong>Seinen no es un género.</strong> Es una categoría editorial vinculada al público al que se dirige una revista de manga, habitualmente hombres jóvenes y adultos. Puede incluir fantasía, deporte, comedia, romance, historia o suspenso. Tampoco equivale por sí sola a una clasificación de edad.</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2 class="wp-block-heading">Qué cambia frente al shonen</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Las etiquetas shonen y seinen describen mercados de publicación, no una escala de seriedad. Un shonen puede tratar violencia o duelo con dureza; un seinen puede ser cotidiano y tranquilo. Para saber si una obra te conviene, mira su premisa, advertencias de contenido y revista de origen.</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p>Por esa razón, <strong>Chainsaw Man y Tokyo Revengers no pertenecen a una lista de seinen</strong>: ambas obras se publicaron en cabeceras shonen. Que tengan escenas violentas o lectores adultos no cambia su demografía editorial.</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2 class="wp-block-heading">Seis series para orientarse</h2>
<!-- /wp:heading -->

<!-- wp:list -->
<ul class="wp-block-list"><!-- wp:list-item -->
<li><strong><a href="https://akibara.cl/serie/maximum-berserk/">Berserk</a>:</strong> fantasía oscura, trauma y supervivencia. Tiene contenido gráfico para adultos; los 21 Maximum de Panini cubren los tomos japoneses 1 al 42.</li>
<!-- /wp:list-item --><!-- wp:list-item -->
<li><strong><a href="https://akibara.cl/serie/vagabond/">Vagabond</a>:</strong> ficción histórica sobre Miyamoto Musashi, publicada en Morning. Su foco está en el combate, la identidad y el aprendizaje.</li>
<!-- /wp:list-item --><!-- wp:list-item -->
<li><strong><a href="https://akibara.cl/serie/monster/">Monster</a>:</strong> suspenso de Naoki Urasawa sobre un médico que intenta corregir una decisión con consecuencias enormes.</li>
<!-- /wp:list-item --><!-- wp:list-item -->
<li><strong><a href="https://akibara.cl/serie/20th-century-boys/">20th Century Boys</a>:</strong> misterio coral sobre memoria, amistad y una amenaza construida desde la infancia.</li>
<!-- /wp:list-item --><!-- wp:list-item -->
<li><strong><a href="https://akibara.cl/serie/golden-kamuy/">Golden Kamuy</a>:</strong> aventura histórica, supervivencia y cultura ainu, serializada en Weekly Young Jump.</li>
<!-- /wp:list-item --><!-- wp:list-item -->
<li><strong><a href="https://akibara.cl/serie/atelier-of-witch-hat/">Atelier of Witch Hat</a>:</strong> fantasía publicada por Kodansha en Morning Two; demuestra que seinen no significa necesariamente violencia extrema.</li>
<!-- /wp:list-item --></ul>
<!-- /wp:list -->

<!-- wp:heading -->
<h2 class="wp-block-heading">Cómo elegir</h2>
<!-- /wp:heading -->

<!-- wp:list -->
<ul class="wp-block-list"><!-- wp:list-item -->
<li>Para fantasía oscura y contenido gráfico: Berserk.</li>
<!-- /wp:list-item --><!-- wp:list-item -->
<li>Para ficción histórica: Vagabond o Golden Kamuy.</li>
<!-- /wp:list-item --><!-- wp:list-item -->
<li>Para suspenso: Monster o 20th Century Boys.</li>
<!-- /wp:list-item --><!-- wp:list-item -->
<li>Para fantasía de aprendizaje: Atelier of Witch Hat.</li>
<!-- /wp:list-item --></ul>
<!-- /wp:list -->

<!-- wp:paragraph -->
<p>Las cifras de catálogo deben leerse con su eje: Akibara registra 21 volúmenes de Maximum Berserk, no 21 tomos japoneses. La obra original tiene 43 tomos en Japón. Consulta cada página de serie para stock actual.</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2 class="wp-block-heading">Fuentes oficiales consultadas</h2>
<!-- /wp:heading -->

<!-- wp:list -->
<ul class="wp-block-list"><!-- wp:list-item -->
<li><a href="https://www.hakusensha.co.jp/comicslist/75530/" target="_blank" rel="noopener">Hakusensha: Berserk 43</a>.</li>
<!-- /wp:list-item --><!-- wp:list-item -->
<li><a href="https://morning.kodansha.co.jp/c/vagabond.html" target="_blank" rel="noopener">Kodansha Morning: Vagabond</a> y <a href="https://www.kodansha.co.jp/comic/products/0000425341" target="_blank" rel="noopener">Kodansha: Atelier of Witch Hat</a>.</li>
<!-- /wp:list-item --><!-- wp:list-item -->
<li><a href="https://youngjump.jp/goldenkamuy/" target="_blank" rel="noopener">Weekly Young Jump: Golden Kamuy</a>.</li>
<!-- /wp:list-item --><!-- wp:list-item -->
<li><a href="https://www.ivrea.com.ar/titulo/chainsaw-man/" target="_blank" rel="noopener">Ivrea: Chainsaw Man identificado como shonen</a>.</li>
<!-- /wp:list-item --></ul>
<!-- /wp:list -->`,
    changelog: [
      'Definió seinen como demografía editorial y no como género o clasificación de edad.',
      'Retiró Chainsaw Man y Tokyo Revengers del listado seinen.',
      'Los reemplazó por Vagabond, Monster, 20th Century Boys, Golden Kamuy y Atelier.',
      'Corrigió Maximum Berserk a 21 volúmenes de catálogo que cubren 42 tomos japoneses.',
      'Eliminó el encuadre de superioridad frente al shonen.'
    ]
  },
  {
    post_id: 23323,
    post_title: 'My Hero Academia: orden y tomos del manga completo',
    post_excerpt: 'My Hero Academia terminó en 42 tomos. El anime cerró con el episodio 170 y el especial More; si viste ambos, no queda manga principal por continuar.',
    rank_math_title: 'My Hero Academia: orden y 42 tomos del manga',
    rank_math_description: 'My Hero Academia está completa en 42 tomos. El anime llegó al capítulo 430 y el especial More adaptó el 431; no hay un capítulo principal posterior.',
    focus_keyword: 'my hero academia manga,my hero academia 42 tomos,orden my hero academia',
    featured_image_alt: 'Izuku Midoriya con el uniforme de héroe de My Hero Academia',
    post_content: `<!-- wp:paragraph -->
<p><strong>My Hero Academia está completa en 42 tomos y se lee del 1 al 42.</strong> Kohei Horikoshi cerró la historia principal de Izuku Midoriya y la clase 1-A; Shueisha publicó el volumen final en Japón el 4 de diciembre de 2024.</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2 class="wp-block-heading">De qué trata</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>En un mundo donde la mayoría de las personas nace con una habilidad llamada don, Midoriya no tiene ninguna. Su encuentro con All Might le abre la posibilidad de heredar One For All y entrar a U.A., una escuela que forma héroes. La serie usa ese aprendizaje para discutir responsabilidad, fama, instituciones y el costo de convertir a una persona en símbolo.</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2 class="wp-block-heading">Cómo se organiza la lectura</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>No hay saltos de numeración ni una precuela obligatoria. La ruta principal es sencilla:</p>
<!-- /wp:paragraph -->

<!-- wp:list -->
<ul class="wp-block-list"><!-- wp:list-item -->
<li><strong>Tomos 1 en adelante:</strong> ingreso a U.A., primeras prácticas y presentación del conflicto con los villanos.</li>
<!-- /wp:list-item --><!-- wp:list-item -->
<li><strong>Tramo medio:</strong> licencias, pasantías, cambios en la sociedad y crecimiento de la Liga de Villanos.</li>
<!-- /wp:list-item --><!-- wp:list-item -->
<li><strong>Tramo final:</strong> guerra abierta, consecuencias para los héroes y cierre en el tomo 42.</li>
<!-- /wp:list-item --></ul>
<!-- /wp:list -->

<!-- wp:paragraph -->
<p>Los títulos derivados —Vigilantes, Team-Up Missions y novelas— amplían el mundo, pero no son necesarios para entender la serie principal.</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2 class="wp-block-heading">Si vienes del anime</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>La serie televisiva terminó el <strong>13 de diciembre de 2025</strong> con el episodio 170. El sitio oficial confirma que ese capítulo cerró la adaptación en el capítulo 430 del manga.</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p>Después se emitió el especial 170+1, <em>More</em>, el <strong>2 de mayo de 2026</strong>. Adapta el capítulo 431, un episodio adicional que está al final del tomo 42.</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p>Si viste el final televisivo, pero no <em>More</em>, lee el capítulo 431 del tomo 42. Si ya viste ambos, <strong>no queda un capítulo posterior de la serie principal por continuar</strong>. Para comparar escenas abreviadas y el cierre en papel, una ruta razonable es leer los tomos 40 al 42; empezar en el tomo 1 sigue siendo la opción completa.</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2 class="wp-block-heading">Edición y disponibilidad</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>El corte de catálogo de Akibara registra los 42 tomos de la edición de Ivrea Argentina. “Catalogado” no significa que todos estén en stock al mismo tiempo: revisa <a href="https://akibara.cl/serie/my-hero-academia/">My Hero Academia en Akibara</a> para ver agotados, reposiciones y preventas.</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2 class="wp-block-heading">Fuentes oficiales consultadas</h2>
<!-- /wp:heading -->

<!-- wp:list -->
<ul class="wp-block-list"><!-- wp:list-item -->
<li><a href="https://www.shueisha.co.jp/books/items/contents.html?isbn=978-4-08-884349-0&amp;mode=1" target="_blank" rel="noopener">Shueisha: ficha del tomo 42</a>.</li>
<!-- /wp:list-item --><!-- wp:list-item -->
<li><a href="https://www.shonenjump.com/j/rensai/myhero.html" target="_blank" rel="noopener">Weekly Shonen Jump: página oficial del manga</a>.</li>
<!-- /wp:list-item --><!-- wp:list-item -->
<li><a href="https://heroaca.com/news/26569/" target="_blank" rel="noopener">Sitio oficial: episodio 170 hasta el capítulo 430 y anuncio de More</a>.</li>
<!-- /wp:list-item --><!-- wp:list-item -->
<li><a href="https://heroaca.com/news/27407/" target="_blank" rel="noopener">Sitio oficial: More emitido el 2 de mayo de 2026</a>.</li>
<!-- /wp:list-item --></ul>
<!-- /wp:list -->`,
    changelog: [
      'Conservó y respaldó el total correcto de 42 tomos completos.',
      'Eliminó fórmulas promocionales heredadas y elogios absolutos.',
      'Separó serie principal de obras derivadas.',
      'Actualizó el anime: final el 13 de diciembre de 2025 y especial More emitido el 2 de mayo de 2026.',
      'Aclaró que More adapta el capítulo 431 y que no queda un capítulo principal posterior si ya se vio el especial.',
      'Separó catálogo completo de disponibilidad inmediata.'
    ]
  },
  {
    post_id: 23324,
    post_title: 'Blue Lock: de qué trata y cuántos tomos tiene',
    post_excerpt: 'Blue Lock convierte el fútbol en una selección de delanteros. Revisamos su premisa, los 40 tomos japoneses y la diferencia con la edición de Ivrea.',
    rank_math_title: 'Blue Lock: de qué trata y cuántos tomos tiene',
    rank_math_description: 'Blue Lock tiene 40 tomos publicados en Japón a agosto de 2026. Conoce su premisa, autores, adaptación y el avance de Ivrea en Akibara.',
    focus_keyword: 'blue lock manga,cuántos tomos tiene blue lock,blue lock chile',
    featured_image_alt: 'Yoichi Isagi entrenando dentro del proyecto Blue Lock',
    post_content: `<!-- wp:paragraph -->
<p><strong>Blue Lock</strong> es un manga de fútbol escrito por Muneyuki Kaneshiro y dibujado por Yusuke Nomura. Su punto de partida no es un campeonato escolar: 300 delanteros juveniles entran a un programa de selección que busca formar al atacante de la selección japonesa.</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2 class="wp-block-heading">Por qué se siente distinto a otros mangas deportivos</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>La competencia se construye alrededor de decisiones individuales: leer el espacio, definir bajo presión y desarrollar una herramienta propia. Yoichi Isagi no avanza solo por entrenar más; debe entender qué aporta a una jugada y cómo cambia frente a rivales que también evolucionan.</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p>Eso acerca la lectura a un torneo de eliminación y a un relato psicológico. Sigue siendo fútbol, pero la tensión nace del puesto, la jerarquía y la posibilidad de quedar fuera.</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2 class="wp-block-heading">Tomos en Japón y en español</h2>
<!-- /wp:heading -->

<!-- wp:list -->
<ul class="wp-block-list"><!-- wp:list-item -->
<li><strong>Japón:</strong> Kodansha publicó el tomo 40 el 17 de agosto de 2026.</li>
<!-- /wp:list-item --><!-- wp:list-item -->
<li><strong>Akibara:</strong> el snapshot de catálogo registra 37 tomos de Ivrea Argentina.</li>
<!-- /wp:list-item --><!-- wp:list-item -->
<li><strong>Estado:</strong> la serie continúa; 37 no es el total japonés ni una promesa de stock simultáneo.</li>
<!-- /wp:list-item --></ul>
<!-- /wp:list -->

<!-- wp:paragraph -->
<p>Blue Lock encabezó el ranking anual de series de manga de Oricon en 2023 con unos 10,5 millones de ejemplares estimados durante el periodo medido. Ese dato tiene fuente y año; no permite llamarlo el manga deportivo más vendido de la historia.</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2 class="wp-block-heading">Anime y película en 2026</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>La película de acción real se estrenó en Japón el 7 de agosto de 2026. La tercera temporada del anime fue anunciada con el título <em>Blue Lock: Neo Egoist League</em>. Al cierre de esta revisión, el sitio oficial confirma el proyecto, pero no publica una fecha de estreno; por eso no incluimos una fecha tomada de terceros.</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2 class="wp-block-heading">Dónde empezar</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Para leer la historia completa, empieza en el tomo 1. Si vienes del anime, compara el último episodio visto con el índice del volumen: las adaptaciones pueden cerrar una escena a mitad de tomo. La <a href="https://akibara.cl/serie/blue-lock/">página de Blue Lock en Akibara</a> muestra qué números están disponibles o en preventa.</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2 class="wp-block-heading">Fuentes oficiales consultadas</h2>
<!-- /wp:heading -->

<!-- wp:list -->
<ul class="wp-block-list"><!-- wp:list-item -->
<li><a href="https://www.kodansha.co.jp/comic/products/0000429942" target="_blank" rel="noopener">Kodansha: ficha del tomo 40</a>.</li>
<!-- /wp:list-item --><!-- wp:list-item -->
<li><a href="https://www.oricon.co.jp/special/65793/" target="_blank" rel="noopener">Oricon: ranking anual de libros y manga 2023</a>.</li>
<!-- /wp:list-item --><!-- wp:list-item -->
<li><a href="https://bluelock-movie.jp/" target="_blank" rel="noopener">Sitio oficial de la película</a> y <a href="https://tv.bluelock-pr.com/news/170/" target="_blank" rel="noopener">sitio oficial de la tercera temporada</a>.</li>
<!-- /wp:list-item --></ul>
<!-- /wp:list -->`,
    changelog: [
      'Actualizó Japón a 40 tomos y mantuvo 37 como eje específico del catálogo de Akibara.',
      'Eliminó el superlativo no demostrable sobre el manga deportivo más vendido.',
      'Conservó el dato verificable de Oricon 2023 con periodo y magnitud.',
      'Actualizó la película al pasado y dejó la temporada 3 sin fecha no oficial.',
      'Retiró cifras de circulación sin respaldo primario visible.'
    ]
  },
  {
    post_id: 23325,
    post_title: 'Demon Slayer: orden de lectura y sus 23 tomos',
    post_excerpt: 'Demon Slayer está completo en 23 tomos. Tras Entrenamiento Hashira sigue en el capítulo 140; tras la primera película del Castillo Infinito, en el 158.',
    rank_math_title: 'Demon Slayer: orden de lectura y 23 tomos',
    rank_math_description: 'Demon Slayer tiene 23 tomos. Tras Entrenamiento Hashira continúa en el tomo 16, capítulo 140; tras la primera película, en el tomo 18, capítulo 158.',
    focus_keyword: 'demon slayer manga,orden demon slayer,demon slayer 23 tomos',
    featured_image_alt: 'Tanjiro y Nezuko en el camino de Demon Slayer',
    post_content: `<!-- wp:paragraph -->
<p><strong>Demon Slayer está completo en 23 tomos y se lee del 1 al 23.</strong> Koyoharu Gotouge cerró la historia de Tanjiro Kamado, Nezuko y el Cuerpo de Exterminio de Demonios sin series intermedias obligatorias.</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2 class="wp-block-heading">De qué trata</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Después del ataque que mata a su familia y transforma a Nezuko en demonio, Tanjiro busca una cura y se entrena como espadachín. La serie alterna combates con historias sobre los personajes que participan en ellos, pero mantiene un objetivo central desde el primer volumen.</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2 class="wp-block-heading">Orden del manga</h2>
<!-- /wp:heading -->

<!-- wp:list -->
<ul class="wp-block-list"><!-- wp:list-item -->
<li>Empieza en el tomo 1 y continúa por numeración hasta el 23.</li>
<!-- /wp:list-item --><!-- wp:list-item -->
<li>Las historias derivadas y novelas son complementarias; no interrumpen la ruta principal.</li>
<!-- /wp:list-item --><!-- wp:list-item -->
<li>La edición de Ivrea Argentina conserva la lectura de derecha a izquierda.</li>
<!-- /wp:list-item --></ul>
<!-- /wp:list -->

<!-- wp:heading -->
<h2 class="wp-block-heading">Dónde continuar después del anime</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>La temporada de <strong>Entrenamiento Hashira</strong> llega hasta el capítulo 139. Para seguir desde allí, abre el <strong>tomo 16 y continúa en el capítulo 140</strong>. Leer el tomo desde el inicio repite algunos capítulos, pero mantiene la transición completa.</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p>El Castillo Infinito se adapta como una trilogía de películas. La primera, <em>Infinity Castle – Parte 1: El regreso de Akaza</em>, se estrenó en Japón el 18 de julio de 2025. Si ya la viste, continúa en el <strong>capítulo 158, dentro del tomo 18</strong>.</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p>Esta equivalencia no aparece como tabla en el sitio de la película: sale de comparar su escena final con el índice oficial de Shueisha. El tomo 18 comienza en el capítulo 152 y el 158 está dentro del mismo volumen; comprarlo completo implica releer los capítulos 152 al 157, pero evita empezar a mitad de libro.</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2 class="wp-block-heading">Edición y disponibilidad</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>El corte de catálogo de Akibara registra los 23 tomos de Ivrea Argentina. La obra está completa; el stock de cada número puede variar. Consulta <a href="https://akibara.cl/serie/demon-slayer-kimetsu-no-yaiba/">Demon Slayer en Akibara</a> para ver el estado actual.</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2 class="wp-block-heading">Fuentes oficiales consultadas</h2>
<!-- /wp:heading -->

<!-- wp:list -->
<ul class="wp-block-list"><!-- wp:list-item -->
<li><a href="https://www.shueisha.co.jp/books/search/search.html?titleauthor=%E9%AC%BC%E6%BB%85%E3%81%AE%E5%88%83" target="_blank" rel="noopener">Shueisha: listado de tomos de Demon Slayer</a>.</li>
<!-- /wp:list-item --><!-- wp:list-item -->
<li><a href="https://kimetsu.com/anime/mugenjyohen_movie/news/?article_id=70220" target="_blank" rel="noopener">Sitio oficial: primera película del Castillo Infinito</a>.</li>
<!-- /wp:list-item --><!-- wp:list-item -->
<li><a href="https://www.shueisha.co.jp/books/items/contents.html?jdcn=08882141880723315501" target="_blank" rel="noopener">Shueisha: índice del tomo 18, capítulos 152 al 160</a>.</li>
<!-- /wp:list-item --><!-- wp:list-item -->
<li><a href="https://www.ivrea.com.ar/titulo/demon-slayer/" target="_blank" rel="noopener">Ivrea Argentina: ficha de la edición</a>.</li>
<!-- /wp:list-item --></ul>
<!-- /wp:list -->`,
    changelog: [
      'Cambió el título y los metadatos para retirar el reclamo absoluto heredado.',
      'Conservó el total correcto de 23 tomos y aclaró el orden lineal.',
      'Actualizó el anime con la primera película del Castillo Infinito.',
      'Añadió la continuación tras Entrenamiento Hashira: tomo 16, capítulo 140.',
      'Añadió la continuación tras la primera película: tomo 18, capítulo 158, como equivalencia editorial explícita.',
      'Retiró promesas de maratón, universalidad y stock permanente.'
    ]
  },
  {
    post_id: 23326,
    post_title: 'Hunter × Hunter: arcos, tomos y regreso del manga',
    post_excerpt: 'Hunter × Hunter sigue abierto: Japón publicó el tomo 39 en julio de 2026, mientras Ivrea lleva 38. Explicamos los arcos y dónde seguir tras el anime.',
    rank_math_title: 'Hunter × Hunter: arcos, tomos y regreso del manga',
    rank_math_description: 'Hunter × Hunter tiene 39 tomos en Japón y 38 en Ivrea. Revisa sus arcos, el estado de publicación y dónde continuar tras el anime de 2011.',
    focus_keyword: 'hunter x hunter manga,hunter x hunter tomo 39,continuar hunter x hunter',
    featured_image_alt: 'Gon, Killua, Kurapika y Leorio en Hunter × Hunter',
    post_content: `<!-- wp:paragraph -->
<p><strong>Hunter × Hunter sigue en publicación.</strong> Shueisha lanzó el tomo 39 en Japón el 3 de julio de 2026, de modo que ya no corresponde hablar de un regreso futuro o incierto como si no hubiera ocurrido. La edición de Ivrea Argentina registra 38 tomos publicados en esta revisión.</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2 class="wp-block-heading">De qué trata</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Gon Freecss decide convertirse en Hunter para encontrar a su padre. En el examen conoce a Killua, Kurapika y Leorio. A partir de esa aventura inicial, Yoshihiro Togashi cambia de registro: torneo, mafia, juego con reglas, guerra y política conviven dentro de la misma serie.</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2 class="wp-block-heading">Arcos principales</h2>
<!-- /wp:heading -->

<!-- wp:list -->
<ul class="wp-block-list"><!-- wp:list-item -->
<li>Examen Hunter y familia Zoldyck.</li>
<!-- /wp:list-item --><!-- wp:list-item -->
<li>Torre Celestial y presentación del Nen.</li>
<!-- /wp:list-item --><!-- wp:list-item -->
<li>Yorknew City y la Brigada Fantasma.</li>
<!-- /wp:list-item --><!-- wp:list-item -->
<li>Greed Island.</li>
<!-- /wp:list-item --><!-- wp:list-item -->
<li>Hormigas Quimera y Elección del presidente.</li>
<!-- /wp:list-item --><!-- wp:list-item -->
<li>Expedición al Continente Oscuro y Guerra de Sucesión, todavía en curso.</li>
<!-- /wp:list-item --></ul>
<!-- /wp:list -->

<!-- wp:heading -->
<h2 class="wp-block-heading">Dónde seguir después del anime de 2011</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>La serie de Madhouse termina tras la Elección del presidente. Para continuar sin saltos, empieza en el <strong>capítulo 340, incluido al final del tomo 32</strong>. El tomo 33 comienza en el capítulo 341; empezar directamente allí omite el 340. Leer desde el inicio sigue siendo válido si quieres comparar el ritmo, la narración y detalles que el anime reorganiza.</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2 class="wp-block-heading">Estado de publicación</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>El tomo 39 confirma material posterior al 38, pero no permite prometer una periodicidad semanal estable. La salud de Togashi ha producido pausas largas y Shueisha comunica cada regreso. Para una guía que no envejezca, registramos tomos publicados y enlazamos la página oficial en vez de proyectar capítulos futuros.</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p>En Akibara, el snapshot registra 38 tomos de Ivrea Argentina. Esto coincide con la edición argentina publicada al corte, pero está un volumen detrás de Japón. Revisa <a href="https://akibara.cl/serie/hunter-x-hunter/">Hunter × Hunter en Akibara</a> para disponibilidad.</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2 class="wp-block-heading">Fuentes oficiales consultadas</h2>
<!-- /wp:heading -->

<!-- wp:list -->
<ul class="wp-block-list"><!-- wp:list-item -->
<li><a href="https://www.shueisha.co.jp/books/items/contents_amp.html?isbn=978-4-08-885174-7&amp;pubDate=20260601" target="_blank" rel="noopener">Shueisha: ficha del tomo 39</a>.</li>
<!-- /wp:list-item --><!-- wp:list-item -->
<li>Shueisha: índices del <a href="https://www.shueisha.co.jp/books/items/contents.html?jdcn=08870698872571325501" target="_blank" rel="noopener">tomo 32, que termina en el capítulo 340</a>, y del <a href="https://www.shueisha.co.jp/books/items/contents.html?jdcn=08880352872571325501" target="_blank" rel="noopener">tomo 33, que empieza en el 341</a>.</li>
<!-- /wp:list-item --><!-- wp:list-item -->
<li><a href="https://www.shonenjump.com/j/rensai/hunter/" target="_blank" rel="noopener">Weekly Shonen Jump: página oficial de Hunter × Hunter</a>.</li>
<!-- /wp:list-item --><!-- wp:list-item -->
<li><a href="https://www.ivrea.com.ar/titulo/hunter-x-hunter/" target="_blank" rel="noopener">Ivrea Argentina: estado de la edición</a>.</li>
<!-- /wp:list-item --></ul>
<!-- /wp:list -->`,
    changelog: [
      'Reemplazó el pronóstico de regreso por el tomo 39 publicado en julio de 2026.',
      'Separó 39 tomos japoneses de 38 tomos publicados por Ivrea y registrados en Akibara.',
      'Evitó prometer periodicidad semanal o capítulos futuros.',
      'Corrigió la continuación tras el anime: capítulo 340 al final del tomo 32; el tomo 33 comienza en el 341.',
      'Retiró etiquetas valorativas y juicios de genialidad.'
    ]
  }
];

const expectedIds = [22353, 22607, 22610, 22638, 22639, 22640, 22641, 22642, 23323, 23324, 23325, 23326];
const actualIds = posts.map((post) => post.post_id);
if (JSON.stringify(actualIds) !== JSON.stringify(expectedIds)) {
  throw new Error(`Unexpected post IDs: ${actualIds.join(', ')}`);
}

const banned = /obra maestra|gu[ií]a definitiva|imprescindible|todo lo que necesitas|sin duda|\bjoya\b|la mejor forma|los mejores manga|uno de los mejores|respuesta definitiva|m[aá]s bello/iu;

for (const post of posts) {
  const source = sourceById.get(post.post_id);
  if (!source) throw new Error(`Missing source post ${post.post_id}`);
  post.post_slug = source.post_slug;
  post.post_status = source.post_status;

  for (const field of ['post_title', 'post_content', 'post_excerpt', 'rank_math_title', 'rank_math_description', 'focus_keyword', 'featured_image_alt']) {
    if (typeof post[field] !== 'string' || !post[field].trim()) {
      throw new Error(`Post ${post.post_id}: empty ${field}`);
    }
  }
  if ([...post.rank_math_title].length > 60) {
    throw new Error(`Post ${post.post_id}: Rank Math title is ${[...post.rank_math_title].length} chars`);
  }
  const descriptionLength = [...post.rank_math_description].length;
  if (descriptionLength < 80 || descriptionLength > 155) {
    throw new Error(`Post ${post.post_id}: description is ${descriptionLength} chars`);
  }
  const focusCount = post.focus_keyword.split(',').map((value) => value.trim()).filter(Boolean).length;
  if (focusCount < 1 || focusCount > 5) {
    throw new Error(`Post ${post.post_id}: focus keyword count is ${focusCount}`);
  }
  if (!post.post_content.includes('Fuentes oficiales consultadas')) {
    throw new Error(`Post ${post.post_id}: missing official sources section`);
  }
  if ((post.post_content.match(/https:\/\//g) ?? []).length < 3) {
    throw new Error(`Post ${post.post_id}: fewer than three linked sources/internal references`);
  }
  const editorialFields = [post.post_title, post.post_content, post.post_excerpt, post.rank_math_title, post.rank_math_description, post.featured_image_alt].join('\n');
  const hit = editorialFields.match(banned);
  if (hit) throw new Error(`Post ${post.post_id}: banned phrase ${hit[0]}`);
  if (!Array.isArray(post.changelog) || post.changelog.length < 3) {
    throw new Error(`Post ${post.post_id}: incomplete changelog`);
  }
}

const report = {
  schema_version: 1,
  report_type: 'akibara_blog_first_batch_editorial_remediation',
  generated_at: new Date().toISOString(),
  environment: 'local-only',
  production_writes: false,
  source_snapshot: '.local-reports/blog-audit-20260818/production-snapshot.json',
  source_snapshot_sha256: snapshotSha256,
  source_payload_sha256: snapshot.payload_sha256,
  scope: {
    post_count: posts.length,
    post_ids: expectedIds,
    fields_are_after_state: true,
    slugs_and_status_preserved: true
  },
  validation: {
    required_fields_complete: true,
    rank_math_title_max_chars: 60,
    rank_math_description_min_chars: 80,
    rank_math_description_max_chars: 155,
    focus_keyword_count_range: [1, 5],
    banned_editorial_phrases_absent: true,
    official_sources_embedded_in_each_post: true
  },
  posts
};

await writeFile(outputPath, `${JSON.stringify(report, null, 2)}\n`, { mode: 0o600 });
const outputBuffer = await readFile(outputPath);
const outputSha256 = createHash('sha256').update(outputBuffer).digest('hex');
await writeFile(hashPath, `${outputSha256}  first-batch-remediation.json\n`, { mode: 0o600 });
console.log(JSON.stringify({ output: outputPath, hash_file: hashPath, posts: posts.length, source_snapshot_sha256: snapshotSha256, output_sha256: outputSha256 }, null, 2));
