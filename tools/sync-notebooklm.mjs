#!/usr/bin/env node
/**
 * tools/sync-notebooklm.mjs
 *
 * Pipeline automatizado de sincronización entre el repositorio local (Código + Markdown + Grafo),
 * el Second Brain (Graphify / Neo4j / Memento) y Google Drive para NotebookLM.
 */

import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';
import os from 'node:os';
import { execFileSync } from 'node:child_process';
import { generateCodebaseVolumes } from './generate-complete-codebase-md.mjs';

const ROOT_DIR = process.cwd();
const DOCS_DIR = path.join(ROOT_DIR, 'notbooklm');
const GRAPH_FILE = path.join(ROOT_DIR, 'graphify-out', 'graph.json');
const CYPHER_FILE = path.join(ROOT_DIR, 'graphify-out', 'akibara_neo4j_export.cypher');

// Ruta detectada de Google Drive en macOS
const DRIVE_BASE = path.join(
	os.homedir(),
	'Library',
	'CloudStorage',
	'GoogleDrive-ale.fvaras@gmail.com',
	'Mi unidad',
	'Akibara-Knowledge-Base'
);

console.log('=== INICIANDO PIPELINE DE SINCRONIZACIÓN NOTEBOOKLM & SECOND BRAIN ===\n');

// 1. Validar existencia del directorio fuente
if (!fs.existsSync(DOCS_DIR)) {
	console.error(`[ERROR] No se encontró el directorio de documentación: ${DOCS_DIR}`);
	process.exit(1);
}

// 2. Extraer estadísticas vivas del Grafo de Conocimiento (Graphify / AST)
let graphStats = { nodes: 0, edges: 0, communities: 0 };
if (fs.existsSync(GRAPH_FILE)) {
	try {
		const rawGraph = JSON.parse(fs.readFileSync(GRAPH_FILE, 'utf8'));
		graphStats.nodes = rawGraph.nodes ? rawGraph.nodes.length : 0;
		graphStats.edges = rawGraph.links ? rawGraph.links.length : 0;
		console.log(`[GRAFO] Graphify AST cargado: ${graphStats.nodes} nodos, ${graphStats.edges} conexiones.`);
	} catch (e) {
		console.warn(`[AVISO] No se pudo parsear graph.json: ${e.message}`);
	}
}

// 3. Generar los 7 volúmenes particionados de código en notbooklm/
generateCodebaseVolumes();

// 4. Auditoría de Guardrails en los archivos Markdown (Cero contradicciones)
const files = fs.readdirSync(DOCS_DIR).filter(f => f.endsWith('.md'));
console.log(`[GUARDRAIL] Auditando ${files.length} documentos Markdown en notbooklm/...`);

