/**
 * Akibara Rewards — Calculadora interactiva del Club de Puntos.
 *
 * Cálculo en tiempo real ultra-ligero sin dependencias externas (0 jQuery, 0 canvas).
 *
 * @package
 */

(function() {
	'use strict';

	function initCalculator() {
		const calcBox = document.getElementById('akb-club-calc');
		if (!calcBox) {
			return;
		}

		const slider = calcBox.querySelector('.akb-calc-range');
		const display = document.getElementById('akb-calc-display');
		const ptsVal = document.getElementById('akb-calc-points-val');
		const clpVal = document.getElementById('akb-calc-discount-val');

		if (!slider || !display || !ptsVal || !clpVal) {
			return;
		}

		const rawEarnAmount = parseInt(calcBox.getAttribute('data-earn-amount'), 10);
		const rawEarnPoints = parseInt(calcBox.getAttribute('data-earn-points'), 10);
		const rawRedeemVal  = parseInt(calcBox.getAttribute('data-redeem-value'), 10);

		const earnAmount = Number.isNaN(rawEarnAmount) ? 0 : Math.max(0, rawEarnAmount);
		const earnPoints = Number.isNaN(rawEarnPoints) ? 0 : Math.max(0, rawEarnPoints);
		const redeemVal  = Number.isNaN(rawRedeemVal) ? 1 : Math.max(1, rawRedeemVal);

		function formatNumber(num) {
			const digits = Math.round(Number(num) || 0).toString();
			let out = '';
			for (let i = 0; i < digits.length; i++) {
				if (i > 0 && (digits.length - i) % 3 === 0) {
					out += '.';
				}
				// eslint-disable-next-line security/detect-object-injection
				out += digits[i];
			}
			return out;
		}

		function formatCLP(num) {
			return '$' + formatNumber(num);
		}

		function update() {
			const amount   = parseInt(slider.value, 10) || 0;
			const earned   = (earnAmount > 0 && earnPoints > 0) ? Math.round((amount / earnAmount) * earnPoints) : 0;
			const discount = earned * redeemVal;

			display.textContent = formatCLP(amount);
			ptsVal.textContent  = formatNumber(earned);
			clpVal.textContent  = formatCLP(discount);
		}

		slider.addEventListener('input', update);
		update();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', initCalculator);
	} else {
		initCalculator();
	}
})();
