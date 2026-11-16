<?php
/**
 * Declarative SEO/editorial data normalization for Akibara.
 *
 * Dry-run (default):
 *   wp eval-file - < ops/apply-seo-content-quality-data.php
 *
 * Apply to an isolated fixture/local environment:
 *   AKB_SEO_CONTENT_APPLY=1 wp eval-file - \
 *     < ops/apply-seo-content-quality-data.php
 *
 * Production additionally requires AKB_SEO_CONTENT_ALLOW_PRODUCTION=1. Rank
 * Math settings are deliberately audit-only: they belong in the official
 * Rank Math administration UI and this script never writes its option arrays.
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Run this file through WP-CLI: wp eval-file -\n" );
	exit( 1 );
}

/**
 * Read an explicit boolean opt-in.
 *
 * @param string $name Environment variable or constant.
 * @return bool
 */
function akb_seo_content_setting_enabled( $name ) {
	if ( defined( $name ) && true === constant( $name ) ) {
		return true;
	}

	return '1' === getenv( $name );
}

/**
 * Reviewed content and configuration manifest.
 *
 * @return array
 */
function akb_seo_content_manifest() {
	return array(
		'post_fingerprints' => array(
			185 => array( 'type' => 'page', 'status' => 'publish', 'slug' => 'faq' ),
			13120 => array( 'type' => 'product', 'status' => 'publish', 'slug' => 'gantz-deluxe-edition-1-ivrea-argentina' ),
			13124 => array( 'type' => 'product', 'status' => 'publish', 'slug' => 'gantz-deluxe-edition-2-ivrea-argentina' ),
			13128 => array( 'type' => 'product', 'status' => 'publish', 'slug' => 'gantz-deluxe-edition-3-ivrea-argentina' ),
			13225 => array( 'type' => 'product', 'status' => 'publish', 'slug' => 'vagabond-1-ivrea-argentina' ),
			13229 => array( 'type' => 'product', 'status' => 'publish', 'slug' => 'vagabond-2-ivrea-argentina' ),
			13232 => array( 'type' => 'product', 'status' => 'publish', 'slug' => 'vagabond-3-ivrea-argentina' ),
			13310 => array( 'type' => 'product', 'status' => 'publish', 'slug' => 'slam-dunk-deluxe-1-ivrea-argentina' ),
			14262 => array( 'type' => 'product', 'status' => 'publish', 'slug' => 'gantz-deluxe-edition-4-ivrea-argentina' ),
			14265 => array( 'type' => 'product', 'status' => 'publish', 'slug' => 'gantz-deluxe-edition-5-ivrea-argentina' ),
			15192 => array( 'type' => 'product', 'status' => 'publish', 'slug' => 'vagabond-4-ivrea-argentina' ),
			15197 => array( 'type' => 'product', 'status' => 'publish', 'slug' => 'vagabond-5-ivrea-argentina' ),
			15200 => array( 'type' => 'product', 'status' => 'publish', 'slug' => 'vagabond-6-ivrea-argentina' ),
			15203 => array( 'type' => 'product', 'status' => 'publish', 'slug' => 'vagabond-7-ivrea-argentina' ),
			15242 => array( 'type' => 'product', 'status' => 'publish', 'slug' => 'vagabond-21-ivrea-argentina' ),
			15406 => array( 'type' => 'product', 'status' => 'publish', 'slug' => 'mob-psycho-100-3-ivrea-argentina' ),
			15409 => array( 'type' => 'product', 'status' => 'publish', 'slug' => 'mob-psycho-100-4-ivrea-argentina' ),
			15412 => array( 'type' => 'product', 'status' => 'publish', 'slug' => 'mob-psycho-100-5-ivrea-argentina' ),
			15415 => array( 'type' => 'product', 'status' => 'publish', 'slug' => 'mob-psycho-100-6-ivrea-argentina' ),
			15418 => array( 'type' => 'product', 'status' => 'publish', 'slug' => 'mob-psycho-100-7-ivrea-argentina' ),
			15428 => array( 'type' => 'product', 'status' => 'publish', 'slug' => 'monster-3-ivrea-argentina' ),
			15431 => array( 'type' => 'product', 'status' => 'publish', 'slug' => 'monster-4-ivrea-argentina' ),
			15434 => array( 'type' => 'product', 'status' => 'publish', 'slug' => 'monster-5-ivrea-argentina' ),
			15437 => array( 'type' => 'product', 'status' => 'publish', 'slug' => 'monster-6-ivrea-argentina' ),
			15440 => array( 'type' => 'product', 'status' => 'publish', 'slug' => 'monster-7-ivrea-argentina' ),
			15443 => array( 'type' => 'product', 'status' => 'publish', 'slug' => 'monster-8-ivrea-argentina' ),
			17099 => array( 'type' => 'product', 'status' => 'publish', 'slug' => 'pluto-1-planeta-espana' ),
			17102 => array( 'type' => 'product', 'status' => 'publish', 'slug' => 'pluto-2-planeta-espana' ),
			17105 => array( 'type' => 'product', 'status' => 'publish', 'slug' => 'pluto-3-planeta-espana' ),
			17108 => array( 'type' => 'product', 'status' => 'publish', 'slug' => 'pluto-4-planeta-espana' ),
			17111 => array( 'type' => 'product', 'status' => 'publish', 'slug' => 'pluto-5-planeta-espana' ),
			17114 => array( 'type' => 'product', 'status' => 'publish', 'slug' => 'pluto-6-planeta-espana' ),
			17117 => array( 'type' => 'product', 'status' => 'publish', 'slug' => 'pluto-7-planeta-espana' ),
			17120 => array( 'type' => 'product', 'status' => 'publish', 'slug' => 'pluto-8-planeta-espana' ),
			21627 => array( 'type' => 'product', 'status' => 'publish', 'slug' => 'tokyo-revengers-2-ivrea-argentina' ),
			21630 => array( 'type' => 'product', 'status' => 'publish', 'slug' => 'tokyo-revengers-3-ivrea-argentina' ),
			21634 => array( 'type' => 'product', 'status' => 'publish', 'slug' => 'tokyo-revengers-4-ivrea-argentina' ),
			21637 => array( 'type' => 'product', 'status' => 'publish', 'slug' => 'tokyo-revengers-30-ivrea-argentina' ),
			21644 => array( 'type' => 'product', 'status' => 'publish', 'slug' => 'tokyo-revengers-5-ivrea-argentina' ),
			21648 => array( 'type' => 'product', 'status' => 'publish', 'slug' => 'tokyo-revengers-6-ivrea-argentina' ),
			21655 => array( 'type' => 'product', 'status' => 'publish', 'slug' => 'tokyo-revengers-8-ivrea-argentina' ),
			21660 => array( 'type' => 'product', 'status' => 'publish', 'slug' => 'tokyo-revengers-9-ivrea-argentina' ),
			21669 => array( 'type' => 'product', 'status' => 'publish', 'slug' => 'tokyo-revengers-12-ivrea-argentina' ),
			21672 => array( 'type' => 'product', 'status' => 'publish', 'slug' => 'tokyo-revengers-13-ivrea-argentina' ),
			21675 => array( 'type' => 'product', 'status' => 'publish', 'slug' => 'tokyo-revengers-14-ivrea-argentina' ),
			21681 => array( 'type' => 'product', 'status' => 'publish', 'slug' => 'tokyo-revengers-16-ivrea-argentina' ),
			21685 => array( 'type' => 'product', 'status' => 'publish', 'slug' => 'tokyo-revengers-17-ivrea-argentina' ),
			21689 => array( 'type' => 'product', 'status' => 'publish', 'slug' => 'tokyo-revengers-18-ivrea-argentina' ),
			21692 => array( 'type' => 'product', 'status' => 'publish', 'slug' => 'tokyo-revengers-19-ivrea-argentina' ),
			21701 => array( 'type' => 'product', 'status' => 'publish', 'slug' => 'tokyo-revengers-22-ivrea-argentina' ),
			21704 => array( 'type' => 'product', 'status' => 'publish', 'slug' => 'tokyo-revengers-23-ivrea-argentina' ),
			21707 => array( 'type' => 'product', 'status' => 'publish', 'slug' => 'tokyo-revengers-24-ivrea-argentina' ),
			21710 => array( 'type' => 'product', 'status' => 'publish', 'slug' => 'tokyo-revengers-25-ivrea-argentina' ),
			21713 => array( 'type' => 'product', 'status' => 'publish', 'slug' => 'tokyo-revengers-26-ivrea-argentina' ),
			21719 => array( 'type' => 'product', 'status' => 'publish', 'slug' => 'tokyo-revengers-28-ivrea-argentina' ),
			21722 => array( 'type' => 'product', 'status' => 'publish', 'slug' => 'tokyo-revengers-29-ivrea-argentina' ),
			23641 => array( 'type' => 'page', 'status' => 'publish', 'slug' => 'devoluciones' ),
			24989 => array( 'type' => 'attachment', 'status' => 'inherit', 'slug' => '24978_new' ),
			24991 => array( 'type' => 'attachment', 'status' => 'inherit', 'slug' => '24976_new' ),
			24992 => array( 'type' => 'attachment', 'status' => 'inherit', 'slug' => '24975_new' ),
		),
		'product_terms'  => array(
			array(
				'ids'            => array( 13120, 13124, 13128, 14262, 14265 ),
				'taxonomy'       => 'pa_autor',
				'expected_before' => array(),
				'desired'        => array( 'Hiroya Oku' ),
				'reason'         => 'Gantz Deluxe 01-05 author',
			),
			array(
				'ids'            => array( 13120, 13124, 13128, 14262, 14265 ),
				'taxonomy'       => 'pa_genero',
				'expected_before' => array(),
				'desired'        => array( 'Acción', 'Ciencia Ficción', 'Terror' ),
				'reason'         => 'Gantz Deluxe 01-05 genres',
			),
			array(
				'ids'            => array( 13225, 13229, 13232, 15192, 15197, 15200, 15203 ),
				'taxonomy'       => 'pa_autor',
				'expected_before' => array(),
				'desired'        => array( 'Takehiko Inoue' ),
				'reason'         => 'Vagabond 01-07 author',
			),
			array(
				'ids'            => array( 13225, 13229, 13232, 15192, 15197, 15200, 15203 ),
				'taxonomy'       => 'pa_genero',
				'expected_before' => array(),
				'desired'        => array( 'Acción', 'Drama', 'Histórico' ),
				'reason'         => 'Vagabond 01-07 genres',
			),
			array(
				'ids'            => array( 13310 ),
				'taxonomy'       => 'pa_autor',
				'expected_before' => array(),
				'desired'        => array( 'Takehiko Inoue' ),
				'reason'         => 'Slam Dunk Deluxe 01 author',
			),
			array(
				'ids'            => array( 13310 ),
				'taxonomy'       => 'pa_genero',
				'expected_before' => array(),
				'desired'        => array( 'Comedia', 'Deportes', 'Drama' ),
				'reason'         => 'Slam Dunk Deluxe 01 genres',
			),
			array(
				'ids'            => array( 17099, 17102, 17105, 17108, 17111, 17114, 17117, 17120 ),
				'taxonomy'       => 'pa_autor',
				'expected_before' => array( 'Hitoshi Iwaaki' ),
				'desired'        => array( 'Naoki Urasawa' ),
				'reason'         => 'Pluto 01-08 author correction',
			),
		),
		'author_terms'   => array(
			array(
				'term_id'  => 236,
				'old_name' => 'Tatsukia Fujimoto',
				'old_slug' => 'tatsukia-fujimoto',
				'new_name' => 'Tatsuki Fujimoto',
				'new_slug' => 'tatsuki-fujimoto',
			),
			array(
				'term_id'  => 275,
				'old_name' => 'Tappei Nagatsuk',
				'old_slug' => 'tappei-nagatsuk',
				'new_name' => 'Tappei Nagatsuki',
				'new_slug' => 'tappei-nagatsuki',
			),
			array(
				'term_id'  => 358,
				'old_name' => 'Brian K.Vaughan',
				'old_slug' => 'brian-k-vaughan',
				'new_name' => 'Brian K. Vaughan',
				'new_slug' => 'brian-k-vaughan',
			),
			array(
				'term_id'  => 456,
				'old_name' => 'Masamure Shirow',
				'old_slug' => 'masamure-shirow',
				'new_name' => 'Masamune Shirow',
				'new_slug' => 'masamune-shirow',
			),
		),
		'attachment_alt' => array(
			24992 => 'Comparación de portadas de One Piece de distintas ediciones',
			24991 => 'Collage de portadas de Vagabond, Berserk, Monster y Gantz',
			24989 => 'Portadas de los cuatro primeros tomos de Banana Fish con Ash Lynx',
		),
		'excerpt_html_cleanup' => array(
			'ids'      => array(
				21627, 21630, 21634, 21637, 21644, 21648, 21655, 21660,
				21669, 21672, 21675, 21681, 21685, 21689, 21692, 21701,
				21704, 21707, 21710, 21713, 21719, 21722,
			),
			'old_open' => '<p class="font-claude-response-body break-words whitespace-normal leading-[1.7]">',
			'new_open' => '<p>',
			'reason'   => 'Remove pasted editor utility classes while preserving the complete Tokyo Revengers copy',
		),
		'excerpt_text_replacements' => array(
			array(
				'post_id' => 15242,
				'old'     => 'tomo 20',
				'new'     => 'tomo 21',
				'reason'  => 'Vagabond 21 excerpt names the wrong volume',
			),
		),
		'curated_product_excerpts' => array(
			array(
				'post_id'               => 15406,
				'content_sha256'        => '386f944c72d63afc9796cd15e67ad8edf0df47e972a53c98bd265e408187c965',
				'expected_before'       => 'Edición de lujo 2 en 1 de Ivrea Argentina. Envío a todo Chile desde Akibara.',
				'desired'               => 'Mob Psycho 100 03 sigue el despertar psíquico de Ritsu y el rescate del cuartel de Claw. Edición 2 en 1 de Ivrea Argentina; despacho según cobertura.',
				'reason'                => 'Unique synopsis derived from the reviewed Ritsu and Claw rescue content',
			),
			array(
				'post_id'               => 15409,
				'content_sha256'        => 'a7f76f6b5c4ee07ee407ee9e5e0f861deb25d8bd3bf711404f038906001130f0',
				'expected_before'       => 'Edición de lujo 2 en 1 de Ivrea Argentina. Envío a todo Chile desde Akibara.',
				'desired'               => 'Mob Psycho 100 04 revela las Siete Ramas de Claw y el papel decisivo de Reigen en el asalto. Edición 2 en 1 de Ivrea Argentina; despacho según cobertura.',
				'reason'                => 'Unique synopsis derived from the reviewed Seven Divisions and Reigen content',
			),
			array(
				'post_id'               => 15412,
				'content_sha256'        => 'c121c95274f894e02a74636d7a8515b17510bafd8b4330255e9a599cab680dd0',
				'expected_before'       => 'Edición de lujo 2 en 1 de Ivrea Argentina. Envío a todo Chile desde Akibara.',
				'desired'               => 'Mob Psycho 100 05 enfrenta a Mob con Suzuki Toichiro en el clímax de Dominación Mundial. Edición 2 en 1 de Ivrea Argentina; despacho según cobertura.',
				'reason'                => 'Unique synopsis derived from the reviewed Suzuki Toichiro climax content',
			),
			array(
				'post_id'               => 15415,
				'content_sha256'        => '96e7e2a4824deb3b69a299a2a9cdae37d85958005b5d72cea09ef648a3bfcc63',
				'expected_before'       => 'Edición de lujo 2 en 1 de Ivrea Argentina. Envío a todo Chile desde Akibara.',
				'desired'               => 'Mob Psycho 100 06 somete a Mob a la realidad espiritual de Keiji Mogami y pone a prueba su ética. Edición 2 en 1 de Ivrea Argentina; despacho según cobertura.',
				'reason'                => 'Unique synopsis derived from the reviewed Keiji Mogami content',
			),
			array(
				'post_id'               => 15418,
				'content_sha256'        => 'd41bd89423aab79d891d7eba298b1957687c59f606c91f95b8d058ad442e3a95',
				'expected_before'       => 'Mob Psycho 100 07 separa a Mob de Reigen y prepara el despertar de ?%, su emoción reprimida. Edición 2 en 1 de Ivrea Argentina; despacho según cobertura.',
				'desired'               => 'Mob Psycho 100 07 separa a Mob de Reigen y prepara el despertar de ???%, su emoción reprimida. Edición 2 en 1 de Ivrea Argentina; despacho según cobertura.',
				'reason'                => 'Unique synopsis derived from the reviewed Reigen separation and canonical triple-question-percent state',
			),
			array(
				'post_id'               => 15428,
				'content_sha256'        => '11f0e5527a2df2ea99446ddf0ef08701602e6b564406fc61d14f6cc7bb00d07f',
				'expected_before'       => 'La obra cumbre de Naoki Urasawa en formato A5 de lujo. Envío a todo Chile desde Akibara.',
				'desired'               => 'Monster 03 enfrenta a Tenma con la trampa de Lunge y la confesión de Eva sobre Johan. Edición kanzenban A5 de Ivrea Argentina; despacho según cobertura.',
				'reason'                => 'Unique synopsis derived from the reviewed Lunge and Eva content',
			),
			array(
				'post_id'               => 15431,
				'content_sha256'        => 'd9cff5a0706a3a888f70bd95611f16dd0fdae16a3482eb9c93a40fa5b4dfde4d',
				'expected_before'       => 'La obra cumbre de Naoki Urasawa en formato A5 de lujo. Envío a todo Chile desde Akibara.',
				'desired'               => 'Monster 04 sigue a Richard Braun, revela la identidad robada de Johan y abre la ruta a Praga. Edición kanzenban A5 de Ivrea Argentina; despacho según cobertura.',
				'reason'                => 'Unique synopsis derived from the reviewed Richard Braun and Prague content',
			),
			array(
				'post_id'               => 15434,
				'content_sha256'        => 'e748df7d3adf1313309517ef550e7590db3863739937a46820f4ab49db4ca087',
				'expected_before'       => 'La obra cumbre de Naoki Urasawa en formato A5 de lujo. Envío a todo Chile desde Akibara.',
				'desired'               => 'Monster 05 presenta El Monstruo Sin Nombre, a Wolfgang Grimmer y los fantasmas del 511 Kinderheim. Kanzenban A5 de Ivrea Argentina; despacho según cobertura.',
				'reason'                => 'Unique synopsis derived from the reviewed nameless monster and Grimmer content',
			),
			array(
				'post_id'               => 15437,
				'content_sha256'        => '95b8b685a6990ab6caa76604e21cd0823b74492bd492022c71cbfe5d716fe570',
				'expected_before'       => 'La obra cumbre de Naoki Urasawa en formato A5 de lujo. Envío a todo Chile desde Akibara.',
				'desired'               => 'Monster 06 reúne la cinta perdida de Johan, la trampa contra Jan Suk y el regreso de Lunge a Praga. Kanzenban A5 de Ivrea Argentina; despacho según cobertura.',
				'reason'                => 'Unique synopsis derived from the reviewed tape, Jan Suk and Lunge content',
			),
			array(
				'post_id'               => 15440,
				'content_sha256'        => '1fd52986f8c9d58cf2b9a31d4fd3e7017b236001edd5ff597b48621704e99b2b',
				'expected_before'       => 'La obra cumbre de Naoki Urasawa en formato A5 de lujo. Envío a todo Chile desde Akibara.',
				'desired'               => 'Monster 07 reconstruye la noche de Nina en la Mansión de la Rosa Roja y redefine la historia de los gemelos. Kanzenban A5 de Ivrea Argentina; despacho según cobertura.',
				'reason'                => 'Unique synopsis derived from the reviewed Nina and Red Rose Mansion content',
			),
			array(
				'post_id'               => 15443,
				'content_sha256'        => '39342809ec301f6aaa0b8ca81682f596f1a6def03976341e070750dda7851b0b',
				'expected_before'       => 'La obra cumbre de Naoki Urasawa en formato A5 de lujo. Envío a todo Chile desde Akibara.',
				'desired'               => 'Monster 08 cierra las memorias de Nina en la Mansión de la Rosa Roja y encamina la confrontación final. Kanzenban A5 de Ivrea Argentina; despacho según cobertura.',
				'reason'                => 'Unique synopsis derived from the reviewed Nina memories and final confrontation content',
			),
		),
		'legal_copy'     => array(
			array(
				'post_id' => 185,
				'old'     => 'Contáctanos dentro de las <strong>72 horas</strong> desde la recepción con fotos del producto y del embalaje. Akibara asume el costo de retorno y te enviamos un reemplazo sin costo o el reembolso íntegro, a tu elección.',
				'new'     => 'Si el daño ocurrió durante el despacho, contáctanos idealmente dentro de las <strong>72 horas</strong> desde la recepción con fotos del producto y del embalaje. Este plazo facilita la gestión con el transportista y <strong>no limita la garantía legal de 6 meses</strong> ante fallas o defectos cubiertos por la garantía legal. Akibara asume el costo de retorno y te enviamos un reemplazo sin costo o el reembolso íntegro, a tu elección.',
				'reason'  => 'FAQ: the 72-hour transport notice does not limit the six-month legal warranty',
			),
			array(
				'post_id' => 23641,
				'old'     => '<li>Contáctanos dentro de <strong>72 horas</strong> desde la recepción con fotos del producto y del embalaje.</li>',
				'new'     => '<li>Si el problema corresponde a daño de transporte, contáctanos idealmente dentro de <strong>72 horas</strong> desde la recepción con fotos del producto y del embalaje. Este plazo facilita la gestión con el transportista y <strong>no limita la garantía legal de 6 meses</strong> ante fallas o defectos cubiertos por la garantía legal.</li>',
				'reason'  => 'Returns policy: distinguish transport notice from the legal warranty',
			),
		),
		'rank_math_ui'   => array(
			'option_name' => 'rank-math-options-titles',
			'policy'      => 'audit-only; configure through Rank Math > Titles & Meta',
			'expected'    => array(
				'noindex_empty_taxonomies' => 'on',
				'pt_product_title'        => '%title% – %wc_brand% | Akibara',
				'pt_page_title'           => '%title% %sep% %sitename%',
				'tax_product_brand_title' => 'Manga y Cómics %term% %page% %sep% Comprar en Akibara Chile',
				'tax_product_cat_title'   => '%term% %page% %sep% Comprar Manga y Cómics en Akibara Chile',
				'tax_pa_autor_title'      => 'Manga de %term% %page% %sep% Comprar en Akibara Chile',
				'tax_pa_autor_description' => 'Todos los títulos de %term% disponibles en Akibara Chile. Despacho disponible según cobertura del transportista.',
				'tax_pa_autor_robots'     => array( 'index' ),
				'tax_pa_genero_title'     => 'Manga de %term% %page% %sep% Comprar en Akibara Chile',
				'tax_pa_serie_title'      => '%term% %sep% Completa tu colección en Akibara Chile',
				'tax_pa_serie_description' => 'Revisa el catálogo y completa tu colección de %term% en Akibara Chile. Tomos disponibles y a pedido, con despacho a todo el país.',
			),
			'sitemap_option_name' => 'rank-math-options-sitemap',
			'sitemap_expected'    => array(
				'tax_pa_autor_sitemap' => 'on',
			),
		),
	);
}

