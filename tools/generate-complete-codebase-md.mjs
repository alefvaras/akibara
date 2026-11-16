import fs from 'node:fs';
import path from 'node:path';

const ROOT_DIR = process.cwd();
const DOCS_DIR = path.join(ROOT_DIR, 'notbooklm');

/**
 * Límite máximo de palabras por archivo para garantizar indexación 100% verde en NotebookLM.
 * NotebookLM falla por límite de contexto con >500.000 palabras; fijamos un techo estricto de 380.000.
 */
export const MAX_WORDS_PER_VOLUME = 380000;

export const VOLUMES = [
	{
		filename: 'AKIBARA_CODIGO_01A_BUSINESS_MODULES.md',
		volumeNumber: '1A',
		volumeTitle: 'VOLUMEN 1A: MÓDULOS DE NEGOCIO (akibara-business/src/Modules)',
		targets: [
			{
				id: 'plugin-akibara-business-modules',
				title: 'MÓDULOS DE NEGOCIO CORE (akibara-business/src/Modules)',
				dir: 'public_html/wp-content/plugins/akibara-business/src/Modules',
				description: 'Lógica modular: AccountCollection, BackInStock, SeriesFollow, SeriesCatalog, Stock, PricingMatrix, TaxonomyDiscount, Wishlist, etc.'
			}
		]
	},
	{
		filename: 'AKIBARA_CODIGO_01B_BUSINESS_CORE_Y_SOPORTE.md',
		volumeNumber: '1B',
		volumeTitle: 'VOLUMEN 1B: CORE, ASSETS, TEMPLATES Y TESTS GLOBALES (akibara-business)',
		targets: [
			{
				id: 'plugin-akibara-business-core',
				title: 'CORE, ASSETS, TEMPLATES Y TESTS GLOBALES (akibara-business)',
				dir: 'public_html/wp-content/plugins/akibara-business',
				excludeDirs: [
					'public_html/wp-content/plugins/akibara-business/src/Modules',
					'public_html/wp-content/plugins/akibara-business/tests/Modules'
				],
				description: 'Infraestructura core, Admin, Services, Integraciones, Utilities, templates, assets y pruebas globales de límites/fronteras.'
			}
		]
	},
	{
		filename: 'AKIBARA_CODIGO_01C_BUSINESS_TESTS_MODULES.md',
		volumeNumber: '1C',
		volumeTitle: 'VOLUMEN 1C: SUITES DE TESTS DE MÓDULOS (akibara-business/tests/Modules)',
		targets: [
			{
				id: 'plugin-akibara-business-tests-modules',
				title: 'TESTS DE MÓDULOS DE NEGOCIO (akibara-business/tests/Modules)',
				dir: 'public_html/wp-content/plugins/akibara-business/tests/Modules',
				description: 'Suites exhaustivas de tests unitarios y de integración para todos los módulos de negocio.'
			}
		]
	},
	{
		filename: 'AKIBARA_CODIGO_02A_REWARDS_SEARCH_SRC.md',
		volumeNumber: '2A',
		volumeTitle: 'VOLUMEN 2A: CÓDIGO FUENTE DE RECOMPENSAS Y BÚSQUEDA (akibara-rewards + akibara-search)',
		targets: [
			{
				id: 'plugin-akibara-rewards-src',
				title: 'PLUGIN DE FIDELIZACIÓN Y RESEÑAS 2.0 - FUENTES (akibara-rewards)',
				dir: 'public_html/wp-content/plugins/akibara-rewards',
				excludeDirs: ['public_html/wp-content/plugins/akibara-rewards/tests'],
				description: 'Plugin de recompensas, ledger inmutable de puntos Akibara, sistema de canje 1:1, programa de referidos, módulo de Reseñas 2.0.'
			},
			{
				id: 'plugin-akibara-search-src',
				title: 'PLUGIN DE MOTOR DE BÚSQUEDA - FUENTES (akibara-search)',
				dir: 'public_html/wp-content/plugins/akibara-search',
				excludeDirs: ['public_html/wp-content/plugins/akibara-search/tests'],
				description: 'Motor de búsqueda inteligente: indexador SQLite/MariaDB full-text, facetas dinámicas, lematización de manga.'
			}
		]
	},
	{
		filename: 'AKIBARA_CODIGO_02B_REWARDS_SEARCH_TESTS.md',
		volumeNumber: '2B',
		volumeTitle: 'VOLUMEN 2B: TESTS DE RECOMPENSAS Y BÚSQUEDA (akibara-rewards/tests + akibara-search/tests)',
		targets: [
			{
				id: 'plugin-akibara-rewards-tests',
				title: 'TESTS DE RECOMPENSAS (akibara-rewards/tests)',
				dir: 'public_html/wp-content/plugins/akibara-rewards/tests',
				description: 'Suites de tests de ledger, puntos, referidos y reseñas.'
			},
			{
				id: 'plugin-akibara-search-tests',
				title: 'TESTS DE MOTOR DE BÚSQUEDA (akibara-search/tests)',
				dir: 'public_html/wp-content/plugins/akibara-search/tests',
				description: 'Suites de tests de indexación, tokenización, scoring y contratos.'
			}
		]
	},
	{
		filename: 'AKIBARA_CODIGO_03A_TEMA_AKIBARA_GP.md',
		volumeNumber: '3A',
		volumeTitle: 'VOLUMEN 3A: TEMA DE PRESENTACIÓN Y UI (akibara-gp)',
		targets: [
			{
				id: 'tema-akibara-gp',
				title: 'TEMA AKIBARA (akibara-gp)',
				dir: 'public_html/wp-content/themes/akibara-gp',
				description: 'Tema hijo de GeneratePress que implementa el sistema de diseño Neo-Brutalista Otaku, plantillas WooCommerce y JS de interacción.'
			}
		]
	},
	{
		filename: 'AKIBARA_CODIGO_03B_BLOQUES_Y_MU_PLUGINS.md',
		volumeNumber: '3B',
		volumeTitle: 'VOLUMEN 3B: BLOQUES DE CHECKOUT Y MU-PLUGINS (akibara-cart-checkout-blocks + mu-plugins)',
		targets: [
			{
				id: 'plugin-akibara-cart-checkout-blocks',
				title: 'PLUGIN DE CARRITO Y BLOQUES DE CHECKOUT (akibara-cart-checkout-blocks)',
				dir: 'public_html/wp-content/plugins/akibara-cart-checkout-blocks',
				description: 'Extensiones nativas de bloques WooCommerce, integración con Store API, selección de puntos PUDO Blue Express y checkout consolidado.'
			},
			{
				id: 'mu-plugins',
				title: 'PLUGINS MANDATORIOS DE INFRAESTRUCTURA (mu-plugins)',
				dir: 'public_html/wp-content/mu-plugins',
				description: 'Must-Use plugins de infraestructura crítica: seguridad, rotación de logs, transporte SMTP, watchdog de crons, suite Blue Express.'
			}
		]
	}
];

