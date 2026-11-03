<?php
/**
 * Stubs de WordPress para las pruebas PHP del theme.
 *
 * Solo lo que el código bajo prueba toca de verdad. Las condicionales de ruta
 * (`is_front_page`, `is_page`, `is_404`…) se mockean por test con Brain Monkey;
 * acá viven únicamente las constantes y helpers que deben existir al momento de
 * cargar los módulos de `inc/`.
 *
 * @package akibara-gp\Tests
 */

declare( strict_types = 1 );

defined( 'MINUTE_IN_SECONDS' ) || define( 'MINUTE_IN_SECONDS', 60 );
defined( 'HOUR_IN_SECONDS' ) || define( 'HOUR_IN_SECONDS', 3600 );
defined( 'DAY_IN_SECONDS' ) || define( 'DAY_IN_SECONDS', 86400 );

if ( ! function_exists( 'esc_attr' ) ) {
	/**
	 * @param string $text Texto.
	 * @return string
	 */
	function esc_attr( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_js' ) ) {
	/**
	 * @param string $text Texto a interpolar dentro de un literal JS.
	 * @return string
	 */
	function esc_js( $text ) {
		return addslashes( (string) $text );
	}
}

if ( ! function_exists( 'esc_url' ) ) {
	/**
	 * @param string $url URL.
	 * @return string
	 */
	function esc_url( $url ) {
		return (string) $url;
	}
}

if ( ! class_exists( 'WP_Query' ) ) {
	/**
	 * `WP_Query` sólo se necesita como TIPO: el cebado de portadas comprueba
	 * `instanceof` y mira `->posts` antes de delegar en `update_post_thumbnail_cache()`,
	 * que es de core y no se reimplementa aquí.
	 */
	class WP_Query {

		/** @var array<int, mixed> */
		public $posts = array();
	}
}

if ( ! class_exists( 'WP_Query_Double' ) ) {
	/**
	 * Instancia de `WP_Query` con posts, para las pruebas del cebado.
	 */
	class WP_Query_Double extends WP_Query {

		/**
		 * @param array<int, mixed> $posts Resultados de la consulta.
		 */
		public function __construct( array $posts ) {
			$this->posts = $posts;
		}
	}
}

if ( ! class_exists( 'WP_Term' ) ) {
	/**
	 * Doble mínimo de `WP_Term`.
	 *
	 * El código del tema sólo lee propiedades públicas (`term_id`,
	 * `term_taxonomy_id`, `slug`, `taxonomy`, `name`, `count`, `description`) y
	 * comprueba `instanceof`. Se declaran esas y nada más: un doble que ofreciera
	 * métodos que el original no tiene invitaría a apoyarse en ellos.
	 */
	class WP_Term {

		/** @var int */
		public $term_id = 0;

		/** @var int */
		public $term_taxonomy_id = 0;

		/** @var string */
		public $slug = '';

		/** @var string */
		public $taxonomy = '';

		/** @var string */
		public $name = '';

		/** @var int */
		public $count = 0;

		/** @var string */
		public $description = '';

		/**
		 * @param array<string, mixed> $props Propiedades a fijar.
		 */
		public function __construct( array $props = array() ) {
			foreach ( $props as $key => $value ) {
				if ( property_exists( $this, $key ) ) {
					$this->$key = $value;
				}
			}
		}
	}
}
