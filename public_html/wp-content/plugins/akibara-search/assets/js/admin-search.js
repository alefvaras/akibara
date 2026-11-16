/**
 * admin-search.js — JS de la página de admin PROPIA del buscador (standalone).
 *
 * Reemplaza la dependencia del admin-panel.js de akibara-business: acá vive solo
 * lo del buscador (poll del rebuild, reordenar/quitar facetas, parent-picker).
 * Vanilla, sin jQuery.
 */
(function () {
	'use strict';

	/* Índice → poll del progreso del rebuild. Solo cuando el server dijo
	 * "reconstruyendo" (data-state de la banda de veredicto). Cada 4s refresca la
	 * región aria-live y el botón deshabilitado; al terminar (o cambiar el
	 * veredicto) recarga para el render fresco. Manda X-WP-Nonce (wp_rest). */
	document.addEventListener('DOMContentLoaded', function () {
		const band = document.querySelector('[data-akb-search-status]');
		if (!band || band.getAttribute('data-state') !== 'reconstruyendo') {
			return;
		}
		const endpoint = band.getAttribute('data-endpoint');
		const nonce = band.getAttribute('data-nonce');
		if (!endpoint || !nonce || !window.fetch) {
			return;
		}
		const progress = band.querySelector('[data-akb-progress]');
		const btn = document.querySelector('[data-akb-rebuild-btn]');
		const timer = window.setInterval(function () {
			window.fetch(endpoint, {
				headers: { 'X-WP-Nonce': nonce },
				credentials: 'same-origin'
			})
				.then(function (r) { return r.ok ? r.json() : null; })
				.then(function (data) {
					if (!data) {
						return; // error transitorio: el próximo tick reintenta.
					}
					if (data.verdict !== 'reconstruyendo' || !data.rebuild) {
						window.clearInterval(timer);
						window.location.reload();
						return;
					}
					const txt = 'Reconstruyendo… ' + data.rebuild.done + ' de ' + data.rebuild.total;
					if (progress) {
						progress.textContent = txt + ' productos';
					}
					if (btn) {
						btn.textContent = txt;
					}
				})
				.catch(function () {});
		}, 4000);
	});

	/* Facetas → alta: el input de contexto apunta al datalist de la taxonomía
	 * elegida en el select (un datalist por taxonomía ya está en el DOM). */
	document.addEventListener('DOMContentLoaded', function () {
		const picker = document.querySelector('[data-akb-parent-picker]');
		const tax = document.getElementById('akb-nf-tax');
		if (!picker || !tax) {
			return;
		}
		const sync = function () {
			if (tax.value) {
				picker.setAttribute('list', 'akb-parents-' + tax.value);
			} else {
				picker.removeAttribute('list');
			}
		};
		tax.addEventListener('change', sync);
		sync();
	});

	/* Facetas: reordenar con ↑/↓ y quitar. El orden real lo da el orden de los
	 * <tr> en el POST; acá movemos filas y anunciamos en la región live (a11y). */
	function facetLabel(tr) {
		const input = tr.querySelector('input[type="text"]');
		const code = tr.querySelector('th code');
		return (input && input.value) || (code && code.textContent) || 'faceta';
	}

	function announceFacet(msg) {
		const live = document.getElementById('akb-facets-live');
		if (live) {
			live.textContent = '';
			window.setTimeout(function () { live.textContent = msg; }, 60);
		}
	}

	document.addEventListener('click', function (e) {
		const move = e.target.closest('[data-akb-move]');
		if (move) {
			const tr = move.closest('tr');
			const body = tr && tr.parentElement;
			if (!tr || !body) {
				return;
			}
			const up = move.getAttribute('data-akb-move') === 'up';
			const sib = up ? tr.previousElementSibling : tr.nextElementSibling;
			if (!sib) {
				announceFacet(facetLabel(tr) + (up ? ' ya está primera' : ' ya está última'));
				return;
			}
			if (up) {
				sib.before(tr);
			} else {
				sib.after(tr);
			}
			const rows = Array.prototype.slice.call(body.children);
			announceFacet(facetLabel(tr) + ' movida a la posición ' + (rows.indexOf(tr) + 1) + ' de ' + rows.length);
			move.focus(); // el botón viaja con la fila: el teclado no se pierde.
			return;
		}

		const remove = e.target.closest('[data-akb-remove-facet]');
		if (remove) {
			const row = remove.closest('tr');
			if (!row) {
				return;
			}
			const label = facetLabel(row);
			const next = row.nextElementSibling || row.previousElementSibling;
			row.remove();
			announceFacet('Faceta ' + label + ' quitada. Guarda para confirmar.');
			const target = next && next.querySelector('[data-akb-move="up"]');
			if (target) {
				target.focus();
			}
		}
	});
}());
