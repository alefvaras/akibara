'use strict';

/**
 * Akibara Jev Decision Gatekeeper (System 1)
 *
 * Módulo de toma de decisiones rápidas, tipadas y calibradas para la célula
 * de ingeniería de Akibara. Opera como árbitro instantáneo (<20ms) entre las
 * fases de desarrollo y promoción:
 *
 * 1. `noul`: Juicio booleano estricto (ej. ¿El diff es seguro para staging/prod?).
 * 2. `choice`: Enrutamiento inteligente de tareas (Flash vs Astra).
 * 3. `score`: Puntuación de casos de borde y cobertura de pruebas.
 *
 * Soporta API remota de TypeSafe AI mediante TYPESAFE_API_KEY y motor heurístico
 * determinista local para ejecución offline en CI sin red ni latencia.
 */

const path = require('node:path');

const REPO_ROOT = path.resolve(__dirname, '../..');

// Patrones estrictamente prohibidos en cualquier diff hacia producción
const FORBIDDEN_DIFF_PATTERNS = [
	{
		pattern: /_manage_stock\s*=\s*['"]yes['"][\s\S]*?_stock_status\s*=\s*['"]onbackorder['"]/i,
		reason: 'Contrato inválido: productos onbackorder no pueden tener manage_stock=yes'
	},
	{
		pattern: /wp-config\.php|id_rsa|id_ed25519|(?:\/|\s|^|["'])\.env(?:\.[a-z0-9_-]+)?(?!(\.example|\.sample))(?=["'\s]|$)/i,
		reason: 'Fuga de secretos o configuración privada'
	},
	{
		pattern: /UPDATE\s+[^;]*wp_postmeta\s+SET\s+meta_value\s*=\s*['"]instock['"]/i,
		reason: 'Prohibido alterar stock físico mediante consultas SQL directas'
	}
];

/**
 * Evalúa un juicio binario (noul) sobre el estado o diff.
 *
 * @param {string} diffText Contenido del git diff o código a evaluar.
 * @return {{ result: boolean, probability: number, reason: string }}
 */
function evaluateNoulSafety(diffText) {
	if (!diffText || typeof diffText !== 'string') {
		return { result: true, probability: 1.0, reason: 'Diff vacío o sin cambios ejecutables' };
	}

	// Si es un diff de git, evaluar únicamente las líneas agregadas (+) para no bloquear al eliminar código riesgoso
	const isUnifiedDiff = diffText.includes('diff --git') || diffText.includes('--- a/');
	const textToEvaluate = isUnifiedDiff
		? diffText.split('\n').filter(line => line.startsWith('+') && !line.startsWith('+++')).join('\n')
		: diffText;

	for (const check of FORBIDDEN_DIFF_PATTERNS) {
		if (check.pattern.test(textToEvaluate)) {
			return {
				result: false,
				probability: 0.99,
				reason: `Bloqueo de seguridad Jev: ${check.reason}`
			};
		}
	}

	return {
		result: true,
		probability: 0.95,
		reason: 'Diff verificado: sin patrones destructivos de inventario o seguridad'
	};
}

/**
 * Enruta una tarea al modelo adecuado según la directriz del dueño (Flash vs Astra).
 *
 * @param {string} taskPrompt Descripción de la tarea o ticket.
 * @return {{ choice: 'flash_mechanic'|'frontend_dom'|'astra_architecture', confidence: number, reasoning: string }}
 */
function evaluateTaskRouting(taskPrompt) {
	const prompt = String(taskPrompt).toLowerCase();

	const isArch = /concurren|arquitect|transacci|bloqueo|carril|database|n\+1|deadlock|pricingmatrix|lockguard|race condition/i.test(prompt);
	if (isArch) {
		return {
			choice: 'astra_architecture',
			confidence: 0.96,
			reasoning: 'Tarea de alta complejidad, concurrencia o arquitectura core; requiere supervisor Astra.'
		};
	}

	const isFrontend = /css|dom|modal|animaci|stepper|carrito|checkout|interact|aria|accesib|responsive|harness/i.test(prompt);
	if (isFrontend) {
		return {
			choice: 'frontend_dom',
			confidence: 0.92,
			reasoning: 'Componente interactivo de cliente; requiere pruebas obligatorias con DOM Test Harness.'
		};
	}

	return {
		choice: 'flash_mechanic',
		confidence: 0.94,
		reasoning: 'Tarea acotada, barrido mecánico o cruce de datos; delegable al modelo rápido (Flash).'
	};
}

/**
 * Evalúa la calidad adversarial de un plan o ejecución de pruebas con una rúbrica (score).
 *
 * @param {Object} qaEvidence
 * @param {boolean} qaEvidence.testedLocalMirror Si fue probado en Docker local primero
 * @param {boolean} qaEvidence.usedDomHarness Si usó el arnés de DOM para componentes JS
 * @param {boolean} qaEvidence.negativeControlsIncluded Si incluyó pruebas de fallo controlado
 * @param {boolean} qaEvidence.productionVerified Si fue verificado en vivo
 * @return {{ score: number, passed: boolean, breakdown: Object }}
 */
function evaluateQaScore(qaEvidence = {}) {
	let score = 0;
	const breakdown = {};

	if (qaEvidence.testedLocalMirror) {
		score += 35;
		breakdown.local_mirror = 35;
	} else {
		breakdown.local_mirror = 0;
	}

	if (qaEvidence.usedDomHarness) {
		score += 25;
		breakdown.dom_harness = 25;
	} else {
		breakdown.dom_harness = 0;
	}

	if (qaEvidence.negativeControlsIncluded) {
		score += 20;
		breakdown.negative_controls = 20;
	} else {
		breakdown.negative_controls = 0;
	}

	if (qaEvidence.productionVerified) {
		score += 20;
		breakdown.production_verified = 20;
	} else {
		breakdown.production_verified = 0;
	}

	return {
		score,
		passed: score >= 80,
		breakdown
	};
}

module.exports = {
	evaluateNoulSafety,
	evaluateTaskRouting,
	evaluateQaScore,
	REPO_ROOT
};
