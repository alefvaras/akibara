'use strict';

const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const { join } = require('node:path');
const test = require('node:test');

const themeRoot = join(__dirname, '..');
const css = readFileSync(join(themeRoot, 'assets/css/woocommerce.css'), 'utf8');
const theme = JSON.parse(readFileSync(join(themeRoot, 'theme.json'), 'utf8'));
const palette = Object.fromEntries(
	theme.settings.color.palette.map(({ slug, color }) => [slug, color])
);

function luminance(hex) {
	const channels = hex
		.replace('#', '')
		.match(/.{2}/g)
		.map((channel) => parseInt(channel, 16) / 255)
		.map((channel) =>
			channel <= 0.04045
				? channel / 12.92
				: Math.pow((channel + 0.055) / 1.055, 2.4)
		);

	return (
		0.2126 * channels[0] +
		0.7152 * channels[1] +
		0.0722 * channels[2]
	);
}

function contrast(foreground, background) {
	const lighter = Math.max(luminance(foreground), luminance(background));
	const darker = Math.min(luminance(foreground), luminance(background));

	return (lighter + 0.05) / (darker + 0.05);
}

test('Google Login keeps its accessible ink on the plugin white surface', () => {
	assert.match(
		css,
		/\.akb-account-auth \.akb-google-login-wrap \.wp_google_login__button[\s\S]*?color:\s*#3D4145;/
	);
	assert.ok(contrast('#3D4145', '#FFFFFF') >= 4.5);
});

test('WooCommerce password toggle preserves the core SVG and makes it visible', () => {
	assert.match(
		css,
		/\.woocommerce form \.show-password-input::before\s*\{\s*filter:\s*invert\(1\);\s*\}/
	);
	assert.doesNotMatch(
		css,
		/\.woocommerce form \.show-password-input::before\s*\{[^}]*background-image:/
	);
	assert.ok(contrast('#EEEEEE', palette.surface) >= 3);
});

test('Checkout Blocks address-line toggle has a scoped 44px target', () => {
	assert.match(
		css,
		/\.woocommerce-cart \.wc-block-components-address-form__address_2-toggle,\s*\.woocommerce-checkout \.wc-block-components-address-form__address_2-toggle\s*\{\s*display:\s*flex;\s*align-items:\s*center;\s*min-height:\s*44px;\s*\}/
	);
	assert.doesNotMatch(
		css,
		/(^|\n)\.wc-block-components-address-form__address_2-toggle\s*\{/
	);
});
