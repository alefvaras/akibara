'use strict';

const path = require('node:path');
const test = require('node:test');
const assert = require('node:assert/strict');
const { createDOM } = require(path.resolve(__dirname, '../../../../../../../tools/testing/dom-harness.js'));

const adminPanelScript = path.resolve(__dirname, '../../../assets/js/admin-panel.js');
const announcementBarScript = path.resolve(__dirname, '../../../assets/js/announcement-bar.js');

test('AnnouncementBar Admin DOM: ocultar campos de countdown según switch (sin fechas duplicadas)', () => {
	const html = `
		<div class="akb-panel">
			<div class="akb-panel-content">
				<table class="form-table">
					<tr id="row-cd-on">
						<td><input type="checkbox" id="akibara_announcement_countdown_enabled"></td>
					</tr>
					<tr id="row-cd-time">
						<td><input type="text" id="akibara_announcement_countdown_time" value="23:59"></td>
					</tr>
					<tr id="row-cd-label">
						<td><input type="text" id="akibara_announcement_countdown_label" value="Termina en"></td>
					</tr>
				</table>
			</div>
		</div>
	`;

	const dom = createDOM({ html });
	try {
		dom.loadScript(adminPanelScript);
		dom.document.dispatchEvent(new dom.window.Event('DOMContentLoaded'));

		const rowCdTime = dom.document.getElementById('row-cd-time');
		const rowCdLabel = dom.document.getElementById('row-cd-label');

		assert.equal(rowCdTime.hidden, true, 'Con Countdown apagado, Hora de cierre se oculta');
		assert.equal(rowCdLabel.hidden, true, 'Con Countdown apagado, Texto previo se oculta');

		const cbCd = dom.document.getElementById('akibara_announcement_countdown_enabled');
		cbCd.checked = true;
		cbCd.dispatchEvent(new dom.window.Event('change', { bubbles: true }));
		assert.equal(rowCdTime.hidden, false, 'Al encender Countdown, Hora de cierre aparece');
		assert.equal(rowCdLabel.hidden, false, 'Al encender Countdown, Texto previo aparece');
	} finally {
		dom.teardown();
	}
});

test('AnnouncementBar Frontend DOM: pruneExpiredPromos retira slide promo vencido, recalcula contador y oculta barra si no quedan fijos', () => {
	const pastEpoch = Date.now() - 10_000;
	const html = `
		<div id="akb-ann-bar" class="akb-ann-bar akb-ann-bar--multi">
			<div id="akb-ann-content" class="akb-ann-content">
				<span id="promo-expired" class="akb-ann-msg akb-on" data-akb-promo-msg="1" data-akb-promo-expires-epoch="${pastEpoch}">
					<span class="akb-ann-msg-inner">Promo expirada</span>
				</span>
				<span id="msg-fixed" class="akb-ann-msg">
					<span class="akb-ann-msg-inner">Envío a todo Chile</span>
				</span>
			</div>
			<button type="button" id="akb-ann-next" class="akb-ann-next" data-akb-ann-next aria-label="Siguiente">
				<span class="akb-ann-next__count" data-akb-ann-count>1/2</span>
			</button>
			<div id="akb-ann-status" class="akb-ann-status" data-akb-ann-status></div>
		</div>
	`;

	const dom = createDOM({ html });
	try {
		dom.window.matchMedia = () => ({ matches: true });
		dom.loadScript(announcementBarScript);

		const promoEl = dom.document.getElementById('promo-expired');
		const fixedEl = dom.document.getElementById('msg-fixed');
		const nextBtn = dom.document.getElementById('akb-ann-next');
		const bar = dom.document.getElementById('akb-ann-bar');

		assert.equal(promoEl.hidden, true, 'Slide promo vencido queda hidden');
		assert.equal(promoEl.getAttribute('aria-hidden'), 'true', 'Slide promo vencido pierde accesibilidad activa');
		assert.equal(fixedEl.classList.contains('akb-on'), true, 'Slide fijo pasa a quedar activo');
		assert.equal(nextBtn.hidden, true, 'Al quedar 1 slide, se oculta el botón Siguiente');
		assert.equal(bar.hidden, false, 'Barra sigue visible mientras exista slide fijo');
	} finally {
		dom.teardown();
	}
});

