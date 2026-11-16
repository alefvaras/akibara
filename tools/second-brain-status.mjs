#!/usr/bin/env node
/**
 * tools/second-brain-status.mjs
 *
 * Diagnóstico rápido de salud del Segundo Cerebro Interoperable:
 * Google Drive, Bóveda Obsidian, Memanto, Grafo AST y Manifiesto NotebookLM.
 */

import fs from 'node:fs';
import path from 'node:path';
import os from 'node:os';
import { execSync } from 'node:child_process';

const ROOT_DIR = process.cwd();
const DOCS_DIR = path.join(ROOT_DIR, 'notbooklm');
const BRAIN_DIR = path.join(ROOT_DIR, 'docs', 'brain');
const GRAPH_FILE = path.join(ROOT_DIR, 'graphify-out', 'graph.json');
const DRIVE_BASE = path.join(
	os.homedir(),
	'Library',
	'CloudStorage',
	'GoogleDrive-ale.fvaras@gmail.com',
	'Mi unidad',
	'Akibara-Knowledge-Base'
);

console.log('╭─────────────────────────────────────────────────────────────╮');
console.log('│  DIAGNÓSTICO DE SALUD: SEGUNDO CEREBRO INTEROPERABLE (PRO)  │');
console.log('╰─────────────────────────────────────────────────────────────╯\n');

let allHealthy = true;

// 1. Google Drive & Bóveda Obsidian
console.log('1. Conexión Google Drive & Bóveda Obsidian:');
if (fs.existsSync(DRIVE_BASE)) {
	const files = fs.readdirSync(DRIVE_BASE);
	const hasObsidian = fs.existsSync(path.join(DRIVE_BASE, '.obsidian'));
	const hasAgents = fs.existsSync(path.join(DRIVE_BASE, 'AGENTS.md'));
	const hasManifest = fs.existsSync(path.join(DRIVE_BASE, 'MANIFEST.json'));

	console.log(`   ✔ Google Drive montado: ${DRIVE_BASE}`);
	console.log(`   ✔ Archivos en Bóveda: ${files.length} elementos`);
	console.log(`   ✔ Bóveda Obsidian (.obsidian/): ${hasObsidian ? 'ACTIVA' : 'FALTA'}`);
	console.log(`   ✔ Prompt Maestro (AGENTS.md): ${hasAgents ? 'PRESENTE' : 'FALTA'}`);
	console.log(`   ✔ Manifiesto SHA-256: ${hasManifest ? 'PRESENTE' : 'FALTA'}`);

	if (!hasObsidian || !hasAgents || !hasManifest) allHealthy = false;
} else {
	console.error(`   ❌ No se detectó la carpeta en Google Drive: ${DRIVE_BASE}`);
	allHealthy = false;
}

// 2. Memanto CLI & Servidor de Memoria
console.log('\n2. Memoria de Agentes (Memanto):');
try {
	const memantoPath = execSync('which memanto', { encoding: 'utf8' }).trim();
	console.log(`   ✔ CLI Memanto: ${memantoPath}`);
	try {
		const statusOut = execSync('memanto status', { encoding: 'utf8' });
		const isOnline = statusOut.includes('online');
		const agentMatch = statusOut.match(/Agent\s+([a-zA-Z0-9_-]+)/);
		console.log(`   ✔ Servidor On-Prem: ${isOnline ? 'ONLINE (http://localhost:8877)' : 'OFFLINE'}`);
		console.log(`   ✔ Agente Activo: ${agentMatch ? agentMatch[1] : 'No identificado'}`);
		if (!isOnline) allHealthy = false;
	} catch (e) {
		console.warn(`   ⚠ Advertencia consultando status: ${e.message}`);
	}
} catch {
	console.error('   ❌ CLI memanto no encontrado en PATH');
	allHealthy = false;
}

// 3. Documentos de Arquitectura & Guardrails
console.log('\n3. Documentación y Guardrails (notbooklm/):');
if (fs.existsSync(DOCS_DIR)) {
	const mdFiles = fs.readdirSync(DOCS_DIR).filter(f => f.endsWith('.md'));
	console.log(`   ✔ Documentos Markdown canónicos: ${mdFiles.length} archivos`);

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

	let issues = 0;
	for (const f of mdFiles) {
		const lines = fs.readFileSync(path.join(DOCS_DIR, f), 'utf8').split('\n');
		lines.forEach((line, idx) => {
			for (const rule of FORBIDDEN_RULES) {
				if (rule.test(line)) {
					console.error(`     ❌ Violación en ${f} (L${idx + 1}): ${rule.name}`);
					issues++;
				}
			}
		});
	}
	if (issues === 0) {
		console.log('   ✔ Guardrails de consistencia: 100% LIMPIO (0 violaciones)');
	} else {
		allHealthy = false;
	}
} else {
	console.error('   ❌ Directorio notbooklm/ no encontrado');
	allHealthy = false;
}

// 4. Grafo de Conocimiento (Graphify AST & Cypher)
console.log('\n4. Grafo de Conocimiento AST & Neo4j:');
if (fs.existsSync(GRAPH_FILE)) {
	try {
		const graphData = JSON.parse(fs.readFileSync(GRAPH_FILE, 'utf8'));
		const nodes = graphData.nodes ? graphData.nodes.length : 0;
		const links = graphData.links ? graphData.links.length : 0;
		console.log(`   ✔ Graphify AST: ${nodes.toLocaleString()} nodos, ${links.toLocaleString()} aristas`);
	} catch (e) {
		console.warn(`   ⚠ No se pudo leer graph.json: ${e.message}`);
	}
} else {
	console.warn('   ⚠ graphify-out/graph.json no encontrado');
}

const cypherFile = path.join(ROOT_DIR, 'graphify-out', 'akibara_neo4j_export.cypher');
if (fs.existsSync(cypherFile)) {
	const stat = fs.statSync(cypherFile);
	console.log(`   ✔ Export Cypher Neo4j: ${stat.size.toLocaleString()} bytes`);
}

// 5. Estado Global
console.log('\n' + '─'.repeat(61));
if (allHealthy) {
	console.log('  ESTADO GLOBAL: SALUDABLE (Todo el pipeline está operativo)');
} else {
	console.log('  ESTADO GLOBAL: CON ADVERTENCIAS (Revisa los puntos marcados con ❌)');
}
console.log('─'.repeat(61) + '\n');
