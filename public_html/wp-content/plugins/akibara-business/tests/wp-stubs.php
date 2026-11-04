<?php
/**
 * Stubs mínimos de WordPress/WooCommerce compartidos por TODOS los bootstraps
 * de prueba.
 *
 * FUENTE ÚNICA a propósito. Antes vivían duplicados en
 * `tests/bootstrap.php` y en
 * `tools/akibara-releases/phpunit-business-blocks-candidate-bootstrap.php`;
 * al agregar `WC_Order` solo al primero, 14 tests pasaban en local y fallaban
 * en el gate del candidato empaquetado (auditoría 2026-07-28). Un solo archivo
 * elimina esa clase de desincronización.
 *
 * Cada stub está bajo `class_exists` para no chocar con WooCommerce real ni
 * con suites que declaren el suyo.
 *
 * @package Akibara\Business\Tests
 */

declare( strict_types = 1 );

if ( ! function_exists( '_prime_post_caches' ) ) {
	/**
	 * Core siempre expone este helper en runtime. El no-op compartido evita que
	 * `function_exists()` cambie de false a true a mitad de PHPUnit sólo porque
	 * un test lo mockeó con Patchwork; Brain Monkey puede reemplazarlo cuando un
	 * contrato necesita inspeccionar sus argumentos.
	 *
	 * @param array<int,int> $ids IDs a primar.
	 * @param bool           $update_term_cache Primar términos.
	 * @param bool           $update_meta_cache Primar metadatos.
	 */
	function _prime_post_caches( $ids, $update_term_cache = true, $update_meta_cache = true ): void {
		unset( $ids, $update_term_cache, $update_meta_cache );
	}
}

if ( ! function_exists( 'update_object_term_cache' ) ) {
	/**
	 * Stub compartido de core para priming de términos en lote.
	 *
	 * @param array<int,int>|int   $object_ids  IDs a primar.
	 * @param array<string>|string $object_type Tipo de objeto.
	 * @return void
	 */
	function update_object_term_cache( $object_ids, $object_type = 'post' ): void {
		unset( $object_ids, $object_type );
	}
}

if ( ! function_exists( 'update_termmeta_cache' ) ) {
	/**
	 * Stub compartido de core para priming de termmeta en lote.
	 *
	 * @param array<int,int> $term_ids IDs de términos a primar.
	 * @return array<int,mixed>
	 */
	function update_termmeta_cache( array $term_ids ): array {
		unset( $term_ids );
		return array();
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	/**
	 * Core lo expone SIEMPRE en runtime, con esta semántica: se lleva el CUERPO
	 * de `script`/`style`, no sólo sus etiquetas.
	 *
	 * Lo llama el saneado compartido de los copys de una línea, que corre tanto
	 * al pintar el panel como al guardarlo y al leer la option. Sin el stub
	 * compartido, sólo pasaba el test al que otro le hubiera definido antes el
	 * símbolo — verde por contagio de orden, rojo en aislamiento.
	 *
	 * @param string $text          Texto de entrada.
	 * @param bool   $remove_breaks Colapsar saltos de línea.
	 */
	function wp_strip_all_tags( $text, $remove_breaks = false ): string {
		$text = (string) preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $text );
		$text = strip_tags( $text );

		return $remove_breaks ? trim( (string) preg_replace( '/[\r\n\t ]+/', ' ', $text ) ) : trim( $text );
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) {
		$raw_key = (string) $key;
		$key     = strtolower( $raw_key );
		return preg_replace( '/[^a-z0-9_\-]/', '', $key );
	}
}

if ( ! function_exists( 'absint' ) ) {
	function absint( $maybeint ) {
		return abs( (int) $maybeint );
	}
}

if ( ! function_exists( 'get_option' ) ) {
	/**
	 * Options de prueba, con el MISMO contrato que ya usaba
	 * `fixtures/wordpress-functions.php`: lo que haya en
	 * `$GLOBALS['akibara_test_options']`, y el default cuando no hay fila.
	 *
	 * Vivía sólo en ese fixture, que cargan cinco tests. Cualquier otro que
	 * tocara una option —por ejemplo el tope de descuento que ahora leen los
	 * validadores de tramos— dependía de que ALGÚN test hubiera definido el
	 * símbolo antes: verde en orden natural, fatal en aislamiento. Al declararlo
	 * acá hay una sola forma para todos y el `function_exists` del fixture queda
	 * en no-op, igual que se hizo con `WP_Post` y `wp_json_encode`.
	 *
	 * Brain Monkey puede reemplazar este cuerpo: el archivo está en la whitelist
	 * de Patchwork.
	 *
	 * @param string $option  Nombre de la option.
	 * @param mixed  $default Valor a devolver cuando no hay fila.
	 * @return mixed
	 */
	function get_option( string $option, $default = false ) {
		$options = $GLOBALS['akibara_test_options'] ?? array();

		return is_array( $options ) && array_key_exists( $option, $options )
			? $options[ $option ]
			: $default;
	}
}

