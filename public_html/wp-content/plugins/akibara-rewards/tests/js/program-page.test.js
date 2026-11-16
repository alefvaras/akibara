'use strict';

const assert = require('node:assert/strict');
const { test } = require('node:test');
const path = require('node:path');
const { createDOM } = require('../../../../../../tools/testing/dom-harness.js');

const SCRIPT_PATH = path.resolve(__dirname, '../../assets/js/program-page.js');

test('program-page calculator computes dynamic points and discount via slider input', () => {
	const dom = createDOM({
		html: `
			<div id="akb-club-calc" data-earn-amount="100" data-earn-points="2" data-redeem-value="1">
				<span id="akb-calc-display">$35.000</span>
				<input type="range" class="akb-calc-range" min="10000" max="150000" step="5000" value="35000" />
				<span id="akb-calc-points-val">700</span>
				<span id="akb-calc-discount-val">$700</span>
			</div>
		`
	});

	dom.loadScript(SCRIPT_PATH);

	const slider = dom.document.querySelector('.akb-calc-range');
	const display = dom.document.getElementById('akb-calc-display');
	const ptsVal = dom.document.getElementById('akb-calc-points-val');
	const clpVal = dom.document.getElementById('akb-calc-discount-val');

	assert.equal(ptsVal.textContent, '700');
	assert.equal(clpVal.textContent, '$700');

	slider.value = '50000';
	slider.dispatchEvent(new dom.window.Event('input', { bubbles: true }));

	assert.equal(display.textContent, '$50.000');
	assert.equal(ptsVal.textContent, '1.000');
	assert.equal(clpVal.textContent, '$1.000');
});

test('program-page calculator keeps 0 points and $0 when accumulation is disabled (data-earn-points="0")', () => {
	const dom = createDOM({
		html: `
			<div id="akb-club-calc" data-earn-amount="1000" data-earn-points="0" data-redeem-value="2">
				<span id="akb-calc-display">$35.000</span>
				<input type="range" class="akb-calc-range" min="10000" max="150000" step="5000" value="50000" />
				<span id="akb-calc-points-val">0</span>
				<span id="akb-calc-discount-val">$0</span>
			</div>
		`
	});

	dom.loadScript(SCRIPT_PATH);

	const ptsVal = dom.document.getElementById('akb-calc-points-val');
	const clpVal = dom.document.getElementById('akb-calc-discount-val');

	assert.equal(ptsVal.textContent, '0');
	assert.equal(clpVal.textContent, '$0');
});