const TEXT_EXTS = new Set(['.php', '.js', '.css', '.mjs', '.json', '.svg', '.md']);
const BINARY_EXTS = new Set(['.woff2', '.webp', '.png', '.jpg', '.jpeg', '.gif', '.ico']);

/**
 * Escanea un directorio y devuelve los archivos de texto y binarios.
 */
function scanTarget(target) {
	const textFiles = [];
	const binaryInventory = [];
	const absDir = path.join(ROOT_DIR, target.dir);
	const excludeRelDirs = (target.excludeDirs || []).map(d => path.resolve(ROOT_DIR, d));

	function scan(dir) {
		if (!fs.existsSync(dir)) return;
		for (const excl of excludeRelDirs) {
			if (dir === excl || dir.startsWith(excl + path.sep)) {
				return;
			}
		}
		const entries = fs.readdirSync(dir, { withFileTypes: true });
		entries.sort((a, b) => a.name.localeCompare(b.name));

		for (const entry of entries) {
			if (entry.name.startsWith('.') || entry.name === 'node_modules' || entry.name === 'vendor') continue;
			const fullPath = path.join(dir, entry.name);
			const relPath = path.relative(ROOT_DIR, fullPath);

			if (entry.isDirectory()) {
				let skip = false;
				for (const excl of excludeRelDirs) {
					if (fullPath === excl || fullPath.startsWith(excl + path.sep)) {
						skip = true;
						break;
					}
				}
				if (!skip) {
					scan(fullPath);
				}
			} else if (entry.isFile()) {
				const ext = path.extname(entry.name).toLowerCase();
				const stat = fs.statSync(fullPath);

				if (TEXT_EXTS.has(ext)) {
					const content = fs.readFileSync(fullPath, 'utf8');
					const lines = content.split('\n').length;
					const words = content.trim().split(/\s+/).filter(Boolean).length;
					textFiles.push({
						relPath,
						ext,
						size: stat.size,
						lines,
						words,
						content
					});
				} else if (BINARY_EXTS.has(ext)) {
					binaryInventory.push({
						target: target.title,
						relPath,
						ext,
						size: stat.size
					});
				}
			}
		}
	}

	scan(absDir);
	textFiles.sort((a, b) => a.relPath.localeCompare(b.relPath));

	const totalSize = textFiles.reduce((acc, f) => acc + f.size, 0);
	const totalLines = textFiles.reduce((acc, f) => acc + f.lines, 0);
	const totalWords = textFiles.reduce((acc, f) => acc + f.words, 0);

	return {
		...target,
		files: textFiles,
		binaryInventory,
		totalSize,
		totalLines,
		totalWords
	};
}

