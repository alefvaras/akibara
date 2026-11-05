'use strict';

/**
 * Akibara DOM Test Harness
 *
 * Proporciona un entorno headless de DOM ultrarrápido y aislado para probar
 * componentes interactivos JavaScript del frontend (temas, plugins, widgets de
 * carrito, modales, steppers, accesibilidad ARIA y despacho de eventos) usando
 * `happy-dom` y el corredor nativo de pruebas de Node.js (`node:test`).
 *
 * Sustituye los tests estáticos basados en expresiones regulares frágiles sobre
 * el código fuente por pruebas de comportamiento real sobre el árbol DOM.
 */

const { Window } = require('happy-dom');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const REPO_ROOT = path.resolve(__dirname, '../..');

/**
 * Crea una instancia limpia de Window y Document con utilidades de interacción.
 *
 * @param {Object} options
 * @param {string} [options.html=''] HTML inicial para inyectar en document.body o en todo el documento.
 * @param {string} [options.url='https://akibara.cl'] URL virtual base.
 * @param {number} [options.innerWidth=1024] Ancho de viewport virtual.
 * @param {number} [options.innerHeight=768] Alto de viewport virtual.
 * @param {Object} [options.globals={}] Variables globales adicionales inyectadas en window.
 * @return {AkibaraDOMInstance}
 */
function createDOM(options = {}) {
	const {
		html = '',
		url = 'https://akibara.cl',
		innerWidth = 1024,
		innerHeight = 768,
		globals = {}
	} = options;

	const window = new Window({
		url,
		innerWidth,
		innerHeight,
		settings: {
			disableJavaScriptEvaluation: false,
			disableJavaScriptFileLoading: true,
			disableCSSFileLoading: true
		}
	});

	const document = window.document;

	// Inyectar HTML si fue provisto
	if (html) {
		if (html.includes('<html') || html.includes('<!DOCTYPE')) {
			document.write(html);
		} else {
			document.body.innerHTML = html;
		}
	}

	// Inyectar variables globales opcionales en el contexto
	for (const [key, val] of Object.entries(globals)) {
		window[key] = val;
	}

	const context = vm.createContext(window);

	/**
	 * Ejecuta un archivo de script dentro del contexto del DOM virtual.
	 *
	 * @param {string} scriptPath Ruta relativa a la raíz del repositorio o absoluta.
	 */
	function loadScript(scriptPath) {
		const fullPath = path.isAbsolute(scriptPath)
			? scriptPath
			: path.join(REPO_ROOT, scriptPath);

		if (!fs.existsSync(fullPath)) {
			throw new Error(`[DOM Harness] Script no encontrado: ${fullPath}`);
		}

		const source = fs.readFileSync(fullPath, 'utf8');
		vm.runInContext(source, context, { filename: fullPath });
	}

	/**
	 * Ejecuta código JavaScript arbitrario en el contexto del DOM.
	 *
	 * @param {string|Function} code
	 */
	function evaluate(code) {
		if (typeof code === 'function') {
			return vm.runInContext(`(${code.toString()})()`, context);
		}
		return vm.runInContext(code, context);
	}

	/**
	 * Simula un clic sobre un selector o elemento HTML.
	 *
	 * @param {string|Element} target
	 * @param {Object} [eventInit={}]
	 */
	function click(target, eventInit = {}) {
		const el = typeof target === 'string' ? document.querySelector(target) : target;
		if (!el) {
			throw new Error(`[DOM Harness] Elemento no encontrado para click: ${target}`);
		}
		if (typeof el.click === 'function') {
			el.click();
		} else {
			const evt = new window.MouseEvent('click', { bubbles: true, cancelable: true, ...eventInit });
			el.dispatchEvent(evt);
		}
	}

	/**
	 * Despacha un evento personalizado sobre un elemento o el documento.
	 *
	 * @param {string|Element|Document|Window} target
	 * @param {string} eventName
	 * @param {Object} [detail={}]
	 * @param {Object} [options={}]
	 */
	function dispatch(target, eventName, detail = {}, options = {}) {
		let el = target;
		if (typeof target === 'string') {
			el = document.querySelector(target);
			if (!el) {
				throw new Error(`[DOM Harness] Elemento no encontrado para dispatch: ${target}`);
			}
		} else if (!el) {
			el = document;
		}

		const evt = new window.CustomEvent(eventName, {
			bubbles: options.bubbles !== false,
			cancelable: options.cancelable !== false,
			detail
		});
		return el.dispatchEvent(evt);
	}

	/**
	 * Asigna un valor a un campo de entrada y dispara los eventos input y change.
	 *
	 * @param {string|Element} target
	 * @param {string} value
	 */
	function fill(target, value) {
		const el = typeof target === 'string' ? document.querySelector(target) : target;
		if (!el) {
			throw new Error(`[DOM Harness] Elemento no encontrado para fill: ${target}`);
		}
		el.value = value;
		el.dispatchEvent(new window.Event('input', { bubbles: true }));
		el.dispatchEvent(new window.Event('change', { bubbles: true }));
	}

	/**
	 * Destruye la ventana y libera recursos.
	 */
	function teardown() {
		try {
			window.happyDOM.abort();
			window.close();
		} catch (_) {
			// Silencioso ante cleanup de entorno
		}
	}

	return {
		window,
		document,
		context,
		loadScript,
		evaluate,
		click,
		dispatch,
		fill,
		teardown
	};
}

module.exports = {
	createDOM,
	REPO_ROOT
};
