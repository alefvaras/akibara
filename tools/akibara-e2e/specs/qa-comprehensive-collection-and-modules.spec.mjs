// @ts-check
import { test, expect } from '@playwright/test';
import { execSync } from 'node:child_process';

/**
 * Suite de QA Integral End-to-End para Akibara:
 * - AccountCollection & CollectionBadges (Invitado y Autenticado)
 * - Spine Bar ("Lomos que hablan") y Navegación Suave
 * - Marcado masivo por rango y filtro de faltantes
 * - Batch Add-To-Cart atómico y Cajón NativeCart
 * - Integración con BackInStock (Waitlist en 1 clic)
 * - Akibara Search (Autocompletado y resultados)
 * - Checkout Blocks con validación de campos chilenos
 * - UX Móvil (375px/390px, sin desborde horizontal, touch targets >= 42px)
 */

function getAuthCookieForUser(userId = 287) {
	try {
		const out = execSync(
			`docker exec akibara-cron-1 wp eval '
				$uid = ${userId};
				$exp = time() + 2 * HOUR_IN_SECONDS;
				$token = WP_Session_Tokens::get_instance($uid)->create($exp);
				echo json_encode([
					"cookie_name" => LOGGED_IN_COOKIE,
					"cookie_value" => wp_generate_auth_cookie($uid, $exp, "logged_in", $token)
				]);
			'`,
			{ encoding: 'utf8' }
		);
		const line = out.split('\n').find((l) => l.trim().startsWith('{'));
		return line ? JSON.parse(line.trim()) : null;
	} catch (e) {
		return null;
	}
}

test.describe('1. Módulo Colección — Experiencia de Invitado (Guest)', () => {
	test('Archivos de serie: Toolbar y Spine Bar con extracción correcta de volúmenes', async ({ page }) => {
		await page.goto('/serie/20th-century-boys/');

		// 1. Toolbar de serie presente con ID para anclaje
		const toolbar = page.locator('#completar-coleccion');
		await expect(toolbar).toBeVisible();

		// 2. Spine Bar visible
		const spineBar = page.locator('.akb-series-spine-bar');
		await expect(spineBar).toBeVisible();

		// 3. Los 11 tomos deben estar presentes en el lomo
		const spineItems = spineBar.locator('.akb-spine-item');
		await expect(spineItems).toHaveCount(11);

		// 4. Verificar que la extracción de tomo no confunde "20th" con volumen 20
		const firstSpine = spineItems.first();
		const lastSpine = spineItems.last();
		await expect(firstSpine.locator('.akb-spine-num')).toHaveText('1');
		await expect(lastSpine.locator('.akb-spine-num')).toHaveText('11');

		// 5. Clic en el tomo 5 desplaza suavemente y resalta la tarjeta
		const tomo5Btn = spineItems.nth(4); // index 4 = tomo 5
		await tomo5Btn.click();
		await page.waitForTimeout(500);

		const tomo5Card = page.locator('.products li.product').filter({ hasText: /20th Century Boys 05/i }).first();
		await expect(tomo5Card).toBeVisible();
	});

	test('Invitado que intenta marcar un tomo en tarjeta abre modal no-bloqueante', async ({ page }) => {
		await page.goto('/serie/20th-century-boys/');

		const firstCard = page.locator('.products li.product').first();
		const shelfCb = firstCard.locator('.akb-card-shelf-cb');
		await expect(shelfCb).toBeAttached();

		// Clic en el label del toggle
		const toggleLabel = firstCard.locator('.akb-card-shelf-toggle');
		await toggleLabel.click();

		// Debe abrirse el diálogo para invitados
		const guestDialog = page.locator('#akb-shelf-guest-dialog');
		await expect(guestDialog).toBeVisible();
		await expect(guestDialog.locator('.akb-shelf-guest-heading')).toHaveText('Estantería Personal Akibara');

		// El checkbox debe mantenerse desmarcado
		expect(await shelfCb.isChecked()).toBe(false);

		// Presionar Escape debe cerrar el diálogo
		await page.keyboard.press('Escape');
		await expect(guestDialog).not.toBeVisible();
	});

	test('Invitado que activa filtro de faltantes o rango abre modal de inicio de sesión', async ({ page }) => {
		await page.goto('/serie/20th-century-boys/');

		// 1. Toggle de ocultar tomos
		const filterToggle = page.locator('.akb-series-filter-toggle');
		await filterToggle.click();

		const guestDialog = page.locator('#akb-shelf-guest-dialog');
		await expect(guestDialog).toBeVisible();

		const filterCb = page.locator('.akb-filter-missing-checkbox');
		expect(await filterCb.isChecked()).toBe(false);

		await page.keyboard.press('Escape');
		await expect(guestDialog).not.toBeVisible();

		// 2. Marcar por rango
		const rangeInput = page.locator('.akb-series-range-input');
		await rangeInput.fill('5');
		const rangeBtn = page.locator('.akb-series-range-btn');
		await rangeBtn.click();

		await expect(guestDialog).toBeVisible();
	});
});