/**
 * Stable sorted string array.
 *
 * @param array $values Values.
 * @return array
 */
function akb_seo_content_sorted_strings( $values ) {
	$values = array_values( array_map( 'strval', $values ) );
	sort( $values, SORT_STRING );
	return $values;
}

/**
 * Current names assigned to an object for a taxonomy.
 *
 * @param int    $object_id Object ID.
 * @param string $taxonomy Taxonomy.
 * @return array|WP_Error
 */
function akb_seo_content_object_term_names( $object_id, $taxonomy ) {
	$names = wp_get_object_terms(
		$object_id,
		$taxonomy,
		array( 'fields' => 'names' )
	);

	return is_wp_error( $names ) ? $names : akb_seo_content_sorted_strings( $names );
}

/**
 * Resolve reviewed term names without creating taxonomy data implicitly.
 *
 * @param string $taxonomy Taxonomy.
 * @param array  $names Exact names.
 * @return array|WP_Error
 */
function akb_seo_content_resolve_term_ids( $taxonomy, $names ) {
	$term_ids = array();

	foreach ( $names as $name ) {
		$term = get_term_by( 'name', $name, $taxonomy );
		if ( ! $term instanceof WP_Term ) {
			return new WP_Error(
				'akb_missing_manifest_term',
				sprintf( 'Missing %s term: %s', $taxonomy, $name )
			);
		}
		$term_ids[] = (int) $term->term_id;
	}

	return $term_ids;
}

