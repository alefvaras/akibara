<?php
/**
 * Funciones de WordPress/WooCommerce que el runtime llama y que ningún test
 * "posee".
 *
 * Sin ellas el suite sólo pasaba cuando el archivo que las declaraba con Brain
 * Monkey corría PRIMERO: 45 errores `MissingFunctionExpectations` con
 * `--order-by=random`. Los valores son los canónicos que ya usaba el propio
 * suite.
 *
 * Vive en un archivo APARTE y se incluye después de Patchwork a propósito:
 * Patchwork sólo instrumenta lo que se incluye después de cargarse, y
 * `bootstrap.php` ya estaba en ejecución. Declarar esto allí las volvía
 * irredefinibles (`DefinedTooEarly`) y rompía a todos los tests que sí las
 * mockean.
 *
 * @package Akibara\Search\Tests
 */

declare( strict_types = 1 );

if ( ! function_exists( 'is_serialized' ) ) {
	function is_serialized( $data, bool $strict = true ): bool {
		unset( $strict );

		return is_string( $data ) && 1 === preg_match( '/^([adObis]:|N;)/', trim( $data ) );
	}
}

if ( ! function_exists( 'maybe_serialize' ) ) {
	/**
	 * Copia fiel de `maybe_serialize()` de WordPress.
	 *
	 * El doble anterior hacía `(string) $value` con los escalares, y eso es
	 * EXACTAMENTE lo que el original NO hace: devuelve el int, el bool o el null
	 * tal cual. Esa diferencia dejó sin cobertura un fatal que llevaba versiones
	 * vivo en producción — `EditorialDiscountPublication::value_hash()` recibía
	 * un int y, con `strict_types=1`, `hash()` lanzaba un `TypeError` que tumbaba
	 * la petición al guardar cualquier descuento por taxonomía. La suite entera
	 * pasaba en verde porque el doble convertía el int en cadena antes de llegar.
	 *
	 * Un doble que no modela producción no prueba producción.
	 *
	 * @param mixed $value
	 * @return mixed
	 */
	function maybe_serialize( $value ) {
		if ( is_array( $value ) || is_object( $value ) ) {
			return serialize( $value );
		}

		// WordPress vuelve a serializar lo que ya parece serializado para que al
		// leerlo no se deserialice dos veces.
		if ( is_serialized( $value, false ) ) {
			return serialize( $value );
		}

		return $value;
	}
}

if ( ! function_exists( 'wp_using_ext_object_cache' ) ) {
	/**
	 * Sin caché de objetos externa: el estado de este entorno de pruebas y el
	 * default de una instalación de WordPress sin drop-in.
	 *
	 * `EditorialDiscountPublication::throttle_uses_object_cache()` la protege con
	 * `function_exists()`, y ese guard MENTÍA en la suite: bastaba con que
	 * `ReconcileThrottleStoreTest` o `Support\RateLimitTest` corrieran antes para
	 * que Brain Monkey dejara el símbolo DEFINIDO —pero sin comportamiento—, así
	 * que `PublicationProtocolTest` pasaba el guard y moría con
	 * `MissingFunctionExpectations`. Verde en orden natural, rojo con
	 * `--order-by=random` (2 de cada 3 corridas, medido). Con el símbolo siempre
	 * declarado el guard es determinista, y quien necesite `true` lo sigue
	 * declarando con Brain Monkey.
	 */
	function wp_using_ext_object_cache(): bool {
		return false;
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	/**
	 * Core siempre lo expone. Lo llama el saneado de los mensajes de «sin
	 * resultados», que corre tanto al pintar la pantalla como al guardarla.
	 *
	 * @param string $text     Texto de entrada.
	 * @param bool   $remove_breaks Colapsar saltos de línea.
	 */
	function wp_strip_all_tags( string $text, bool $remove_breaks = false ): string {
		$text = (string) preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', $text );
		$text = strip_tags( $text );

		return $remove_breaks ? trim( (string) preg_replace( '/[\r\n\t ]+/', ' ', $text ) ) : trim( $text );
	}
}

if ( ! function_exists( 'taxonomy_exists' ) ) {
	function taxonomy_exists( string $taxonomy ): bool {
		unset( $taxonomy );

		return false;
	}
}

if ( ! function_exists( 'get_term_by' ) ) {
	function get_term_by( string $field, $value, string $taxonomy = '' ) {
		if ( 'slug' === $field && 'pa_pais' === $taxonomy ) {
			if ( 'argentina' === $value ) {
				return new WP_Term( 'pa_pais', 98, 0, 'argentina', 'Argentina' );
			}
			if ( 'espana' === $value ) {
				return new WP_Term( 'pa_pais', 154, 0, 'espana', 'España' );
			}
		}

		return false;
	}
}

if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
	function as_get_scheduled_actions( array $args = array(), string $return_format = 'OBJECT' ): array {
		unset( $args, $return_format );

		return array();
	}
}

