module.exports = {
	root: true,
	env: {
		browser: true,
		es2021: true,
		node: true,
	},
	globals: {
		jQuery: 'readonly',
		$: 'readonly',
		wp: 'readonly',
		wc: 'readonly',
		google: 'readonly',
		gsap: 'readonly',
		ScrollTrigger: 'readonly',
		akb_cart_blocks_params: 'readonly',
		akbSearchSettings: 'readonly',
		akb_pudo_params: 'readonly',
	},
	settings: {
		jest: {
			version: 29,
		},
	},
	extends: [
		'plugin:@woocommerce/recommended',
		'plugin:security/recommended-legacy',
		'plugin:no-unsanitized/DOM',
	],
	rules: {
		'prettier/prettier': 'off',
		'yoda': 'off',
		'no-var': 'error',
		'no-alert': 'error',
		'no-eval': 'error',
		'no-implied-eval': 'error',
		'security/detect-eval-with-expression': 'error',
		'security/detect-non-literal-regexp': 'warn',
		'security/detect-unsafe-regex': 'error',
		'@wordpress/no-global-active-element': 'error',
		'@wordpress/no-unused-vars-before-return': 'error',
		'jsdoc/check-param-names': 'warn',
		'jsdoc/check-tag-names': 'warn',
		'jsdoc/newline-after-description': 'off',
		'@typescript-eslint/no-unused-vars': [
			'error',
			{
				argsIgnorePattern: '^_',
				varsIgnorePattern: '^_',
				caughtErrorsIgnorePattern: '^_',
			},
		],
		'no-unused-vars': [
			'error',
			{
				argsIgnorePattern: '^_',
				varsIgnorePattern: '^_',
				caughtErrorsIgnorePattern: '^_',
			},
		],
	},
};