/**
 * Append a planned/applied change.
 *
 * @param array  $changes Change list.
 * @param bool   $apply Apply mode.
 * @param string $operation Operation name.
 * @param array  $payload Auditable payload.
 * @return array
 */
function akb_seo_content_change( $changes, $apply, $operation, $payload ) {
	$changes[] = array(
		'operation' => $operation,
		'status'    => $apply ? 'applied' : 'planned',
		'payload'   => $payload,
	);
	return $changes;
}

/**
 * Normalize editorial text for duplicate/length auditing.
 *
 * @param string $text Raw text.
 * @return string
 */
function akb_seo_content_normalize_text( $text ) {
	$text = html_entity_decode( wp_strip_all_tags( (string) $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	$text = preg_replace( '/\s+/u', ' ', trim( $text ) );
	return (string) $text;
}

/**
 * Stable content fingerprint across the official editor and CSV importer.
 *
 * WordPress may persist identical HTML with LF or CRLF line endings depending
 * on the administration surface used. Newline normalization keeps the
 * fail-closed guard focused on semantic source changes.
 *
 * @param string $content Raw post content.
 * @return string
 */
function akb_seo_content_sha256( $content ) {
	$content = str_replace( array( "\r\n", "\r" ), "\n", (string) $content );
	return hash( 'sha256', $content );
}

/**
 * Audit duplicate and long product excerpts/individual Rank Math metadata.
 *
 * This is report-only. Copy is not auto-truncated because that can cut a
 * sentence and silently propagate weak text into Product schema.
 *
 * @return array
 */
function akb_seo_content_editorial_quality_audit() {
	$product_ids = get_posts(
		array(
			'post_type'              => 'product',
			'post_status'            => 'publish',
			'fields'                 => 'ids',
			'posts_per_page'         => -1,
			'orderby'                => 'ID',
			'order'                  => 'ASC',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		)
	);

	$excerpt_groups = array();
	$meta_groups    = array();
	$long_excerpts = array();
	$long_meta     = array();
	$residual_editor_html_excerpts = array();

	foreach ( $product_ids as $product_id ) {
		$title       = get_the_title( $product_id );
		$raw_excerpt = (string) get_post_field( 'post_excerpt', $product_id, 'raw' );
		$excerpt     = akb_seo_content_normalize_text( $raw_excerpt );
		$raw_meta    = (string) get_post_meta( $product_id, 'rank_math_description', true );
		$meta        = akb_seo_content_normalize_text( $raw_meta );

		if ( '' !== $excerpt ) {
			$excerpt_key                    = function_exists( 'mb_strtolower' ) ? mb_strtolower( $excerpt, 'UTF-8' ) : strtolower( $excerpt );
			$excerpt_groups[ $excerpt_key ][] = array( 'id' => $product_id, 'title' => $title );
			$excerpt_length                 = function_exists( 'mb_strlen' ) ? mb_strlen( $excerpt, 'UTF-8' ) : strlen( $excerpt );
			if ( $excerpt_length > 180 ) {
				$long_excerpts[] = array( 'id' => $product_id, 'title' => $title, 'length' => $excerpt_length );
			}
			if ( false !== strpos( $raw_excerpt, 'font-claude-response-body' ) ) {
				$residual_editor_html_excerpts[] = array( 'id' => $product_id, 'title' => $title );
			}
		}

		if ( '' !== $meta ) {
			$meta_key                  = function_exists( 'mb_strtolower' ) ? mb_strtolower( $meta, 'UTF-8' ) : strtolower( $meta );
			$meta_groups[ $meta_key ][] = array( 'id' => $product_id, 'title' => $title );
			$meta_length               = function_exists( 'mb_strlen' ) ? mb_strlen( $meta, 'UTF-8' ) : strlen( $meta );
			if ( $meta_length > 180 ) {
				$long_meta[] = array( 'id' => $product_id, 'title' => $title, 'length' => $meta_length );
			}
		}
	}

	$duplicate_excerpts = array_values(
		array_map(
			static function ( $items ) {
				return array( 'count' => count( $items ), 'products' => $items );
			},
			array_filter(
				$excerpt_groups,
				static function ( $items ) {
					return count( $items ) > 1;
				}
			)
		)
	);
	$duplicate_meta = array_values(
		array_map(
			static function ( $items ) {
				return array( 'count' => count( $items ), 'products' => $items );
			},
			array_filter(
				$meta_groups,
				static function ( $items ) {
					return count( $items ) > 1;
				}
			)
		)
	);

	return array(
		'policy'                   => 'report-only; curate unique 120-155 character copy in WooCommerce/Rank Math UI',
		'published_products'       => count( $product_ids ),
		'duplicate_excerpt_groups' => $duplicate_excerpts,
		'long_excerpts_over_180'   => $long_excerpts,
		'residual_editor_html_excerpts' => $residual_editor_html_excerpts,
		'duplicate_meta_groups'    => $duplicate_meta,
		'long_meta_over_180'       => $long_meta,
	);
}

/**
 * Audit the effective pa_autor index/sitemap policy.
 *
 * @return array
 */
function akb_seo_content_author_policy_audit() {
	global $wpdb;

	$nonempty_terms = get_terms(
		array(
			'taxonomy'   => 'pa_autor',
			'hide_empty' => true,
			'fields'     => 'ids',
		)
	);
	$nonempty_terms = is_wp_error( $nonempty_terms ) ? array() : array_map( 'intval', $nonempty_terms );

	$exact_noindex_overrides = (int) $wpdb->get_var(
		$wpdb->prepare(
			"
			SELECT COUNT(*)
			FROM {$wpdb->termmeta} AS meta
			INNER JOIN {$wpdb->term_taxonomy} AS taxonomy
				ON taxonomy.term_id = meta.term_id
			WHERE taxonomy.taxonomy = %s
				AND taxonomy.count > 0
				AND meta.meta_key = %s
				AND meta.meta_value = %s
			",
			'pa_autor',
			'rank_math_robots',
			maybe_serialize( array( 'noindex' ) )
		)
	);

	$sitemap_links = array();
	if ( class_exists( '\RankMath\Sitemap\Providers\Taxonomy' ) ) {
		$provider      = new \RankMath\Sitemap\Providers\Taxonomy();
		$sitemap_links = $provider->get_sitemap_links( 'pa_autor', 200, 1 );
	}

	return array(
		'nonempty_terms'          => count( $nonempty_terms ),
		'exact_noindex_overrides' => $exact_noindex_overrides,
		'sitemap_entries'         => count( $sitemap_links ),
		'sitemap_covers_nonempty' => count( $sitemap_links ) === count( $nonempty_terms ),
	);
}

/**
 * Execute an already validated mutation plan inside one DB transaction.
 *
 * @param array $changes Validated changes.
 * @return array Applied changes.
 */
function akb_seo_content_execute_changes( $changes ) {
	global $wpdb;

	$wpdb->query( 'START TRANSACTION' );
	try {
		foreach ( $changes as &$change ) {
			$payload = $change['payload'];
			$result  = true;

			switch ( $change['operation'] ) {
				case 'replace_product_terms':
					$term_ids = akb_seo_content_resolve_term_ids( $payload['taxonomy'], $payload['to'] );
					$result   = is_wp_error( $term_ids )
						? $term_ids
						: wp_set_object_terms( $payload['product_id'], $term_ids, $payload['taxonomy'], false );
					break;
				case 'update_author_term':
					$result = wp_update_term(
						$payload['term_id'],
						'pa_autor',
						array(
							'name' => $payload['new_name'],
							'slug' => $payload['new_slug'],
						)
					);
					break;
				case 'update_attachment_alt':
					$result = update_post_meta(
						$payload['attachment_id'],
						'_wp_attachment_image_alt',
						$payload['to']
					);
					break;
				case 'correct_excerpt_volume':
				case 'normalize_excerpt_html':
					$post   = get_post( $payload['product_id'] );
					$result = wp_update_post(
						array(
							'ID'           => $payload['product_id'],
							'post_excerpt' => str_replace( $payload['from'], $payload['to'], $post->post_excerpt ),
						),
						true
					);
					break;
				case 'update_product_excerpt':
					$post = get_post( $payload['product_id'] );
					if (
						! $post
						|| $payload['from'] !== $post->post_excerpt
						|| $payload['content_sha256'] !== akb_seo_content_sha256( $post->post_content )
					) {
						throw new RuntimeException(
							sprintf( 'Product %d changed after excerpt preflight', $payload['product_id'] )
						);
					}
					$result = wp_update_post(
						array(
							'ID'           => $payload['product_id'],
							'post_excerpt' => $payload['to'],
						),
						true
					);
					break;
				case 'update_legal_copy':
					$post   = get_post( $payload['post_id'] );
					$result = wp_update_post(
						array(
							'ID'           => $payload['post_id'],
							'post_content' => str_replace( $payload['from'], $payload['to'], $post->post_content ),
						),
						true
					);
					break;
			}

			if ( is_wp_error( $result ) || false === $result ) {
				throw new RuntimeException(
					is_wp_error( $result ) ? $result->get_error_message() : 'Mutation returned false'
				);
			}
			$change['status'] = 'applied';
		}
		unset( $change );
		$wpdb->query( 'COMMIT' );
	} catch ( Throwable $error ) {
		$wpdb->query( 'ROLLBACK' );
		WP_CLI::error( 'SEO content transaction rolled back: ' . $error->getMessage() );
	}

	return $changes;
}

$requested_apply = akb_seo_content_setting_enabled( 'AKB_SEO_CONTENT_APPLY' );
$apply           = false; // Planning is always read-only; writes happen only after full preflight.
$environment = wp_get_environment_type();
if (
	$requested_apply
	&& 'production' === $environment
	&& ! akb_seo_content_setting_enabled( 'AKB_SEO_CONTENT_ALLOW_PRODUCTION' )
) {
	WP_CLI::error(
		'Production apply blocked. Validate the isolated Docker fixture first; production is the only remote target.'
	);
}

$manifest  = akb_seo_content_manifest();
$changes   = array();
$conflicts = array();

foreach ( $manifest['post_fingerprints'] as $post_id => $fingerprint ) {
	$post = get_post( $post_id );
	if (
		! $post
		|| $fingerprint['type'] !== $post->post_type
		|| $fingerprint['status'] !== $post->post_status
		|| $fingerprint['slug'] !== $post->post_name
	) {
		$conflicts[] = array(
			'type'        => 'post_fingerprint_mismatch',
			'post_id'     => $post_id,
			'expected'    => $fingerprint,
			'actual'      => $post
				? array( 'type' => $post->post_type, 'status' => $post->post_status, 'slug' => $post->post_name )
				: null,
		);
	}
}

foreach ( $manifest['product_terms'] as $assignment ) {
	$expected = akb_seo_content_sorted_strings( $assignment['expected_before'] );
	$desired  = akb_seo_content_sorted_strings( $assignment['desired'] );
	$term_ids = akb_seo_content_resolve_term_ids( $assignment['taxonomy'], $assignment['desired'] );

	if ( is_wp_error( $term_ids ) ) {
		$conflicts[] = array(
			'type'     => 'missing_manifest_term',
			'taxonomy' => $assignment['taxonomy'],
			'desired'  => $desired,
			'error'    => $term_ids->get_error_message(),
		);
		continue;
	}

	foreach ( $assignment['ids'] as $product_id ) {
		$product = get_post( $product_id );
		if ( ! $product || 'product' !== $product->post_type || 'publish' !== $product->post_status ) {
			$conflicts[] = array(
				'type'       => 'invalid_manifest_product',
				'product_id' => $product_id,
				'reason'     => $assignment['reason'],
			);
			continue;
		}

		$current = akb_seo_content_object_term_names( $product_id, $assignment['taxonomy'] );
		if ( is_wp_error( $current ) ) {
			$conflicts[] = array(
				'type'       => 'term_read_failure',
				'product_id' => $product_id,
				'taxonomy'   => $assignment['taxonomy'],
				'error'      => $current->get_error_message(),
			);
			continue;
		}
		if ( $current === $desired ) {
			continue;
		}
		if ( $current !== $expected ) {
			$conflicts[] = array(
				'type'            => 'unexpected_product_terms',
				'product_id'      => $product_id,
				'taxonomy'        => $assignment['taxonomy'],
				'expected_before' => $expected,
				'actual'          => $current,
				'desired'         => $desired,
			);
			continue;
		}

		if ( $apply ) {
			$result = wp_set_object_terms( $product_id, $term_ids, $assignment['taxonomy'], false );
			if ( is_wp_error( $result ) ) {
				WP_CLI::error( 'Product taxonomy mutation failed: ' . $result->get_error_message() );
			}
		}
		$changes = akb_seo_content_change(
			$changes,
			$apply,
			'replace_product_terms',
			array(
				'product_id' => $product_id,
				'taxonomy'   => $assignment['taxonomy'],
				'from'       => $current,
				'to'         => $desired,
				'reason'     => $assignment['reason'],
			)
		);
	}
}

foreach ( $manifest['author_terms'] as $correction ) {
	$term = get_term( $correction['term_id'], 'pa_autor' );
	if ( ! $term instanceof WP_Term ) {
		$conflicts[] = array(
			'type'    => 'missing_author_term',
			'term_id' => $correction['term_id'],
		);
		continue;
	}

	$is_desired = $correction['new_name'] === $term->name && $correction['new_slug'] === $term->slug;
	if ( $is_desired ) {
		continue;
	}
	if ( $correction['old_name'] !== $term->name || $correction['old_slug'] !== $term->slug ) {
		$conflicts[] = array(
			'type'          => 'unexpected_author_term',
			'term_id'       => $correction['term_id'],
			'expected_name' => $correction['old_name'],
			'expected_slug' => $correction['old_slug'],
			'actual_name'   => $term->name,
			'actual_slug'   => $term->slug,
		);
		continue;
	}

	if ( $apply ) {
		$result = wp_update_term(
			$correction['term_id'],
			'pa_autor',
			array(
				'name' => $correction['new_name'],
				'slug' => $correction['new_slug'],
			)
		);
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( 'Author term correction failed: ' . $result->get_error_message() );
		}
	}
	$changes = akb_seo_content_change(
		$changes,
		$apply,
		'update_author_term',
		$correction
	);
}

$rank_math_ui_term_overrides = array();
$nonempty_authors = get_terms(
	array(
		'taxonomy'   => 'pa_autor',
		'hide_empty' => true,
	)
);
if ( is_wp_error( $nonempty_authors ) ) {
	$conflicts[] = array(
		'type'  => 'author_policy_term_query_failed',
		'error' => $nonempty_authors->get_error_message(),
	);
} else {
	foreach ( $nonempty_authors as $author_term ) {
		$robot_values = get_term_meta( $author_term->term_id, 'rank_math_robots', false );
		if ( ! in_array( array( 'noindex' ), $robot_values, true ) ) {
			continue;
		}
		$rank_math_ui_term_overrides[] = array(
			'term_id'   => (int) $author_term->term_id,
			'term_name' => $author_term->name,
		);
	}
}

foreach ( $manifest['attachment_alt'] as $attachment_id => $desired_alt ) {
	$attachment = get_post( $attachment_id );
	if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
		$conflicts[] = array(
			'type'          => 'invalid_attachment',
			'attachment_id' => $attachment_id,
		);
		continue;
	}

	$current_alt = (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
	if ( $desired_alt === $current_alt ) {
		continue;
	}
	if ( '' !== $current_alt ) {
		$conflicts[] = array(
			'type'          => 'unexpected_attachment_alt',
			'attachment_id' => $attachment_id,
			'actual'        => $current_alt,
			'desired'       => $desired_alt,
		);
		continue;
	}

	if ( $apply ) {
		update_post_meta( $attachment_id, '_wp_attachment_image_alt', $desired_alt );
	}
	$changes = akb_seo_content_change(
		$changes,
		$apply,
		'update_attachment_alt',
		array(
			'attachment_id' => $attachment_id,
			'from'          => $current_alt,
			'to'            => $desired_alt,
		)
	);
}

$excerpt_replacements = $manifest['excerpt_text_replacements'];
foreach ( $excerpt_replacements as $excerpt_replacement ) {
	$product = get_post( $excerpt_replacement['post_id'] );
	if ( ! $product || 'product' !== $product->post_type || 'publish' !== $product->post_status ) {
		$conflicts[] = array(
			'type'       => 'invalid_excerpt_text_product',
			'product_id' => $excerpt_replacement['post_id'],
		);
		continue;
	}

	$old_count = substr_count( $product->post_excerpt, $excerpt_replacement['old'] );
	$new_count = substr_count( $product->post_excerpt, $excerpt_replacement['new'] );
	if ( 0 === $old_count && 1 === $new_count ) {
		continue;
	}
	if ( 1 !== $old_count || 0 !== $new_count ) {
		$conflicts[] = array(
			'type'       => 'unexpected_excerpt_text',
			'product_id' => $excerpt_replacement['post_id'],
			'old_count'  => $old_count,
			'new_count'  => $new_count,
		);
		continue;
	}

	if ( $apply ) {
		$result = wp_update_post(
			array(
				'ID'           => $excerpt_replacement['post_id'],
				'post_excerpt' => str_replace(
					$excerpt_replacement['old'],
					$excerpt_replacement['new'],
					$product->post_excerpt
				),
			),
			true
		);
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( 'Objective excerpt correction failed: ' . $result->get_error_message() );
		}
	}
	$changes = akb_seo_content_change(
		$changes,
		$apply,
		'correct_excerpt_volume',
		array(
			'product_id' => $excerpt_replacement['post_id'],
			'from'       => $excerpt_replacement['old'],
			'to'         => $excerpt_replacement['new'],
			'reason'     => $excerpt_replacement['reason'],
		)
	);
}