/**
 * Compila y escribe un volumen de código en notbooklm/.
 */
function buildVolume(vol) {
	if (!fs.existsSync(DOCS_DIR)) {
		fs.mkdirSync(DOCS_DIR, { recursive: true });
	}

	const outputPath = path.join(DOCS_DIR, vol.filename);
	const packages = vol.targets.map(t => scanTarget(t));

	const volFilesCount = packages.reduce((acc, p) => acc + p.files.length, 0);
	const volLinesCount = packages.reduce((acc, p) => acc + p.totalLines, 0);
	const volWordsCount = packages.reduce((acc, p) => acc + p.totalWords, 0);
	const volBytesCount = packages.reduce((acc, p) => acc + p.totalSize, 0);
	const allBinaries = packages.flatMap(p => p.binaryInventory);

	let md = `# CÓDIGO FUENTE DE AKIBARA - ${vol.volumeTitle}\n\n`;
	md += `> **Documento Maestro de Código Consolidado (Verbatim / Sin Omisiones ni Inferencias)**  \n`;
	md += `> **Volumen:** ${vol.volumeNumber} de ${VOLUMES.length}  \n`;
	md += `> **Fecha de compilación:** ${new Date().toISOString()}  \n`;
	md += `> **Entorno:** Akibara E-Commerce Platform (akibara.cl)  \n`;
	md += `> **Total de archivos fuente:** ${volFilesCount.toLocaleString()}  \n`;
	md += `> **Total de líneas de código:** ${volLinesCount.toLocaleString()}  \n`;
	md += `> **Total de palabras estimadas:** ${volWordsCount.toLocaleString()}  \n`;
	md += `> **Tamaño total:** ${(volBytesCount / 1024 / 1024).toFixed(2)} MB  \n\n`;

	md += `---\n\n`;
	md += `## RESUMEN POR MÓDULO Y PAQUETE\n\n`;
	md += `| # | Componente / Paquete | Directorio | Archivos | Líneas | Palabras | Tamaño |\n`;
	md += `|---|----------------------|------------|----------|--------|----------|--------|\n`;

	packages.forEach((pkg, idx) => {
		md += `| ${idx + 1} | **${pkg.title}** | \`${pkg.dir}\` | ${pkg.files.length.toLocaleString()} | ${pkg.totalLines.toLocaleString()} | ${pkg.totalWords.toLocaleString()} | ${(pkg.totalSize / 1024 / 1024).toFixed(2)} MB |\n`;
	});
	md += `| **TOTAL** | **Consolidado del Volumen** | - | **${volFilesCount.toLocaleString()}** | **${volLinesCount.toLocaleString()}** | **${volWordsCount.toLocaleString()}** | **${(volBytesCount / 1024 / 1024).toFixed(2)} MB** |\n\n`;

	if (allBinaries.length > 0) {
		md += `---\n\n`;
		md += `## INVENTARIO DE ACTIVOS BINARIOS (FUENTES E IMÁGENES)\n\n`;
		md += `| Paquete | Ruta de Archivo | Formato | Tamaño (Bytes) |\n`;
		md += `|---------|-----------------|---------|----------------|\n`;
		for (const bin of allBinaries) {
			md += `| ${bin.target} | \`${bin.relPath}\` | ${bin.ext} | ${bin.size.toLocaleString()} |\n`;
		}
		md += `\n`;
	}

	md += `---\n\n`;
	md += `## ÍNDICE DE ARCHIVOS DE ESTE VOLUMEN\n\n`;
	for (const pkg of packages) {
		md += `### ${pkg.title}\n\n`;
		md += `*${pkg.description}*\n\n`;
		for (const file of pkg.files) {
			md += `- [\`${file.relPath}\`](#archivo-${file.relPath.toLowerCase().replace(/[^a-z0-9]+/g, '-')}) (${file.lines.toLocaleString()} líneas · ${file.words.toLocaleString()} palabras · ${(file.size / 1024).toFixed(1)} KB)\n`;
		}
		md += `\n`;
	}

	md += `---\n\n`;
	md += `# CÓDIGO FUENTE ÍNTEGRO (100% VERBATIM)\n\n`;

	for (const pkg of packages) {
		md += `\n\n################################################################\n`;
		md += `## ${pkg.title}\n`;
		md += `## Directorio base: ${pkg.dir}\n`;
		md += `################################################################\n\n`;

		for (const file of pkg.files) {
			md += `\n================================================================\n`;
			md += `### ARCHIVO: ${file.relPath}\n`;
			md += `================================================================\n\n`;
			md += file.content;
			if (!file.content.endsWith('\n')) {
				md += '\n';
			}
		}
	}

	const stripTimestamp = (text) => text.replace(/^> \*\*Fecha de compilación:\*\* .*$/m, '');
	if (!fs.existsSync(outputPath) || stripTimestamp(fs.readFileSync(outputPath, 'utf8')) !== stripTimestamp(md)) {
		fs.writeFileSync(outputPath, md, 'utf8');
	}
	const stat = fs.statSync(outputPath);

	return {
		filename: vol.filename,
		outputPath,
		filesCount: volFilesCount,
		linesCount: volLinesCount,
		wordsCount: volWordsCount,
		sizeBytes: stat.size
	};
}

