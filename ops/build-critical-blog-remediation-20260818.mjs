#!/usr/bin/env node

import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';

const ROOT = path.resolve(path.dirname(new URL(import.meta.url).pathname), '..');
const SNAPSHOT_PATH = path.join(
	ROOT,
	'.local-reports/blog-audit-20260818/production-snapshot.json'
);
const OUTPUT_PATH = path.join(
	ROOT,
	'.local-reports/blog-audit-20260818/critical-remediation.json'
);

function fail(message) {
	throw new Error(`Critical blog remediation failed: ${message}`);
}

function sha256(value) {
	return crypto.createHash('sha256').update(value).digest('hex');
}

function canonicalJson(value) {
	if (Array.isArray(value)) {
		return `[${value.map((item) => canonicalJson(item)).join(',')}]`;
	}
	if (value && typeof value === 'object') {
		return `{${Object.keys(value).sort().map((key) => (
			`${JSON.stringify(key)}:${canonicalJson(value[key])}`
		)).join(',')}}`;
	}
	return JSON.stringify(value);
}

function writeAtomic(file, value) {
	if (fs.existsSync(file)) fail(`refusing to overwrite ${file}`);
	fs.mkdirSync(path.dirname(file), { recursive: true, mode: 0o700 });
	const temporary = `${file}.tmp-${process.pid}`;
	fs.writeFileSync(temporary, `${JSON.stringify(value, null, 2)}\n`, {
		encoding: 'utf8',
		mode: 0o600,
		flag: 'wx',
	});
	fs.renameSync(temporary, file);
	fs.chmodSync(file, 0o600);
}