$excerpt_cleanup = $manifest['excerpt_html_cleanup'];
foreach ( $excerpt_cleanup['ids'] as $product_id ) {
	$product = get_post( $product_id );
	if ( ! $product || 'product' !== $product->post_type || 'publish' !== $product->post_status ) {
		$conflicts[] = array(
			'type'       => 'invalid_excerpt_cleanup_product',
			'product_id' => $product_id,
		);
		continue;
	}

	$old_count = substr_count( $product->post_excerpt, $excerpt_cleanup['old_open'] );
	if ( 0 === $old_count && false === strpos( $product->post_excerpt, 'font-claude-response-body' ) ) {
		continue;
	}
	if ( 1 !== $old_count ) {
		$conflicts[] = array(
			'type'             => 'unexpected_excerpt_editor_markup',
			'product_id'       => $product_id,
			'expected_matches' => 1,
			'actual_matches'   => $old_count,
		);
		continue;
	}

	if ( $apply ) {
		$result = wp_update_post(
			array(
				'ID'           => $product_id,
				'post_excerpt' => str_replace(
					$excerpt_cleanup['old_open'],
					$excerpt_cleanup['new_open'],
					$product->post_excerpt
				),
			),
			true
		);
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( 'Excerpt HTML cleanup failed: ' . $result->get_error_message() );
		}
	}
	$changes = akb_seo_content_change(
		$changes,
		$apply,
		'normalize_excerpt_html',
		array(
			'product_id' => $product_id,
			'from'       => $excerpt_cleanup['old_open'],
			'to'         => $excerpt_cleanup['new_open'],
			'reason'     => $excerpt_cleanup['reason'],
		)
	);
}

