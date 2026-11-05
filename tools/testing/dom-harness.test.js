'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const { createDOM } = require('./dom-harness.js');

test('DOM Harness: initializes isolated Window and Document with HTML', () => {
	const dom = createDOM({
		html: '<div id="container"><span class="item">Akibara</span></div>'
	});

	try {
		const span = dom.document.querySelector('.item');
		assert.ok(span, 'Elemento span.item debe existir');
		assert.equal(span.textContent, 'Akibara');
		assert.equal(dom.window.location.href, 'https://akibara.cl/');
	} finally {
		dom.teardown();
	}
});

test('DOM Harness: click and custom events propagate through document listeners', () => {
	const dom = createDOM({
		html: '<button id="btn">Click me</button><div id="output">0</div>'
	});

	try {
		let clickCount = 0;
		dom.document.getElementById('btn').addEventListener('click', () => {
			clickCount++;
			dom.document.getElementById('output').textContent = String(clickCount);
		});

		dom.click('#btn');
		assert.equal(clickCount, 1);
		assert.equal(dom.document.getElementById('output').textContent, '1');

		dom.click('#btn');
		assert.equal(clickCount, 2);
		assert.equal(dom.document.getElementById('output').textContent, '2');
	} finally {
		dom.teardown();
	}
});

test('DOM Harness: fill updates input value and dispatches input/change events', () => {
	const dom = createDOM({
		html: '<input type="text" id="nombre" value="">'
	});

	try {
		let inputDispatched = false;
		let changeDispatched = false;
		const input = dom.document.getElementById('nombre');

		input.addEventListener('input', () => { inputDispatched = true; });
		input.addEventListener('change', () => { changeDispatched = true; });

		dom.fill('#nombre', 'Goku');

		assert.equal(input.value, 'Goku');
		assert.equal(inputDispatched, true, 'input event debe dispararse');
		assert.equal(changeDispatched, true, 'change event debe dispararse');
	} finally {
		dom.teardown();
	}
});

test('DOM Harness: loads external scripts and executes within context', () => {
	const dom = createDOM({
		html: '<div id="status">pending</div>'
	});

	try {
		dom.evaluate(() => {
			document.getElementById('status').textContent = 'active';
		});

		assert.equal(dom.document.getElementById('status').textContent, 'active');
	} finally {
		dom.teardown();
	}
});