const remediations = {
	23329: {
		post_title: '¿Qué es el manga? Historia, lectura y formatos',
		post_excerpt: 'Una introducción al manga: origen del término, sentido de lectura, demografías editoriales, formatos y cómo elegir una primera serie en Chile.',
		rank_math_title: 'Qué es el manga: historia, lectura y formatos | Akibara',
		rank_math_description: 'Qué es el manga, cómo se lee, qué significan shonen, seinen y shojo, y qué formatos encontrarás al comprar una edición en español.',
		rank_math_focus_keyword: 'qué es el manga, cómo leer manga, tipos de manga',
		featured_image_alt: 'Qué es el manga: guía de lectura y formatos',
		post_content: `<!-- wp:paragraph -->
<p>El manga no es un género único ni una forma de contar reservada a un tipo de lector. Es el nombre que usamos fuera de Japón para hablar de la historieta japonesa, un medio que abarca desde comedia escolar hasta historia, deporte, terror o cocina. Esta guía reúne lo esencial para reconocer una edición, leerla y elegir por dónde empezar.</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2 class="wp-block-heading">¿Qué significa manga?</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p><em>Manga</em> se escribe 漫画. A veces se traduce como “dibujos caprichosos”, pero esa fórmula no funciona como una definición histórica exacta. El término tuvo distintos usos y Katsushika Hokusai lo empleó en 1814 para sus libros de bocetos <em>Hokusai Manga</em>. La historieta japonesa moderna se consolidó durante el siglo XX y también recibió influencias extranjeras, pero sus antecedentes son anteriores a la posguerra.</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p>En Japón, la palabra puede referirse a historietas en general. En Chile y otros países suele identificar las obras creadas para el mercado japonés y sus ediciones traducidas.</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2 class="wp-block-heading">¿Cómo se lee un manga?</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>La mayoría de los manga japoneses se compone para leer de derecha a izquierda. Muchas ediciones en español conservan ese sentido: el libro se abre por el lado que, en una novela occidental, parecería la contraportada; dentro de cada página se siguen primero las viñetas y luego los globos de derecha a izquierda y de arriba hacia abajo.</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p>No es una regla universal para toda historieta asiática ni para toda edición traducida. Si tienes dudas, revisa las primeras páginas: las editoriales suelen incluir una indicación de lectura.</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2 class="wp-block-heading">Shonen, seinen, shojo y josei no son géneros</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Estas palabras describen el público editorial de la revista o plataforma japonesa donde apareció una serie; no determinan por sí solas su tema ni funcionan como una clasificación de edad chilena.</p>
<!-- /wp:paragraph -->

<!-- wp:list -->
<ul class="wp-block-list">
<li><strong>Shonen:</strong> publicación orientada principalmente a lectores adolescentes masculinos. <a href="https://akibara.cl/serie/one-piece/">One Piece</a> y <a href="https://akibara.cl/serie/jujutsu-kaisen/">Jujutsu Kaisen</a> son ejemplos conocidos.</li>
<li><strong>Seinen:</strong> publicación orientada principalmente a hombres adultos. Puede incluir acción, comedia, vida cotidiana o cocina; no significa simplemente “más violento”. <a href="https://akibara.cl/serie/maximum-berserk/">Berserk</a> y <a href="https://akibara.cl/serie/golden-kamuy/">Golden Kamuy</a> pertenecen a este grupo.</li>
<li><strong>Shojo:</strong> publicación orientada principalmente a adolescentes femeninas. <a href="https://akibara.cl/serie/nana/">Nana</a> se publicó en la revista shojo <em>Cookie</em>, aunque trate relaciones adultas.</li>
<li><strong>Josei:</strong> publicación orientada principalmente a mujeres adultas.</li>
<li><strong>Kodomo:</strong> obras concebidas para público infantil.</li>
</ul>
<!-- /wp:list -->

<!-- wp:paragraph -->
<p>Una persona puede leer cualquiera de estas categorías. Para decidir si una obra es adecuada para regalar, conviene revisar la sinopsis, la clasificación de la edición y sus advertencias de contenido.</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2 class="wp-block-heading">Formatos que encontrarás en español</h2>
<!-- /wp:heading -->

<!-- wp:list -->
<ul class="wp-block-list">
<li><strong>Tankobon:</strong> volumen recopilatorio estándar. El tamaño, el número de páginas y la presencia de sobrecubierta dependen de cada editorial.</li>
<li><strong>Omnibus o 2 en 1 / 3 en 1:</strong> reúne varios volúmenes de la edición original. La serie <a href="https://akibara.cl/serie/one-piece-no/">One Piece 3 en 1</a> es un ejemplo.</li>
<li><strong>Kanzenban:</strong> reedición de una obra terminada, normalmente en mayor tamaño y con una nueva organización de tomos. Sus extras varían según la licencia.</li>
<li><strong>Deluxe o Master Edition:</strong> nombres comerciales para ediciones de mayor tamaño o encuadernación. No representan un estándar único: hay que comparar la ficha de cada producto.</li>
<li><strong>Bunko:</strong> edición compacta japonesa. No todas las licencias en español la reproducen.</li>
</ul>
<!-- /wp:list -->

<!-- wp:heading -->
<h2 class="wp-block-heading">Manga, manhwa y manhua</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Los tres términos remiten a tradiciones editoriales y lingüísticas distintas: manga en Japón, <a href="https://akibara.cl/manhwa/">manhwa</a> en Corea y manhua en el ámbito chino. El país de origen no garantiza un formato: existen obras impresas y digitales, en color y en blanco y negro, con diferentes sentidos de lectura. Los webtoons coreanos de desplazamiento vertical son muy visibles, pero no representan a todo el manhwa.</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2 class="wp-block-heading">Cómo elegir una primera serie</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Empieza por una historia que ya se acerque a lo que disfrutas en cine, novelas o videojuegos. Si prefieres una colección cerrada y lineal, <a href="https://akibara.cl/serie/demon-slayer-kimetsu-no-yaiba/">Demon Slayer</a> tiene 23 tomos. Si buscas deporte, prueba <a href="https://akibara.cl/serie/blue-lock/">Blue Lock</a>; para fantasía pausada, <a href="https://akibara.cl/serie/atelier-of-witch-hat/">Atelier of Witch Hat</a>; y para acción con humor, <a href="https://akibara.cl/serie/one-punch-man/">One-Punch Man</a>.</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p>Antes de comprar una colección completa, revisa tres datos en la ficha: editorial y país de edición, número del tomo y estado visible —Disponible, Preventa o Agotado—. No mezcles tomos de editoriales diferentes sin comprobar formato y numeración, porque cada licencia puede organizar la obra de otra manera.</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2 class="wp-block-heading">Comprar manga en Chile</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>En la sección de <a href="https://akibara.cl/manga/">manga de Akibara</a> puedes filtrar el catálogo por serie, editorial, precio y disponibilidad. La ficha de cada producto muestra los datos vigentes para esa edición. Si el título que buscas no aparece, revisa las <a href="https://akibara.cl/faq/">preguntas frecuentes</a> antes de solicitar un encargo.</p>
<!-- /wp:paragraph -->

<!-- wp:heading {"level":3} -->
<h3 class="wp-block-heading">Fuentes consultadas</h3>
<!-- /wp:heading -->

<!-- wp:list -->
<ul class="wp-block-list">
<li><a href="https://www.britishmuseum.org/blog/hokusai-father-manga">British Museum: Hokusai, father of manga?</a></li>
<li><a href="https://www.britishmuseum.org/blog/manga-brief-history-12-works">British Museum: Manga, a brief history in 12 works</a></li>
<li><a href="https://web-japan.org/trends/11_culture/pop202503_manga-magazines.html">Web Japan: manga magazines and their audiences</a></li>
</ul>
<!-- /wp:list -->`,
	},
	24977: {
		post_title: 'Preventa de manga en Chile: cómo funciona en Akibara',
		post_excerpt: 'Cómo reservar una preventa en Akibara, qué significa la fecha estimada y cuándo se despacha un pedido que combina stock y preventa.',
		rank_math_title: 'Preventa de manga en Chile: cómo funciona | Akibara',
		rank_math_description: 'Cómo funciona una preventa de manga en Akibara: fecha estimada, pedidos mixtos, despacho, seguimiento, demoras y opciones de reembolso.',
		rank_math_focus_keyword: 'preventa de manga, preventa manga Chile, reservar manga',
		featured_image_alt: 'Preparación de un pedido de preventa de manga en Chile',
		post_content: `<!-- wp:paragraph -->
<p>Una preventa permite reservar un producto que todavía no está disponible para entrega inmediata. En Akibara, la ficha indica que el tomo está en <strong>Preventa</strong> y muestra una fecha estimada de llegada a nuestra bodega. Esa fecha es una referencia logística: puede ser distinta de la fecha de publicación anunciada por la editorial.</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2 class="wp-block-heading">Qué ocurre después de reservar</h2>
<!-- /wp:heading -->

<!-- wp:list {"ordered":true} -->
<ol class="wp-block-list">
<li>Eliges un producto marcado como Preventa y completas el pago.</li>
<li>El pedido queda pendiente mientras el tomo viaja desde el país de origen y llega a Akibara.</li>
<li>Cuando el producto ingresa a bodega, enviamos un correo para avisar que está listo para despacho o retiro.</li>
<li>Si contrataste despacho, el plazo del transportista comienza una vez que el pedido completo está preparado.</li>
</ol>
<!-- /wp:list -->

<!-- wp:paragraph -->
<p>La reserva no convierte la estimación en una fecha garantizada. Una editorial puede modificar un lanzamiento y una importación puede demorarse por transporte o aduana. La ficha conserva la referencia vigente para que puedas revisar el estado.</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2 class="wp-block-heading">Qué pasa si mezclas stock y preventa</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Puedes incluir productos disponibles y preventas en una misma compra. En ese caso, el pedido se despacha cuando <strong>todos</strong> los productos estén listos. Si prefieres recibir primero lo que ya está en stock, contáctanos para coordinar envíos separados; cada despacho adicional tiene costo.</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2 class="wp-block-heading">Demoras, falta de stock y reembolso</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Si la demora supera 60 días desde la fecha estimada, o si la editorial confirma que el título quedó agotado, puedes cambiarlo por otro producto o solicitar el reembolso íntegro. Akibara te contactará por correo si el proveedor informa que no puede entregar el ejemplar reservado.</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2 class="wp-block-heading">Cómo seguir tu preventa</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Revisa la estimación en la ficha del producto y los correos asociados a tu pedido. Para pedir un envío separado o consultar una demora, utiliza el <a href="https://akibara.cl/contacto/">formulario de contacto</a> e incluye tu número de pedido; no publiques datos personales en comentarios o redes sociales.</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p>Puedes ver las reservas activas en <a href="https://akibara.cl/preventas/">Preventas</a>. Si necesitas comparar este sistema con un producto que ya está en bodega, revisa también la sección <a href="https://akibara.cl/disponibles/">Disponibles</a>.</p>
<!-- /wp:paragraph -->

<!-- wp:heading {"level":3} -->
<h3 class="wp-block-heading">Información oficial de Akibara</h3>
<!-- /wp:heading -->

<!-- wp:list -->
<ul class="wp-block-list">
<li><a href="https://akibara.cl/faq/">Preguntas frecuentes: envíos, stock y preventas</a></li>
<li><a href="https://akibara.cl/terminos-y-condiciones/">Términos y condiciones</a></li>
</ul>
<!-- /wp:list -->`,
	},
	24974: {
		post_title: 'Jujutsu Kaisen: orden para leer el manga y el tomo 0',
		post_excerpt: 'Dónde encaja Jujutsu Kaisen 0 y dos órdenes de lectura sin saltarse capítulos de la serie principal, completa en 30 tomos.',
		rank_math_title: 'Jujutsu Kaisen: orden de lectura y tomo 0 | Akibara',
		rank_math_description: 'Dónde leer Jujutsu Kaisen 0 y cómo seguir la serie principal del tomo 1 al 30 sin confundir el manga con las novelas de la franquicia.',
		rank_math_focus_keyword: 'orden Jujutsu Kaisen, Jujutsu Kaisen tomo 0, orden manga Jujutsu Kaisen',
		featured_image_alt: 'Orden de lectura de Jujutsu Kaisen y el tomo 0',
		post_content: `<!-- wp:paragraph -->
<p><strong>Respuesta breve:</strong> si vas a comenzar desde cero, lee <em>Jujutsu Kaisen 0</em> y después los tomos 1 al 30. El tomo 0 es la precuela oficial y forma parte de la historia; no es una novela ni un resumen de la película.</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2 class="wp-block-heading">Por qué existe un tomo 0</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Antes de iniciar la serie principal, Gege Akutami publicó una historia de cuatro capítulos protagonizada por Yuta Okkotsu. Shueisha la recopiló como <em>Jujutsu Kaisen 0: Tokyo Metropolitan Curse Technical School</em> y la presenta como la precuela que conecta con <em>Jujutsu Kaisen</em>.</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p>Allí aparecen Yuta, Rika, Maki, Toge, Panda, Gojo y Geto. Leerla aporta contexto para relaciones y decisiones que la serie principal retoma más adelante.</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2 class="wp-block-heading">Orden recomendado para una primera lectura</h2>
<!-- /wp:heading -->

<!-- wp:list {"ordered":true} -->
<ol class="wp-block-list">
<li><strong>Jujutsu Kaisen 0.</strong></li>
<li><strong>Jujutsu Kaisen 1 al 30.</strong></li>
</ol>
<!-- /wp:list -->

<!-- wp:paragraph -->
<p>Este orden es fácil de seguir, presenta primero la historia anterior en el tiempo y luego recorre la serie principal completa. No hay que intercalar películas, novelas ni fanbooks para entender el manga.</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2 class="wp-block-heading">Alternativa si ya empezaste por el tomo 1</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>También puedes leer los tomos 1 al 9, pasar al tomo 0 y continuar desde el 10. Esta pausa deja la precuela antes del Incidente de Shibuya, cuando su contexto empieza a ser especialmente útil. No es un orden oficial distinto: es una opción editorial para quien ya avanzó en la colección.</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2 class="wp-block-heading">Manga, novela y Movie Edition no son lo mismo</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Comprueba siempre el tipo de producto. El <strong>tomo 0 del manga</strong> contiene la historieta original de Akutami. Una ficha que diga “novela” o “Movie Edition” corresponde a un libro en prosa basado en la película y no reemplaza ese volumen.</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p>En Akibara, la colección principal disponible se organiza bajo la edición de Panini Argentina. Revisa editorial, formato y número antes de combinar ejemplares: dos licencias diferentes pueden variar en tamaño, traducción y numeración.</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2 class="wp-block-heading">Dónde revisar la colección</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>La página de <a href="https://akibara.cl/serie/jujutsu-kaisen/">Jujutsu Kaisen</a> reúne las fichas de la serie. Consulta precio, stock, despacho y medios de pago vigentes en cada producto.</p>
<!-- /wp:paragraph -->

<!-- wp:heading {"level":3} -->
<h3 class="wp-block-heading">Fuentes consultadas</h3>
<!-- /wp:heading -->

<!-- wp:list -->
<ul class="wp-block-list">
<li><a href="https://www.shueisha.co.jp/books/items/contents.html?isbn=978-4-08-881672-2">Shueisha: Jujutsu Kaisen 0</a></li>
<li><a href="https://www.viz.com/blog/posts/the-prequel-to-jujutsu-kaisen">VIZ: The Prequel to Jujutsu Kaisen</a></li>
<li><a href="https://www.viz.com/jujutsu-kaisen">VIZ: serie completa y tomo 30</a></li>
</ul>
<!-- /wp:list -->`,
	},
	25039: {
		post_title: 'Dandadan: en qué tomo seguir después de la temporada 1',
		post_excerpt: 'La primera temporada continúa en el capítulo 34 del tomo 5. Te explicamos cuánto repite el volumen y dónde seguir si ya viste la temporada 2.',
		rank_math_title: 'Dandadan: dónde seguir tras la temporada 1 | Akibara',
		rank_math_description: 'Después de la temporada 1 de Dandadan, continúa en el capítulo 34 del tomo 5. Si viste la temporada 2, el punto de lectura es otro.',
		rank_math_focus_keyword: 'Dandadan después del anime, Dandadan tomo 5, Dandadan capítulo 34',
		featured_image_alt: 'Dandadan: continuación del anime en el tomo 5',
		post_content: `<!-- wp:paragraph -->
<p><strong>Respuesta breve:</strong> después de la primera temporada de <em>Dandadan</em>, continúa en el <strong>capítulo 34</strong>, incluido en el <strong>tomo 5</strong>. El episodio 12 adapta material del comienzo de ese volumen, por lo que comprar el tomo completo implica repetir una parte breve.</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2 class="wp-block-heading">Qué contiene el tomo 5</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>La ficha oficial de Shueisha muestra que el tomo 5 reúne los capítulos 33 al 40. La primera temporada termina durante la llegada a la casa de Jiji; el capítulo 34 retoma ese punto y desarrolla lo que el episodio deja abierto.</p>
<!-- /wp:paragraph -->

<!-- wp:list -->
<ul class="wp-block-list">
<li><strong>Si quieres continuar sin volver al inicio:</strong> capítulo 34.</li>
<li><strong>Si compras en papel:</strong> tomo 5; releerás el capítulo 33 y parte de la transición.</li>
<li><strong>Si prefieres comparar la adaptación:</strong> comienza el tomo 5 desde la primera página.</li>
</ul>
<!-- /wp:list -->

<!-- wp:heading -->
<h2 class="wp-block-heading">¿Y si ya viste la temporada 2?</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>No empieces en el tomo 5 si ya terminaste los 24 episodios. La continuación editorial después de la temporada 2 está en el capítulo 72, dentro del tomo 9. Puedes revisar el detalle en nuestra <a href="https://akibara.cl/dandadan-en-que-tomo-continuar-despues-de-la-temporada-2/">guía de Dandadan después de la temporada 2</a>.</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2 class="wp-block-heading">Qué edición revisar en Chile</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Akibara reúne la edición de Ivrea Argentina en la página de <a href="https://akibara.cl/serie/dan-da-dan/">Dandadan</a>. La disponibilidad cambia, así que consulta precio, stock, despacho y medios de pago vigentes en la ficha de cada tomo. Si aparece Agotado, el aviso de reposición permite recibir un correo cuando vuelva.</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p>La serie combina acción sobrenatural, humor y escenas intensas. Si vas a regalarla, revisa la clasificación de la edición en lugar de asumir que la demografía shonen equivale a una edad determinada.</p>
<!-- /wp:paragraph -->

<!-- wp:heading {"level":3} -->
<h3 class="wp-block-heading">Fuentes consultadas</h3>
<!-- /wp:heading -->

<!-- wp:list -->
<ul class="wp-block-list">
<li><a href="https://anime-dandadan.com/story/ep12/">Sitio oficial del anime: episodio 12</a></li>
<li><a href="https://www.shueisha.co.jp/books/items/contents.html?jdcn=08X10000000020853600">Shueisha: contenido del tomo 5</a></li>
</ul>
<!-- /wp:list -->`,
	},
	27460: {
		post_title: 'Manga en Chile: 15 series comparadas en Google Trends',
		post_excerpt: 'Comparación reproducible de 15 series del catálogo en Google Trends Chile entre agosto de 2025 y agosto de 2026, con método y límites.',
		rank_math_title: 'Manga en Chile: 15 series en Google Trends | Akibara',
		rank_math_description: 'Comparamos 15 series del catálogo en Google Trends Chile, del 18 de agosto de 2025 al 18 de agosto de 2026. Resultados, método y límites.',
		rank_math_focus_keyword: 'manga Google Trends Chile, mangas buscados Chile, ranking manga Chile',
		featured_image_alt: 'Índice relativo de 15 series de manga en Google Trends Chile',
		post_content: `<!-- wp:paragraph -->
<p>¿Cómo se comparan en Google Trends 15 series que forman parte del catálogo de Akibara? Consultamos los mismos términos, en Chile y durante un periodo fijo de 53 semanas. El resultado no representa “todos los manga” ni ventas: muestra el interés relativo que Trends calculó para este grupo de candidatos.</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2 class="wp-block-heading">Resultado comparable</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>One Piece se usa como ancla con índice 100. Los demás valores indican su media relativa frente a esa serie dentro de la misma tanda. No son volúmenes absolutos de búsquedas.</p>
<!-- /wp:paragraph -->

<!-- wp:table -->
<figure class="wp-block-table"><table><thead><tr><th>Posición</th><th>Serie</th><th>Índice relativo</th></tr></thead><tbody>
<tr><td>1</td><td><a href="https://akibara.cl/serie/one-piece/">One Piece</a></td><td>100</td></tr>
<tr><td>2</td><td><a href="https://akibara.cl/serie/blue-lock/">Blue Lock</a></td><td>33,9</td></tr>
<tr><td>3</td><td><a href="https://akibara.cl/serie/jujutsu-kaisen/">Jujutsu Kaisen</a></td><td>29,0</td></tr>
<tr><td>4</td><td><a href="https://akibara.cl/serie/chainsaw-man/">Chainsaw Man</a></td><td>19,5</td></tr>
<tr><td>5</td><td><a href="https://akibara.cl/serie/maximum-berserk/">Berserk</a></td><td>13,8</td></tr>
<tr><td>6</td><td><a href="https://akibara.cl/serie/hunter-x-hunter/">Hunter x Hunter</a></td><td>9,6</td></tr>
<tr><td>7</td><td><a href="https://akibara.cl/serie/dan-da-dan/">Dandadan</a></td><td>8,7</td></tr>
<tr><td>8</td><td><a href="https://akibara.cl/serie/nana/">Nana</a></td><td>2,1</td></tr>
<tr><td>9</td><td>Haikyuu!!</td><td>1,4</td></tr>
<tr><td>10</td><td><a href="https://akibara.cl/serie/bleach-remix/">Bleach</a></td><td>1,3</td></tr>
</tbody></table></figure>
<!-- /wp:table -->

<!-- wp:heading -->
<h2 class="wp-block-heading">Cinco series sin orden fiable</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>The Climber, Tokyo Revengers, El verano en que Hikaru murió, Los diarios de la boticaria y El chico que me gusta no es un chico quedaron en el piso de resolución de Trends. Acumularon entre 2 y 9 puntos semanales en total durante 53 semanas; los ceros y el muestreo pesan demasiado para asignarles posiciones definitivas.</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p>The Climber, Tokyo Revengers y El verano en que Hikaru murió incluso obtuvieron el mismo índice al conservar tres decimales: 0,316. Presentarlos como puestos 11, 12 y 13 daría una precisión que los datos no tienen.</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2 class="wp-block-heading">Metodología</h2>
<!-- /wp:heading -->

<!-- wp:list -->
<ul class="wp-block-list">
<li><strong>Universo:</strong> las 15 series que ya estaban incluidas en el borrador de este artículo; no una selección exhaustiva del mercado chileno.</li>
<li><strong>Región y periodo:</strong> Chile, búsqueda web, todas las categorías, del 18 de agosto de 2025 al 18 de agosto de 2026.</li>
<li><strong>Consulta:</strong> cada título como término de búsqueda con el sufijo “manga”. Trends trata los términos como coincidencia amplia, no como temas.</li>
<li><strong>Tandas:</strong> hasta cinco términos por consulta. “one piece manga” aparece en las cuatro tandas como ancla común.</li>
<li><strong>Cálculo:</strong> media de los 53 índices semanales de cada serie, dividida por la media de One Piece en la misma tanda y multiplicada por 100.</li>
<li><strong>Fecha de consulta:</strong> 18 de agosto de 2026.</li>
</ul>
<!-- /wp:list -->

<!-- wp:heading -->
<h2 class="wp-block-heading">Qué se puede concluir</h2>
<!-- /wp:heading -->

<!-- wp:paragraph -->
<p>Dentro de estos términos y este periodo, One Piece tuvo una señal claramente superior. Blue Lock y Jujutsu Kaisen formaron el segundo grupo, seguidos por Chainsaw Man y Berserk. Esa comparación no demuestra calidad, intención de compra ni causalidad con un anime o una campaña.</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p>Google Trends trabaja con una muestra normalizada de consultas. Una recarga futura puede devolver cifras algo distintas, sobre todo en búsquedas pequeñas. Por eso conservamos las fechas, los términos, las URLs y la fórmula, y evitamos ordenar el grupo de bajo volumen.</p>
<!-- /wp:paragraph -->

<!-- wp:heading {"level":3} -->
<h3 class="wp-block-heading">Consultas y documentación</h3>
<!-- /wp:heading -->

<!-- wp:list -->
<ul class="wp-block-list">
<li><a href="https://trends.google.com/trends/explore?date=2025-08-18%202026-08-18&amp;geo=CL&amp;q=one%20piece%20manga,jujutsu%20kaisen%20manga,berserk%20manga,dandadan%20manga,the%20climber%20manga&amp;hl=es">Tanda A: One Piece, Jujutsu Kaisen, Berserk, Dandadan y The Climber</a></li>
<li><a href="https://trends.google.com/trends/explore?date=2025-08-18%202026-08-18&amp;geo=CL&amp;q=one%20piece%20manga,el%20chico%20que%20me%20gusta%20no%20es%20un%20chico%20manga,el%20verano%20en%20que%20hikaru%20murio%20manga,bleach%20manga,los%20diarios%20de%20la%20boticaria%20manga&amp;hl=es">Tanda B: One Piece, El chico, Hikaru, Bleach y Boticaria</a></li>
<li><a href="https://trends.google.com/trends/explore?date=2025-08-18%202026-08-18&amp;geo=CL&amp;q=one%20piece%20manga,haikyuu%20manga,chainsaw%20man%20manga,tokyo%20revengers%20manga,nana%20manga&amp;hl=es">Tanda C: One Piece, Haikyuu, Chainsaw Man, Tokyo Revengers y Nana</a></li>
<li><a href="https://trends.google.com/trends/explore?date=2025-08-18%202026-08-18&amp;geo=CL&amp;q=one%20piece%20manga,hunter%20x%20hunter%20manga,blue%20lock%20manga&amp;hl=es">Tanda D: One Piece, Hunter x Hunter y Blue Lock</a></li>
<li><a href="https://support.google.com/trends/answer/4365533?hl=es">Google Trends: cómo se ajustan y normalizan los datos</a></li>
<li><a href="https://support.google.com/trends/answer/4359550?hl=es-419">Google Trends: comparar términos de búsqueda</a></li>
</ul>
<!-- /wp:list -->`,
	},
};