foreach ( $manifest['curated_product_excerpts'] as $curated_excerpt ) {
	$product = get_post( $curated_excerpt['post_id'] );
	if ( ! $product || 'product' !== $product->post_type || 'publish' !== $product->post_status ) {
		$conflicts[] = array(
			'type'       => 'invalid_curated_excerpt_product',
			'product_id' => $curated_excerpt['post_id'],
		);
		continue;
	}

	$desired_length = function_exists( 'mb_strlen' )
		? mb_strlen( $curated_excerpt['desired'], 'UTF-8' )
		: strlen( $curated_excerpt['desired'] );
	if (
		180 < $desired_length
		|| false === strpos( $curated_excerpt['desired'], 'despacho según cobertura' )
	) {
		$conflicts[] = array(
			'type'           => 'invalid_curated_excerpt_copy',
			'product_id'     => $curated_excerpt['post_id'],
			'desired_length' => $desired_length,
		);
		continue;
	}

	$content_sha256 = akb_seo_content_sha256( $product->post_content );
	if ( $curated_excerpt['content_sha256'] !== $content_sha256 ) {
		$conflicts[] = array(
			'type'            => 'curated_excerpt_source_changed',
			'product_id'      => $curated_excerpt['post_id'],
			'expected_sha256' => $curated_excerpt['content_sha256'],
			'actual_sha256'   => $content_sha256,
		);
		continue;
	}

	if ( $curated_excerpt['desired'] === $product->post_excerpt ) {
		continue;
	}
	if ( $curated_excerpt['expected_before'] !== $product->post_excerpt ) {
		$conflicts[] = array(
			'type'            => 'unexpected_curated_excerpt',
			'product_id'      => $curated_excerpt['post_id'],
			'expected_before' => $curated_excerpt['expected_before'],
			'actual'          => $product->post_excerpt,
			'desired'         => $curated_excerpt['desired'],
		);
		continue;
	}

	$changes = akb_seo_content_change(
		$changes,
		$apply,
		'update_product_excerpt',
		array(
			'product_id'     => $curated_excerpt['post_id'],
			'from'           => $curated_excerpt['expected_before'],
			'to'             => $curated_excerpt['desired'],
			'content_sha256' => $curated_excerpt['content_sha256'],
			'reason'         => $curated_excerpt['reason'],
		)
	);
}

