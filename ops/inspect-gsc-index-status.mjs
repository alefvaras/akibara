#!/usr/bin/env node

/**
 * Mide el estado de indexación real de un conjunto de URLs contra la URL
 * Inspection API de Google Search Console y, opcionalmente, comprueba que un
 * conjunto de redirecciones 301 siga vivo. Emite un resumen accionable.
 *
 * Es de SOLO LECTURA: no toca WordPress, no envía nada a la Indexing API y no
 * escribe fuera del archivo indicado en --output.
 *
 * Uso:
 *   node ops/inspect-gsc-index-status.mjs \
 *     --urls .local-reports/pending-index-verification-20260804.json \
 *     [--output .local-reports/index-status-<fecha>.json] \
 *     [--site https://akibara.cl/] [--delay-ms 150] [--skip-redirects]
 *
 * Formato de --urls (todo opcional salvo tener URLs de alguna forma):
 *   { "grupos": { "<nombre>": ["https://…/", …] },
 *     "redirecciones": [ { "desde": "https://…viejo/", "hacia": "https://…nuevo/" } ] }
 * También acepta un arreglo plano de URLs o { "urls": [...] }.
 *
 * Credenciales: la misma service account que usan los MCP de Search Console
 * (GOOGLE_SERVICE_ACCOUNT_FILE o la ruta por defecto). La clave privada nunca
 * se imprime ni se copia a disco.
 *
 * Criterio de "indexada": `verdict === 'PASS'` del indexStatusResult. NO se
 * compara el texto de coverageState: ese literal viene traducido y cambia con
 * el idioma y con los renombres de Google.
 */

import crypto from 'node:crypto';
import fs from 'node:fs';
import process from 'node:process';

const DEFAULT_KEY_FILE = '/Users/alefvaras/.codex/credentials/google-search-console-service-account.json';
const SCOPE = 'https://www.googleapis.com/auth/webmasters.readonly';
const INSPECT_ENDPOINT = 'https://searchconsole.googleapis.com/v1/urlInspection/index:inspect';
const RETRY_STATUSES = new Set([429, 500, 502, 503, 504]);
/** Marcas de "Google no conoce esta URL" en los idiomas que devuelve la API. */
const UNKNOWN_MARKERS = ['no reconoce', 'unknown to google'];

function parseArgs(argv) {
	const args = { site: 'https://akibara.cl/', delayMs: 150, skipRedirects: false };
	for (let i = 2; i < argv.length; i += 1) {
		const [flag, inlineValue] = argv[i].split('=');
		if (flag === '--skip-redirects') {
			args.skipRedirects = true;
			continue;
		}
		const value = inlineValue ?? argv[i + 1];
		if (inlineValue === undefined) i += 1;
		if (value === undefined) throw new Error(`Falta el valor de ${flag}`);
		if (flag === '--urls') args.urls = value;
		else if (flag === '--output') args.output = value;
		else if (flag === '--site') args.site = value;
		else if (flag === '--key') args.key = value;
		else if (flag === '--delay-ms') args.delayMs = Number(value);
		else throw new Error(`Argumento no reconocido: ${flag}`);
	}
	if (!args.urls) throw new Error('Falta --urls <archivo.json>');
	return args;
}

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const base64url = (buffer) => Buffer.from(buffer).toString('base64url');

/**
 * Reintenta ante fallos transitorios (rate limit, 5xx, cortes de red). Sin esto
 * un hipo de red marca la URL como error y el reporte miente sobre el estado.
 */
async function conReintentos(operacion, { intentos = 4, etiqueta = 'petición' } = {}) {
	let esperaMs = 1000;
	for (let intento = 1; ; intento += 1) {
		try {
			const response = await operacion();
			if (!RETRY_STATUSES.has(response.status) || intento === intentos) return response;
			console.error(`  reintento ${intento}/${intentos - 1} en ${etiqueta}: HTTP ${response.status}`);
		} catch (error) {
			if (intento === intentos) throw error;
			console.error(`  reintento ${intento}/${intentos - 1} en ${etiqueta}: ${error.message}`);
		}
		await sleep(esperaMs);
		esperaMs *= 3;
	}
}

