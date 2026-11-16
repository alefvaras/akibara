#!/usr/bin/env node
/**
 * tools/sync-notebooklm-sources.mjs
 *
 * Sincronizador automático inteligente entre la Bóveda de Obsidian / Documentación de Akibara
 * y los cuadernos oficiales en Google NotebookLM para todas las cuentas autorizadas:
 * 1. ale.fvaras@gmail.com (perfil: ale-fvaras -> cuaderno "akibara")
 * 2. alejandro.fvaras@gmail.com (perfil: alejandro-fvaras -> cuaderno "analisis")
 * 3. donatello20260@gmail.com (perfil: donatello20260 -> cuaderno "akibara")
 *
 * Características:
 * - Detecta automáticamente archivos modificados mediante SHA-256 por cuaderno en .notebooklm-sync-state.json.
 * - Purgado defensivo de fuentes obsoletas (monolitos antiguos no particionados) y fuentes en estado de error.
 * - Erradicación automática de duplicados: deja exactamente 1 copia activa por documento.
 * - Creación automática de cuaderno si no existe en la cuenta.
 * - Pacing y reintentos automáticos para evitar throttles HTTP 500 de Google.
 * - Protección de cuota estricta (< 50 fuentes por cuaderno).
 */

import { execFileSync, execSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';
import os from 'node:os';

const ROOT_DIR = process.cwd();
const STATE_FILE = path.join(ROOT_DIR, 'tools', '.notebooklm-sync-state.json');

const KNOWN_TARGETS = [
	{
		profile: 'alejandro-fvaras',
		notebookId: '1e6160e5-07f2-47eb-a21a-930dd90c9cd1',
		name: 'analisis',
		email: 'alejandro.fvaras@gmail.com',
	},
	{
		profile: 'ale-fvaras',
		notebookId: '06cc0698-c80f-4ac9-bc38-d8d15a96a17f',
		name: 'akibara',
		email: 'ale.fvaras@gmail.com',
	},
	{
		profile: 'donatello20260',
		notebookId: '8cfdcfe7-c596-4145-8f49-9b5f5ede69e5',
		name: 'akibara',
		email: 'donatello20260@gmail.com',
	},
];

const STALE_TITLES = [
	'AKIBARA_CODIGO_COMPLETO.md',
	'AKIBARA_CODIGO_01_BUSINESS.md',
	'AKIBARA_CODIGO_02_REWARDS_Y_SEARCH.md',
	'AKIBARA_CODIGO_03_TEMA_BLOQUES_Y_MU.md',
];

function log(msg) {
	console.log(`[NotebookLM Sync] ${msg}`);
}

function sleep(ms) {
	return new Promise(resolve => setTimeout(resolve, ms));
}

function sha256File(filePath) {
	const content = fs.readFileSync(filePath);
	return crypto.createHash('sha256').update(content).digest('hex');
}

let cachedNotebookLMBin = null;

function resolveNotebookLMBin() {
	if (cachedNotebookLMBin) return cachedNotebookLMBin;
	const candidates = [
		path.join(os.homedir(), '.local', 'bin', 'notebooklm'),
		'/opt/homebrew/bin/notebooklm',
		'/usr/local/bin/notebooklm',
	];
	try {
		const fromPath = execSync('which notebooklm', { encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] }).trim();
		if (fromPath) {
			cachedNotebookLMBin = fromPath;
			return cachedNotebookLMBin;
		}
	} catch {
		// Continuar con rutas conocidas si PATH mínimo no incluye ~/.local/bin.
	}
	for (const candidate of candidates) {
		if (fs.existsSync(candidate)) {
			cachedNotebookLMBin = candidate;
			return cachedNotebookLMBin;
		}
	}
	return null;
}

function runNotebookLM(args, profile = null, options = {}) {
	const bin = resolveNotebookLMBin() || 'notebooklm';
	const finalArgs = profile ? ['-p', profile, ...args] : args;
	return execFileSync(bin, finalArgs, {
		encoding: 'utf8',
		stdio: options.stdio || ['pipe', 'pipe', 'pipe'],
		timeout: options.timeout || 180000,
		...options,
	});
}