function validateEntry(id, entry) {
	const required = [
		'post_title',
		'post_content',
		'post_excerpt',
		'rank_math_title',
		'rank_math_description',
		'rank_math_focus_keyword',
		'featured_image_alt',
	];
	for (const field of required) {
		if (typeof entry[field] !== 'string' || !entry[field].trim()) {
			fail(`${id} has blank ${field}`);
		}
	}
	if ([...entry.rank_math_title].length > 60) {
		fail(`${id} Rank Math title exceeds 60 characters`);
	}
	const descriptionLength = [...entry.rank_math_description].length;
	if (descriptionLength < 80 || descriptionLength > 155) {
		fail(`${id} Rank Math description length is ${descriptionLength}`);
	}
	const keywordCount = entry.rank_math_focus_keyword
		.split(',')
		.map((keyword) => keyword.trim())
		.filter(Boolean).length;
	if (keywordCount < 1 || keywordCount > 5) {
		fail(`${id} has ${keywordCount} focus keywords`);
	}
	const haystack = Object.values(entry).join('\n');
	const banned = [
		/obra(?:s)? maestra(?:s)?/iu,
		/gu[ií]a definitiva/iu,
		/imperdible/iu,
		/imprescindible/iu,
		/lectura obligatoria/iu,
		/todo lo que necesitas saber/iu,
		/claude(?: code)?/iu,
		/anthropic/iu,
	];
	for (const pattern of banned) {
		if (pattern.test(haystack)) fail(`${id} contains banned pattern ${pattern}`);
	}
	if (!entry.post_content.includes('<h2')) fail(`${id} content has no H2`);
	if (!entry.post_content.includes('href=')) fail(`${id} content has no links`);
}