const FORBIDDEN_RULES = [
	{
		name: 'Shortcode preventa activo',
		test: (line) => /\[akb_preventas/i.test(line),
	},
	{
		name: 'Tabla residual descrita como activa',
		test: (line) => /(wp_akb_search_requests|wp_pmw_tracking_accuracy)/i.test(line) && !/(ELIMINAD|DROP TABLE|❌|residuo|obsolet)/i.test(line),
	},
];

let issuesFound = 0;
for (const file of files) {
	const fullPath = path.join(DOCS_DIR, file);
	const rawText = fs.readFileSync(fullPath, 'utf8');
	if (rawText.trim().length === 0) {
		console.error(`  ❌ [VIOLACIÓN] ${file}: Documento vacío (0 bytes útiles).`);
		issuesFound++;
		continue;
	}
	const lines = rawText.split('\n');
	lines.forEach((line, idx) => {
		for (const rule of FORBIDDEN_RULES) {
			if (rule.test(line)) {
				console.error(`  ❌ [VIOLACIÓN] ${file} (L${idx + 1}): ${rule.name} -> "${line.trim().slice(0, 80)}"`);
				issuesFound++;
			}
		}
	});
}

if (issuesFound > 0) {
	console.error(`\n[FALLO] Se detectaron ${issuesFound} residuos en la documentación. Abortando sincronización.`);
	process.exit(1);
}
console.log('  ✔ Todos los documentos cumplen el 100% de los guardrails de consistencia.');

// 5. Asegurar carpeta de destino en Google Drive y purgar artefactos obsoletos
let driveAvailable = false;
try {
	if (!fs.existsSync(DRIVE_BASE)) {
		fs.mkdirSync(DRIVE_BASE, { recursive: true });
	}
	driveAvailable = true;
	console.log(`[DRIVE] Directorio Google Drive verificado: ${DRIVE_BASE}`);

	// Purgar archivos monolíticos obsoletos en Google Drive
	const staleFilesDrive = [
		'AKIBARA_CODIGO_COMPLETO.md',
		'AKIBARA_CODIGO_01_BUSINESS.md',
		'AKIBARA_CODIGO_02_REWARDS_Y_SEARCH.md',
		'AKIBARA_CODIGO_03_TEMA_BLOQUES_Y_MU.md'
	];
	for (const stale of staleFilesDrive) {
		const stalePath = path.join(DRIVE_BASE, stale);
		if (fs.existsSync(stalePath)) {
			fs.unlinkSync(stalePath);
			console.log(`  ✔ Archivo obsoleto eliminado de Google Drive: ${stale}`);
		}
	}
} catch (e) {
	console.warn(`[AVISO] No se pudo acceder a Google Drive local: ${e.message}`);
}

// 6. Sincronización y Generación de Manifiesto
const manifest = {
	generated_at: new Date().toISOString(),
	graph_ast: graphStats,
	sources: [],
};

for (const file of files) {
	const srcPath = path.join(DOCS_DIR, file);
	const content = fs.readFileSync(srcPath);
	const hash = crypto.createHash('sha256').update(content).digest('hex');

	manifest.sources.push({
		filename: file,
		sha256: hash,
		bytes: content.length,
		description: file.replace(/^\d+-/, '').replace(/\.md$/, '').replace(/-/g, ' '),
	});

	if (driveAvailable) {
		const destPath = path.join(DRIVE_BASE, file);
		fs.writeFileSync(destPath, content);
	}
}

// 6. Copiar exportación de Neo4j Cypher si existe
if (fs.existsSync(CYPHER_FILE)) {
	const cypherContent = fs.readFileSync(CYPHER_FILE);
	if (driveAvailable) {
		fs.writeFileSync(path.join(DRIVE_BASE, 'akibara_neo4j_export.cypher'), cypherContent);
	}
	manifest.cypher_export = {
		filename: 'akibara_neo4j_export.cypher',
		bytes: cypherContent.length,
	};
	console.log('  ✔ Exportación Neo4j Cypher sincronizada.');
}

// 7. Sincronización Bidireccional Inteligente (Repo <-> Google Drive)
const BRAIN_DIR = path.join(ROOT_DIR, 'docs', 'brain');
if (driveAvailable && fs.existsSync(BRAIN_DIR)) {
	// A) Traer notas nuevas creadas en Obsidian (Drive -> Repo)
	const bidirectionalDirs = ['Agentes', 'decisiones'];
	for (const sub of bidirectionalDirs) {
		const driveSub = path.join(DRIVE_BASE, sub);
		const repoSub = path.join(BRAIN_DIR, sub);
		if (fs.existsSync(driveSub)) {
			if (!fs.existsSync(repoSub)) fs.mkdirSync(repoSub, { recursive: true });
			const driveFiles = fs.readdirSync(driveSub).filter(f => f.endsWith('.md'));
			for (const f of driveFiles) {
				const driveFile = path.join(driveSub, f);
				const repoFile = path.join(repoSub, f);
				if (!fs.existsSync(repoFile)) {
					fs.copyFileSync(driveFile, repoFile);
					console.log(`  ⬇ [OBSIDIAN -> REPO] Nota nueva sincronizada al repositorio: ${sub}/${f}`);
				}
			}
		}
	}

	// B) Proyectar Bóveda Obsidian, puentes (AGENTS.md, CLAUDE.md, .gemini) y ADRs (Repo -> Drive)
	const syncItems = ['.obsidian', 'AGENTS.md', 'CLAUDE.md', '.gemini', 'Agentes', 'decisiones', 'CONTEXTO_VIVO.md', 'lecciones.md'];
	for (const item of syncItems) {
		const src = path.join(BRAIN_DIR, item);
		const dst = path.join(DRIVE_BASE, item);
		if (fs.existsSync(src)) {
			fs.cpSync(src, dst, { recursive: true });
		}
	}
	console.log('  ✔ Bóveda Obsidian, puentes (AGENTS.md, CLAUDE.md, GEMINI.md) y ADRs sincronizados en Google Drive.');
}

// 8. Guardar Manifiesto tanto local como en Google Drive
const manifestJson = JSON.stringify(manifest, null, 2);
fs.writeFileSync(path.join(DOCS_DIR, 'MANIFEST.json'), manifestJson);
if (driveAvailable) {
	fs.writeFileSync(path.join(DRIVE_BASE, 'MANIFEST.json'), manifestJson);
}

// 9. Sincronización Directa de Fuentes con los Cuadernos en NotebookLM
const syncSourcesScript = path.join(ROOT_DIR, 'tools', 'sync-notebooklm-sources.mjs');
if (!fs.existsSync(syncSourcesScript)) {
	throw new Error('Falta el sincronizador de fuentes NotebookLM.');
}
console.log('\n[NOTEBOOKLM] Sincronizando las fuentes y comprobando su estado remoto...');
execFileSync('node', [syncSourcesScript, ...process.argv.slice(2)], { stdio: 'inherit' });
console.log('  ✔ Fuentes de NotebookLM verificadas.');

console.log(`\n=== SINCRONIZACIÓN EXITOSA ===`);
console.log(`  • Documentos sincronizados: ${files.length} archivos .md`);
console.log(`  • Destino local: notbooklm/`);
if (driveAvailable) {
	console.log(`  • Destino Google Drive: ${DRIVE_BASE}`);
	console.log(`  • Estado Drive: Los archivos se subirán automáticamente a Google Cloud en segundo plano.`);
	console.log(`  • En NotebookLM: Puedes vincular directamente la carpeta 'Akibara-Knowledge-Base' desde Google Drive.`);
}