function readNotebookLMJson(args, profile, listKey) {
	const parsed = JSON.parse(runNotebookLM(args, profile));
	if (!parsed || parsed.error || (listKey && !Array.isArray(parsed[listKey]))) {
		throw new Error(`Respuesta inválida de NotebookLM: ${parsed?.code || listKey || 'JSON'}`);
	}
	return parsed;
}

function listSources(notebookId, profile) {
	return readNotebookLMJson(['source', 'list', '-n', notebookId, '--json'], profile, 'sources').sources;
}

async function safeAddSource(notebookId, filePath, title, profile, previousSources) {
	const previousIds = new Set(previousSources.map(source => source.id));
	try {
		runNotebookLM(['source', 'add', '-n', notebookId, filePath, '--title', title], profile);
		return true;
	} catch (e) {
		// Reconciliar: si Google devolvió 500 en finalize, la fuente frecuentemente ya fue creada y está en 'preparing'
		try {
			await sleep(2000);
			const sources = listSources(notebookId, profile);
			const found = sources.some(s => s.title === title && s.id && !previousIds.has(s.id) && !['error', 'failed'].includes(s.status));
			if (found) {
				log(`    ✔ Fuente "${title}" confirmada en lista de fuentes.`);
				return true;
			}
		} catch {
			// fallback
		}
		console.warn(`    ⚠ Falló subida de "${title}": ${e.message.slice(0, 120)}`);
		return false;
	}
}

function getAvailableProfiles() {
	const profilesDir = path.join(os.homedir(), '.notebooklm', 'profiles');
	if (fs.existsSync(profilesDir)) {
		try {
			return fs.readdirSync(profilesDir).filter(f => !f.startsWith('.'));
		} catch {
			return [];
		}
	}
	return [];
}

function ensureTargetNotebook(target) {
	const { profile, notebookId, name } = target;
	// Un error de autenticación no equivale a un cuaderno ausente.
	const list = readNotebookLMJson(['list', '--json'], profile, 'notebooks').notebooks;

	if (notebookId) {
		const found = list.find(n => n.id === notebookId);
		if (found) return notebookId;
	}

	// Buscar por nombre
	const byName = list.find(n => (n.title || '').toLowerCase() === name.toLowerCase());
	if (byName) {
		target.notebookId = byName.id;
		return byName.id;
	}

	// Crear nuevo cuaderno si no existe
	log(`Creando cuaderno "${name}" para perfil "${profile}"...`);
	const created = readNotebookLMJson(['create', name, '--json'], profile);
	const newId = created.notebook?.id;
	if (!newId) {
		throw new Error(`No se pudo confirmar el cuaderno creado: ${name}`);
	}
	target.notebookId = newId;
	log(`Cuaderno "${name}" creado exitosamente (ID: ${newId}).`);
	return newId;
}