test('AnnouncementBar Frontend DOM: con 2 fijos restantes tras expirar promo, sincroniza contador a 1/2 y mantiene Siguiente visible', () => {
	const pastEpoch = Date.now() - 10_000;
	const html = `
		<div id="akb-ann-bar" class="akb-ann-bar akb-ann-bar--multi">
			<div id="akb-ann-content" class="akb-ann-content">
				<span id="promo-expired" class="akb-ann-msg akb-on" data-akb-promo-msg="1" data-akb-promo-expires-epoch="${pastEpoch}">
					<span class="akb-ann-msg-inner">Promo expirada</span>
				</span>
				<span id="msg-fixed-1" class="akb-ann-msg">
					<span class="akb-ann-msg-inner">Envío a todo Chile</span>
				</span>
				<span id="msg-fixed-2" class="akb-ann-msg">
					<span class="akb-ann-msg-inner">Retiro en Metro San Miguel</span>
				</span>
			</div>
			<button type="button" id="akb-ann-next" class="akb-ann-next" data-akb-ann-next aria-label="Siguiente">
				<span id="ann-count" class="akb-ann-next__count" data-akb-ann-count>1/3</span>
			</button>
			<div id="akb-ann-status" class="akb-ann-status" data-akb-ann-status></div>
		</div>
	`;

	const dom = createDOM({ html });
	try {
		dom.window.matchMedia = () => ({ matches: true });
		dom.loadScript(announcementBarScript);

		const promoEl = dom.document.getElementById('promo-expired');
		const fixed1 = dom.document.getElementById('msg-fixed-1');
		const nextBtn = dom.document.getElementById('akb-ann-next');
		const countEl = dom.document.getElementById('ann-count');

		assert.equal(promoEl.hidden, true);
		assert.equal(fixed1.classList.contains('akb-on'), true);
		assert.equal(nextBtn.hidden, false);
		assert.equal(countEl.textContent, '1/2');
	} finally {
		dom.teardown();
	}
});

test('AnnouncementBar Frontend DOM: pruneExpiredPromos oculta barra completa si solo existía el slide promo vencido', () => {
	const pastEpoch = Date.now() - 10_000;
	const html = `
		<div id="akb-ann-bar" class="akb-ann-bar">
			<div id="akb-ann-content" class="akb-ann-content">
				<span id="promo-only" class="akb-ann-msg akb-on" data-akb-promo-msg="1" data-akb-promo-expires-epoch="${pastEpoch}">
					<span class="akb-ann-msg-inner">Única promo</span>
				</span>
			</div>
		</div>
	`;

	const dom = createDOM({ html });
	try {
		dom.window.matchMedia = () => ({ matches: true });
		dom.loadScript(announcementBarScript);

		const bar = dom.document.getElementById('akb-ann-bar');
		assert.equal(bar.hidden, true, 'Sin slides restantes tras expirar promo única, la barra se oculta');
	} finally {
		dom.teardown();
	}
});

test('AnnouncementBar Frontend DOM: countdown oculta chip vencido sin dejar 00h 00m 00s congelado', () => {
	const futureEpoch = Date.now() + 90_000;
	const pastEpoch = Date.now() - 5_000;
	const html = `
		<div id="akb-ann-bar" class="akb-ann-bar">
			<div id="akb-ann-content" class="akb-ann-content">
				<span class="akb-ann-msg akb-on">
					<span class="akb-ann-msg-inner">
						Promo viva
						<span id="cd-future" class="akb-ann-countdown" data-akb-countdown-epoch="${futureEpoch}">
							<span class="akb-ann-countdown__digits">00h 01m 30s</span>
						</span>
						<span id="cd-past" class="akb-ann-countdown" data-akb-countdown-epoch="${pastEpoch}">
							<span class="akb-ann-countdown__digits">00h 00m 01s</span>
						</span>
					</span>
				</span>
			</div>
		</div>
	`;

	const dom = createDOM({ html });
	try {
		dom.window.matchMedia = () => ({ matches: true });
		let intervalId = 0;
		const origSetInterval = dom.window.setInterval;
		dom.window.setInterval = (fn, ms) => {
			intervalId = origSetInterval(fn, ms);
			return intervalId;
		};
		dom.loadScript(announcementBarScript);

		const futureEl = dom.document.getElementById('cd-future');
		const pastEl = dom.document.getElementById('cd-past');
		assert.equal(futureEl.hidden, false, 'Contador futuro permanece visible');
		assert.equal(pastEl.hidden, true, 'Contador ya vencido se oculta automáticamente');
		if (intervalId) {
			dom.window.clearInterval(intervalId);
		}
	} finally {
		dom.teardown();
	}
});