if ( ! function_exists( 'wp_unslash' ) ) {
	/**
	 * Core lo expone SIEMPRE. Vive aquí por lo mismo que `admin_url`: es de uso
	 * general y depender de que otro test lo stubee antes hace que el resultado
	 * cambie con el orden [[function-exists-miente-en-la-suite]].
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
	 * Saneado de una línea, con la misma semántica que usa el proyecto: fuera
	 * etiquetas y espacios de los extremos.
	 *
	 * @param mixed $value Valor crudo.
	 */
	function sanitize_text_field( $value ): string {
		return trim( (string) preg_replace( '/[\r\n\t]+/', ' ', strip_tags( (string) $value ) ) );
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	/**
	 * Core lo expone SIEMPRE. Vive aquí por lo mismo que `wp_unslash`: es de uso
	 * general y depender de que otro test lo stubee antes hace que el resultado
	 * cambie con el orden [[function-exists-miente-en-la-suite]].
	 *
	 * @param mixed $text Texto crudo.
	 */
	function esc_html( $text ): string {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_attr' ) ) {
	/**
	 * Gemela de `esc_html` en core: misma conversión, distinto punto de uso.
	 *
	 * @param mixed $text Texto crudo.
	 */
	function esc_attr( $text ): string {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'trailingslashit' ) ) {
	/**
	 * Core lo expone SIEMPRE en runtime.
	 *
	 * @param string $value Ruta o URL.
	 */
	function trailingslashit( $value ): string {
		return rtrim( (string) $value, '/\\' ) . '/';
	}
}

if ( ! function_exists( 'admin_url' ) ) {
	/**
	 * Ruta de administración del sitio.
	 *
	 * Vive aquí y no en cada test por lo de siempre: `NavigationLinks` la usa
	 * para saber qué prefijo NO es un destino de comprador, y `function_exists()`
	 * cambiaba de false a true a mitad de la suite según qué test la hubiera
	 * stubeado antes — verde en orden natural, fatal en aislamiento
	 * [[function-exists-miente-en-la-suite]]. Una sola forma para todos.
	 *
	 * @param string $path Ruta relativa dentro de wp-admin.
	 */
	function admin_url( string $path = '' ): string {
		return 'https://akibara.cl/wp-admin/' . ltrim( $path, '/' );
	}
}

if ( ! class_exists( 'WP' ) ) {
	/** Stub mínimo del request parser para pruebas de query vars. */
	class WP {
		/** @var array<string,mixed> */
		public array $query_vars = array();
	}
}

if ( ! class_exists( 'WP_Post' ) ) {
	/**
	 * Stub canónico del post de WordPress.
	 *
	 * Vivía DUPLICADO en `MenuInjectorTest` y `BrandLandingsTest`, cada uno con
	 * un comentario pidiéndole al otro que se mantuviera sincronizado: un solo
	 * proceso comparte la clase, así que ganaba el que corriera primero y el
	 * otro moría con «Undefined property» según el orden. Al declararlo acá —el
	 * bootstrap carga este archivo antes que cualquier test— hay una sola forma
	 * para todos y los `class_exists` de esos ficheros quedan en no-op. Mismo
	 * criterio que ya se aplicó a `wp_json_encode` y `WP_Term`.
	 *
	 * Declara la UNIÓN de las propiedades que usan los consumidores: PHP 8.2
	 * emite `Deprecated: Creation of dynamic property` para cualquiera que
	 * falte, y con `failOnWarning` eso es una suite roja por un stub incompleto.
	 */
	class WP_Post {
		public int $ID = 0;

		public string $post_type = 'product';

		public string $post_status = 'publish';

		/** Slug: lo asigna BrandLandingSchemaTest, y sin declararlo PHP 8.2 avisa «dynamic property». */
		public string $post_name = '';

		/** @var list<string> */
		public array $classes = array();

		public string $menu_item_parent = '0';

		public bool $current = false;

		public bool $current_item_parent = false;

		public bool $current_item_ancestor = false;
	}
}

if ( ! class_exists( 'WP_Term' ) ) {
	/** Stub mínimo para términos de taxonomía en pruebas unitarias. */
	class WP_Term {
		public int $term_id;
		public int $term_taxonomy_id;
		public string $taxonomy;
		public string $slug;
		public string $name;
		public int $count;
		/** Descripción curada del término; la usan el hero del theme y SeriesSchema. */
		public string $description = '';

		public function __construct( int $term_id, string $slug, string $name ) {
			$this->term_id          = $term_id;
			// Coinciden en el caso común; los tests que necesiten divergencia
			// (shared terms legacy) reasignan la propiedad tras construir.
			$this->term_taxonomy_id = $term_id;
			$this->taxonomy         = 'pa_autor';
			$this->slug             = $slug;
			$this->name             = $name;
			$this->count            = 1;
		}
	}
}

if ( ! class_exists( 'WP_User' ) ) {
	/** Stub canónico de usuario de WordPress para pruebas unitarias. */
	class WP_User {
		public int $ID = 0;
		public string $user_login = '';
		public string $user_email = '';
		public string $first_name = '';
		public string $last_name = '';
		public string $display_name = '';
	}
}

if ( ! class_exists( 'WC_Customer' ) ) {
	/** Stub canónico de cliente WooCommerce para pruebas unitarias. */
	class WC_Customer {
		public array $akb_meta = array();
		private int $id = 0;
		private string $billing_first_name = '';
		private string $billing_last_name = '';
		private string $billing_email = '';

		public function __construct( int $id = 0 ) {
			$this->id = $id;
		}

		public function update_meta_data( string $key, $value ): void {
			$this->akb_meta[ $key ] = $value;
		}

		public function get_meta( string $key, bool $single = true ) {
			unset( $single );
			return $this->akb_meta[ $key ] ?? '';
		}

		public function get_billing_first_name(): string {
			return $this->billing_first_name;
		}

		public function set_billing_first_name( string $val ): void {
			$this->billing_first_name = $val;
		}

		public function get_billing_last_name(): string {
			return $this->billing_last_name;
		}

		public function set_billing_last_name( string $val ): void {
			$this->billing_last_name = $val;
		}

		public function get_billing_email(): string {
			return $this->billing_email;
		}

		public function set_billing_email( string $val ): void {
			$this->billing_email = $val;
		}

		public function save(): int {
			return $this->id;
		}
	}
}

if ( ! class_exists( 'WP_Query' ) ) {
	/**
	 * Stub mínimo de `WP_Query` para los filtros que reciben la query como
	 * argumento (`posts_clauses`, `pre_get_posts`).
	 *
	 * Solo `get()`/`set()` sobre `query_vars`: es la única superficie que usan
	 * las rutas virtuales, y un doble que imite más de lo necesario invita a
	 * probar contra el doble en vez de contra el contrato.
	 */
	class WP_Query {
		/** @var array<string, mixed> */
		public array $query_vars = array();

		/**
		 * Resultados de la consulta. Existe porque en Core es una propiedad
		 * pública y hay módulos que la leen directo (`fields => 'ids'` la deja
		 * como lista de IDs); sin ella, leerla en un test es un acceso a
		 * propiedad indefinida, no una consulta vacía.
		 *
		 * @var array<int, mixed>
		 */
		public array $posts = array();

		/**
		 * Quien resuelve una consulta en un test: recibe los args y devuelve
		 * `posts`. Null (default) = consulta vacía, como siempre. Opt-in por
		 * test para no cambiar el comportamiento de los que no lo usan.
		 *
		 * @var callable|null
		 */
		public static $akb_resolver = null;

		/**
		 * @param array<string, mixed> $query_vars Vars iniciales.
		 */
		public function __construct( array $query_vars = array() ) {
			$this->query_vars = $query_vars;
			if ( is_callable( self::$akb_resolver ) ) {
				$this->posts = (array) call_user_func( self::$akb_resolver, $query_vars );
			}
		}

		/**
		 * @param string $key     Nombre de la query var.
		 * @param mixed  $default Valor si no está definida.
		 * @return mixed
		 */
		public function get( $key, $default = '' ) {
			return $this->query_vars[ $key ] ?? $default;
		}

		/**
		 * @param string $key   Nombre de la query var.
		 * @param mixed  $value Valor.
		 * @return void
		 */
		public function set( $key, $value ): void {
			$this->query_vars[ $key ] = $value;
		}

		/**
		 * Core devuelve true sólo para el query principal del request. Los
		 * filtros de rutas virtuales lo usan como guard: un query var público
		 * (registrado en `query_vars`) puede llegar en CUALQUIER query, así que
		 * el doble tiene que poder decir «no soy el principal».
		 *
		 * @var bool
		 */
		public bool $is_main_query_result = true;

		public function is_main_query(): bool {
			return $this->is_main_query_result;
		}
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	/**
	 * Stub de `WP_Error` con la FORMA REAL de Core.
	 *
	 * Importa que sea fiel: `get_the_terms()` devuelve exactamente esto cuando la
	 * taxonomía no existe o la consulta falla, y el contrato de precio editorial
	 * tiene que distinguirlo de "este producto no tiene términos" [ronda 4].
	 */
	class WP_Error {
		/** @var array<string, array<int, string>> */
		public array $errors = array();

		/** @var array<string, mixed> */
		public array $error_data = array();

		/**
		 * @param string $code    Código del error.
		 * @param string $message Mensaje.
		 * @param mixed  $data    Datos asociados.
		 */
		public function __construct( $code = '', $message = '', $data = '' ) {
			if ( '' === $code ) {
				return;
			}
			$this->errors[ $code ][] = $message;
			if ( '' !== $data ) {
				$this->error_data[ $code ] = $data;
			}
		}

		public function get_error_code() {
			$codes = array_keys( $this->errors );
			return empty( $codes ) ? '' : $codes[0];
		}

		public function get_error_message( $code = '' ) {
			$code = '' === $code ? $this->get_error_code() : $code;
			return isset( $this->errors[ $code ][0] ) ? $this->errors[ $code ][0] : '';
		}

		/** @return array<int, string> */
		public function get_error_messages( $code = '' ): array {
			if ( '' === $code ) {
				return array_merge( ...array_values( $this->errors ) ?: array( array() ) );
			}
			return $this->errors[ $code ] ?? array();
		}

		public function has_errors(): bool {
			return ! empty( $this->errors );
		}

		/** @return array<int, string> */
		public function get_error_codes(): array {
			return array_keys( $this->errors );
		}

		/**
		 * @param string $code    Código del error.
		 * @param string $message Mensaje.
		 * @param mixed  $data    Datos asociados.
		 * @return void
		 */
		public function add( $code, $message = '', $data = '' ): void {
			$this->errors[ $code ][] = $message;
			if ( '' !== $data ) {
				$this->error_data[ $code ] = $data;
			}
		}

		/**
		 * @param string $code Código.
		 * @return mixed
		 */
		public function get_error_data( $code = '' ) {
			$code = '' === $code ? $this->get_error_code() : $code;
			return $this->error_data[ $code ] ?? null;
		}

		/**
		 * @param string $code Código a remover.
		 * @return void
		 */
		public function remove( $code ): void {
			unset( $this->errors[ $code ], $this->error_data[ $code ] );
		}
	}
}

if ( ! class_exists( 'WC_Product' ) ) {
	/** Stub mínimo para pruebas unitarias sin cargar WordPress/WooCommerce. */
	class WC_Product {
		private string $stock_status;

		/** @var array<string,string> */
		private array $meta;

		private bool $purchasable;

		public int $akb_id = 0;

		public int $akb_parent_id = 0;

		public string $akb_regular_price = '';

		public string $akb_sale_price = '';

		public string $akb_sku = '';

		public bool $akb_on_sale = false;

		public string $akb_type = 'simple';

		/** @var \DateTimeInterface|string|null */
		public $akb_date_on_sale_to = null;

		/**
		 * @param array<string,string> $meta Metadatos simulados.
		 */
		public function __construct( string $stock_status = 'instock', array $meta = array(), bool $purchasable = true ) {
			$this->stock_status = $stock_status;
			$this->meta         = $meta;
			$this->purchasable  = $purchasable;
		}

		public function get_stock_status(): string {
			return $this->stock_status;
		}

		public string $akb_status = 'publish';

		/** @return string */
		public function get_status() {
			return $this->akb_status;
		}

		public function is_purchasable(): bool {
			return $this->purchasable;
		}

		public function is_in_stock(): bool {
			return 'outofstock' !== $this->stock_status;
		}

		public function get_meta( string $key ): string {
			return $this->meta[ $key ] ?? '';
		}

		/**
		 * SIN tipo de retorno declarado a propósito: este stub es la clase padre
		 * de dobles que ya existen en la suite con firmas distintas —unos
		 * declaran `get_id(): int`, otros `get_id()` a secas—. Un padre sin tipo
		 * admite ambos por covarianza; uno tipado rompe al que no lo declara.
		 *
		 * @return int
		 */
		public function get_id() {
			return $this->akb_id;
		}

		/** @return int */
		public function get_parent_id() {
			return $this->akb_parent_id;
		}

		public function get_sku(): string {
			return $this->akb_sku;
		}

		/**
		 * @param string $context Contexto WC ('view' | 'edit').
		 * @return string
		 */
		public function get_regular_price( string $context = 'view' ): string {
			unset( $context );
			return $this->akb_regular_price;
		}

		/**
		 * @param string $context Contexto WC ('view' | 'edit').
		 * @return string
		 */
		public function get_sale_price( string $context = 'view' ): string {
			unset( $context );
			return $this->akb_sale_price;
		}

		/**
		 * @param string $context Contexto WC ('view' | 'edit').
		 * @return bool
		 */
		public function is_on_sale( string $context = 'view' ): bool {
			unset( $context );
			return $this->akb_on_sale;
		}

		public function is_type( string $type ): bool {
			return $this->akb_type === $type;
		}

		/**
		 * @param string $context Contexto WC ('view' | 'edit').
		 * @return \DateTimeInterface|string|null
		 */
		public function get_date_on_sale_to( string $context = 'view' ) {
			unset( $context );
			return $this->akb_date_on_sale_to;
		}
	}
}

if ( ! class_exists( 'WC_Order_Item_Product' ) ) {
	/**
	 * Stub mínimo de línea de pedido para las pruebas de preventa.
	 *
	 * Estado público con prefijo `akb_` para que los tests lo compongan sin
	 * setters ceremoniales; la API imitada es la que consume el módulo.
	 */
	class WC_Order_Item_Product {
		public int $akb_id = 0;

		public int $akb_quantity = 1;

		public string $akb_name = '';

		/** @var array<string, string> */
		public array $akb_meta = array();

		public function get_id(): int {
			return $this->akb_id;
		}

		public function get_name(): string {
			return $this->akb_name;
		}

		public function get_quantity(): int {
			return $this->akb_quantity;
		}

		public function get_meta( string $key, bool $single = true ) {
			unset( $single );
			return $this->akb_meta[ $key ] ?? '';
		}

		public int $akb_product_id = 0;

		/** @var \WC_Product|null */
		public $akb_product = null;

		/**
		 * Sin tipo de retorno a propósito: dobles hijos de la suite ya declaran
		 * `get_product_id(): int`, y un padre tipado rompería al que no lo declara.
		 *
		 * @return int
		 */
		public function get_product_id() {
			return $this->akb_product_id;
		}

		/** @return \WC_Product|null */
		public function get_product() {
			return $this->akb_product;
		}

		/** @param mixed $value Valor. */
		public function update_meta_data( string $key, $value ): void {
			$this->akb_meta[ $key ] = $value;
		}

		public function delete_meta_data( string $key ): void {
			unset( $this->akb_meta[ $key ] );
		}
	}
}

if ( ! class_exists( 'WC_Admin_Meta_Boxes' ) ) {
	/** Recoge los avisos de error que WooCommerce pinta al guardar un producto. */
	class WC_Admin_Meta_Boxes {
		/** @var array<int, string> */
		public static array $errors = array();

		public static function add_error( $text ): void {
			self::$errors[] = (string) $text;
		}
	}
}

if ( ! class_exists( 'WC_Order' ) ) {
	/** Stub mínimo de pedido para las pruebas de preventa. */
	class WC_Order {
		/** @var array<string, string> */
		public array $akb_meta = array();

		/** @var array<int, WC_Order_Item_Product> */
		public array $akb_items = array();

		/** @var array<int, int> Cantidad reembolsada por item id. */
		public array $akb_refunded = array();

		public string $akb_status = 'processing';

		public int $akb_id = 100;

		public string $akb_billing_phone = '';

		/**
		 * Constructor polimórfico a propósito: este stub es COMPARTIDO por las
		 * tres suites que antes declaraban su propio doble con firmas distintas
		 * — meta por array (RutValidationTest) y teléfono por string (fixture de
		 * cart-checkout-blocks). Acepta ambas para que exista una sola clase.
		 *
		 * @param array<string, string>|string $arg Meta simulado, o billing phone.
		 */
		public function __construct( $arg = array() ) {
			if ( is_array( $arg ) ) {
				$this->akb_meta = $arg;
			} elseif ( is_string( $arg ) ) {
				$this->akb_billing_phone = $arg;
			}
		}

		public function get_id(): int {
			return $this->akb_id;
		}

		public function get_billing_phone(): string {
			return $this->akb_billing_phone;
		}

		/** @var array<int, string> */
		public array $akb_coupon_codes = array();

		public function get_coupon_codes(): array {
			return $this->akb_coupon_codes;
		}

		/** @var string Celular de ENVÍO: el que usa el transportista para avisar. */
		public string $akb_shipping_phone = '';

		public function get_shipping_phone(): string {
			return $this->akb_shipping_phone;
		}

		/** @var mixed Fecha de pago; null = sin pagar. */
		public $akb_date_paid = null;

		/** @var mixed Fecha de completado; null = sin completar. */
		public $akb_date_completed = null;

		/** @return mixed */
		public function get_date_paid() {
			return $this->akb_date_paid;
		}

		/** @return mixed */
		public function get_date_completed() {
			return $this->akb_date_completed;
		}

		/** @var \DateTimeInterface|null Fecha de creación; null = sin fecha. */
		public $akb_date_created = null;

		/** @return mixed */
		public function get_date_created() {
			return $this->akb_date_created;
		}

		public function get_edit_order_url(): string {
			return 'https://akibara.test/wp-admin/admin.php?page=wc-orders&action=edit&id=' . $this->akb_id;
		}

		/**
		 * @param mixed $value Valor del meta.
		 */
		public function update_meta_data( string $key, $value ): void {
			$this->akb_meta[ $key ] = $value;
		}

		public function get_meta( string $key, bool $single = true ) {
			unset( $single );
			return $this->akb_meta[ $key ] ?? '';
		}

		/**
		 * @return array<int, WC_Order_Item_Product>
		 */
		public function get_items( string $types = 'line_item' ): array {
			unset( $types );
			return $this->akb_items;
		}

		public function get_qty_refunded_for_item( int $item_id ): int {
			// WooCommerce devuelve el reembolso en negativo.
			return -abs( $this->akb_refunded[ $item_id ] ?? 0 );
		}

		/**
		 * @param string|array<int, string> $status Estado o lista de estados.
		 */
		public function has_status( $status ): bool {
			return is_array( $status )
				? in_array( $this->akb_status, $status, true )
				: $this->akb_status === $status;
		}

		public function get_status(): string {
			return $this->akb_status;
		}

		public function get_order_number(): string {
			return (string) $this->akb_id;
		}

		public function get_billing_email(): string {
			return $this->akb_billing_email;
		}

		public function delete_meta_data( string $key ): void {
			unset( $this->akb_meta[ $key ] );
		}

		/**
		 * `save()` sobre un doble en memoria no persiste nada, pero SÍ cuenta:
		 * los caminos de recuperación de preventa dependen de que el marker
		 * quede escrito ANTES o DESPUÉS de un punto exacto, y sin contador no se
		 * puede distinguir "no lo guardó" de "lo guardó y lo revirtió".
		 */
		public function save(): int {
			++$this->akb_saves;
			return $this->akb_id;
		}

		public function add_order_note( string $note, $is_customer_note = 0, $added_by_user = false ): int {
			// Como WooCommerce (`WC_Order::add_order_note`): un pedido que aún
			// no existe en la base (ID 0) no puede llevar notas; devuelve 0 y
			// no anota. El Store API pasa por ahí al copiar los campos de la
			// sesión al pedido recién construido (R6-consentimiento-sin-nota).
			if ( $this->akb_id < 1 ) {
				return 0;
			}
			$this->akb_notes[]      = $note;
			$this->akb_note_flags[] = array( $is_customer_note, $added_by_user );
			return count( $this->akb_notes );
		}

		/** @var array<int, array{0: mixed, 1: mixed}> Por nota: (¿al cliente?, ¿firmada por el usuario del panel?). */
		public array $akb_note_flags = array();

		public string $akb_billing_email = 'cliente@akibara.cl';

		public int $akb_saves = 0;

		/** @var array<int, string> */
		public array $akb_notes = array();

		public function update_status( $new_status, $note = '', $manual = false ) {
			unset( $manual );
			$this->akb_status = (string) $new_status;
			if ( '' !== (string) $note ) {
				$this->add_order_note( (string) $note );
			}
			return true;
		}
	}
}

if ( ! class_exists( 'WC_DateTime' ) ) {
	class WC_DateTime extends \DateTime {
	}
}

if ( ! class_exists( 'WC_Email' ) ) {
	/**
	 * Stub de la clase base de emails de WooCommerce.
	 *
	 * Sólo lo que `ReadyToShipEmail` consume, con UNA adición deliberada:
	 * `setup_locale()`/`restore_locale()` llevan contador. El defecto que estas
	 * pruebas persiguen es precisamente un locale que se abre y no se cierra
	 * cuando el tramo de envío lanza, y sin instrumentar el par no hay forma de
	 * verlo desde afuera [auditoría 2026-08-05].
	 */
	class WC_Email {
		public string $id = '';

		public bool $customer_email = false;

		public string $title = '';

		public string $description = '';

		public string $template_html = '';

		public string $template_plain = '';

		public string $template_base = '';

		/** Declarada en WC_Email real y escrita por GatewayEmail; sin ella PHP 8.2 emite dynamic-property deprecation. */
		public string $email_type = '';

		/** @var array<string, string> */
		public array $placeholders = array();

		/**
		 * Declarada en `WC_Settings_API`, de donde WC_Email la hereda.
		 * `init_form_fields()` la escribe, y sin declararla PHP 8.2 emite
		 * `Deprecated: Creation of dynamic property` — con `failOnWarning`, una
		 * suite roja por un stub incompleto.
		 *
		 * @var array<string, array<string, mixed>>
		 */
		public array $form_fields = array();

		/** @var WC_Order|null */
		public $object = null;

		public string $recipient = '';

		public string $enabled = 'yes';

		/**
		 * Réplica de `WC_Email::$sending`: WooCommerce lo enciende en
		 * `get_content()` y sólo lo apaga `handle_multipart()` desde
		 * `phpmailer_init`, así que un render que lanza lo deja colgado. El stub
		 * imita ese ciclo para que la suite pueda observarlo.
		 *
		 * @var bool
		 */
		public bool $sending = false;

		/** Veces que se activó el locale del cliente. */
		public static int $akb_locale_setups = 0;

		/** Veces que se restauró. Debe empatar con los setups SIEMPRE. */
		public static int $akb_locale_restores = 0;

		/** Última llamada a send() para aserciones en tests. @var array<string, mixed> */
		public static array $akb_last_send = array();

		/** Si no es null, send() lanzará esta excepción para probar bloques finally. */
		public static ?\Throwable $akb_fail_send_exception = null;

		public static function akb_reset_locale_counters(): void {
			self::$akb_locale_setups   = 0;
			self::$akb_locale_restores = 0;
		}

		/** ¿Quedó un locale abierto? */
		public static function akb_locale_is_balanced(): bool {
			return self::$akb_locale_setups === self::$akb_locale_restores;
		}

		public function __construct() {}

		public function get_blogname(): string {
			return 'Akibara';
		}

		public function is_enabled(): bool {
			return 'yes' === $this->enabled;
		}

		public function setup_locale(): void {
			++self::$akb_locale_setups;
		}

		public function restore_locale(): void {
			++self::$akb_locale_restores;
		}

		public function get_recipient(): string {
			return $this->recipient;
		}

		/** @var array<string, string> Ajustes guardados, como `WC_Settings_API`. */
		public array $settings = array();

		public function init_settings(): void {
			if ( array() !== $this->settings ) {
				return;
			}
			foreach ( $this->form_fields as $clave => $definicion ) {
				$this->settings[ $clave ] = (string) ( $definicion['default'] ?? '' );
			}
		}

		/**
		 * Copia FIEL de `WC_Settings_API::get_option()`, incluida la línea que más
		 * importa: cuando el valor guardado está vacío, ESCRIBE el default de
		 * vuelta en `$this->settings`. Sin esa escritura, un default que dependa
		 * del envío parece funcionar en la suite y en producción congela el del
		 * primer cliente del lote — `DigestRunner::run()` reutiliza UNA instancia
		 * para los 50 clientes.
		 *
		 * @param string $key         Clave.
		 * @param mixed  $empty_value Valor si está vacía.
		 * @return string
		 */
		public function get_option( $key, $empty_value = null ) {
			if ( array() === $this->settings ) {
				$this->init_settings();
			}
			if ( ! isset( $this->settings[ $key ] ) ) {
				$this->settings[ $key ] = (string) ( $this->form_fields[ $key ]['default'] ?? '' );
			}
			if ( null !== $empty_value && '' === $this->settings[ $key ] ) {
				$this->settings[ $key ] = (string) $empty_value;
			}

			return $this->settings[ $key ];
		}

		/**
		 * @param string $key         Clave.
		 * @param mixed  $empty_value Valor si está vacía.
		 * @return string
		 */
		protected function get_option_or_transient( string $key, $empty_value = null ) {
			return $this->get_option( $key, $empty_value );
		}

		/**
		 * `str_replace`, como el de WooCommerce: por eso un «%» en un texto del
		 * dueño sale literal y nunca se interpreta como formato.
		 *
		 * @param mixed $string Texto con marcadores.
		 * @return string
		 */
		public function format_string( $string ): string {
			return str_replace( array_keys( $this->placeholders ), array_values( $this->placeholders ), (string) $string );
		}

		/** @return string */
		public function get_default_subject() {
			return '';
		}

		/** @return string */
		public function get_default_heading() {
			return '';
		}

		public function get_subject(): string {
			$this->init_form_fields_si_hace_falta();

			return $this->format_string( $this->get_option_or_transient( 'subject', (string) $this->get_default_subject() ) );
		}

		/** El doble no pasa por el ciclo de admin de WC, que es quien los llena. */
		private function init_form_fields_si_hace_falta(): void {
			if ( array() === $this->form_fields && method_exists( $this, 'init_form_fields' ) ) {
				$this->init_form_fields();
			}
		}

		public function get_heading(): string {
			$this->init_form_fields_si_hace_falta();

			return $this->format_string( $this->get_option_or_transient( 'heading', (string) $this->get_default_heading() ) );
		}

		/**
		 * Mismo ciclo que la clase real: enciende `$sending` y delega en
		 * `get_content_html()`. Los dobles lanzan desde ahí, que es donde lanza
		 * `wc_get_template_html()` en producción.
		 */
		public function get_content(): string {
			$this->sending = true;

			return $this->get_content_html();
		}

		public function get_content_html(): string {
			return '<p>contenido</p>';
		}

		public function get_from_address( $from = '' ): string {
			unset( $from );
			return 'no-reply@akibara.cl';
		}

		public function get_from_name( $name = '' ): string {
			unset( $name );
			return 'Akibara';
		}

		public function get_content_type( $type = '' ): string {
			unset( $type );
			return 'text/html';
		}

		/**
		 * Las tres opciones reales de WooCommerce. `AkibaraEmail::init_form_fields()`
		 * las pide para el campo `email_type`, que es obligatorio: sin él
		 * `get_content_type()` cae a `text/plain` y el correo sale con el HTML
		 * dentro del cuerpo.
		 *
		 * @return array<string, string>
		 */
		public function get_email_type_options(): array {
			return array(
				'plain'     => 'Plain text',
				'html'      => 'HTML',
				'multipart' => 'Multipart',
			);
		}

		public function get_headers(): string {
			return 'Content-Type: text/html';
		}

		/** @return array<int, string> */
		public function get_attachments(): array {
			return array();
		}

		public function get_additional_content(): string {
			return '';
		}

		/**
		 * @param string           $to          Destinatario.
		 * @param string           $subject     Asunto.
		 * @param string           $message     Cuerpo.
		 * @param string           $headers     Cabeceras.
		 * @param array<int,mixed> $attachments Adjuntos.
		 * @return bool
		 */
		public function send( $to, $subject, $message, $headers, $attachments ): bool {
			if ( null !== self::$akb_fail_send_exception ) {
				$ex                         = self::$akb_fail_send_exception;
				self::$akb_fail_send_exception = null;
				throw $ex;
			}
			self::$akb_last_send = array(
				'id'            => $this->id,
				'to'            => $to,
				'subject'       => $subject,
				'message'       => $message,
				'headers'       => $headers,
				'sending'       => $this->sending,
				'content_plain' => ( $this instanceof \Akibara\Business\Services\GatewayEmail ) ? $this->get_content_plain() : '',
			);
			return true;
		}
	}
}

/*
 * `wp_json_encode()` canónico del suite.
 *
 * Lo declaraban por su cuenta, con `eval()`, dos ficheros de test
 * (BundleCouponEngineTest y OrderInstrumentationTest). El resultado es que
 * `BypConfigControllerTest` sólo pasaba si uno de esos dos corría ANTES:
 * ejecutado solo —o con `--filter`— moría con «Call to undefined function
 * wp_json_encode()», y en la suite completa salía verde por contagio. Un test
 * que depende del orden no es una prueba: es una moneda al aire, y esconde el
 * momento en que el código de verdad se rompe.
 *
 * Declararlo acá (el bootstrap carga este archivo antes que cualquier test) le
 * da una sola forma a todos; los `function_exists` de esos ficheros quedan en
 * no-op. Mismo criterio que ya se aplicó al `WP_Term` de akibara-search.
 */
if ( ! function_exists( 'wp_json_encode' ) ) {
	/**
	 * Copia fiel: WordPress devuelve `string|false`, NO siempre string.
	 *
	 * Devolver `''` en el fallo colapsaría el caso de error contra un JSON vacío
	 * y dejaría verde una prueba que existe justamente para comprobar que un
	 * valor no serializable ENVENENA la autoridad de precios
	 * (`PriceSnapshotContractTest::test_invalid_json_encoding_poisons_the_authority`).
	 * Es el mismo patrón que ya escondió un fatal vivo con `maybe_serialize`.
	 *
	 * @param mixed $value Valor a serializar.
	 * @return string|false
	 */
	function wp_json_encode( $value ) {
		return json_encode( $value ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- este ES el doble de wp_json_encode().
	}
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
	if ( ! class_exists( 'WP_REST_Response' ) ) {
		/**
		 * Stub mínimo de la respuesta REST.
		 *
		 * Subió aquí el 2026-09-19 desde `CollectionToggleTest.php`, por la misma
		 * razón que `WP_REST_Request` más abajo: `ContactoSubmitEmailTest` la usa y,
		 * corrida sola, caía con «Class not found» (`tools/test-clases-aisladas.sh`).
		 */
		class WP_REST_Response {
			/** @var mixed */
			public $data;

			public int $status;

			/**
			 * @param mixed $data   Cuerpo.
			 * @param int   $status Código HTTP.
			 */
			public function __construct( $data = null, int $status = 200 ) {
				$this->data   = $data;
				$this->status = $status;
			}

			/** @return mixed */
			public function get_data() {
				return $this->data;
			}

			public function get_status(): int {
				return $this->status;
			}
		}
	}

	/**
	 * Doble de la petición REST.
	 *
	 * Vivía dentro de `CollectionToggleTest`, así que sólo existía si ese fichero
	 * se cargaba antes —y con `--filter` a menudo no lo hace—. Un doble que
	 * aparece según el orden de ejecución es la misma clase de trampa que
	 * `function_exists()` mintiendo en la suite: sube al suelo compartido, donde
	 * cualquier prueba puede contar con él.
	 *
	 * Lleva `get_params()` además de `get_param()`: los endpoints de la casa leen
	 * el cuerpo entero de una vez, y sin ese método no se podía probar ninguno.
	 */
	class WP_REST_Request {
		/** @var array<string, mixed> */
		private array $params;

		/** @var array<string, string> */
		private array $headers;

		/**
		 * @param array<string, mixed>  $params  Cuerpo.
		 * @param array<string, string> $headers Cabeceras.
		 */
		public function __construct( array $params = array(), array $headers = array() ) {
			$this->params  = $params;
			$this->headers = $headers;
		}

		/**
		 * @param string $key Nombre del parámetro.
		 * @return mixed
		 */
		public function get_param( string $key ) {
			return $this->params[ $key ] ?? null;
		}

		/** @return array<string, mixed> */
		public function get_params(): array {
			return $this->params;
		}

		/**
		 * @param string $key   Nombre del parámetro.
		 * @param mixed  $value Valor.
		 * @return void
		 */
		public function set_param( string $key, $value ): void {
			$this->params[ $key ] = $value;
		}

		/**
		 * @param string $key Nombre de la cabecera.
		 * @return string|null
		 */
		public function get_header( string $key ): ?string {
			return $this->headers[ $key ] ?? null;
		}
	}
}

if ( ! class_exists( 'WC_Breadcrumb' ) ) {
	/** Stub: la miga que WooCommerce pintaría, fijada por cada prueba. */
	class WC_Breadcrumb {
		/** @var array<int, array{0:string,1:string}> */
		public static array $crumbs = array();

		/** @return array<int, array{0:string,1:string}> */
		public function generate(): array {
			return self::$crumbs;
		}
	}
}