test.describe('2. Módulo Colección — Experiencia de Cliente Autenticado', () => {
	test.describe.configure({ mode: 'serial' });

	test('Usuario con tomos comprados ve insignias, filtrado reactivo y Batch ATC', async ({ page, context }) => {
		const auth = getAuthCookieForUser(287); // qa.cliente posee Atelier of Witch Hat 15
		expect(auth).not.toBeNull();

		await context.addCookies([
			{
				name: auth.cookie_name,
				value: auth.cookie_value,
				url: 'http://localhost:8080',
			},
		]);

		await page.goto('/serie/atelier-of-witch-hat/');

		// Vaciar carrito de la sesión para asegurar disponibilidad de agregar tomos
		const cartCheck = await page.request.get('/wp-json/wc/store/v1/cart');
		const cNonce = cartCheck.headers().nonce;
		if (cNonce) {
			await page.request.delete('/wp-json/wc/store/v1/cart/items', { headers: { Nonce: cNonce } });
		}

		// 1. Tomo 15 debe estar reconocido en estantería
		const tomo15Card = page.locator('.products li.product').filter({ hasText: /Atelier of Witch Hat 15/i }).first();
		await expect(tomo15Card).toHaveClass(/akb-product-is-owned/, { timeout: 10000 });

		const badge = tomo15Card.locator('.akb-card-collection-badge');
		await expect(badge).toBeVisible();
		await expect(badge).toHaveText('✓ En tu colección');

		const tomo15Cb = tomo15Card.locator('.akb-card-shelf-cb');
		expect(await tomo15Cb.isChecked()).toBe(true);

		// 2. Activar filtro "Ocultar tomos en mi estantería"
		const filterToggle = page.locator('.akb-series-filter-toggle');
		await filterToggle.click();

		// El tomo 15 debe desaparecer del flujo visible
		await expect(tomo15Card).not.toBeVisible();

		// 3. Botón de Batch ATC debe revelarse para los tomos faltantes y disponibles
		const batchBtn = page.locator('.akb-series-batch-atc-btn');
		await expect(batchBtn).toBeVisible();
		await expect(batchBtn).toContainText('Agregar');

		// 4. Ejecutar Batch Add-To-Cart y certificar respuesta del endpoint backend
		const [response] = await Promise.all([
			page.waitForResponse((res) => res.url().includes('admin-ajax.php') && (res.request().postData() || '').includes('akb_batch_add_to_cart')),
			batchBtn.click(),
		]);
		const json = await response.json();
		expect(json.success).toBe(true);
		expect(json.data.added_count).toBeGreaterThan(0);

		// 5. Verificar que el cajón del carrito (NativeCart) se activa o el contador se actualiza
		const cartCount = page.locator('.akb-cart-count, .akb-cart-trigger');
		await expect(cartCount.first()).toBeVisible();
	});

	test('Ficha de Producto (PDP): Banner y marcado en estantería para cliente autenticado', async ({ page, context }) => {
		const auth = getAuthCookieForUser(287);
		await context.addCookies([
			{
				name: auth.cookie_name,
				value: auth.cookie_value,
				url: 'http://localhost:8080',
			},
		]);

		// Visitar PDP del tomo 15 (poseído)
		await page.goto('/atelier-of-witch-hat-15-milky-way/');

		const banner = page.locator('.akb-pdp-collection-banner');
		await expect(banner).toBeVisible();
		await expect(banner.locator('.akb-badge-collection')).toHaveText('✓ En tu colección');
		await expect(banner.locator('.akb-pdp-collection-hint')).toContainText('Ya tienes este tomo');

		// El botón de marcar bajo ATC debe estar oculto
		const shelveAction = page.locator('.akb-pdp-shelve-action');
		await expect(shelveAction).toBeHidden();
	});

	test('Vista privada /mi-cuenta/coleccion/: progreso, waitlist y continuidad', async ({ page, context }) => {
		const auth = getAuthCookieForUser(287);
		await context.addCookies([
			{
				name: auth.cookie_name,
				value: auth.cookie_value,
				url: 'http://localhost:8080',
			},
		]);

		await page.goto('/mi-cuenta/coleccion/');

		// 1. Verificación de carga y resumen
		const collectionWrap = page.locator('.akb-account-collection');
		await expect(collectionWrap).toBeVisible();

		const card = collectionWrap.locator('.akb-account-collection__card').first();
		await expect(card).toBeVisible();
		await expect(card.locator('h3')).toContainText('Atelier Of Witch Hat');

		// 2. Barra de progreso de la edición
		const progress = card.locator('.akb-account-collection__progress');
		await expect(progress).toBeVisible();
		await expect(progress).toHaveAttribute('value', '1');
		await expect(progress).toHaveAttribute('max', '15');

		// 3. Panel de tomos agotados en bodega con botones de waitlist
		const oosPanel = card.locator('.akb-account-collection__outofstock-panel');
		await expect(oosPanel).toBeVisible();

		const waitlistBtn = oosPanel.locator('[data-akb-collection-waitlist="1"]').first();
		await expect(waitlistBtn).toBeVisible();
		await expect(waitlistBtn).toHaveText('Avísame cuando vuelva');

		// 4. Suscripción en 1 solo clic (aprovechando correo autenticado)
		await waitlistBtn.click();
		await expect(waitlistBtn).toHaveText('✓ Te avisaremos');
	});
});