if ( ! function_exists( 'as_unschedule_all_actions' ) ) {
	/*
	 * `EditorialDiscountPublication::schedule_boundary()` la llama detrás de
	 * `function_exists()` para retirar los boundaries pendientes antes de
	 * programar el vigente. Stub REAL y no mock por test, por la misma razón que
	 * `as_get_scheduled_actions`: su mera presencia cambia el camino que recorren
	 * los demás tests. Anota cada llamada para que `BoundaryDedupTest` pueda
	 * mirar el orden respecto de `as_schedule_single_action`.
	 */
	function as_unschedule_all_actions( string $hook, array $args = array(), string $group = '' ): void {
		$GLOBALS['akb_search_test_unscheduled'][] = array( $hook, $args, $group );
	}
}

if ( ! function_exists( 'wp_generate_uuid4' ) ) {
	function wp_generate_uuid4(): string {
		return '00000000-0000-4000-8000-000000000001';
	}
}

/*
 * `AuditLog::record()` la llama detrás de `function_exists()`, así que su mera
 * PRESENCIA cambia el resultado de otros tests: en cuanto un archivo la
 * declaraba con Brain Monkey, todos los que corrían DESPUÉS y tocaban el
 * AuditLog morían con `MissingFunctionExpectations`. Antes eso quedaba tapado
 * porque el único archivo que la mockeaba (ReproofRegressionTest) ordena al
 * final; con un test nuevo que ordena antes, salieron 3 errores. Igual que con
 * `maybe_unserialize`: una sola declaración real para todo el suite.
 */
if ( ! function_exists( 'wp_get_current_user' ) ) {
	function wp_get_current_user() {
		return null;
	}
}

/*
 * WC_Product canónico del suite — MISMA trampa que WP_Term, cobrada de verdad.
 *
 * Tres archivos lo declaraban tras `class_exists` y uno de ellos
 * (ThumbnailSizeParityTest) sólo traía `get_id()` y `get_image_id()`. Cuando el
 * orden aleatorio hacía ganar a ESA versión, DocumentBuilder moría en
 * `get_name()` y 7 tests de IndexerProtocolV2Test daban `failed` en vez de
 * `indexed`: un flake de ~1 de cada 20 corridas con `--order-by=random`,
 * anterior a este cambio y reproducido con la semilla 1788330884. Una sola
 * declaración, cargada por el bootstrap antes que cualquier test, lo cierra.
 *
 * Los valores son los de la versión rica que ya compartían IndexerProtocolV2Test
 * y RuntimeReadersFailClosedTest (idénticas salvo `unset()` decorativos).
 */
if ( ! class_exists( 'WC_Product' ) ) {
	class WC_Product {

		private int $id;

		public function __construct( int $id ) {
			$this->id = $id;
		}

		public function get_id(): int {
			return $this->id;
		}

		/**
		 * Un producto simple no tiene padre. Lo pide DiscountResolver al
		 * resolver el descuento por taxonomía, y sin él la integración
		 * search↔business moría con «undefined method» disfrazado de fallo de
		 * autoridad de precios [gate cruzado, 2026-09-05].
		 */
		public function get_parent_id(): int {
			return 0;
		}

		public function get_name(): string {
			return 'Manga 01';
		}

		public function get_stock_status(): string {
			return 'instock';
		}

		public function get_price() {
			return '9990';
		}

		public function get_regular_price( string $context = 'view' ) {
			unset( $context );

			return '9990';
		}

		/**
		 * Sin oferta: es lo que devuelve WooCommerce para un producto a precio
		 * de lista, y lo que el resolvedor de descuento por taxonomía consulta
		 * antes de aplicar el suyo.
		 *
		 * @param string $context Contexto de lectura, como en WooCommerce.
		 * @return string
		 */
		public function get_sale_price( string $context = 'view' ) {
			unset( $context );

			return '';
		}

		public function get_sku(): string {
			return '';
		}

		/**
		 * Producto a precio de lista, en línea con get_sale_price().
		 *
		 * @return bool
		 */
		public function is_on_sale(): bool {
			return false;
		}

		public function get_menu_order(): int {
			return 0;
		}

		public function is_visible(): bool {
			return true;
		}

		public function get_status(): string {
			return 'publish';
		}

		public function get_price_html(): string {
			return '$9.990';
		}

		public function is_type( string $type ): bool {
			unset( $type );

			return false;
		}

		public function get_short_description(): string {
			return '';
		}

		public function get_description(): string {
			return '';
		}

		public function get_image_id() {
			return 0;
		}

		public function get_date_created() {
			return null;
		}
	}
}

