#!/usr/bin/env node
/**
 * tools/sync-notebooklm-sources.mjs
 *
 * Sincronizador automático inteligente entre la Bóveda de Obsidian / Documentación de Akibara
 * y el cuaderno oficial en Google NotebookLM:
 * 1. ale.fvaras@gmail.com (perfil: ale-fvaras -> cuaderno "akibara")
 *
 * Características:
 * - Detecta automáticamente archivos modificados mediante SHA-256 por cuaderno en .local-quality/notebooklm-sync-state.json.
 * - Purgado defensivo de fuentes obsoletas (monolitos antiguos no particionados) y fuentes en estado de error.
 * - Erradicación automática de duplicados: deja exactamente 1 copia activa por documento.
 * - Creación automática de cuaderno si no existe en la cuenta.
 * - Pacing y reintentos automáticos para evitar throttles HTTP 500 de Google.
 * - Protección de cuota estricta (< 50 fuentes por cuaderno).
 */

import { execFileSync, spawnSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';
import os from 'node:os';

const ROOT_DIR = process.cwd();
const STATE_DIR = path.join(ROOT_DIR, '.local-quality');
const STATE_FILE = path.join(STATE_DIR, 'notebooklm-sync-state.json');
const LEGACY_STATE_FILE = path.join(ROOT_DIR, 'tools', '.notebooklm-sync-state.json');

const KNOWN_TARGETS = [
	{
		profile: 'ale-fvaras',
		notebookId: '06cc0698-c80f-4ac9-bc38-d8d15a96a17f',
		name: 'akibara',
		email: 'ale.fvaras@gmail.com',
		role: 'primary',
	},
];

const STALE_TITLES = [
	'AKIBARA_CODIGO_COMPLETO.md',
	'AKIBARA_CODIGO_01_BUSINESS.md',
	'AKIBARA_CODIGO_02_REWARDS_Y_SEARCH.md',
	'AKIBARA_CODIGO_03_TEMA_BLOQUES_Y_MU.md',
];

const AUTH_ERROR_CODES = new Set([
	'AUTH_ERROR',
	'UNAUTHENTICATED',
	'EXPIRED_TOKEN',
	'LOGIN_REQUIRED',
]);

class NotebookLMAuthError extends Error {
	constructor(message, code = 'AUTH_ERROR', profile = null) {
		super(message);
		this.name = 'NotebookLMAuthError';
		this.code = code;
		this.profile = profile;
		this.isAuthError = true;
	}
}

function readOwn(obj, key) {
	if (!obj || typeof obj !== 'object' || !Object.hasOwn(obj, key)) return undefined;
	return Object.getOwnPropertyDescriptor(obj, key)?.value;
}

function writeOwn(obj, key, value) {
	Object.defineProperty(obj, key, {
		value,
		writable: true,
		enumerable: true,
		configurable: true,
	});
}

function deleteOwn(obj, key) {
	if (obj && typeof obj === 'object' && Object.hasOwn(obj, key)) {
		Reflect.deleteProperty(obj, key);
	}
}

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
	const whichResult = spawnSync('which', ['notebooklm'], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] });
	if (whichResult.status === 0) {
		const fromPath = (whichResult.stdout || '').trim();
		if (fromPath) {
			cachedNotebookLMBin = fromPath;
			return cachedNotebookLMBin;
		}
	} else if (whichResult.error && whichResult.error.code !== 'ENOENT') {
		console.warn(`⚠ No se pudo inspeccionar PATH para notebooklm: ${whichResult.error.message}`);
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

function parseJsonSafe(raw) {
	if (!raw || typeof raw !== 'string') return null;
	const trimmed = raw.trim();
	if (!trimmed.startsWith('{') && !trimmed.startsWith('[')) return null;
	try {
		return JSON.parse(trimmed);
	} catch (error) {
		console.warn(`[NotebookLM] Salida JSON inválida ignorada: ${error.message}`);
		return null;
	}
}

function extractProcessStreamText(stream) {
	if (typeof stream === 'string') return stream;
	if (Buffer.isBuffer(stream)) return stream.toString('utf8');
	return '';
}

function detectAuthError(parsed, stderrText = '', stdoutText = '', profile = null) {
	const code = typeof parsed?.code === 'string' ? parsed.code.trim() : '';
	const combinedText = `${parsed?.message || ''}\n${stdoutText}\n${stderrText}`;
	if (
		AUTH_ERROR_CODES.has(code) ||
		/AUTH_ERROR|Authentication expired|notebooklm login|re-authenticate|UNAUTHENTICATED/i.test(combinedText)
	) {
		return new NotebookLMAuthError(
			parsed?.message || `Sesión expirada o inválida en NotebookLM (perfil: ${profile || 'default'})`,
			code || 'AUTH_ERROR',
			profile
		);
	}
	return null;
}

function readNotebookLMJson(args, profile, listKey) {
	let rawOutput;
	try {
		rawOutput = runNotebookLM(args, profile);
	} catch (err) {
		const stdoutText = extractProcessStreamText(err?.stdout);
		const stderrText = extractProcessStreamText(err?.stderr);
		const parsedErr = parseJsonSafe(stdoutText) || parseJsonSafe(stderrText);
		const authErr = detectAuthError(parsedErr, stderrText, stdoutText, profile);
		if (authErr) throw authErr;
		throw err;
	}
	const parsed = parseJsonSafe(rawOutput);
	if (!parsed || parsed.error || (listKey && !Array.isArray(parsed[listKey]))) {
		const authErr = detectAuthError(parsed, '', rawOutput, profile);
		if (authErr) throw authErr;
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
		const stdoutText = extractProcessStreamText(e?.stdout);
		const stderrText = extractProcessStreamText(e?.stderr);
		const parsedErr = parseJsonSafe(stdoutText) || parseJsonSafe(stderrText);
		const addAuthErr = detectAuthError(parsedErr, stderrText, stdoutText, profile);
		if (addAuthErr) throw addAuthErr;

		// Reconciliar: si Google devolvió 500 en finalize, la fuente frecuentemente ya fue creada y está en 'preparing'
		try {
			await sleep(2000);
			const sources = listSources(notebookId, profile);
			const found = sources.some(s => s.title === title && s.id && !previousIds.has(s.id) && !['error', 'failed'].includes(s.status));
			if (found) {
				log(`    ✔ Fuente "${title}" confirmada en lista de fuentes.`);
				return true;
			}
		} catch (reconcileErr) {
			if (reconcileErr?.isAuthError) {
				throw reconcileErr;
			}
			console.warn(`    ⚠ Reconciliación tras error de subida falló para "${title}": ${reconcileErr?.message || reconcileErr}`);
		}
		console.warn(`    ⚠ Falló subida de "${title}": ${e.message.slice(0, 120)}`);
		return false;
	}
}

function getAvailableProfiles() {
	const profilesDir = path.join(os.homedir(), '.notebooklm', 'profiles');
	if (!fs.existsSync(profilesDir)) {
		return [];
	}
	try {
		return fs.readdirSync(profilesDir).filter(f => !f.startsWith('.'));
	} catch (error) {
		if (error && (error.code === 'ENOENT' || error.code === 'ENOTDIR')) {
			return [];
		}
		console.warn(`⚠ No se pudo leer el directorio de perfiles NotebookLM (${profilesDir}): ${error?.message || error}`);
		throw error;
	}
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

	// Estado aislado de trabajo para este cuaderno (commit atómico sólo tras verificación)
	const existingNbState = readOwn(allState, notebookId);
	const nbState = existingNbState && typeof existingNbState === 'object' ? { ...existingNbState } : {};

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
			deleteOwn(nbState, stale);
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
		const previousHash = readOwn(nbState, title);
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
				writeOwn(nbState, title, currentHash);
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
						writeOwn(nbState, title, currentHash);
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
					writeOwn(nbState, title, currentHash);
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
				writeOwn(nbState, title, currentHash);
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
	writeOwn(allState, notebookId, nbState);
	log(`✔ Sincronización de "${name}" finalizada.`);
	return { notebookId, upToDateCount, duplicatesPurged, updatedCount, refreshedCount, addedCount, currentTotalSources };
}

function recordProfileHealth(allState, target, statusInfo) {
	const existingProfiles = readOwn(allState, '_profiles');
	const profiles = existingProfiles && typeof existingProfiles === 'object' ? { ...existingProfiles } : {};
	const key = target.profile || 'default';
	const existing = readOwn(profiles, key);
	const nextEntry = {
		role: target.role || 'mirror',
		notebookId: statusInfo.notebookId || target.notebookId || null,
		notebookName: target.name || null,
		email: target.email || null,
		status: statusInfo.status,
		...(statusInfo.code ? { error: statusInfo.code } : {}),
	};
	const unchanged = Boolean(
		existing
		&& typeof existing === 'object'
		&& existing.role === nextEntry.role
		&& existing.notebookId === nextEntry.notebookId
		&& existing.notebookName === nextEntry.notebookName
		&& existing.email === nextEntry.email
		&& existing.status === nextEntry.status
		&& (Object.hasOwn(existing, 'error') ? existing.error : undefined) === (Object.hasOwn(nextEntry, 'error') ? nextEntry.error : undefined)
	);
	writeOwn(profiles, key, {
		...nextEntry,
		updatedAt: unchanged && typeof existing.updatedAt === 'string' && existing.updatedAt
			? existing.updatedAt
			: new Date().toISOString(),
	});
	writeOwn(allState, '_profiles', profiles);
}

function loadSyncState() {
	const statePath = fs.existsSync(STATE_FILE) ? STATE_FILE : (fs.existsSync(LEGACY_STATE_FILE) ? LEGACY_STATE_FILE : null);
	if (!statePath) {
		return { allState: {}, persistedJson: null };
	}
	try {
		const rawText = fs.readFileSync(statePath, 'utf8');
		const parsedState = JSON.parse(rawText);
		if (parsedState && typeof parsedState === 'object') {
			const isFlat = Object.values(parsedState).some(v => typeof v === 'string');
			const allState = isFlat ? { 'legacy': parsedState } : parsedState;
			return {
				allState,
				persistedJson: statePath === STATE_FILE ? rawText : null,
			};
		}
	} catch (error) {
		if (error && error.code === 'ENOENT') {
			return { allState: {}, persistedJson: null };
		}
		console.warn(`⚠ Estado previo de NotebookLM corrupto o ilegible (${statePath}), se reinicia estado local: ${error?.message || error}`);
		return { allState: {}, persistedJson: null };
	}
	return { allState: {}, persistedJson: null };
}

function persistSyncState(allState, persistedJson) {
	const nextJson = JSON.stringify(allState, null, 2);
	if (nextJson === persistedJson) {
		return persistedJson;
	}
	fs.mkdirSync(STATE_DIR, { recursive: true });
	fs.writeFileSync(STATE_FILE, nextJson, 'utf8');
	return nextJson;
}

async function main() {
	log('=== PIPELINE NOTEBOOKLM (ale.fvaras@gmail.com -> "akibara") ===');

	// 1. Verificar CLI notebooklm
	if (!resolveNotebookLMBin()) {
		console.error('Error: CLI "notebooklm" no encontrado en PATH ni ~/.local/bin. Ejecuta: uv tool install notebooklm-py');
		process.exit(1);
	}

	// 2. Parsear argumentos CLI
	const args = process.argv.slice(2);
	let cliProfile = null;
	let cliNotebook = null;
	let strictMode = ['1', 'true', 'yes'].includes(String(process.env.NOTEBOOKLM_STRICT || '').toLowerCase());

	for (let i = 0; i < args.length; i++) {
		if (args[i] === '--profile' || args[i] === '-p') {
			cliProfile = args[++i];
		} else if (args[i] === '--notebook' || args[i] === '-n') {
			cliNotebook = args[++i];
		} else if (args[i] === '--strict') {
			strictMode = true;
		}
	}

	if (!cliProfile && process.env.NOTEBOOKLM_PROFILE) {
		cliProfile = process.env.NOTEBOOKLM_PROFILE;
	}
	if (!cliNotebook && process.env.NOTEBOOKLM_NOTEBOOK) {
		cliNotebook = process.env.NOTEBOOKLM_NOTEBOOK;
	}

	// 3. Determinar targets a sincronizar
	const targets = [];
	const availableProfiles = getAvailableProfiles();

	if (cliNotebook) {
		targets.push({
			profile: cliProfile || null,
			notebookId: cliNotebook,
			name: cliNotebook,
			email: cliProfile,
			role: 'primary',
		});
	} else if (cliProfile) {
		const matched = KNOWN_TARGETS.filter(t => t.profile === cliProfile);
		if (matched.length > 0) {
			targets.push(...matched.map(t => ({ ...t, role: 'primary' })));
		} else {
			targets.push({ profile: cliProfile, notebookId: cliProfile, name: cliProfile, email: cliProfile, role: 'primary' });
		}
	} else {
		for (const target of KNOWN_TARGETS) {
			if (!target.profile || availableProfiles.includes(target.profile)) {
				targets.push({ ...target });
			}
		}

		if (targets.length === 0) {
			if (availableProfiles.length > 0 || strictMode) {
				throw new Error('Perfil primario de NotebookLM ausente en ~/.notebooklm/profiles (esperado: ale-fvaras).');
			}
			targets.push({
				profile: null,
				notebookId: 'akibara',
				name: 'akibara',
				email: 'default',
				role: 'primary',
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
	const loadedState = loadSyncState();
	const allState = loadedState.allState;
	let persistedJson = loadedState.persistedJson;

	// 6. Ejecutar sincronización fail-closed únicamente sobre la cuenta autorizada
	for (const target of targets) {
		const result = await syncTarget(target, localFiles, allState);
		recordProfileHealth(allState, target, { status: 'SYNCED', notebookId: result.notebookId });
		persistedJson = persistSyncState(allState, persistedJson);
	}

	log(`\n=== PIPELINE DE FUENTES NOTEBOOKLM COMPLETADO EXITOSAMENTE (${targets.length} cuaderno(s)) ===\n`);
}

main().catch(err => {
	console.error('Error fatal durante la sincronización:', err);
	process.exit(1);
});