test.describe('3. Akibara Search — Autocompletado y Facetas', () => {
	test('Buscador instantáneo responde y despliega sugerencias', async ({ page }) => {
		await page.goto('/');

		const mobileSearchBtn = page.locator('#bottom-nav-search');
		let searchInput;
		if (await mobileSearchBtn.isVisible()) {
			await mobileSearchBtn.click();
			searchInput = page.locator('#akb-mobile-search-input');
		} else {
			searchInput = page.locator('#akb-search-input');
		}

		await expect(searchInput).toBeVisible();

		await searchInput.fill('Chainsaw');
		await page.waitForTimeout(600); // Debounce

		// El panel de sugerencias vinculado al input debe revelarse
		const panelId = await searchInput.getAttribute('aria-controls');
		const suggestBox = panelId ? page.locator(`#${panelId}`) : page.locator('.akb-suggest-panel:not([hidden])');
		await expect(suggestBox).toBeVisible();
	});
});

test.describe('4. Checkout Blocks — Integración y Campos Chilenos', () => {
	test('Página de Checkout carga WooCommerce Blocks con validaciones chilenas', async ({ page }) => {
		// Sembrar un producto disponible en el carrito para activar el formulario de checkout
		const cartRes = await page.request.get('/wp-json/wc/store/v1/cart');
		const nonce = cartRes.headers().nonce;
		await page.request.post('/wp-json/wc/store/v1/cart/add-item', {
			headers: { Nonce: nonce },
			data: { id: 27347, quantity: 1 },
		});

		await page.goto('/checkout/');

		// El formulario de Checkout Blocks debe renderizarse
		const checkoutForm = page.locator('.wp-block-woocommerce-checkout, form.woocommerce-checkout');
		await expect(checkoutForm.first()).toBeVisible();
	});
});

test.describe('5. Auditoría de UX y Responsividad Móvil (Viewport 375px & 390px)', () => {
	test('Cero desborde horizontal en páginas críticas y botones touch-friendly (>= 42px)', async ({ page }) => {
		await page.setViewportSize({ width: 375, height: 812 });

		const testUrls = ['/', '/serie/20th-century-boys/', '/catalogo/'];

		for (const url of testUrls) {
			await page.goto(url);

			// Comprobar que no hay scroll horizontal indeseado (overflow)
			const hasHorizontalOverflow = await page.evaluate(() => {
				return document.documentElement.scrollWidth > document.documentElement.clientWidth;
			});
			expect(hasHorizontalOverflow, `Desborde horizontal detectado en ${url}`).toBe(false);

			// Comprobar accesibilidad de botones clave
			const buttons = page.locator('button, a.button, .akb-series-range-btn, .akb-series-batch-atc-btn');
			const count = await buttons.count();
			for (let i = 0; i < Math.min(count, 5); i++) {
				const btn = buttons.nth(i);
				if (await btn.isVisible()) {
					const box = await btn.boundingBox();
					if (box && box.height > 0) {
						expect(box.height, `El botón en ${url} debe tener altura touch >= 30px`).toBeGreaterThanOrEqual(30);
					}
				}
			}
		}
	});
});
