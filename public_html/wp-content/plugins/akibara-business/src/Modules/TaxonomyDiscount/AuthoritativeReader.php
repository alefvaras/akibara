<?php
/**
 * Lecturas RAW y observables de todo lo que decide el precio editorial.
 *
 * Por qué no basta `get_option()` + mirar `$wpdb->last_error`: cuando el valor
 * viene de `alloptions`/`notoptions` **no hubo consulta**, así que no hay error
 * que observar y el default se devuelve como si fuera un hecho. Lo mismo con el
 * object cache de relaciones (`update_object_term_cache`) y de term-meta
 * (`update_meta_cache`): un vacío contaminado es indistinguible de una ausencia
 * legítima, y con los transients de `wc_get_attribute_taxonomies()` una
 * taxonomía entera puede desaparecer del barrido.
 *
 * Este lector consulta la base directamente y observa cada frontera:
 *
 *   1. limpia `$wpdb->last_error` ANTES (es global y volátil: la siguiente
 *      consulta exitosa lo borra, así que comprobarlo al final no sirve);
 *   2. ejecuta dentro de `try`, capturando `Throwable`;
 *   3. mira el error inmediatamente después;
 *   4. valida forma y dominio del valor.
 *
 * Ausencia legítima (la fila no existe) y fallo de lectura se distinguen a
 * propósito: la primera es un dato válido, el segundo envenena la autoridad.
 *
 * @package Akibara\Business
 */

declare( strict_types = 1 );

namespace Akibara\Business\Modules\TaxonomyDiscount;

defined( 'ABSPATH' ) || exit;

/**
 * @since 1.99.15
 */
final class AuthoritativeReader {

	/** Mismo dominio sellado por Search y AdoptedSnapshot. */
	private const MAX_DECIMALS = 8;

	/**
	 * TODAS las opciones que deciden precio o vigencia, leídas de una sola vez.
	 *
	 * La foto tiene que ser completa desde el principio: si se leyera por
	 * demanda, la primera llamada fijaría el conjunto y las claves pedidas
	 * después volverían `null` —o sea, su default— reintroduciendo exactamente
	 * el defecto que este lector existe para cerrar.
	 */
	private const PRICE_OPTIONS = array(
		\Akibara\Business\Utilities\DiscountCeiling::OPTION,
		Settings::OPTION_ENABLED,
		Settings::OPTION_FROM,
		Settings::OPTION_TO,
		Settings::OPTION_APPLY_TO,
		Settings::OPTION_SALE_POLICY,
		Settings::OPTION_STACKS_COUPONS,
		'woocommerce_price_num_decimals',
		'timezone_string',
		'gmt_offset',
	);

	private PriceAuthority $authority;

	/**
	 * Foto de opciones ya leída en esta petición: nombre → valor, o null si la
	 * fila no existe (ausencia legítima).
	 *
	 * @var array<string, string|null>|null
	 */
	private ?array $options = null;

	/**
	 * Mapa `pa_slug => label real` leído en crudo y ordenado por attribute_id.
	 *
	 * @var array<string, string>|null
	 */
	private ?array $attribute_map = null;

	/** Decimales efectivos, resueltos UNA sola vez por petición. */
	private ?int $decimals = null;

	/** Tope editorial efectivo, resuelto UNA sola vez por petición. */
	private ?int $max_pct = null;

	/** Taxonomías efectivas (post-filtro), resueltas UNA sola vez. */
	private ?array $effective_taxonomies = null;

	/** Mapa efectivo (slug => label) tal cual sale del filtro. */
	private ?array $effective_map = null;

	/**
	 * Material que entra en la huella de la foto (para el final-check del
	 * consumidor).
	 *
	 * @var array<string, mixed>
	 */
	private array $fingerprint_material = array();

	public function __construct( PriceAuthority $authority ) {
		$this->authority = $authority;
	}

	public function authority(): PriceAuthority {
		return $this->authority;
	}