foreach ( $manifest['legal_copy'] as $legal_change ) {
	$post = get_post( $legal_change['post_id'] );
	if ( ! $post || 'page' !== $post->post_type || 'publish' !== $post->post_status ) {
		$conflicts[] = array(
			'type'    => 'invalid_legal_page',
			'post_id' => $legal_change['post_id'],
		);
		continue;
	}

	$old_count = substr_count( $post->post_content, $legal_change['old'] );
	$new_count = substr_count( $post->post_content, $legal_change['new'] );
	if ( 0 === $old_count && 1 === $new_count ) {
		continue;
	}
	if ( 1 !== $old_count || 0 !== $new_count ) {
		$conflicts[] = array(
			'type'      => 'unexpected_legal_copy',
			'post_id'   => $legal_change['post_id'],
			'old_count' => $old_count,
			'new_count' => $new_count,
			'reason'    => $legal_change['reason'],
		);
		continue;
	}

	if ( $apply ) {
		$result = wp_update_post(
			array(
				'ID'           => $legal_change['post_id'],
				'post_content' => str_replace( $legal_change['old'], $legal_change['new'], $post->post_content ),
			),
			true
		);
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( 'Legal copy mutation failed: ' . $result->get_error_message() );
		}
	}
	$changes = akb_seo_content_change(
		$changes,
		$apply,
		'update_legal_copy',
		array(
			'post_id' => $legal_change['post_id'],
			'from'    => $legal_change['old'],
			'to'      => $legal_change['new'],
			'reason'  => $legal_change['reason'],
		)
	);
}