async function syncTarget(target, localFiles, allState) {
	const { profile, name, email } = target;
	const notebookId = ensureTargetNotebook(target);

	log(`\n============================================================`);
	log(`SINCRONIZANDO: Cuenta ${email || profile} | Cuaderno "${name}" (${notebookId})`);
	log(`============================================================`);

	// 1. Obtener lista de fuentes actuales
	const existingSources = listSources(notebookId, profile);

	log(`Fuentes actuales detectadas: ${existingSources.length}/50`);

	// Estado para este cuaderno
	allState[notebookId] = allState[notebookId] || {};
	const nbState = allState[notebookId];

	// 2. Agrupar por título: Map<string, Array<Source>>
	const sourcesByTitle = new Map();
	for (const s of existingSources) {
		if (!sourcesByTitle.has(s.title)) {
			sourcesByTitle.set(s.title, []);
		}
		sourcesByTitle.get(s.title).push(s);
	}

	// 3. Purgar monolitos obsoletos
	for (const stale of STALE_TITLES) {
		const list = sourcesByTitle.get(stale);
		if (list && list.length > 0) {
			for (const s of list) {
				log(`Eliminando monolito obsoleto: "${stale}" (ID: ${s.id})...`);
				try {
					runNotebookLM(['source', 'delete', '-n', notebookId, '-y', s.id], profile);
				} catch (e) {
					console.warn(`  ⚠ Error al eliminar "${stale}": ${e.message}`);
				}
			}
			sourcesByTitle.delete(stale);
			delete nbState[stale];
		}
	}

	// 4. Purgar fuentes en estado de error
	for (const [title, list] of sourcesByTitle.entries()) {
		const validList = [];
		for (const s of list) {
			if (s.status === 'error' || s.status === 'failed') {
				log(`Eliminando fuente en estado error: "${title}" (ID: ${s.id})...`);
				try {
					runNotebookLM(['source', 'delete', '-n', notebookId, '-y', s.id], profile);
				} catch (e) {
					console.warn(`  ⚠ Error al eliminar fuente con error "${title}": ${e.message}`);
				}
			} else {
				validList.push(s);
			}
		}
		if (validList.length > 0) {
			sourcesByTitle.set(title, validList);
		} else {
			sourcesByTitle.delete(title);
		}
	}

	let refreshedCount = 0;
	let updatedCount = 0;
	let addedCount = 0;
	let upToDateCount = 0;
	let duplicatesPurged = 0;

	// Conteo dinámico de fuentes
	let currentTotalSources = 0;
	for (const list of sourcesByTitle.values()) {
		currentTotalSources += list.length;
	}

	// 5. Procesar archivos locales
	for (const [title, filePath] of localFiles.entries()) {
		const currentHash = sha256File(filePath);
		const previousHash = nbState[title];
		const sources = sourcesByTitle.get(title) || [];

		// CASO A: Múltiples copias detectadas (Duplicados)
		if (sources.length > 1) {
			log(`Detectadas ${sources.length} copias de "${title}". Purgando duplicados...`);
			for (const s of sources) {
				try {
					runNotebookLM(['source', 'delete', '-n', notebookId, '-y', s.id], profile);
					currentTotalSources--;
					duplicatesPurged++;
				} catch (e) {
					console.warn(`  ⚠ Error eliminando copia de "${title}": ${e.message}`);
				}
			}
			sourcesByTitle.delete(title);

			// Subir versión limpia y fresca desde disco con backoff defensivo
			await sleep(1500);
			log(`Subiendo versión limpia y unificada de "${title}"...`);
			const ok = await safeAddSource(notebookId, filePath, title, profile, sources);
			if (ok) {
				nbState[title] = currentHash;
				sourcesByTitle.set(title, [{ title, id: 'new', status: 'ready' }]);
				currentTotalSources++;
				updatedCount++;
			}
			continue;
		}

		// CASO B: Exactamente 1 copia
		if (sources.length === 1) {
			const s = sources[0];

			// Subcaso B.1: Fuente Google Drive vinculada
			if (s.type === 'google_drive') {
				if (previousHash !== currentHash) {
					log(`Refrescando fuente de Google Drive: "${title}"...`);
					try {
						runNotebookLM(['source', 'refresh', '-n', notebookId, s.id], profile);
						nbState[title] = currentHash;
						refreshedCount++;
					} catch (e) {
						console.warn(`  ⚠ Falló refresco de Drive para "${title}": ${e.message}`);
					}
				} else {
					upToDateCount++;
				}
				continue;
			}

			// Subcaso B.2: Markdown directo (primero sube versión nueva, luego elimina anterior)
			if (previousHash !== currentHash) {
				log(`Actualizando fuente modificada: "${title}"...`);
				await sleep(1500);
				const ok = await safeAddSource(notebookId, filePath, title, profile, sources);
				if (ok) {
					try {
						runNotebookLM(['source', 'delete', '-n', notebookId, '-y', s.id], profile);
					} catch (e) {
						console.warn(`  ⚠ Error eliminando versión anterior de "${title}": ${e.message}`);
					}
					nbState[title] = currentHash;
					sourcesByTitle.set(title, [{ title, id: 'new', status: 'ready' }]);
					updatedCount++;
				}
			} else {
				upToDateCount++;
			}
			continue;
		}

		// CASO C: Nueva fuente a agregar
		if (sources.length === 0) {
			if (currentTotalSources >= 48) {
				log(`  [CUOTA] Omitiendo alta de "${title}": capacidad casi al límite (${currentTotalSources}/50).`);
				continue;
			}

			log(`Agregando nueva fuente: "${title}"...`);
			await sleep(1500);
			const ok = await safeAddSource(notebookId, filePath, title, profile, sources);
			if (ok) {
				nbState[title] = currentHash;
				sourcesByTitle.set(title, [{ title, id: 'new', status: 'ready' }]);
				currentTotalSources++;
				addedCount++;
			}
		}
	}

	// 6. Purgar duplicados en fuentes externas que no sean archivos locales
	for (const [title, list] of sourcesByTitle.entries()) {
		if (!localFiles.has(title) && list.length > 1) {
			log(`Purgando ${list.length - 1} duplicados de fuente externa: "${title}"...`);
			for (let i = 1; i < list.length; i++) {
				try {
					runNotebookLM(['source', 'delete', '-n', notebookId, '-y', list[i].id], profile);
					duplicatesPurged++;
				} catch (e) {
					console.warn(`  ⚠ Error al eliminar duplicado externo: ${e.message}`);
				}
			}
		}
	}

	log(`\n=== RESUMEN CUADERNO "${name}" (${email || profile}) ===`);
	log(`  • Fuentes al día (sin cambios): ${upToDateCount}`);
	log(`  • Duplicados eliminados: ${duplicatesPurged}`);
	log(`  • Fuentes actualizadas/reemplazadas: ${updatedCount}`);
	log(`  • Fuentes de Drive refrescadas: ${refreshedCount}`);
	log(`  • Nuevas fuentes agregadas: ${addedCount}`);
	log(`  • Total fuentes activas: ${currentTotalSources}/50`);

	let pendingTitles = [];
	const maxChecks = updatedCount + addedCount + refreshedCount > 0 ? 5 : 1;
	for (let attempt = 0; attempt < maxChecks; attempt++) {
		const finalSources = listSources(notebookId, profile);
		pendingTitles = [];
		for (const [title, filePath] of localFiles) {
			const recordedHash = Object.hasOwn(nbState, title)
				? Object.getOwnPropertyDescriptor(nbState, title)?.value
				: undefined;
			if (recordedHash !== sha256File(filePath)) {
				throw new Error(`Hash local no confirmado en ${name}: ${title}`);
			}
			const copies = finalSources.filter(source => source.title === title);
			if (copies.length > 1) {
				throw new Error(`Duplicado remoto detectado en ${name}: ${title} (${copies.length})`);
			}
			if (copies.length === 0) {
				pendingTitles.push(title);
				continue;
			}
			const status = copies[0].status;
			if (status === 'error' || status === 'failed') {
				throw new Error(`Fuente en error en ${name}: ${title} (${status})`);
			}
			if (status !== 'ready') {
				pendingTitles.push(title);
			}
		}
		if (pendingTitles.length === 0) {
			break;
		}
		if (attempt + 1 < maxChecks) {
			await sleep(2500);
		}
	}
	if (pendingTitles.length > 0) {
		throw new Error(`Fuente pendiente de confirmar en ${name}: ${pendingTitles[0]}`);
	}
	log(`✔ Sincronización de "${name}" finalizada.`);
}