	/**
	 * Lee en UNA consulta todas las opciones que deciden precio y vigencia.
	 *
	 * @param array<int, string> $names Nombres de opción.
	 * @return array<string, string|null> Valor, o null si la fila no existe.
	 */
	public function options( array $names ): array {
		if ( null !== $this->options ) {
			return $this->options;
		}

		// La foto cubre SIEMPRE el conjunto canónico completo; pedir algo fuera
		// de él significaría que una opción que decide precio no está declarada
		// y no entraría al fingerprint.
		foreach ( $names as $requested ) {
			if ( ! in_array( $requested, self::PRICE_OPTIONS, true ) ) {
				$this->authority->fail( sprintf( 'Se pidió la opción %s fuera del conjunto canónico de precio.', $requested ) );
			}
		}

		$names = self::PRICE_OPTIONS;
		$this->options = array_fill_keys( $names, null );

		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			$this->authority->fail( 'No hay $wpdb para leer las opciones de precio.' );
			return $this->options;
		}

		$placeholders = implode( ', ', array_fill( 0, count( $names ), '%s' ) );
		$wpdb->last_error = '';

		try {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- el nombre de tabla y la lista de placeholders se generan acá; los VALORES sí van preparados.
					"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name IN ({$placeholders})",
					// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
					$names
				),
				ARRAY_A
			);
		} catch ( \Throwable $e ) {
			$this->authority->fail( 'Lectura cruda de opciones lanzó ' . get_class( $e ) . ': ' . $e->getMessage() );
			return $this->options;
		}

		if ( '' !== (string) $wpdb->last_error ) {
			$this->authority->fail( 'Lectura cruda de opciones falló en SQL: ' . (string) $wpdb->last_error );
			return $this->options;
		}

		if ( ! is_array( $rows ) ) {
			$this->authority->fail( 'Lectura cruda de opciones devolvió una forma inválida.' );
			return $this->options;
		}

		$seen = array();
		foreach ( $rows as $row ) {
			// Forma EXACTA: dos claves, ni una más. Una fila con `autoload`, un
			// `option_value` que no sea string o un nombre fuera del conjunto
			// canónico significan que la consulta no devolvió lo que este código
			// cree estar leyendo.
			if ( ! is_array( $row ) || array( 'option_name', 'option_value' ) !== array_keys( $row ) ) {
				$this->authority->fail( 'Fila de opciones con forma inesperada.' );
				return $this->options;
			}

			$name  = $row['option_name'];
			$value = $row['option_value'];

			if ( ! is_string( $name ) || ! is_string( $value ) ) {
				$this->authority->fail( 'Fila de opciones con tipos inesperados.' );
				return $this->options;
			}

			if ( ! in_array( $name, self::PRICE_OPTIONS, true ) ) {
				$this->authority->fail( sprintf( 'La consulta de opciones devolvió %s, fuera del conjunto canónico.', $name ) );
				return $this->options;
			}

			if ( isset( $seen[ $name ] ) ) {
				$this->authority->fail( sprintf( 'La opción %s vino duplicada: el valor efectivo es ambiguo.', $name ) );
				return $this->options;
			}

			$seen[ $name ]         = true;
			$this->options[ $name ] = $value;
		}

		$this->fingerprint_material['options'] = $this->options;

		return $this->options;
	}

	/**
	 * Valida el DOMINIO de la foto completa.
	 *
	 * Se ejecuta al entrar en modo estricto y no cuando cada rama consulta su
	 * opción: si `sale_policy` trae basura, el precio de un producto sin oferta
	 * no la consultaría nunca y el documento se sellaría igual, con una
	 * configuración que este código no sabe interpretar. Una opción que decide
	 * precio y está fuera de dominio invalida el precio entero, la use esta rama
	 * o no.
	 *
	 * @return void
	 */
	public function validate_price_options(): void {
		$options = $this->options( self::PRICE_OPTIONS );

		$domains = array(
			Settings::OPTION_ENABLED        => array( 'yes', 'no' ),
			Settings::OPTION_STACKS_COUPONS => array( 'yes', 'no' ),
			Settings::OPTION_APPLY_TO       => array(
				Settings::APPLY_INSTOCK_ONLY,
				Settings::APPLY_INSTOCK_AND_ONBACKORDER,
				Settings::APPLY_ALL,
			),
			Settings::OPTION_SALE_POLICY    => array(
				Settings::POLICY_GREATEST_WINS,
				Settings::POLICY_SKIP_IF_ON_SALE,
				Settings::POLICY_FROM_REGULAR,
				Settings::POLICY_STACK_ON_SALE,
			),
		);

		foreach ( $domains as $name => $allowed ) {
			$value = $options[ $name ] ?? null;
			if ( null === $value || '' === $value ) {
				continue; // ausencia legítima: manda el default declarado.
			}
			if ( ! in_array( $value, $allowed, true ) ) {
				$this->authority->fail( sprintf( 'La opción %s tiene un valor fuera de dominio.', $name ) );
				return;
			}
		}

		foreach ( array( Settings::OPTION_FROM, Settings::OPTION_TO ) as $name ) {
			$value = $options[ $name ] ?? null;
			if ( null === $value || '' === $value ) {
				continue;
			}
			$parsed = \DateTimeImmutable::createFromFormat( 'Y-m-d', $value );
			if ( false === $parsed || $parsed->format( 'Y-m-d' ) !== $value ) {
				$this->authority->fail( sprintf( 'La opción %s no es una fecha Y-m-d válida.', $name ) );
				return;
			}
		}

		// Fuerza la validación de dominio de decimales y zona horaria aunque la
		// rama de precio de este producto no llegue a usarlas.
		$this->price_decimals();
		$this->site_today();
	}

	/**
	 * Valor de una opción validado contra un dominio cerrado.
	 *
	 * @param string             $name    Opción.
	 * @param array<int, string> $allowed Valores permitidos.
	 * @param string             $default_value Valor cuando la fila NO existe.
	 * @return string
	 */
	public function enumerated_option( string $name, array $allowed, string $default_value ): string {
		$value = $this->options( array( $name ) )[ $name ] ?? null;

		if ( null === $value || '' === $value ) {
			return $default_value; // ausencia legítima.
		}

		if ( ! in_array( $value, $allowed, true ) ) {
			// Un valor fuera de dominio NO puede degradarse al default: alguien
			// escribió algo que este código no sabe interpretar, y adivinar
			// cambiaría el precio en silencio.
			$this->authority->fail( sprintf( 'La opción %s tiene un valor fuera de dominio.', $name ) );
			return $default_value;
		}

		return $value;
	}

	/**
	 * Decimales de precio: option RAW + el filtro de WooCommerce aplicado UNA vez.
	 *
	 * No se llama `wc_get_price_decimals()`: esa función relee la option por la
	 * API cacheada, que es justo la fuente contaminada de la que este lector
	 * escapa. Se parte del valor crudo, se valida forma y rango ANTES del cast
	 * —`(int) 'abc'` es 0 y ese 0 cambiaría el precio en silencio— y recién
	 * entonces se pasa una sola vez por `wc_get_price_decimals`, que es el filtro
	 * que Search también respeta.
	 *
	 * Default 2, el de WooCommerce; degradar a 0 sería inventar un precio
	 * distinto.
	 */
	public function price_decimals(): int {
		if ( null !== $this->decimals ) {
			return $this->decimals;
		}

		$raw   = $this->options( array( 'woocommerce_price_num_decimals' ) )['woocommerce_price_num_decimals'] ?? null;
		$value = 2;

		if ( '' === $raw ) {
			// Una fila presente y vacía no equivale a ausencia en WooCommerce:
			// absint( '') produce 0. Search/Adopted la rechazan para no firmar un
			// default 2 distinto del valor que usaría Core.
			$this->authority->fail( 'woocommerce_price_num_decimals está presente pero vacío.' );
			return 2;
		}

		if ( null !== $raw ) {
			if ( ! is_scalar( $raw ) || ! is_numeric( $raw ) ) {
				$this->authority->fail( 'woocommerce_price_num_decimals no es numérico.' );
				return 2;
			}

			$candidate = (int) $raw;
			if ( (string) $candidate !== trim( (string) $raw ) || $candidate < 0 || $candidate > self::MAX_DECIMALS ) {
				$this->authority->fail( sprintf( 'woocommerce_price_num_decimals fuera de dominio (%s).', (string) $raw ) );
				return 2;
			}

			$value = $candidate;
		}

		// El MISMO filtro que aplica WooCommerce, exactamente una vez.
		if ( function_exists( 'apply_filters' ) ) {
			try {
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- filtro de WooCommerce: se consume el suyo, no se inventa otro.
		$filtered = apply_filters( 'wc_get_price_decimals', $value );
			} catch ( \Throwable $e ) {
				$this->authority->fail( 'El filtro wc_get_price_decimals lanzó ' . get_class( $e ) . ': ' . $e->getMessage() );
				return $value;
			}

			if ( ! is_scalar( $filtered ) || ! is_numeric( $filtered ) ) {
				$this->authority->fail( 'El filtro wc_get_price_decimals devolvió una forma inválida.' );
				return $value;
			}

			$filtered_int = (int) $filtered;
			if ( $filtered_int < 0 || $filtered_int > self::MAX_DECIMALS ) {
				$this->authority->fail( sprintf( 'El filtro wc_get_price_decimals devolvió %d, fuera de dominio.', $filtered_int ) );
				return $value;
			}

			$value = $filtered_int;
		}

		$this->decimals = $value;
		$this->fingerprint_material['decimals'] = $value;

		return $value;
	}

	/**
	 * Fecha "hoy" en la zona del sitio, leída en crudo.
	 *
	 * La ventana de vigencia se compara contra esto: si la zona horaria degrada
	 * a un default, un descuento vigente puede leerse como expirado (o al revés)
	 * sin que nada falle visiblemente.
	 *
	 * @return string `Y-m-d`, o cadena vacía si no se pudo determinar.
	 */
	public function site_today(): string {
		$options  = $this->options( array( 'timezone_string', 'gmt_offset' ) );
		$timezone = $options['timezone_string'] ?? null;
		$offset   = $options['gmt_offset'] ?? null;

		try {
			if ( null !== $timezone && '' !== $timezone ) {
				return ( new \DateTimeImmutable( 'now', new \DateTimeZone( $timezone ) ) )->format( 'Y-m-d' );
			}

			if ( null === $offset || '' === $offset ) {
				// Sitio sin zona configurada: UTC es el comportamiento de Core.
				return gmdate( 'Y-m-d' );
			}

			if ( ! is_numeric( $offset ) ) {
				$this->authority->fail( 'gmt_offset no es numérico: no se puede fechar la ventana de vigencia.' );
				return '';
			}

			// Offsets fraccionarios REALES: India (+5:30), Nepal (+5:45), Chatham
			// (+12:45). Truncar a horas enteras con `(int)` mueve la frontera del
			// día y puede dar por expirada una ventana vigente.
			$offset_hours = (float) $offset;
			$sign         = $offset_hours < 0 ? '-' : '+';
			$absolute     = abs( $offset_hours );
			$hours        = (int) floor( $absolute );
			$minutes      = (int) round( ( $absolute - $hours ) * 60 );
			if ( 60 === $minutes ) {
				++$hours;
				$minutes = 0;
			}

			$zone = sprintf( '%s%02d:%02d', $sign, $hours, $minutes );

			return ( new \DateTimeImmutable( 'now', new \DateTimeZone( $zone ) ) )->format( 'Y-m-d' );
		} catch ( \Throwable $e ) {
			$this->authority->fail( 'No se pudo resolver la zona horaria del sitio: ' . $e->getMessage() );
			return '';
		}
	}

	/**
	 * Taxonomías de atributo (`pa_*`) leídas de su tabla, no del transient.
	 *
	 * @return array<int, string>
	 */
	/**
	 * Taxonomías de atributo con su LABEL real, leídas de su tabla.
	 *
	 * Paridad exacta con lo que Search entrega a
	 * `akibara_taxonomy_discount_taxonomies`: se seleccionan
	 * `attribute_id, attribute_name, attribute_label` **ordenados por
	 * attribute_id**, y el label es el real (o el slug si viene vacío). Una
	 * consulta de sólo `attribute_name`, sin orden y con el label fabricado como
	 * `pa_slug`, deja pasar este defecto: un callback del filtro que decida por
	 * label u orden vería un mapa distinto del de Search, sellaría `A` y
	 * calcularía el precio `B`.
	 *
	 * @return array<string, string> `pa_slug => label`.
	 */
	public function attribute_taxonomy_map(): array {
		if ( null !== $this->attribute_map ) {
			return $this->attribute_map;
		}

		$this->attribute_map = array();

		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			$this->authority->fail( 'No hay $wpdb para leer las taxonomías de atributo.' );
			return $this->attribute_map;
		}

		$table = $wpdb->prefix . 'woocommerce_attribute_taxonomies';
		$wpdb->last_error = '';

		try {
			$rows = $wpdb->get_results(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nombre de tabla del prefijo; la consulta no lleva valores.
				"SELECT attribute_id, attribute_name, attribute_label FROM {$table} ORDER BY attribute_id ASC",
				ARRAY_A
			);
		} catch ( \Throwable $e ) {
			$this->authority->fail( 'Lectura cruda de atributos lanzó ' . get_class( $e ) . ': ' . $e->getMessage() );
			return $this->attribute_map;
		}

		if ( '' !== (string) $wpdb->last_error ) {
			$this->authority->fail( 'Lectura cruda de atributos falló en SQL: ' . (string) $wpdb->last_error );
			return $this->attribute_map;
		}

		if ( ! is_array( $rows ) ) {
			$this->authority->fail( 'Lectura cruda de atributos devolvió una forma inválida.' );
			return $this->attribute_map;
		}

		$seen_ids   = array();
		$seen_slugs = array();

		foreach ( $rows as $row ) {
			// Forma EXACTA y dominio canónico, con la misma semántica que aplica
			// Search: id entero positivo sin ceros a la izquierda, name y label
			// strings, name no vacío tras `trim()`, label real o fallback al slug.
			if ( ! is_array( $row )
				|| array( 'attribute_id', 'attribute_name', 'attribute_label' ) !== array_keys( $row ) ) {
				$this->authority->fail( 'Fila de atributos con forma inesperada.' );
				return $this->attribute_map;
			}

			$id    = $row['attribute_id'];
			$name  = $row['attribute_name'];
			$label = $row['attribute_label'];

			if ( ! is_string( $name ) || ! is_string( $label ) ) {
				$this->authority->fail( 'Atributo con name/label de tipo inesperado.' );
				return $this->attribute_map;
			}

			$id_string = is_int( $id ) ? (string) $id : ( is_string( $id ) ? $id : '' );
			if ( 1 !== preg_match( '/^[1-9][0-9]*$/D', $id_string ) ) {
				$this->authority->fail( 'Atributo con attribute_id no canónico.' );
				return $this->attribute_map;
			}

			$name = trim( $name );
			if ( '' === $name ) {
				$this->authority->fail( 'Atributo sin attribute_name: el barrido de taxonomías sería incompleto.' );
				return $this->attribute_map;
			}

			$slug = 'pa_' . $name;

			if ( isset( $seen_ids[ $id_string ] ) ) {
				$this->authority->fail( 'Dos atributos comparten attribute_id.' );
				return $this->attribute_map;
			}
			if ( isset( $seen_slugs[ $slug ] ) ) {
				$this->authority->fail( 'Dos atributos comparten slug.' );
				return $this->attribute_map;
			}

			$seen_ids[ $id_string ]  = true;
			$seen_slugs[ $slug ]     = true;
			$this->attribute_map[ $slug ] = '' !== trim( $label ) ? $label : $slug;
		}

		$this->fingerprint_material['attributes'] = $this->attribute_map;

		return $this->attribute_map;
	}

	/**
	 * Términos de un objeto leídos por JOIN, sin pasar por el object cache.
	 *
	 * @param int                $object_id  Producto.
	 * @param array<int, string> $taxonomies Taxonomías a barrer.
	 * @return array<int, int>
	 */
	public function object_term_ids( int $object_id, array $taxonomies ): array {
		if ( $object_id <= 0 || empty( $taxonomies ) ) {
			return array();
		}

		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			$this->authority->fail( 'No hay $wpdb para leer las relaciones de términos.' );
			return array();
		}

		$placeholders = implode( ', ', array_fill( 0, count( $taxonomies ), '%s' ) );
		$relationships = $wpdb->prefix . 'term_relationships';
		$term_taxonomy = $wpdb->prefix . 'term_taxonomy';
		$wpdb->last_error = '';

		try {
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- nombres de tabla del prefijo; object_id y taxonomías van preparados.
					"SELECT tt.term_id FROM {$relationships} tr
					INNER JOIN {$term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
					WHERE tr.object_id = %d AND tt.taxonomy IN ({$placeholders})",
					// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					array_merge( array( $object_id ), array_values( $taxonomies ) )
				)
			);
		} catch ( \Throwable $e ) {
			$this->authority->fail( 'Lectura cruda de relaciones lanzó ' . get_class( $e ) . ': ' . $e->getMessage() );
			return array();
		}

		if ( '' !== (string) $wpdb->last_error ) {
			$this->authority->fail( 'Lectura cruda de relaciones falló en SQL: ' . (string) $wpdb->last_error );
			return array();
		}

		if ( ! is_array( $ids ) ) {
			$this->authority->fail( 'Lectura cruda de relaciones devolvió una forma inválida.' );
			return array();
		}

		// El object cache pudo quedar contaminado con el vacío que este JOIN
		// acaba de desmentir: se descarta para que nadie lo reutilice.
		if ( ! empty( $ids ) && function_exists( 'clean_object_term_cache' ) ) {
			clean_object_term_cache( $object_id, 'product' );
		}

		// `intval()` convierte basura en un entero plausible: '12abc' pasaría a
		// 12 y un objeto a 1. Cada fila tiene que ser un entero positivo o su
		// string decimal canónico, y la consulta ya trae DISTINCT: un duplicado
		// significa que la forma no es la esperada.
		$out  = array();
		$seen = array();
		foreach ( $ids as $value ) {
			$candidate = is_int( $value ) ? (string) $value : ( is_string( $value ) ? $value : null );

			if ( null === $candidate || 1 !== preg_match( '/^[1-9][0-9]*$/D', $candidate ) ) {
				$this->authority->fail( 'La consulta de relaciones devolvió un term_id no canónico.' );
				return array();
			}

			if ( isset( $seen[ $candidate ] ) ) {
				$this->authority->fail( 'La consulta de relaciones devolvió términos duplicados pese al DISTINCT.' );
				return array();
			}

			$seen[ $candidate ] = true;
			$out[]              = (int) $candidate;
		}

		$this->fingerprint_material[ 'terms:' . $object_id ] = $out;

		return $out;
	}

	/**
	 * Porcentaje de un término leído de `termmeta` en crudo.
	 *
	 * @param int    $term_id  Término.
	 * @param string $meta_key Clave.
	 * @param int    $max_pct  Tope editorial.
	 * @return int
	 */
	public function term_pct( int $term_id, string $meta_key, int $max_pct ): int {
		if ( $term_id <= 0 ) {
			return 0;
		}

		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			$this->authority->fail( 'No hay $wpdb para leer el term-meta de descuento.' );
			return 0;
		}

		$termmeta = $wpdb->prefix . 'termmeta';
		$wpdb->last_error = '';

		try {
			// `LIMIT 2` y no `LIMIT 1`: con meta duplicada, un LIMIT 1 elegiría
			// una fila arbitraria y el precio dependería del orden interno de
			// MySQL. Se pide una de más justamente para poder detectarlo.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT meta_id, meta_value FROM {$termmeta} WHERE term_id = %d AND meta_key = %s ORDER BY meta_id ASC LIMIT 2", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- {$termmeta} es $wpdb->prefix. 'termmeta'; term_id y meta_key sí van por marcadores.
					$term_id,
					$meta_key
				),
				ARRAY_A
			);
		} catch ( \Throwable $e ) {
			$this->authority->fail( 'Lectura cruda de term-meta lanzó ' . get_class( $e ) . ': ' . $e->getMessage() );
			return 0;
		}

		if ( '' !== (string) $wpdb->last_error ) {
			$this->authority->fail( 'Lectura cruda de term-meta falló en SQL: ' . (string) $wpdb->last_error );
			return 0;
		}

		if ( ! is_array( $rows ) ) {
			$this->authority->fail( 'Lectura cruda de term-meta devolvió una forma inválida.' );
			return 0;
		}

		if ( array() === $rows ) {
			$this->fingerprint_material[ 'pct:' . $term_id ] = null;
			return 0; // ausencia LEGÍTIMA: el término no lleva descuento.
		}

		if ( count( $rows ) > 1 ) {
			$this->authority->fail( sprintf( 'El término %d tiene term-meta de descuento duplicada: el valor es ambiguo.', $term_id ) );
			return 0;
		}

		$row = $rows[0];
		if ( ! is_array( $row ) || array( 'meta_id', 'meta_value' ) !== array_keys( $row ) ) {
			$this->authority->fail( 'Fila de term-meta con forma inesperada.' );
			return 0;
		}

		$meta_id = is_int( $row['meta_id'] ) ? (string) $row['meta_id'] : ( is_string( $row['meta_id'] ) ? $row['meta_id'] : '' );
		if ( 1 !== preg_match( '/^[1-9][0-9]*$/D', $meta_id ) ) {
			$this->authority->fail( 'Fila de term-meta con meta_id no canónico.' );
			return 0;
		}

		$raw = $row['meta_value'];
		if ( ! is_string( $raw ) ) {
			$this->authority->fail( 'El term-meta de descuento no es una cadena.' );
			return 0;
		}

		// Dominio CERRADO, no `is_numeric()`: ese acepta `1e2`, `' 15 '`, `12.5`
		// y `+15`, formas todas que cambiarían el precio tras el cast sin que
		// nadie lo note.
		if ( 1 !== preg_match( '/^(0|[1-9][0-9]*)$/D', $raw ) ) {
			$this->authority->fail( sprintf( 'El term-meta del término %d no es un entero decimal canónico.', $term_id ) );
			return 0;
		}

		$pct = max( 0, min( $max_pct, (int) $raw ) );
		$this->fingerprint_material[ 'pct:' . $term_id ] = $pct;

		// Un `term_meta` cacheado vacío que este SELECT desmiente no puede
		// sobrevivir para el resto de la petición.
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( $term_id, 'term_meta' );
		}

		return $pct;
	}

	/**
	 * Serializa validando que el encode DEVOLVIÓ algo.
	 *
	 * `wp_json_encode()` devuelve `false` ante UTF-8 inválido o profundidad
	 * excedida; hashear esa cadena vacía sellaría un token constante con
	 * autoridad verdadera.
	 *
	 * @param mixed $material Estructura a serializar.
	 * @return string Cadena vacía si no se pudo serializar.
	 */
	private function encode( $material ): string {
		$json = function_exists( 'wp_json_encode' ) ? wp_json_encode( $material ) : json_encode( $material );

		if ( ! is_string( $json ) || '' === $json ) {
			$this->authority->fail( 'No se pudo serializar la foto autoritativa (encode inválido).' );
			return '';
		}

		return $json;
	}

	/**
	 * Huella de TODO lo leído, incluidas las lecturas por producto. Sirve para
	 * diagnóstico y para demostrar que un cálculo estricto no reutilizó datos
	 * viejos.
	 */
	public function fingerprint(): string {
		$material = $this->fingerprint_material;
		ksort( $material );

		$json = $this->encode( $material );

		return '' === $json ? '' : hash( 'sha256', $json );
	}

	/**
	 * Tope editorial efectivo: el filtro `akibara_taxonomy_discount_max_pct`
	 * resuelto UNA vez, con dominio canónico y el mismo clamp 1..90 que aplica
	 * Search. Entra en el snapshot comparable porque decide el precio.
	 */
	public function max_pct(): int {
		if ( null !== $this->max_pct ) {
			return $this->max_pct;
		}

		// El tope configurado se toma de la MISMA foto raw que el resto de las
		// opciones de precio (akb_discount_max_pct está en PRICE_OPTIONS): un
		// get_option() aquí podría servir alloptions contaminado y sellar un
		// fingerprint distinto del catálogo servido — exactamente lo que este
		// lector existe para impedir. El filtro recibe ese valor como default
		// (override de código de terceros; jamás hay provider registrado).
		$foto    = $this->options( array( \Akibara\Business\Utilities\DiscountCeiling::OPTION ) );
		$default = \Akibara\Business\Utilities\DiscountCeiling::sanitize( $foto[ \Akibara\Business\Utilities\DiscountCeiling::OPTION ] ?? null );

		$value = $default;

		if ( function_exists( 'apply_filters' ) ) {
			try {
				$filtered = apply_filters( 'akibara_taxonomy_discount_max_pct', $default );
			} catch ( \Throwable $e ) {
				$this->authority->fail( 'El filtro akibara_taxonomy_discount_max_pct lanzó ' . get_class( $e ) . '.' );
				return $default;
			}

			$canonical = is_int( $filtered ) ? (string) $filtered : ( is_string( $filtered ) ? $filtered : null );
			if ( null === $canonical || 1 !== preg_match( '/^-?(0|[1-9][0-9]*)$/D', $canonical ) ) {
				$this->authority->fail( 'El filtro akibara_taxonomy_discount_max_pct devolvió una forma inválida.' );
				return $default;
			}

			$value = max( 1, min( 90, (int) $canonical ) );
		}

		$this->max_pct = $value;
		$this->fingerprint_material['max_pct'] = $value;

		return $value;
	}

	/**
	 * Mapa efectivo `slug => label` tras el filtro, con la MISMA semántica que
	 * `EditorialDiscountPublication::effective_taxonomies_from_attribute_rows()`
	 * de Search: core + atributos existentes, filtro aplicado una vez, y slugs
	 * `trim()`, únicos y ordenados.
	 *
	 * @return array<string, string>
	 */
	public function effective_taxonomy_map(): array {
		if ( null !== $this->effective_map ) {
			return $this->effective_map;
		}

		$map = array();
		foreach ( array( 'product_brand' => 'Editoriales', 'product_cat' => 'Categorías', 'product_tag' => 'Etiquetas' ) as $slug => $label ) {
			if ( function_exists( 'taxonomy_exists' ) && taxonomy_exists( $slug ) ) {
				$map[ $slug ] = $label;
			}
		}

		foreach ( $this->attribute_taxonomy_map() as $slug => $label ) {
			if ( function_exists( 'taxonomy_exists' ) && taxonomy_exists( $slug ) ) {
				$map[ $slug ] = $label;
			}
		}

		try {
			$filtered = function_exists( 'apply_filters' )
				? apply_filters( 'akibara_taxonomy_discount_taxonomies', $map )
				: $map;
		} catch ( \Throwable $e ) {
			$this->authority->fail( 'El filtro akibara_taxonomy_discount_taxonomies lanzó ' . get_class( $e ) . '.' );
			$this->effective_map = array();
			return $this->effective_map;
		}

		if ( ! is_array( $filtered ) ) {
			$this->authority->fail( 'El filtro akibara_taxonomy_discount_taxonomies devolvió una forma inválida.' );
			$this->effective_map = array();
			return $this->effective_map;
		}

		$clean = array();
		foreach ( $filtered as $slug => $label ) {
			if ( ! is_string( $slug ) || '' === trim( $slug ) ) {
				$this->authority->fail( 'El mapa efectivo de taxonomías tiene un slug vacío o de tipo inválido.' );
				$this->effective_map = array();
				return $this->effective_map;
			}
			$clean[ trim( $slug ) ] = is_string( $label ) ? $label : trim( $slug );
		}

		$this->effective_map = $clean;

		$slugs = array_keys( $clean );
		sort( $slugs, SORT_STRING );
		$this->effective_taxonomies = $slugs;
		$this->fingerprint_material['taxonomies'] = $slugs;

		return $this->effective_map;
	}

	/**
	 * Slugs efectivos, únicos y ordenados: la lista comparable con Search.
	 *
	 * @return array<int, string>
	 */
	public function effective_taxonomies(): array {
		if ( null === $this->effective_taxonomies ) {
			$this->effective_taxonomy_map();
		}

		return $this->effective_taxonomies ?? array();
	}

	/**
	 * Zona horaria efectiva como cadena comparable.
	 */
	public function effective_timezone(): string {
		$options  = $this->options( array( 'timezone_string', 'gmt_offset' ) );
		$timezone = (string) ( $options['timezone_string'] ?? '' );

		if ( '' !== $timezone ) {
			return $timezone;
		}

		$offset = $options['gmt_offset'] ?? null;

		return ( null === $offset || '' === $offset ) ? 'UTC' : 'UTC' . $offset;
	}

	/**
	 * Devuelve un snapshot canónico comparable con la autoridad de Search.
	 *
	 * Las claves ordenadas y los valores efectivos se comparan literalmente antes
	 * y después de la operación. El token debe incluir toda configuración relevante:
	 * una huella interna estable no prueba igualdad entre callers de max_pct,
	 * taxonomías u otros ajustes efectivos.
	 *
	 * @return array<string, mixed>
	 */
	public function canonical_snapshot(): array {
		$options = $this->options( self::PRICE_OPTIONS );
		ksort( $options );

		$effective_options = array();
		foreach ( $options as $name => $value ) {
			$effective_options[ $name ] = null === $value ? '' : $value;
		}

		return array(
			'decimals'   => $this->price_decimals(),
			'max_pct'    => $this->max_pct(),
			'options'    => $effective_options,
			'taxonomies' => $this->effective_taxonomies(),
			'timezone'   => $this->effective_timezone(),
		);
	}

	/**
	 * Huella del SNAPSHOT canónico. Es lo que respalda el token que el consumidor
	 * guarda, y por eso excluye las lecturas por producto: si los términos de
	 * cada producto entraran, el token cambiaría en cada llamada.
	 */
	public function config_fingerprint(): string {
		$json = $this->encode( $this->canonical_snapshot() );

		return '' === $json ? '' : hash( 'sha256', $json );
	}
}