$rank_math_options    = get_option( $manifest['rank_math_ui']['option_name'], array() );
$rank_math_mismatches = array();
foreach ( $manifest['rank_math_ui']['expected'] as $key => $expected_value ) {
	$actual_value = is_array( $rank_math_options ) && array_key_exists( $key, $rank_math_options )
		? $rank_math_options[ $key ]
		: null;
	if ( $expected_value !== $actual_value ) {
		$rank_math_mismatches[] = array(
			'key'      => $key,
			'expected' => $expected_value,
			'actual'   => $actual_value,
		);
	}
}
$rank_math_sitemap_options = get_option( $manifest['rank_math_ui']['sitemap_option_name'], array() );
foreach ( $manifest['rank_math_ui']['sitemap_expected'] as $key => $expected_value ) {
	$actual_value = is_array( $rank_math_sitemap_options ) && array_key_exists( $key, $rank_math_sitemap_options )
		? $rank_math_sitemap_options[ $key ]
		: null;
	if ( $expected_value !== $actual_value ) {
		$rank_math_mismatches[] = array(
			'key'      => $key,
			'expected' => $expected_value,
			'actual'   => $actual_value,
		);
	}
}
if ( ! empty( $rank_math_ui_term_overrides ) ) {
	$rank_math_mismatches[] = array(
		'key'      => 'nonempty_pa_autor_term_noindex_overrides',
		'expected' => 0,
		'actual'   => count( $rank_math_ui_term_overrides ),
		'terms'    => $rank_math_ui_term_overrides,
		'action'   => 'Remove each override exclusively through the Rank Math term UI.',
	);
}

