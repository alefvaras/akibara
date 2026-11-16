import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

import {
	ensureWordPressBlockEditorField,
} from './lib/wordpress-block-editor-official-ui.mjs';

const fixturePath = path.join(
	path.dirname(fileURLToPath(import.meta.url)),
	'fixtures',
	'wordpress-block-editor-official-ui.json'
);
const fixtures = JSON.parse(fs.readFileSync(fixturePath, 'utf8'));

class FakeLocator {
	constructor(page, kind, index = 0, textPattern = null) {
		this.page = page;
		this.kind = kind;
		this.index = index;
		this.textPattern = textPattern;
	}

	nth(index) {
		return new FakeLocator(this.page, this.kind, index, this.textPattern);
	}

	filter(options) {
		return new FakeLocator(
			this.page,
			this.kind,
			this.index,
			options?.hasText ?? this.textPattern
		);
	}

	locator() {
		if (this.kind !== 'dialog') {
			return new FakeLocator(this.page, 'unknown');
		}
		return new FakeLocator(this.page, 'guide-close');
	}

	async count() {
		switch (this.kind) {
			case 'body':
			case 'options':
				return 1;
			case 'dialog':
			case 'guide-close':
				return this.page.state.guide ? 1 : 0;
			case 'menu-control':
				return this.page.state.codeMenuOpen ? 1 : 0;
			case 'content':
				return this.page.state.codeField ? 1 : 0;
			case 'excerpt':
			case 'excerpt-wrapper':
				return this.page.state.excerptField ? 1 : 0;
			case 'edit-excerpt':
				return 1;
			case 'busy':
			case 'unknown':
				return 0;
			default:
				throw new Error(`unknown fake locator kind ${this.kind}`);
		}
	}

	async isVisible() {
		return await this.count() === 1;
	}

	async isEnabled() {
		return this.kind === 'options';
	}

	async scrollIntoViewIfNeeded() {}

	async click(options = {}) {
		if (this.kind === 'guide-close') {
			if (!options.trial) {
				this.page.state.guide = '';
				this.page.metrics.guideCloseClicks += 1;
			}
			return;
		}
		if (this.kind === 'options') {
			if (this.page.state.permanentOverlay) {
				throw new Error('element intercepts pointer events');
			}
			if (!options.trial) {
				this.page.state.codeMenuOpen = !this.page.state.menuNeverAppears;
				this.page.metrics.optionsClicks += 1;
			}
			return;
		}
		if (this.kind === 'menu-control') {
			if (!options.trial) {
				this.page.state.codeMenuOpen = false;
				this.page.state.codeField = true;
				this.page.metrics.codeEditorClicks += 1;
			}
			return;
		}
		if (this.kind === 'edit-excerpt') {
			if (!options.trial) {
				this.page.state.excerptField = true;
				this.page.metrics.excerptEditClicks += 1;
			}
			return;
		}
		throw new Error(`unexpected click on ${this.kind}`);
	}

	async waitFor({ state }) {
		if (state === 'hidden' && this.kind === 'dialog' && !this.page.state.guide) {
			return;
		}
		if (state === 'visible' && await this.isVisible()) {
			return;
		}
		throw new Error(`${this.kind} did not reach ${state}`);
	}

	async getAttribute(name) {
		if (this.kind === 'dialog' && name === 'aria-label') {
			return this.page.state.guide;
		}
		if (this.kind === 'body' && name === 'class') {
			return 'wp-admin block-editor-page';
		}
		return null;
	}

	async textContent() {
		if (this.kind === 'dialog') {
			return this.page.state.guide;
		}
		if (this.kind === 'menu-control') {
			return 'Editor de código';
		}
		return '';
	}

	async inputValue() {
		if (this.kind === 'excerpt') {
			return this.page.state.excerptValue;
		}
		if (this.kind !== 'content') {
			throw new Error(`unexpected inputValue on ${this.kind}`);
		}
		return this.page.state.value;
	}
}

class FakePage {
	constructor(fixture) {
		this.state = {
			guide: fixture.guide,
			codeField: fixture.code_field,
			codeMenuOpen: fixture.code_menu_open,
			permanentOverlay: fixture.permanent_overlay,
			menuNeverAppears: fixture.menu_never_appears ?? false,
			excerptField: false,
			excerptValue: 'extracto previo',
			value: 'contenido previo',
		};
		this.metrics = {
			guideCloseClicks: 0,
			optionsClicks: 0,
			codeEditorClicks: 0,
			excerptEditClicks: 0,
			retryWaits: 0,
		};
	}

