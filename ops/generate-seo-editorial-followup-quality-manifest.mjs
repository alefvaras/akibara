#!/usr/bin/env node

import { createHash } from 'node:crypto';
import { readFileSync, writeFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { spawnSync } from 'node:child_process';

const repoRoot = resolve(import.meta.dirname, '..');
const outputPath = resolve(
	repoRoot,
	process.argv[2] ?? 'ops/seo-editorial-followup-quality-manifest.json',
);
const reviewedManifestPath = resolve(
	repoRoot,
	'ops/seo-editorial-followup-quality-manifest.json',
);
const reviewedEntriesByKey = new Map(
	JSON.parse(readFileSync(reviewedManifestPath, 'utf8')).entries.map(
		(entry) => [entry.key, entry],
	),
);

const word = (before, after) => ({ before, after, match_mode: 'text_word' });
const text = (before, after) => ({ before, after, match_mode: 'text_literal' });
const raw = (before, after) => ({ before, after, match_mode: 'raw_literal' });

const accentWords = (pairs) => pairs.map(([before, after]) => word(before, after));

const expectedAfterSha256ByKey = {
	'product:15418:post_excerpt': '0de3da5d016857473cd696508dd6e04392dffbc21b7b36af36d802ffa2dc925c',
	'product:15418:post_content': 'd41bd89423aab79d891d7eba298b1957687c59f606c91f95b8d058ad442e3a95',
	'product:15421:post_content': '27ecd9c198d96ce60f34cfdd5153785176daa7473fb5d225333183c80a0bcc73',
	'post:22607:post_content': '893bee99123e550e665e62ff985f0b7abb837ccfc8594fba04d6c1f6393c5293',
	'post:22610:post_content': 'ac4afd5b37c4d721cc6518e7604b431b4b62f549bf44c096b29768fde3b61db1',
	'post:22638:post_excerpt': '8d5124df6d23d7bbf493b804c72220329027e267592c13ec75ecfb134ca6ab62',
	'post:22638:post_content': 'c77c6897ba7b0985fd121cfcdcafb149780e6060b54416133d7b4e93cc5f79fa',
	'post:23323:post_content': 'e683b9d784d2a59b6b16b064e1782fc2e0b5a3e89d599407916a49b884c112f1',
	'post:23324:post_content': 'e05c067c0343deeb974c0d7447784a8d28596a85f55e7ebe56311f089696cb13',
	'post:23325:post_content': '388130c2c0ea45122c926960bbf15fd71725f5693d0b889ef77a91327d9bd18c',
	'post:23326:post_content': 'e30241dd4b4b247cddf4e3077e26710f2204ce708943726a2228244e1691d0c3',
	'post:23330:post_excerpt': 'c978fcb8bd934c7c3e46cf60dd2c7520ba207e216a4e135a250aed32e770c39b',
	'post:23330:post_content': '9f54b8a5358d3a9b9a2396e9c835098096b1be95bac4345a606cbc665eb7ac6e',
};

const entries = [
	{
		object_id: 15418,
		object_type: 'product',
		title: 'Mob Psycho 100 07',
		field: 'post_excerpt',
		expected_before_sha256: 'fecfd82531dcf486fdad55a99cb73a92f261a6da3799110bbfcb5cb4e2fcae8e',
		issue_codes: ['canonical_notation_corrupted'],
		rules: [text('?%', '???%')],
	},
	{
		object_id: 15418,
		object_type: 'product',
		title: 'Mob Psycho 100 07',
		field: 'post_content',
		expected_before_sha256: 'a91f20cb85d531c44011ac4a62e56d61bc3a7a83569bf4f6d6a871165f88507f',
		issue_codes: ['canonical_notation_corrupted', 'missing_article'],
		rules: [
			text('?%', '???%'),
			text('tomar control completo', 'tomar el control completo'),
		],
	},
	{
		object_id: 15421,
		object_type: 'product',
		title: 'Mob Psycho 100 08',
		field: 'post_content',
		expected_before_sha256: '407bae7e4744a4d0e955e2aed52cc8af281872700635481a0358ad48a65b71df',
		issue_codes: ['canonical_notation_corrupted', 'missing_article', 'untranslated_common_noun'],
		rules: [
			text('?%', '???%'),
			text('toma control', 'toma el control'),
			word('villain', 'villano'),
		],
	},
	{
		object_id: 22607,
		object_type: 'post',
		title: 'Guía completa para empezar a leer One Piece en 2026',
		field: 'post_content',
		expected_before_sha256: '5af52b447a7114bc091af353cbc14b6ca7e653310ce8820cddfb833e1a2da806',
		issue_codes: ['dead_presentational_span', 'malformed_wpautop_section_header'],
		rules: [raw('<span class="aki-page-section-accent"></span>', '')],
	},
	{
		object_id: 22610,
		object_type: 'post',
		title: 'Berserk: guía para leer la obra definitiva del manga seinen',
		field: 'post_content',
		expected_before_sha256: 'c64ebd87f6cc4dcd14f6eb8c5277ecbb283aa9b1ebfbf128f32a1598084660c3',
		issue_codes: [
			'dead_presentational_span',
			'malformed_wpautop_section_header',
			'spanish_diacritics_omitted',
		],
		rules: [
			raw('<span class="aki-page-section-accent"></span>', ''),
			word('apostoles', 'apóstoles'),
		],
	},
	{
		object_id: 22638,
		object_type: 'post',
		title: '¿En qué tomo del manga continuar Jujutsu Kaisen después del anime?',
		field: 'post_excerpt',
		expected_before_sha256: '855af53e8dbb4fa50d31c53b2c62cd7d9b29882d97f4449f41145a6c21db9913',
		issue_codes: ['missing_opening_question_mark'],
		rules: [text('Terminaste el anime', '¿Terminaste el anime')],
	},
	{
		object_id: 22638,
		object_type: 'post',
		title: '¿En qué tomo del manga continuar Jujutsu Kaisen después del anime?',
		field: 'post_content',
		expected_before_sha256: 'bd2a76fdc74301abb0db836cb8e76220cdde322d1bb1404dc084a5caab2694fe',
		issue_codes: ['spanish_diacritics_omitted', 'question_word_diacritics_omitted'],
		rules: [
			...accentWords([
				['accion', 'acción'],
				['adaptacion', 'adaptación'],
				['Ademas', 'Además'],
				['animacion', 'animación'],
				['aparicion', 'aparición'],
				['Aqui', 'Aquí'],
				['asi', 'así'],
				['Coleccion', 'Colección'],
				['coleccion', 'colección'],
				['conclusion', 'conclusión'],
				['despues', 'después'],
				['detencion', 'detención'],
				['edicion', 'edición'],
				['epilogos', 'epílogos'],
				['espanol', 'español'],
				['espiritu', 'espíritu'],
				['estrategicas', 'estratégicas'],
				['guia', 'guía'],
				['Hechiceria', 'Hechicería'],
				['hechiceria', 'hechicería'],
				['informacion', 'información'],
				['maldicion', 'maldición'],
				['mision', 'misión'],
				['motivacion', 'motivación'],
				['Pelicula', 'Película'],
				['pelicula', 'película'],
				['rapido', 'rápido'],
				['tambien', 'también'],
				['Tecnica', 'Técnica'],
				['traumatico', 'traumático'],
				['unicas', 'únicas'],
				['version', 'versión'],
				['volumenes', 'volúmenes'],
				['aun', 'aún'],
				['separo', 'separó'],
				['Continua', 'Continúa'],
				['concluyo', 'concluyó'],
				['mas', 'más'],
			]),
			text('¿Que cubre', '¿Qué cubre'),
			text('¿En que tomo continuar', '¿En qué tomo continuar'),
			text('¿Desde donde leer', '¿Desde dónde leer'),
			text('¿Por que leer', '¿Por qué leer'),
		],
	},
	{
		object_id: 23323,
		object_type: 'post',
		title: 'My Hero Academia: guía completa para leer el manga en 2026',
		field: 'post_content',
		expected_before_sha256: '99177dc33bc4a8b7850465580a53458af976dccffc9e2f6568ed437645bb2b42',
		issue_codes: ['spanish_diacritics_omitted', 'missing_enye', 'question_word_diacritics_omitted'],
		rules: [
			...accentWords([
				['Despues', 'Después'],
				['despues', 'después'],
				['anos', 'años'],
				['publicacion', 'publicación'],
				['capitulos', 'capítulos'],
				['llego', 'llegó'],
				['convirtio', 'convirtió'],
				['esta', 'está'],
				['edicion', 'edición'],
				['coleccion', 'colección'],
				['Coleccion', 'Colección'],
				['concibio', 'concibió'],
				['poblacion', 'población'],
				['heroe', 'héroe'],
				['heroes', 'héroes'],
				['Heroes', 'Héroes'],
				['profesion', 'profesión'],
				['determinacion', 'determinación'],
				['atencion', 'atención'],
				['numero', 'número'],
				['ahi', 'ahí'],
				['Japon', 'Japón'],
				['companeros', 'compañeros'],
				['enfrentara', 'enfrentará'],
				['mas', 'más'],
				['detras', 'detrás'],
				['aparicion', 'aparición'],
				['Aqui', 'Aquí'],
				['dinamicas', 'dinámicas'],
				['Liberacion', 'Liberación'],
				['revelacion', 'revelación'],
				['adaptacion', 'adaptación'],
				['cubrio', 'cubrió'],
				['ultimos', 'últimos'],
				['epilogo', 'epílogo'],
				['epicos', 'épicos'],
				['cerrara', 'cerrará'],
				['paginas', 'páginas'],
				['volumenes', 'volúmenes'],
				['cuantos', 'cuántos'],
				['extension', 'extensión'],
				['Guia', 'Guía'],
				['guia', 'guía'],
				['capitulo', 'capítulo'],
				['aun', 'aún'],
				['dinamico', 'dinámico'],
				['recomendacion', 'recomendación'],
				['atrapo', 'atrapó'],
				['preparate', 'prepárate'],
				['dramaticamente', 'dramáticamente'],
				['encontraras', 'encontrarás'],
				['espanol', 'español'],
				['Latinoamerica', 'Latinoamérica'],
				['traduccion', 'traducción'],
				['japones', 'japonés'],
				['tambien', 'también'],
				['guias', 'guías'],
				['titulos', 'títulos'],
				['seccion', 'sección'],
			]),
			text('De que trata', 'De qué trata'),
			text('Que cubre el anime y por que leer', 'Qué cubre el anime y por qué leer'),
		],
	},
	{
		object_id: 23324,
		object_type: 'post',
		title: 'Blue Lock: la revolución del manga deportivo en 2026',
		field: 'post_content',
		expected_before_sha256: 'c157bc70a811c4ef88b6bde62dba9de6c6d1be85afcdf4edda7de6547ae8e9a5',
		issue_codes: ['spanish_diacritics_omitted', 'question_word_diacritics_omitted'],
		rules: [
			...accentWords([
				['genero', 'género'],
				['Ademas', 'Además'],
				['decadas', 'décadas'],
				['aparecio', 'apareció'],
				['podia', 'podía'],
				['circulacion', 'circulación'],
				['mas', 'más'],
				['cambio', 'cambió'],
				['Despues', 'Después'],
				['seleccion', 'selección'],
				['Seleccion', 'Selección'],
				['futbol', 'fútbol'],
				['Japon', 'Japón'],
				['instalacion', 'instalación'],
				['traves', 'través'],
				['egoista', 'egoísta'],
				['rapido', 'rápido'],
				['fisico', 'físico'],
				['clasicos', 'clásicos'],
				['superacion', 'superación'],
				['formula', 'fórmula'],
				['Egoismo', 'Egoísmo'],
				['eliminacion', 'eliminación'],
				['tension', 'tensión'],
				['ultimo', 'último'],
				['Analisis', 'Análisis'],
				['tactico', 'táctico'],
				['tacticos', 'tácticos'],
				['iconos', 'íconos'],
				['filosofia', 'filosofía'],
				['ideologicos', 'ideológicos'],
				['ademas', 'además'],
				['introduccion', 'introducción'],
				['aparicion', 'aparición'],
				['japones', 'japonés'],
				['climax', 'clímax'],
				['preparandose', 'preparándose'],
				['continua', 'continúa'],
				['desafios', 'desafíos'],
				['cubrio', 'cubrió'],
				['confirmo', 'confirmó'],
				['cubrira', 'cubrirá'],
				['pelicula', 'película'],
				['todavia', 'todavía'],
				['exploto', 'explotó'],
				['logro', 'logró'],
				['psicologica', 'psicológica'],
				['frenetico', 'frenético'],
				['clasico', 'clásico'],
				['titulos', 'títulos'],
				['accion', 'acción'],
				['edicion', 'edición'],
				['coleccion', 'colección'],
				['volumenes', 'volúmenes'],
				['dia', 'día'],
				['tambien', 'también'],
				['guia', 'guía'],
				['seccion', 'sección'],
			]),
			text('Por que Blue Lock', 'Por qué Blue Lock'),
			text('El anime de Blue Lock y que cubre', 'El anime de Blue Lock y qué cubre'),
		],
	},
	{
		object_id: 23325,
		object_type: 'post',
		title: 'Demon Slayer (Kimetsu no Yaiba): guía definitiva del manga',
		field: 'post_content',
		expected_before_sha256: '31d4ab8e7d10159f9d1939a58605e3b68b4edb2b0d77c00333e57ab69b672b3c',
		issue_codes: ['spanish_diacritics_omitted', 'question_word_diacritics_omitted'],
		rules: [
			...accentWords([
				['definio', 'definió'],
				['ultima', 'última'],
				['decada', 'década'],
				['rompio', 'rompió'],
				['records', 'récords'],
				['Japon', 'Japón'],
				['convirtio', 'convirtió'],
				['fenomeno', 'fenómeno'],
				['organizacion', 'organización'],
				['accion', 'acción'],
				['empatia', 'empatía'],
				['Seleccion', 'Selección'],
				['aparicion', 'aparición'],
				['Demoniacas', 'Demoníacas'],
				['inspiro', 'inspiró'],
				['pelicula', 'película'],
				['mas', 'más'],
				['tragico', 'trágico'],
				['mision', 'misión'],
				['Accion', 'Acción'],
				['limite', 'límite'],
				['Preparacion', 'Preparación'],
				['epico', 'épico'],
				['epilogo', 'epílogo'],
				['animacion', 'animación'],
				['elevo', 'elevó'],
				['increibles', 'increíbles'],
				['climax', 'clímax'],
				['trilogia', 'trilogía'],
				['peliculas', 'películas'],
				['comenzo', 'comenzó'],
				['anos', 'años'],
				['leido', 'leído'],
				['eleccion', 'elección'],
				['Extension', 'Extensión'],
				['edicion', 'edición'],
				['larguisimas', 'larguísimas'],
				['agil', 'ágil'],
				['capitulo', 'capítulo'],
				['espanol', 'español'],
				['Latinoamerica', 'Latinoamérica'],
				['traduccion', 'traducción'],
				['japones', 'japonés'],
				['coleccion', 'colección'],
				['autentica', 'auténtica'],
				['dibujo', 'dibujó'],
				['estanteria', 'estantería'],
				['corazon', 'corazón'],
				['tambien', 'también'],
				['guias', 'guías'],
				['seccion', 'sección'],
				['articulo', 'artículo'],
			]),
			text('De que trata', 'De qué trata'),
			text('Que cubre el anime y que falta', 'Qué cubre el anime y qué falta'),
			text('esta siendo adaptado', 'está siendo adaptado'),
			text('Por que Demon Slayer', 'Por qué Demon Slayer'),
			text('por donde empezar', 'por dónde empezar'),
			text('que es el manga', 'qué es el manga'),
		],
	},
	{
		object_id: 23326,
		object_type: 'post',
		title: 'Hunter x Hunter: por qué debes leer el manga de Togashi',
		field: 'post_content',
		expected_before_sha256: '91992bc6adc7e0ed3ec0948081b21ca58eb5bc598b602a9fd8c6a691e422ed9c',
		issue_codes: ['spanish_diacritics_omitted', 'missing_enye', 'question_word_diacritics_omitted'],
		rules: [
			...accentWords([
				['todavia', 'todavía'],
				['leido', 'leído'],
				['mas', 'más'],
				['genero', 'género'],
				['desafian', 'desafían'],
				['habia', 'había'],
				['clasico', 'clásico'],
				['psicologica', 'psicológica'],
				['faciles', 'fáciles'],
				['terminos', 'términos'],
				['tematica', 'temática'],
				['elite', 'élite'],
				['abandono', 'abandonó'],
				['nino', 'niño'],
				['clasica', 'clásica'],
				['estrategico', 'estratégico'],
				['introduccion', 'introducción'],
				['capitulos', 'capítulos'],
				['empatia', 'empatía'],
				['Eleccion', 'Elección'],
				['Sucesion', 'Sucesión'],
				['politica', 'política'],
				['adapto', 'adaptó'],
				['adaptacion', 'adaptación'],
				['despues', 'después'],
				['sucesion', 'sucesión'],
				['politicas', 'políticas'],
				['mostro', 'mostró'],
				['publicacion', 'publicación'],
				['multiples', 'múltiples'],
				['decadas', 'décadas'],
				['sorprendio', 'sorprendió'],
				['Segun', 'Según'],
				['Guia', 'Guía'],
				['guia', 'guía'],
				['continua', 'continúa'],
				['grafico', 'gráfico'],
				['unico', 'único'],
				['omitio', 'omitió'],
				['edicion', 'edición'],
				['espanol', 'español'],
				['Latinoamerica', 'Latinoamérica'],
				['traduccion', 'traducción'],
				['japones', 'japonés'],
				['coleccion', 'colección'],
				['creacion', 'creación'],
				['construccion', 'construcción'],
				['vision', 'visión'],
				['artistica', 'artística'],
				['contemporaneo', 'contemporáneo'],
				['seccion', 'sección'],
			]),
			text('te estas perdiendo', 'te estás perdiendo'),
			text('todo esta conectado', 'todo está conectado'),
			text('Quien es Yoshihiro Togashi y por que', 'Quién es Yoshihiro Togashi y por qué'),
			text('De que trata', 'De qué trata'),
			text('Que cubre el anime vs que es', 'Qué cubre el anime vs qué es'),
			text('Guía de lectura: como empezar', 'Guía de lectura: cómo empezar'),
			text('Por que Hunter x Hunter', 'Por qué Hunter x Hunter'),
		],
	},
	{
		object_id: 23330,
		object_type: 'post',
		title: 'Manga vs Cómics: diferencias, similitudes y cuál elegir',
		field: 'post_excerpt',
		expected_before_sha256: 'e3a7ed26fd83913715512bd5f38ddb6606b6de09ec49866d417bff9a9cb57bc2',
		issue_codes: ['missing_opening_question_mark'],
		rules: [text('Manga o cómics?', '¿Manga o cómics?')],
	},
	{
		object_id: 23330,
		object_type: 'post',
		title: 'Manga vs Cómics: diferencias, similitudes y cuál elegir',
		field: 'post_content',
		expected_before_sha256: 'e77b427f1705c443b1774c5bca46abaa0eeb700a3aa98ee8e2a018b74bc3f0c7',
		issue_codes: ['spanish_diacritics_omitted', 'question_word_diacritics_omitted'],
		rules: [
			...accentWords([
				['estas', 'estás'],
				['graficas', 'gráficas'],
				['comics', 'cómics'],
				['comic', 'cómic'],
				['ayudara', 'ayudará'],
				['mas', 'más'],
				['guia', 'guía'],
				['Japon', 'Japón'],
				['comunicacion', 'comunicación'],
				['raices', 'raíces'],
				['genero', 'género'],
				['superheroes', 'superhéroes'],
				['generos', 'géneros'],
				['Latinoamerica', 'Latinoamérica'],
				['presentacion', 'presentación'],
				['atmosfera', 'atmósfera'],
				['estetica', 'estética'],
				['paginas', 'páginas'],
				['Direccion', 'Dirección'],
				['tradicion', 'tradición'],
				['mayoria', 'mayoría'],
				['Tamano', 'Tamaño'],
				['tipico', 'típico'],
				['numero', 'número'],
				['Aqui', 'Aquí'],
				['Autoria', 'Autoría'],
				['comun', 'común'],
				['dinamicas', 'dinámicas'],
				['unico', 'único'],
				['multiples', 'múltiples'],
				['decadas', 'décadas'],
				['tecnicas', 'técnicas'],
				['cinematograficas', 'cinematográficas'],
				['descompresion', 'descompresión'],
				['expresion', 'expresión'],
				['dialogos', 'diálogos'],
				['Generos', 'Géneros'],
				['demografia', 'demografía'],
				['jovenes', 'jóvenes'],
				['ninos', 'niños'],
				['estan', 'están'],
				['diversificandose', 'diversificándose'],
				['historicamente', 'históricamente'],
				['terminos', 'términos'],
				['relacion', 'relación'],
				['numeros', 'números'],
				['segun', 'según'],
				['vision', 'visión'],
				['alla', 'allá'],
				['accion', 'acción'],
				['traves', 'través'],
				['aun', 'aún'],
				['apreciacion', 'apreciación'],
				['asi', 'así'],
				['titulos', 'títulos'],
				['especificos', 'específicos'],
			]),
			text('Cual elegir', 'Cuál elegir'),
		],
	},
];

const sha256 = (value) => createHash('sha256').update(value, 'utf8').digest('hex');
const normalizeUiLineEndings = (value) => value.replace(/\r\n?/g, '\n');

function escapeRegExp(value) {
	return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

function rawPattern(rule) {
	const escaped = escapeRegExp(rule.before);
	if (rule.match_mode === 'text_word') {
		return new RegExp(`(?<![\\p{L}])${escaped}(?![\\p{L}])`, 'gu');
	}
	return new RegExp(escaped, 'gu');
}

function transformTextNodes(value, callback) {
	return value
		.split(/(<[^>]*>)/g)
		.map((part, index) => (index % 2 === 0 ? callback(part) : part))
		.join('');
}

function countRule(value, rule) {
	const pattern = rawPattern(rule);
	if (rule.match_mode === 'raw_literal') {
		return [...value.matchAll(pattern)].length;
	}
	let count = 0;
	transformTextNodes(value, (part) => {
		count += [...part.matchAll(pattern)].length;
		return part;
	});
	return count;
}

function applyRule(value, rule) {
	const pattern = rawPattern(rule);
	if (rule.match_mode === 'raw_literal') {
		return value.replace(pattern, rule.after);
	}
	return transformTextNodes(value, (part) => part.replace(pattern, rule.after));
}

function readDockerField(entry) {
	const sqlField = entry.field === 'post_content' ? 'post_content' : 'post_excerpt';
	const sql = [
		`SELECT post_type,post_status,REPLACE(TO_BASE64(${sqlField}),CHAR(10),'')`,
		'FROM wp_posts',
		`WHERE ID=${entry.object_id};`,
	].join(' ');
	const query = spawnSync(
		'docker',
		[
			'compose', 'exec', '-T', 'db',
			'mariadb', '-uroot', '-proot_local', 'akibara',
			'--batch', '--raw', '--skip-column-names',
			'-e', sql,
		],
		{ cwd: repoRoot, encoding: 'utf8', maxBuffer: 4 * 1024 * 1024 },
	);
	if (query.status !== 0) {
		throw new Error(`Docker read failed for ${entry.object_id}: ${query.stderr.trim()}`);
	}
	const parts = query.stdout.trim().split('\t');
	if (parts.length !== 3) {
		throw new Error(`Docker row shape drifted for ${entry.object_id}:${entry.field}`);
	}
	const [postType, postStatus, encoded] = parts;
	if (postType !== entry.object_type || postStatus !== 'publish' || !encoded) {
		throw new Error(
			`Docker object drifted for ${entry.object_id}: expected ${entry.object_type}/publish`,
		);
	}
	return Buffer.from(encoded.replace(/\s+/g, ''), 'base64').toString('utf8');
}

let totalExactSubstitutions = 0;
const manifestEntries = entries.map((entry) => {
	const key = `${entry.object_type}:${entry.object_id}:${entry.field}`;
	const observedValue = normalizeUiLineEndings(readDockerField(entry));
	const observedHash = sha256(observedValue);
	const reviewedEntry = reviewedEntriesByKey.get(key);
	let expectedBefore = observedValue;

	if (observedHash === expectedAfterSha256ByKey[key]) {
		if (
			!reviewedEntry
			|| reviewedEntry.expected_before_sha256 !== entry.expected_before_sha256
			|| reviewedEntry.proposed_after_sha256 !== expectedAfterSha256ByKey[key]
		) {
			throw new Error(
				`${key} is already applied but its reviewed source state is unavailable`,
			);
		}
		expectedBefore = normalizeUiLineEndings(reviewedEntry.expected_before);
	}

	const observedBeforeHash = sha256(expectedBefore);
	if (observedBeforeHash !== entry.expected_before_sha256) {
		throw new Error(
			`${entry.object_id}:${entry.field} drifted; expected `
			+ `${entry.expected_before_sha256} before or `
			+ `${expectedAfterSha256ByKey[key]} applied, observed ${observedHash}`,
		);
	}

	let proposedAfter = expectedBefore;
	const substitutions = entry.rules.map((rule) => {
		if (rule.before === rule.after) {
			throw new Error(
				`${entry.object_id}:${entry.field} contains a no-op rule for ${rule.before}`,
			);
		}
		const expectedCount = countRule(proposedAfter, rule);
		if (expectedCount < 1) {
			throw new Error(
				`${entry.object_id}:${entry.field} rule ${JSON.stringify(rule.before)} `
				+ 'matched zero times',
			);
		}
		proposedAfter = applyRule(proposedAfter, rule);
		totalExactSubstitutions += expectedCount;
		return { ...rule, expected_count: expectedCount };
	});
	const proposedAfterSha256 = sha256(proposedAfter);
	if (proposedAfterSha256 !== expectedAfterSha256ByKey[key]) {
		throw new Error(
			`${key} proposed state drifted; expected ${expectedAfterSha256ByKey[key]}, `
			+ `observed ${proposedAfterSha256}`,
		);
	}

	const isProduct = entry.object_type === 'product';
	const fieldLabel = entry.field === 'post_excerpt'
		? (isProduct ? 'Descripción corta del producto' : 'Extracto')
		: (isProduct ? 'Descripción del producto' : 'Editor de código');

	return {
		key,
		object_type: entry.object_type,
		object_id: entry.object_id,
		title: entry.title,
		field: entry.field,
		post_status: 'publish',
		content_disposition: 'valid_published_content_keep',
		issue_codes: entry.issue_codes,
		severity: 'high',
		application_lane: 'official_ui',
		apply_via: isProduct
			? `WooCommerce > Productos > ${entry.title} > ${fieldLabel} > Actualizar`
			: `WordPress > Entradas > ${entry.title} > ${fieldLabel} > Actualizar`,
		expected_before: expectedBefore,
		expected_before_sha256: observedBeforeHash,
		substitutions,
		proposed_after: proposedAfter,
		proposed_after_sha256: proposedAfterSha256,
	};
});

const manifest = {
	schema_version: 1,
	generated_at: '2026-07-24',
	environment: 'docker-local-snapshot',
	purpose: 'Fail-closed, UI-only follow-up for canonical notation, Spanish orthography and dead editorial markup.',
	rank_math_excluded: true,
	policy: {
		official_ui_first: true,
		no_direct_database_writes: true,
		delete_valid_published_content_as_historical_garbage: false,
		remove_only_verified_dead_historical_markup: true,
		preserve_urls_and_html_attributes: true,
	},
	evidence: {
		mob_psycho_canonical_notation: '???%',
		mob_psycho_reference: 'https://www.penguinrandomhouse.com/books/759806/mob-psycho-100-volume-16-by-story-and-art-by-one/',
	},
	audit_summary: {
		entries: manifestEntries.length,
		total_exact_substitutions: totalExactSubstitutions,
		encoding_replacement_characters_observed: 0,
		mojibake_markers_observed: 0,
	},
	entries: manifestEntries,
};

writeFileSync(outputPath, `${JSON.stringify(manifest, null, 2)}\n`, 'utf8');
console.log(`Wrote ${outputPath}`);
