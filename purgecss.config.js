/* eslint-disable import/no-extraneous-dependencies */
const purgecssWordpress = require( 'purgecss-with-wordpress' );

module.exports = {
	content: [
		'public_html/wp-content/plugins/akibara-*/**/*.php',
		'public_html/wp-content/plugins/akibara-*/**/*.js',
		'public_html/wp-content/themes/akibara-gp/**/*.php',
		'public_html/wp-content/themes/akibara-gp/**/*.js',
	],
	css: [
		'public_html/wp-content/plugins/akibara-*/assets/css/*.css',
		'public_html/wp-content/themes/akibara-gp/assets/css/*.css',
	],
	safelist: {
		standard: [
			...purgecssWordpress.safelist,
			/^woocommerce/,
			/^wc-/,
			/^wc_/,
			/^akb-/,
			/^select2/,
			/^pswp/,
		],
		deep: [
			/^woocommerce/,
			/^wc-/,
			/^akb-/,
		],
		greedy: [
			/^woocommerce/,
			/^wc-/,
			/^akb-/,
		],
	},
};