	url() {
		return 'http://localhost:8080/wp-admin/post.php?post=22353&action=edit';
	}

	locator(selector) {
		if (selector === '[role="dialog"]:visible') {
			return new FakeLocator(this, 'dialog');
		}
		if (selector === 'body') {
			return new FakeLocator(this, 'body');
		}
		if (selector === '.editor-post-text-editor') {
			return new FakeLocator(this, 'content');
		}
		if (selector === '.editor-post-excerpt__textarea textarea') {
			return new FakeLocator(this, 'excerpt');
		}
		if (selector === '.editor-post-excerpt__textarea') {
			return new FakeLocator(this, 'excerpt-wrapper');
		}
		if (selector.includes('[role="menuitem"]')) {
			return new FakeLocator(this, 'menu-control');
		}
		if (selector.includes('button[aria-label="Opciones"]')) {
			return new FakeLocator(this, 'options');
		}
		if (selector.includes('.components-spinner:visible')) {
			return new FakeLocator(this, 'busy');
		}
		return new FakeLocator(this, 'unknown');
	}

	getByRole(role) {
		assert.equal(role, 'button');
		return new FakeLocator(this, 'edit-excerpt');
	}

	async waitForFunction() {}

	async waitForTimeout() {
		this.metrics.retryWaits += 1;
	}
}

const contentItem = {
	field: 'post_content',
	key: 'editorial:post:22353:post_content',
};

test('dismisses only the recognized first-run guide before opening code editor', async () => {
	const page = new FakePage(fixtures.first_run_welcome);
	const field = await ensureWordPressBlockEditorField(page, contentItem);
	assert.equal(await field.inputValue(), 'contenido previo');
	assert.deepEqual(page.metrics, {
		guideCloseClicks: 1,
		optionsClicks: 1,
		codeEditorClicks: 1,
		excerptEditClicks: 0,
		retryWaits: 0,
	});
});

test('targets the WordPress 7 textarea inside the excerpt control wrapper', async () => {
	const page = new FakePage(fixtures.code_editor_already_active);
	const field = await ensureWordPressBlockEditorField(page, {
		field: 'post_excerpt',
		key: 'editorial:post:22638:post_excerpt',
	});
	assert.equal(await field.inputValue(), 'extracto previo');
	assert.equal(page.metrics.excerptEditClicks, 1);
});

test('is idempotent when the code editor is already active', async () => {
	const page = new FakePage(fixtures.code_editor_already_active);
	await ensureWordPressBlockEditorField(page, contentItem);
	assert.equal(page.metrics.optionsClicks, 0);
	assert.equal(page.metrics.codeEditorClicks, 0);
});

test('uses an already-open official options menu without toggling it closed', async () => {
	const page = new FakePage(fixtures.options_menu_already_open);
	await ensureWordPressBlockEditorField(page, contentItem);
	assert.equal(page.metrics.optionsClicks, 0);
	assert.equal(page.metrics.codeEditorClicks, 1);
});

test('fails closed instead of dismissing an unrelated dialog', async () => {
	const page = new FakePage(fixtures.unrecognized_dialog);
	await assert.rejects(
		ensureWordPressBlockEditorField(page, contentItem),
		/BLOCK_EDITOR_UNEXPECTED_DIALOG/
	);
	assert.equal(page.metrics.guideCloseClicks, 0);
	assert.equal(page.metrics.optionsClicks, 0);
});

test('bounds actionability retries and reports structured overlay evidence', async () => {
	const page = new FakePage(fixtures.intercepting_overlay);
	await assert.rejects(
		ensureWordPressBlockEditorField(page, contentItem),
		(error) => {
			assert.match(error.message, /BLOCK_EDITOR_OPTIONS_NOT_ACTIONABLE/);
			assert.match(error.message, /"visible_options_count":1/);
			assert.match(error.message, /element intercepts pointer events/);
			return true;
		}
	);
	assert.equal(page.metrics.optionsClicks, 0);
	assert.equal(page.metrics.retryWaits, 3);
});

test('does not toggle options again when the expected menu control is absent', async () => {
	const page = new FakePage(fixtures.options_open_without_code_control);
	await assert.rejects(
		ensureWordPressBlockEditorField(page, contentItem),
		/CODE_EDITOR_MENU_MISSING/
	);
	assert.equal(page.metrics.optionsClicks, 1);
	assert.equal(page.metrics.retryWaits, 20);
});