/** Token con auto-refresco: una lista larga puede pasar de los 60 min de vida. */
function creaProveedorDeToken(keyFile) {
	let token = null;
	let expiraEn = 0;
	return async function obtenerToken() {
		if (token && Date.now() < expiraEn - 5 * 60 * 1000) return token;
		const sa = JSON.parse(fs.readFileSync(keyFile, 'utf8'));
		const now = Math.floor(Date.now() / 1000);
		const header = base64url(JSON.stringify({ alg: 'RS256', typ: 'JWT' }));
		const claims = base64url(JSON.stringify({
			iss: sa.client_email,
			scope: SCOPE,
			aud: 'https://oauth2.googleapis.com/token',
			iat: now,
			exp: now + 3600,
		}));
		const firma = base64url(crypto.sign('RSA-SHA256', Buffer.from(`${header}.${claims}`), sa.private_key));
		const response = await conReintentos(() => fetch('https://oauth2.googleapis.com/token', {
			method: 'POST',
			headers: { 'content-type': 'application/x-www-form-urlencoded' },
			body: new URLSearchParams({
				grant_type: 'urn:ietf:params:oauth:grant-type:jwt-bearer',
				assertion: `${header}.${claims}.${firma}`,
			}),
		}), { etiqueta: 'token OAuth' });
		if (!response.ok) {
			throw new Error(`No se pudo obtener el token (${response.status}): ${await response.text()}`);
		}
		const datos = await response.json();
		token = datos.access_token;
		expiraEn = Date.now() + (Number(datos.expires_in ?? 3600) * 1000);
		return token;
	};
}

function cargaEntrada(file) {
	const raw = JSON.parse(fs.readFileSync(file, 'utf8'));
	if (Array.isArray(raw)) return { grupos: { todas: raw }, redirecciones: [] };
	const grupos = raw.grupos && typeof raw.grupos === 'object'
		? raw.grupos
		: (Array.isArray(raw.urls) ? { todas: raw.urls } : null);
	if (!grupos) throw new Error('El archivo debe ser un arreglo, {urls:[...]} o {grupos:{...}}');
	return { grupos, redirecciones: Array.isArray(raw.redirecciones) ? raw.redirecciones : [] };
}

const normalizaUrl = (valor) => String(valor ?? '').trim().replace(/\/+$/, '').toLowerCase();

function clasifica(index) {
	const cobertura = String(index.coverageState ?? '').toLowerCase();
	if (index.verdict === 'PASS') return 'indexada';
	if (UNKNOWN_MARKERS.some((marca) => cobertura.includes(marca))) return 'desconocida_para_google';
	return 'pendiente';
}

async function inspecciona(url, site, obtenerToken) {
	const token = await obtenerToken();
	let response;
	try {
		response = await conReintentos(() => fetch(INSPECT_ENDPOINT, {
			method: 'POST',
			headers: { authorization: `Bearer ${token}`, 'content-type': 'application/json' },
			body: JSON.stringify({ inspectionUrl: url, siteUrl: site, languageCode: 'es' }),
		}), { etiqueta: `inspección de ${url}` });
	} catch (error) {
		return { url, clase: 'error', estado: `ERROR de red: ${error.message}` };
	}
	if (!response.ok) {
		return { url, clase: 'error', estado: `ERROR ${response.status}`, detalle: (await response.text()).slice(0, 300) };
	}
	const index = (await response.json())?.inspectionResult?.indexStatusResult ?? {};
	const canonica = index.googleCanonical ?? null;
	return {
		url,
		clase: clasifica(index),
		estado: index.coverageState ?? '?',
		veredicto: index.verdict ?? '?',
		ultimo_rastreo: index.lastCrawlTime ?? null,
		canonica_google: canonica,
		canonica_coincide: canonica ? normalizaUrl(canonica) === normalizaUrl(url) : null,
		en_sitemap: Boolean(index.sitemap?.length),
	};
}

