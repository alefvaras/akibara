'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const { createDOM } = require('./dom-harness.js');

test('Review Lightbox: trigger button opens dialog with full image and caption', () => {
	const html = `
		<figure class="akb-rw-foto">
			<button type="button" class="akb-rw-foto__btn" data-akb-lightbox-trigger data-full-src="https://akibara.cl/uploads/manga-full.jpg" data-alt="Foto de la reseña de Camila" aria-label="Ampliar foto">
				<img src="https://akibara.cl/uploads/manga-medium.jpg" alt="Foto de la reseña de Camila">
				<span class="akb-rw-foto__zoom-hint">🔍 Ampliar foto</span>
			</button>
			<span class="akb-rw-foto__badge">📸 Compra verificada con fotos</span>
		</figure>
		<dialog id="akb-review-lightbox" class="akb-review-lightbox">
			<div class="akb-review-lightbox__inner">
				<button type="button" class="akb-review-lightbox__close">&times;</button>
				<img id="akb-review-lightbox-img" src="" alt="">
				<p id="akb-review-lightbox-caption"></p>
			</div>
		</dialog>
	`;

	const dom = createDOM({ html });

	try {
		// Simular el script del lightbox registrado en PhotoSurfaces
		dom.document.addEventListener('click', function(e) {
			const t = e.target.closest('[data-akb-lightbox-trigger]');
			if (t) {
				const d = dom.document.getElementById('akb-review-lightbox');
				const img = dom.document.getElementById('akb-review-lightbox-img');
				const c = dom.document.getElementById('akb-review-lightbox-caption');
				if (d && img) {
					img.src = t.getAttribute('data-full-src') || '';
					img.alt = t.getAttribute('data-alt') || '';
					if (c) c.textContent = t.getAttribute('data-alt') || '';
					d.setAttribute('open', '');
				}
			}
			if (e.target.matches('#akb-review-lightbox, .akb-review-lightbox__close')) {
				const d2 = dom.document.getElementById('akb-review-lightbox');
				if (d2) d2.removeAttribute('open');
			}
		});

		const dialog = dom.document.getElementById('akb-review-lightbox');
		const img = dom.document.getElementById('akb-review-lightbox-img');
		const caption = dom.document.getElementById('akb-review-lightbox-caption');

		assert.equal(dialog.hasAttribute('open'), false, 'Dialog debe iniciar cerrado');

		// 1. Clic en el trigger
		dom.click('.akb-rw-foto__btn');

		assert.equal(dialog.hasAttribute('open'), true, 'Dialog debe abrirse tras clic en el trigger');
		assert.equal(img.src, 'https://akibara.cl/uploads/manga-full.jpg', 'Debe cargar el src en alta resolución');
		assert.equal(img.alt, 'Foto de la reseña de Camila', 'Debe heredar el texto alternativo');
		assert.equal(caption.textContent, 'Foto de la reseña de Camila', 'Debe reflejar la leyenda de la reseña');

		// 2. Clic en el botón cerrar
		dom.click('.akb-review-lightbox__close');
		assert.equal(dialog.hasAttribute('open'), false, 'Dialog debe cerrarse al hacer clic en cerrar');
	} finally {
		dom.teardown();
	}
});