/*
 * WP_REST_Response y WP_Post canónicos — la MISMA trampa que WP_Term y
 * WC_Product, y la que de verdad estaba tumbando el suite en aleatorio.
 *
 * `Support/RestCacheEdgePolicyTest` declaraba un WP_REST_Response SIN
 * `set_status()` ni `set_data()`; cuando el orden lo hacía ganar, siete tests de
 * RuntimeReadersFailClosedTest morían con «Call to undefined method» y el
 * arrastre dejaba a IndexerProtocolV2Test devolviendo `failed` en vez de
 * `indexed`. Ídem WP_Post: una versión tipaba `post_type`/`post_status` como
 * string y la otra los dejaba sin tipo, así que un test que inyecta un valor no
 * string sólo pasaba si ganaba la permisiva.
 *
 * Formas canónicas = la UNIÓN de lo que usaban los tests: `$headers` público
 * (lo lee RestCacheEdgePolicyTest) más los setters, y las propiedades de post
 * sin tipo (lo permisivo es lo que cubre a los dos).
 */
if ( ! class_exists( 'WP_REST_Response' ) ) {
	class WP_REST_Response {
		/** @var array<string, string> */
		public array $headers = array();

		/** @var mixed */
		private $data;

		private int $status;

		/** @param mixed $data Payload. */
		public function __construct( $data = null, int $status = 200 ) {
			$this->data   = $data;
			$this->status = $status;
		}

		public function set_status( int $status ): void {
			$this->status = $status;
		}

		public function get_status(): int {
			return $this->status;
		}

		/** @param mixed $data Payload. */
		public function set_data( $data ): void {
			$this->data = $data;
		}

		/** @return mixed */
		public function get_data() {
			return $this->data;
		}

		public function header( string $key, string $value, bool $replace = true ): void {
			unset( $replace );
			$this->headers[ $key ] = $value;
		}

		/** @return array<string, string> */
		public function get_headers(): array {
			return $this->headers;
		}
	}
}