async function main() {
	log('=== PIPELINE MULTI-CUADERNO NOTEBOOKLM (Ale / Alejandro / Donatello) ===');

	// 1. Verificar CLI notebooklm
	if (!resolveNotebookLMBin()) {
		console.error('Error: CLI "notebooklm" no encontrado en PATH ni ~/.local/bin. Ejecuta: uv tool install notebooklm-py');
		process.exit(1);
	}

	// 2. Parsear argumentos CLI
	const args = process.argv.slice(2);
	let cliProfile = null;
	let cliNotebook = null;

	for (let i = 0; i < args.length; i++) {
		if (args[i] === '--profile' || args[i] === '-p') {
			cliProfile = args[++i];
		} else if (args[i] === '--notebook' || args[i] === '-n') {
			cliNotebook = args[++i];
		}
	}

	if (!cliProfile && process.env.NOTEBOOKLM_PROFILE) {
		cliProfile = process.env.NOTEBOOKLM_PROFILE;
	}
	if (!cliNotebook && process.env.NOTEBOOKLM_NOTEBOOK) {
		cliNotebook = process.env.NOTEBOOKLM_NOTEBOOK;
	}

	// 3. Determinar targets a sincronizar
	let targets = [];
	const availableProfiles = getAvailableProfiles();

	if (cliNotebook) {
		targets.push({
			profile: cliProfile || null,
			notebookId: cliNotebook,
			name: cliNotebook,
			email: cliProfile,
		});
	} else if (cliProfile) {
		const matched = KNOWN_TARGETS.filter(t => t.profile === cliProfile);
		if (matched.length > 0) {
			targets.push(...matched);
		} else {
			targets.push({ profile: cliProfile, notebookId: cliProfile, name: cliProfile, email: cliProfile });
		}
	} else {
		// Por defecto: sincronizar los 3 perfiles oficiales de Akibara
		for (const target of KNOWN_TARGETS) {
			if (!target.profile || availableProfiles.includes(target.profile)) {
				targets.push(target);
			} else {
				log(`Aviso: Perfil "${target.profile}" no encontrado en ~/.notebooklm/profiles. Omitiendo "${target.name}".`);
			}
		}

		if (targets.length === 0) {
			targets.push({
				profile: null,
				notebookId: 'akibara',
				name: 'akibara',
				email: 'default',
			});
		}
	}

	// 4. Mapear archivos locales candidatos
	const localFiles = new Map(); // title -> fullPath

	// A) Documentos de arquitectura y particiones de código en notbooklm/
	const notbooklmDir = path.join(ROOT_DIR, 'notbooklm');
	if (fs.existsSync(notbooklmDir)) {
		const entries = fs.readdirSync(notbooklmDir).filter(f => f.endsWith('.md')).sort();
		for (const f of entries) {
			localFiles.set(f, path.join(notbooklmDir, f));
		}
	}

	// B) Decisiones de arquitectura (ADRs) en docs/brain/decisiones/
	const adrDir = path.join(ROOT_DIR, 'docs', 'brain', 'decisiones');
	if (fs.existsSync(adrDir)) {
		const adrEntries = fs.readdirSync(adrDir).filter(f => f.endsWith('.md')).sort();
		for (const f of adrEntries) {
			localFiles.set(f, path.join(adrDir, f));
		}
	}

	// C) Documentos raíz de docs/brain/
	const brainRoot = path.join(ROOT_DIR, 'docs', 'brain');
	const rootMds = ['AGENTS.md', 'CLAUDE.md', 'CONTEXTO_VIVO.md', 'lecciones.md', 'auditoria-integral-panel-akibara.md'];
	for (const f of rootMds) {
		const p = path.join(brainRoot, f);
		if (fs.existsSync(p)) {
			localFiles.set(f, p);
		}
	}

	log(`Total de documentos locales candidatos para sincronizar: ${localFiles.size}`);

	// 5. Cargar estado de sincronización persistente
	let allState = {};
	if (fs.existsSync(STATE_FILE)) {
		try {
			const parsedState = JSON.parse(fs.readFileSync(STATE_FILE, 'utf8'));
			if (parsedState && typeof parsedState === 'object') {
				const isFlat = Object.values(parsedState).some(v => typeof v === 'string');
				if (isFlat) {
					allState = { 'legacy': parsedState };
				} else {
					allState = parsedState;
				}
			}
		} catch {
			allState = {};
		}
	}

	// 6. Ejecutar sincronización para cada target
	for (const target of targets) {
		await syncTarget(target, localFiles, allState);
		// Guardar progreso intermedio de hashes tras cada cuaderno
		fs.writeFileSync(STATE_FILE, JSON.stringify(allState, null, 2), 'utf8');
	}

	log('\n=== PIPELINE DE FUENTES MULTI-CUADERNO COMPLETADO EXITOSAMENTE ===\n');
}

main().catch(err => {
	console.error('Error fatal durante la sincronización:', err);
	process.exit(1);
});