/** Un 301 que desapareció convierte una URL viva en 404: se comprueba siempre. */
async function verificaRedireccion({ desde, hacia }) {
	try {
		const response = await conReintentos(
			() => fetch(desde, { method: 'GET', redirect: 'manual' }),
			{ etiqueta: `redirección ${desde}` }
		);
		const destino = response.headers.get('location');
		const ok = response.status === 301 && normalizaUrl(destino) === normalizaUrl(hacia);
		return { desde, hacia, status: response.status, destino, ok };
	} catch (error) {
		return { desde, hacia, status: null, destino: null, ok: false, error: error.message };
	}
}

async function main() {
	const args = parseArgs(process.argv);
	const { grupos, redirecciones } = cargaEntrada(args.urls);
	const obtenerToken = creaProveedorDeToken(args.key ?? process.env.GOOGLE_SERVICE_ACCOUNT_FILE ?? DEFAULT_KEY_FILE);

	const salida = {
		consultado_en: new Date().toISOString(),
		site: args.site,
		criterio_indexada: "indexStatusResult.verdict === 'PASS'",
		grupos: {},
		redirecciones: null,
	};

	for (const [nombre, urls] of Object.entries(grupos)) {
		const filas = [];
		for (const [i, url] of urls.entries()) {
			filas.push(await inspecciona(url, args.site, obtenerToken));
			process.stderr.write(`\r  ${nombre}: ${i + 1}/${urls.length}   `);
			await sleep(args.delayMs);
		}
		process.stderr.write('\r');

		const porClase = (clase) => filas.filter((fila) => fila.clase === clase);
		const canonicaDistinta = filas.filter((fila) => fila.canonica_coincide === false);
		salida.grupos[nombre] = {
			total: filas.length,
			indexadas: porClase('indexada').length,
			// Las "desconocidas" son las ÚNICAS que ameritan un re-ping.
			desconocidas_para_google: porClase('desconocida_para_google').map((fila) => fila.url),
			pendientes: porClase('pendiente').map((fila) => ({ url: fila.url, estado: fila.estado })),
			errores: porClase('error').map((fila) => ({ url: fila.url, estado: fila.estado })),
			canonica_distinta: canonicaDistinta.map((fila) => ({ url: fila.url, canonica_google: fila.canonica_google })),
			filas,
		};

		const grupo = salida.grupos[nombre];
		console.log(`${nombre}: ${grupo.indexadas}/${grupo.total} indexadas`);
		for (const url of grupo.desconocidas_para_google) console.log(`  DESCONOCIDA PARA GOOGLE  ${url}`);
		for (const fila of grupo.pendientes) console.log(`  PENDIENTE  ${fila.url} — ${fila.estado}`);
		for (const fila of grupo.errores) console.log(`  ERROR      ${fila.url} — ${fila.estado}`);
		for (const fila of grupo.canonica_distinta) console.log(`  CANÓNICA DISTINTA  ${fila.url} → ${fila.canonica_google}`);
	}

	if (redirecciones.length > 0 && !args.skipRedirects) {
		const filas = [];
		for (const [i, par] of redirecciones.entries()) {
			filas.push(await verificaRedireccion(par));
			process.stderr.write(`\r  redirecciones: ${i + 1}/${redirecciones.length}   `);
		}
		process.stderr.write('\r');
		const rotas = filas.filter((fila) => !fila.ok);
		salida.redirecciones = { total: filas.length, ok: filas.length - rotas.length, rotas, filas };
		console.log(`redirecciones: ${filas.length - rotas.length}/${filas.length} correctas`);
		for (const fila of rotas) console.log(`  ROTA  ${fila.desde} → HTTP ${fila.status} ${fila.destino ?? fila.error ?? ''}`);
	}

	if (args.output) {
		fs.writeFileSync(args.output, `${JSON.stringify(salida, null, 1)}\n`);
		console.log(`\nReporte: ${args.output}`);
	}

	const pendientes = Object.values(salida.grupos)
		.reduce((suma, grupo) => suma + grupo.total - grupo.indexadas, 0);
	const redRotas = salida.redirecciones?.rotas.length ?? 0;
	console.log(`\nRESUMEN: ${pendientes} URL(s) sin indexar | ${redRotas} redirección(es) rota(s)`);
	if (redRotas > 0) process.exitCode = 2;
}

main().catch((error) => {
	console.error(`FALLO: ${error.message}`);
	process.exitCode = 1;
});
