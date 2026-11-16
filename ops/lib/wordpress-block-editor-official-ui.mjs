/**
 * Resolve an editorial field through the official WordPress block-editor UI.
 *
 * This function is deliberately self-contained: the SEO runner serializes it
 * into a Playwright CLI browser function with `toString()`. Do not add imports
 * or references to module-level state.
 *
 * @param {import('playwright').Page} page
 * @param {{ field: string, key: string }} item
 * @returns {Promise<import('playwright').Locator>}
 */
export async function ensureWordPressBlockEditorField(page, item) {
	const contentSelector = '.editor-post-text-editor';
	/*
	 * WordPress 7 assigns editor-post-excerpt__textarea to the
	 * TextareaControl wrapper, not to the form control itself. Always resolve
	 * the descendant textarea so inputValue()/fill() operate on the official
	 * field instead of on its presentation container.
	 */
	const excerptSelector = '.editor-post-excerpt__textarea textarea';
	const optionsSelector = [
		'button[aria-label="Opciones"]',
		'button[aria-label="Options"]',
	].join(', ');
	const visibleOptionsSelector = [
		'button[aria-label="Opciones"]:visible',
		'button[aria-label="Options"]:visible',
	].join(', ');
	const menuControlSelector = [
		'[role="menuitem"]:visible',
		'[role="menuitemradio"]:visible',
		'[role="menuitemcheckbox"]:visible',
		'button.components-menu-item__button:visible',
	].join(', ');
	const recognizedWelcome = [
		/bienvenid[oa]\s+(?:al|a la)\s+editor(?:\s+de\s+bloques)?/iu,
		/gu[ií]a\s+de\s+bienvenida/iu,
		/welcome\s+to\s+the\s+(?:block|wordpress)\s+editor/iu,
		/welcome\s+guide/iu,
	];

	async function diagnostics(code, lastError = '') {
		const allOptions = page.locator(optionsSelector);
		const visibleOptions = page.locator(visibleOptionsSelector);
		const dialogs = page.locator('[role="dialog"]:visible');
		const dialogSummaries = [];
		const dialogCount = Math.min(await dialogs.count(), 3);
		for (let index = 0; index < dialogCount; index += 1) {
			const dialog = dialogs.nth(index);
			dialogSummaries.push({
				label: String(await dialog.getAttribute('aria-label') ?? '')
					.trim()
					.slice(0, 120),
				text: String(await dialog.textContent() ?? '')
					.trim()
					.replace(/\s+/gu, ' ')
					.slice(0, 180),
			});
		}
		const bodyClass = String(
			await page.locator('body').getAttribute('class') ?? ''
		).trim().slice(0, 240);
		return `${code}:${JSON.stringify({
			path: String(page.url()).split('#')[0].slice(0, 300),
			field: item.field,
			key: item.key,
			options_count: await allOptions.count(),
			visible_options_count: await visibleOptions.count(),
			visible_dialogs: dialogSummaries,
			busy_indicators: await page.locator(
				'.components-spinner:visible, .is-busy:visible'
			).count(),
			body_class: bodyClass,
			last_error: String(lastError).replace(/\s+/gu, ' ').slice(0, 240),
		})}`;
	}

	async function failClosed(code, lastError = '') {
		throw new Error(await diagnostics(code, lastError));
	}

	async function dismissRecognizedWelcomeGuide() {
		const dialogs = page.locator('[role="dialog"]:visible');
		const count = await dialogs.count();
		for (let index = 0; index < count; index += 1) {
			const dialog = dialogs.nth(index);
			const identity = [
				await dialog.getAttribute('aria-label'),
				await dialog.textContent(),
			].filter(Boolean).join(' ').replace(/\s+/gu, ' ').trim();
			if (!recognizedWelcome.some((pattern) => pattern.test(identity))) {
				continue;
			}
			const close = dialog.locator([
				'button[aria-label="Cerrar diálogo"]:visible',
				'button[aria-label="Close dialog"]:visible',
				'button[aria-label="Cerrar"]:visible',
				'button[aria-label="Close"]:visible',
				'button[title="Cerrar"]:visible',
				'button[title="Close"]:visible',
			].join(', '));
			if (await close.count() !== 1) {
				await failClosed('BLOCK_EDITOR_GUIDE_CLOSE_MISSING');
			}
			try {
				await close.click({ trial: true, timeout: 5000 });
				await close.click({ timeout: 5000 });
				await dialog.waitFor({ state: 'hidden', timeout: 10000 });
			} catch (error) {
				await failClosed('BLOCK_EDITOR_GUIDE_CLOSE_FAILED', error);
			}
		}
		const remainingDialogs = page.locator('[role="dialog"]:visible');
		if (await remainingDialogs.count() > 0) {
			await failClosed('BLOCK_EDITOR_UNEXPECTED_DIALOG');
		}
	}

	function codeEditorControls() {
		return page.locator(menuControlSelector).filter({
			hasText: /Editor\s+de\s+c[oó]digo|Code\s+editor/iu,
		});
	}

	async function openCodeEditorMenu() {
		let controls = codeEditorControls();
		if (await controls.count() === 1) {
			return controls;
		}
		if (await controls.count() > 1) {
			await failClosed('CODE_EDITOR_MENU_AMBIGUOUS');
		}

		let lastError = '';
		for (let attempt = 1; attempt <= 3; attempt += 1) {
			await dismissRecognizedWelcomeGuide();
			controls = codeEditorControls();
			if (await controls.count() === 1) {
				return controls;
			}
			if (await controls.count() > 1) {
				await failClosed('CODE_EDITOR_MENU_AMBIGUOUS');
			}

			const options = page.locator(visibleOptionsSelector);
			if (await options.count() > 1) {
				await failClosed('BLOCK_EDITOR_OPTIONS_AMBIGUOUS');
			}
			if (
				await options.count() !== 1
				|| !await options.isEnabled()
			) {
				lastError = 'official options button is absent or disabled';
				await page.waitForTimeout(500 * attempt);
				continue;
			}

			try {
				await options.scrollIntoViewIfNeeded();
				await options.click({ trial: true, timeout: 5000 });
				await options.click({ timeout: 5000 });
			} catch (error) {
				lastError = error;
				await page.waitForTimeout(500 * attempt);
				continue;
			}

			for (let poll = 0; poll < 20; poll += 1) {
				controls = codeEditorControls();
				if (await controls.count() === 1) {
					return controls;
				}
				if (await controls.count() > 1) {
					await failClosed('CODE_EDITOR_MENU_AMBIGUOUS');
				}
				await page.waitForTimeout(250);
			}
			await failClosed(
				'CODE_EDITOR_MENU_MISSING',
				'options opened but the code-editor control did not appear'
			);
		}

		await failClosed('BLOCK_EDITOR_OPTIONS_NOT_ACTIONABLE', lastError);
	}

	async function closeOptionsMenuIfOpen() {
		const options = page.locator(visibleOptionsSelector);
		if (await options.count() > 1) {
			await failClosed('BLOCK_EDITOR_OPTIONS_AMBIGUOUS');
		}
		if (
			await options.count() === 1
			&& await options.getAttribute('aria-expanded') === 'true'
		) {
			try {
				await options.click({ trial: true, timeout: 5000 });
				await options.click({ timeout: 5000 });
				await page.locator('[role="menu"]:visible').waitFor({
					state: 'hidden',
					timeout: 10000,
				});
			} catch (error) {
				await failClosed('BLOCK_EDITOR_OPTIONS_CLOSE_FAILED', error);
			}
		}
	}

	await page.waitForFunction(
		() => Boolean(document.querySelector([
			'.editor-post-text-editor',
			'.edit-post-header',
			'.editor-header',
			'button[aria-label="Opciones"]',
			'button[aria-label="Options"]',
		].join(', '))),
		null,
		{ timeout: 30000 }
	);
	await dismissRecognizedWelcomeGuide();

	if (item.field === 'post_content') {
		let field = page.locator(contentSelector);
		if (await field.count() === 1 && await field.isVisible()) {
			return field;
		}
		if (await field.count() > 1) {
			await failClosed('BLOCK_EDITOR_CONTENT_FIELD_AMBIGUOUS');
		}

		const codeEditor = await openCodeEditorMenu();
		try {
			await codeEditor.click({ trial: true, timeout: 5000 });
			await codeEditor.click({ timeout: 5000 });
			field = page.locator(contentSelector);
			await field.waitFor({ state: 'visible', timeout: 15000 });
			await closeOptionsMenuIfOpen();
		} catch (error) {
			await failClosed('BLOCK_EDITOR_CODE_FIELD_NOT_VISIBLE', error);
		}
		if (await field.count() !== 1) {
			await failClosed('BLOCK_EDITOR_CONTENT_FIELD_AMBIGUOUS');
		}
		return field;
	}

	if (item.field !== 'post_excerpt') {
		await failClosed('BLOCK_EDITOR_FIELD_UNSUPPORTED');
	}
	let field = page.locator(excerptSelector);
	if (await field.count() === 1 && await field.isVisible()) {
		return field;
	}
	if (await field.count() > 1) {
		await failClosed('BLOCK_EDITOR_EXCERPT_FIELD_AMBIGUOUS');
	}
	const editExcerpt = page.getByRole('button').filter({
		hasText: /Editar\s+extracto|Edit\s+excerpt/iu,
	});
	if (await editExcerpt.count() !== 1 || !await editExcerpt.isVisible()) {
		await failClosed('EDIT_EXCERPT_CONTROL_MISSING');
	}
	try {
		await editExcerpt.click({ trial: true, timeout: 5000 });
		await editExcerpt.click({ timeout: 5000 });
		field = page.locator(excerptSelector);
		await field.waitFor({ state: 'visible', timeout: 15000 });
	} catch (error) {
		await failClosed('EDIT_EXCERPT_CONTROL_NOT_ACTIONABLE', error);
	}
	if (await field.count() !== 1) {
		await failClosed('BLOCK_EDITOR_EXCERPT_FIELD_AMBIGUOUS');
	}
	return field;
}