/**
 * Función principal para generar los volúmenes particionados.
 */
export function generateCodebaseVolumes() {
	console.log(`=== GENERANDO ${VOLUMES.length} VOLÚMENES PARTICIONADOS DE CÓDIGO (< 380.000 palabras) PARA NOTEBOOKLM ===\n`);

	// Archivos obsoletos que deben eliminarse de la raíz y de notbooklm/
	const staleFiles = [
		'AKIBARA_CODIGO_COMPLETO.md',
		'AKIBARA_CODIGO_01_BUSINESS.md',
		'AKIBARA_CODIGO_02_REWARDS_Y_SEARCH.md',
		'AKIBARA_CODIGO_03_TEMA_BLOQUES_Y_MU.md'
	];

	for (const stale of staleFiles) {
		const rootPath = path.join(ROOT_DIR, stale);
		if (fs.existsSync(rootPath)) {
			fs.unlinkSync(rootPath);
			console.log(`✔ Archivo obsoleto eliminado de la raíz: ${stale}`);
		}
		const docsPath = path.join(DOCS_DIR, stale);
		if (fs.existsSync(docsPath)) {
			fs.unlinkSync(docsPath);
			console.log(`✔ Archivo obsoleto eliminado de notbooklm/: ${stale}`);
		}
	}

	const results = [];
	for (const vol of VOLUMES) {
		console.log(`Compilando ${vol.filename}...`);
		const res = buildVolume(vol);
		if (res.wordsCount > MAX_WORDS_PER_VOLUME) {
			throw new Error(`[LÍMITE EXCEDIDO] ${res.filename} contiene ${res.wordsCount.toLocaleString()} palabras (> ${MAX_WORDS_PER_VOLUME.toLocaleString()}). NotebookLM fallará al indexarlo.`);
		}
		results.push(res);
		console.log(`  ✔ [${res.filename}]`);
		console.log(`     Archivos: ${res.filesCount.toLocaleString()} | Líneas: ${res.linesCount.toLocaleString()} | Palabras: ${res.wordsCount.toLocaleString()} | Peso: ${(res.sizeBytes / 1024 / 1024).toFixed(2)} MB`);
	}

	console.log(`\n✔ Los ${VOLUMES.length} volúmenes fueron generados exitosamente en notbooklm/ sin exceder el umbral de 380k palabras.\n`);
	return results;
}

// Ejecución directa por CLI
const isDirectCall = process.argv[1] && path.resolve(process.argv[1]) === path.resolve(new URL(import.meta.url).pathname);
if (isDirectCall) {
	generateCodebaseVolumes();
}