$pre_author_policy = akb_seo_content_author_policy_audit();

if ( $requested_apply && ( ! empty( $conflicts ) || ! empty( $rank_math_mismatches ) ) ) {
	WP_CLI::line(
		wp_json_encode(
			array(
				'mode'                 => 'preflight-failed',
				'conflicts'            => $conflicts,
				'rank_math_mismatches' => $rank_math_mismatches,
				'planned_mutations'    => count( $changes ),
			),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		)
	);
	WP_CLI::error( 'SEO content preflight failed before the first write.' );
}
if ( $requested_apply ) {
	$changes = akb_seo_content_execute_changes( $changes );
}

$editorial_quality = akb_seo_content_editorial_quality_audit();
$author_policy     = akb_seo_content_author_policy_audit();
$output            = array(
	'generated_at' => gmdate( 'c' ),
	'mode'         => $requested_apply ? 'apply' : 'dry-run',
	'environment'  => $environment,
	'summary'      => array(
		'mutations'                   => count( $changes ),
		'conflicts'                   => count( $conflicts ),
		'rank_math_ui_mismatches'     => count( $rank_math_mismatches ),
		'duplicate_excerpt_groups'    => count( $editorial_quality['duplicate_excerpt_groups'] ),
		'long_excerpts_over_180'      => count( $editorial_quality['long_excerpts_over_180'] ),
		'residual_editor_html_excerpts' => count( $editorial_quality['residual_editor_html_excerpts'] ),
		'duplicate_meta_groups'       => count( $editorial_quality['duplicate_meta_groups'] ),
		'long_meta_over_180'          => count( $editorial_quality['long_meta_over_180'] ),
		'nonempty_author_terms'        => $author_policy['nonempty_terms'],
		'nonempty_author_noindex_overrides' => $author_policy['exact_noindex_overrides'],
		'author_sitemap_entries'       => $author_policy['sitemap_entries'],
	),
	'changes'      => $changes,
	'conflicts'    => $conflicts,
	'rank_math_ui' => array(
		'policy'     => $manifest['rank_math_ui']['policy'],
		'expected'   => $manifest['rank_math_ui']['expected'],
		'sitemap_expected' => $manifest['rank_math_ui']['sitemap_expected'],
		'mismatches' => $rank_math_mismatches,
	),
	'editorial_quality' => $editorial_quality,
	'author_policy' => $author_policy,
);

WP_CLI::line( wp_json_encode( $output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );

if (
	akb_seo_content_setting_enabled( 'AKB_SEO_CONTENT_STRICT' )
	&& (
		! empty( $conflicts )
		|| ! empty( $rank_math_mismatches )
		|| 0 !== $author_policy['exact_noindex_overrides']
		|| ! $author_policy['sitemap_covers_nonempty']
	)
) {
	WP_CLI::error( 'SEO content/config manifest invariants failed.' );
}