if ( ! class_exists( 'WP_Post' ) ) {
	class WP_Post {
		public int $ID;
		/** @var mixed Sin tipo a propósito: hay tests que inyectan no-strings. */
		public $post_type;
		public int $post_parent;
		/** @var mixed Sin tipo a propósito: ídem. */
		public $post_status;
		public string $post_title;
		public string $post_excerpt;
		public string $post_content;
		public int $menu_order;
		public string $post_name;
		public string $post_date;
		public string $post_date_gmt;
		public string $post_modified;
		public string $post_modified_gmt;
		public string $post_password;
		public string $comment_status;

		/**
		 * @param mixed $post_status Estado (sin tipo: los tests de fail-closed inyectan basura).
		 */
		public function __construct( string $post_type, int $post_parent = 0, $post_status = 'publish', int $id = 0, string $post_title = 'Manga 01', string $post_excerpt = '', string $post_content = '', int $menu_order = 0, string $post_name = 'manga-01', string $post_date = '2026-01-01 00:00:00', string $post_date_gmt = '2026-01-01 00:00:00', string $post_modified = '2026-01-01 00:00:00', string $post_modified_gmt = '2026-01-01 00:00:00', string $post_password = '', string $comment_status = 'open' ) {
			$this->ID                = $id;
			$this->post_type         = $post_type;
			$this->post_parent       = $post_parent;
			$this->post_status       = $post_status;
			$this->post_title        = $post_title;
			$this->post_excerpt      = $post_excerpt;
			$this->post_content      = $post_content;
			$this->menu_order        = $menu_order;
			$this->post_name         = $post_name;
			$this->post_date         = $post_date;
			$this->post_date_gmt     = $post_date_gmt;
			$this->post_modified     = $post_modified;
			$this->post_modified_gmt = $post_modified_gmt;
			$this->post_password     = $post_password;
			$this->comment_status    = $comment_status;
		}
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {}
}

/*
 * WP_Term canónico del suite. Tres archivos de test lo declaraban por su cuenta
 * detrás de `class_exists`, así que la definición ganadora dependía del ORDEN de
 * ejecución: con `--order-by=random`, un test que necesitaba un campo que la
 * versión ganadora no tenía moría con TypeError. Declararlo acá (el bootstrap
 * carga este archivo antes que cualquier test) deja una sola forma para todos;
 * los `if ( ! class_exists( 'WP_Term' ) )` de los tests quedan en no-op.
 */
if ( ! class_exists( 'WP_Term' ) ) {
	class WP_Term {
		public string $taxonomy;
		public int $term_id;
		public int $parent;
		public string $slug;
		public string $name;
		public int $object_id;
		/** Productos PUBLICADOS del término: de acá sale la elegibilidad de una serie. */
		public int $count;

		public function __construct(
			string $taxonomy,
			int $term_id = 1,
			int $parent = 0,
			string $slug = 'manga',
			string $name = 'Manga',
			int $object_id = 0,
			int $count = 0
		) {
			$this->taxonomy  = $taxonomy;
			$this->term_id   = $term_id;
			$this->parent    = $parent;
			$this->slug      = $slug;
			$this->name      = $name;
			$this->object_id = $object_id;
			$this->count     = $count;
		}
	}
}

if ( ! function_exists( 'maybe_unserialize' ) ) {
	/**
	 * Copia fiel de Core: deserializa sólo lo que está serializado.
	 * El reproof del receipt editorial lee el raw exacto de MySQL por aquí.
	 *
	 * @param mixed $data Valor.
	 * @return mixed
	 */
	function maybe_unserialize( $data ) {
		if ( is_serialized( $data ) ) {
			return @unserialize( trim( (string) $data ) );
		}
		return $data;
	}
}

if ( ! function_exists( 'wp_unslash' ) ) {
	/**
	 * Core lo expone SIEMPRE en runtime.
	 *
	 * Vive aquí desde que el gate de página deslizó de verdad `REQUEST_METHOD` y
	 * `QUERY_STRING` en vez de silenciar el aviso del análisis estático: sin el
	 * stub, seis pruebas del gate caían porque la función no existía y todo el
	 * método salía por la rama de «no cacheable».
	 *
	 * @param mixed $value Valor deslizado por WordPress.
	 * @return mixed
	 */
	function wp_unslash( $value ) {
		return is_string( $value ) ? stripslashes( $value ) : $value;
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	/**
	 * Saneado de una línea con la semántica que usa el proyecto.
	 *
	 * @param mixed $value Valor crudo.
	 */
	function sanitize_text_field( $value ): string {
		return trim( (string) preg_replace( '/[\r\n\t]+/', ' ', strip_tags( (string) $value ) ) );
	}
}

if ( ! function_exists( 'wp_prime_option_caches' ) ) {
	/**
	 * Función de WP 6.4+ para precargar options en memoria.
	 *
	 * @param string[] $options Nombres de options a precargar.
	 */
	function wp_prime_option_caches( array $options ): void {
		unset( $options );
	}
}

if ( ! function_exists( '_prime_post_caches' ) ) {
	/**
	 * Stub compartido de core para precargar posts, términos y metadatos en lote.
	 *
	 * @param array<int,int> $ids               IDs de posts a precargar.
	 * @param bool           $update_term_cache Precargar términos.
	 * @param bool           $update_meta_cache Precargar postmeta.
	 */
	function _prime_post_caches( array $ids, bool $update_term_cache = true, bool $update_meta_cache = true ): void {
		unset( $ids, $update_term_cache, $update_meta_cache );
	}
}
