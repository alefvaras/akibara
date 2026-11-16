#!/usr/bin/env node
/**
 * tools/doc-drift-watchdog.mjs
 *
 * Guardián de Deriva Documental y Sincronización Automática de Akibara.
 * Supervisa que ante cambios de código fuente, los documentos de notbooklm/
 * y docs/brain/ mantengan coherencia absoluta y se sincronicen hacia Google Drive
 * y NotebookLM de forma transparente y sin intervención manual.
 */

import fs from 'node:fs';
import path from 'node:path';
import { execSync } from 'node:child_process';

const ROOT_DIR = process.cwd();
const DOCS_DIR = path.join(ROOT_DIR, 'notbooklm');
const BRAIN_DIR = path.join(ROOT_DIR, 'docs', 'brain');

// Mapeo canónico: Módulos de Código -> Archivos de Documentación
const MODULE_DOC_MAP = [
	{
		name: 'Hondana / Colección y Estantería',
		match: /^public_html\/wp-content\/plugins\/akibara-business\/src\/Modules\/AccountCollection\//,
		doc: 'notbooklm/01-B-MODULO-COLECCION-Y-ESTANTERIA-FLUJO-COMPLETO.md',
		adrPrefix: 'ADR-016',
	},
	{
		name: 'Akibara Rewards / Puntos y Reseñas',
		match: /^public_html\/wp-content\/plugins\/akibara-rewards\//,
		doc: 'notbooklm/02-SISTEMA-DE-PUNTOS-Y-FIDELIZACION-AKIBARA-REWARDS.md',
		adrPrefix: 'ADR-012',
	},
	{
		name: 'Akibara Search / Motor de Búsqueda',
		match: /^public_html\/wp-content\/plugins\/akibara-search\//,
		doc: 'notbooklm/03-MOTOR-DE-BUSQUEDA-AKIBARA-SEARCH.md',
		adrPrefix: 'ADR-013',
	},
	{
		name: 'Checkout Blocks / Carrito y PUDO',
		match: /^public_html\/wp-content\/plugins\/akibara-cart-checkout-blocks\//,
		doc: 'notbooklm/04-CHECKOUT-CARRITO-Y-BLOQUES-AKIBARA-CART-CHECKOUT-BLOCKS.md',
		adrPrefix: 'ADR-004',
	},
	{
		name: 'Tema Akibara GP / UI y CSS',
		match: /^public_html\/wp-content\/themes\/akibara-gp\//,
		doc: 'notbooklm/05-TEMA-FRONTEND-Y-EXPERIENCIA-DE-USUARIO-AKIBARA-GP.md',
		adrPrefix: 'ADR-011',
	},
	{
		name: 'MU-Plugins / Infraestructura y Bluex',
		match: /^public_html\/wp-content\/mu-plugins\//,
		doc: 'notbooklm/06-MU-PLUGINS-INFRAESTRUCTURA-Y-HERRAMIENTAS-CRITICAS.md',
		adrPrefix: 'ADR-014',
	},
	{
		name: 'Catálogo / Precios / Disponibilidad Business',
		match: /^public_html\/wp-content\/plugins\/akibara-business\/src\/Modules\/(PricingMatrix|Stock|TaxonomyDiscount|SeoEnhancements)\//,
		doc: 'notbooklm/01-MODULOS-CORE-AKIBARA-BUSINESS.md',
		adrPrefix: 'ADR-010',
	},
];

function getChangedFiles() {
	try {
		// Detecta archivos en stage + modificados respecto a HEAD
		const staged = execSync('git diff --cached --name-only', { encoding: 'utf8' }).trim().split('\n').filter(Boolean);
		const unstaged = execSync('git diff --name-only HEAD', { encoding: 'utf8' }).trim().split('\n').filter(Boolean);
		return Array.from(new Set([...staged, ...unstaged]));
	} catch {
		return [];
	}
}

function auditDocumentationDrift(changedFiles) {
	const warnings = [];
	const touchedDocs = new Set(changedFiles.filter(f => f.startsWith('notbooklm/') || f.startsWith('docs/brain/')));

	for (const mod of MODULE_DOC_MAP) {
		const codeChanges = changedFiles.filter(f => mod.match.test(f));
		if (codeChanges.length > 0) {
			const hasDocUpdate = touchedDocs.has(mod.doc) ||
				Array.from(touchedDocs).some(d => d.includes(mod.adrPrefix));

			if (!hasDocUpdate) {
				warnings.push({
					module: mod.name,
					files: codeChanges,
					suggestedDoc: mod.doc,
				});
			}
		}
	}

	return warnings;
}

// 1. Detección de archivos modificados
const changed = getChangedFiles();
const driftWarnings = auditDocumentationDrift(changed);

if (driftWarnings.length > 0) {
	console.log('\n[WATCHDOG DOCUMENTAL] Se detectaron cambios de código con posible deriva documental:');
	for (const w of driftWarnings) {
		console.log(`  ⚠️ Módulo: ${w.module}`);
		console.log(`     Archivos de código tocados: ${w.files.length} (${w.files.slice(0, 3).join(', ')}${w.files.length > 3 ? '...' : ''})`);
		console.log(`     Documento canónico a revisar: ${w.suggestedDoc}`);
	}
	console.log('  💡 Asegúrate de que las decisiones arquitectónicas estén reflejadas en notbooklm/ y docs/brain/decisiones/\n');
} else {
	console.log('[WATCHDOG DOCUMENTAL] ✔ Cero deriva documental detectada entre código y documentación.');
}

// 2. Ejecutar sincronización de NotebookLM si hay cambios o si se invoca directamente
console.log('[WATCHDOG DOCUMENTAL] Ejecutando sincronización continua de volúmenes y Google Drive...');
try {
	execSync('node tools/sync-notebooklm.mjs', { stdio: 'inherit', cwd: ROOT_DIR });
} catch (e) {
	console.error('[WATCHDOG DOCUMENTAL] Error en tools/sync-notebooklm.mjs:', e.message);
	process.exit(1);
}