function main() {
	const snapshotRaw = fs.readFileSync(SNAPSHOT_PATH, 'utf8');
	const snapshot = JSON.parse(snapshotRaw);
	if (
		snapshot.schema_version !== 1
		|| snapshot.snapshot_type !== 'akibara_blog_production_read_only'
		|| snapshot.environment !== 'production'
		|| snapshot.direct_writes !== 0
		|| !Array.isArray(snapshot.posts)
	) {
		fail('source snapshot contract is invalid');
	}
	const byId = new Map(snapshot.posts.map((post) => [post.post_id, post]));
	const posts = Object.entries(remediations).map(([idText, after]) => {
		const id = Number(idText);
		const source = byId.get(id);
		if (!source) fail(`post ${id} missing from source snapshot`);
		validateEntry(id, after);
		return {
			post_id: id,
			post_status: source.post_status,
			post_slug: source.post_slug,
			before_modified_gmt: source.modified_gmt,
			after,
			field_changes: Object.keys(after).filter((field) => {
				const before = field.startsWith('rank_math_')
					? source.rank_math[field.replace('rank_math_', '')]
					: field === 'featured_image_alt'
						? source.featured_image.alt
						: source[field];
				return String(before ?? '') !== after[field];
			}),
		};
	}).sort((a, b) => a.post_id - b.post_id);
	const payload = {
		schema_version: 1,
		remediation_type: 'akibara_blog_critical_editorial_after_values',
		generated_at: new Date().toISOString(),
		source_snapshot: path.resolve(SNAPSHOT_PATH),
		source_snapshot_payload_sha256: snapshot.payload_sha256,
		source_snapshot_file_sha256: sha256(snapshotRaw),
		direct_wordpress_writes: 0,
		posts,
	};
	payload.payload_sha256 = sha256(canonicalJson(payload));
	writeAtomic(OUTPUT_PATH, payload);
	process.stdout.write(`${JSON.stringify({
		result: 'PASS',
		posts: posts.length,
		payload_sha256: payload.payload_sha256,
		output: OUTPUT_PATH,
	}, null, 2)}\n`);
}

main();
