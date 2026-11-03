<?php
/**
 * SemanticPublication — publicación durable de cambios de configuración.
 *
 * Las options semánticas cambian lo que el buscador sirve sin necesariamente
 * tocar sus tablas derivadas. Un bump post-write simple deja una ventana de
 * pérdida si PHP cae entre ambos writes. Este protocolo persiste una barrera
 * antes del source write, confirma exactamente ese target después del write y
 * conserva la deuda para init/watchdog si el request muere.
 *
 * Un fingerprint leído directamente desde wp_options cierra además los bypass
 * inevitables de los hooks de Core (SQL directo, restore parcial, activation
 * temprana): ningún fingerprint se sella hasta que una generación y su purge
 * fueron verificados físicamente después de observarlo.
 *
 * @package Akibara\Search
 */

declare( strict_types = 1 );

namespace Akibara\Search;

defined( 'ABSPATH' ) || exit;

/**
 * @since 0.1.14
 */
final class SemanticPublication {

	/** Receipt del último fingerprint semántico físicamente publicado. */
	public const OPTION_FINGERPRINT = 'akb_search_semantic_fingerprint';

	private const FINGERPRINT_PROTOCOL = 1;
	private const PREWRITE_DELAY       = 10;

	/**
	 * Barreras creadas en esta request. Es un stack por option para que una
	 * actualización anidada confirme su propio target y no el de su caller.
	 *
	 * @var array<string, array<int, array{operation: string, value_hash: string, identity: array{token: string, seq: int, target: string, not_before: int}}>>
	 */
	private array $pending = array();

	private bool $reconciling = false;

	/** Foto raw request-local consumida por todos los lectores query-time. */
	private static bool $authoritative_loaded = false;

	/** La foto request-local sólo se vuelve false; nunca se recupera en el request. */
	private static bool $authoritative_available = true;

	/** Primer diagnóstico de la foto query-time. */
	private static string $authoritative_error = '';

	/** @var array<string, string> Bytes exactos presentes en wp_options. */
	private static array $authoritative_raw = array();

	/**
	 * Options cuyo valor cambia la interpretación, facetas o cachés servidos.
	 * La whitelist es cerrada para no enganchar nunca INDEX_GEN/OUTBOX y entrar
	 * en recursión durante la propia publicación.
	 *
	 * @return array<int, string>
	 */
	public static function options(): array {
		return array(
			Synonyms::OPTION,
			Stopwords::OPTION,
			Fuzzy::OPTION,
			'akb_search_sku_fastpath',
			FacetRegistry::OPTION_KEY,
			FacetRegistry::OPTION_PRECIO_BUCKETS,
			PriceBuckets::OPTION_MODE,
			Novedad::OPTION,
			'akb_search_popular_enabled',
			'default_product_cat',
			'akb_search_indexing',
			'akb_search_suggest',
			'akb_search_facets',
		);
	}

	/**
	 * Valor query-time desde una única foto raw de wp_options por request.
	 * Un error SQL cierra la autoridad global; los consumidores pueden devolver
	 * su default local, pero el token de publicación impedirá servir/cachear ese
	 * resultado como autoritativo.
	 *
	 * @param mixed $default Valor si la fila no existe.
	 * @return mixed
	 */
	public static function authoritative_value( string $name, $default = null ) {
		if ( ! in_array( $name, self::authoritative_names(), true ) ) {
			self::mark_authoritative_unavailable( 'Unmanaged semantic option requested: ' . $name );
			return $default;
		}
		if ( ! self::$authoritative_loaded ) {
			$raw = self::read_raw_options( self::authoritative_names() );
			self::$authoritative_loaded = true;
			if ( ! is_array( $raw ) ) {
				self::mark_authoritative_unavailable( 'Could not read the semantic option snapshot.' );
				return $default;
			}
			self::$authoritative_raw = $raw;
			if ( ! self::valid_raw_snapshot( $raw ) ) {
				self::mark_authoritative_unavailable( 'Semantic option snapshot contains invalid bytes or values.' );
				return $default;
			}
		}
		if ( ! self::$authoritative_available || ! array_key_exists( $name, self::$authoritative_raw ) ) {
			return $default;
		}

		$decoded = self::decode_raw_option( self::$authoritative_raw[ $name ] );
		if ( ! $decoded['valid'] || ! self::valid_authoritative_value( $name, $decoded['value'] ) ) {
			self::mark_authoritative_unavailable( 'Semantic option has invalid serialized bytes: ' . $name );
			return $default;
		}
		return $decoded['value'];
	}

	/** Autoridad de la foto query-time vigente. */
	public static function authoritative_available(): bool {
		return self::$authoritative_available;
	}

	/** Primer error de la foto query-time. */
	public static function authoritative_error(): string {
		return self::$authoritative_error;
	}

	/** Invalida la foto tras un write sancionado; nunca revive un fallo observado. */
	public static function reset_authoritative_snapshot(): void {
		self::$authoritative_loaded = false;
		self::$authoritative_raw    = array();
	}

	/**
	 * Reinicia también el latch sticky entre casos PHPUnit. No existe en un
	 * runtime normal: una request productiva jamás puede revivir tras un fallo.
	 *
	 * @internal
	 */
	public static function reset_authoritative_state_for_tests(): void {
		if ( ! defined( 'AKIBARA_SEARCH_TESTING' ) || ! AKIBARA_SEARCH_TESTING ) {
			return;
		}
		self::$authoritative_loaded    = false;
		self::$authoritative_available = true;
		self::$authoritative_error      = '';
		self::$authoritative_raw        = array();
	}

	/** Hash de la foto request-local que consumen motor/facetas/cache. */
	public static function request_runtime_fingerprint(): ?string {
		// Carga la foto sin exigir que ninguna option concreta esté presente.
		self::authoritative_value( Install::OPTION_INDEX_GEN, null );
		return self::$authoritative_available && self::$authoritative_loaded
			? self::hash_raw_snapshot( self::authoritative_names(), self::$authoritative_raw )
			: null;
	}

	/** Fingerprint semántico de la misma foto request-local. */
	public static function request_semantic_fingerprint(): ?string {
		self::authoritative_value( Install::OPTION_INDEX_GEN, null );
		return self::$authoritative_available && self::$authoritative_loaded
			? self::hash_raw_snapshot( self::options(), self::$authoritative_raw )
			: null;
	}

	/** @internal Nombres exactos para el snapshot batched de ServingLease. */
	public static function authoritative_names_for_snapshot(): array {
		return self::authoritative_names();
	}

	/**
	 * Deriva ambos hashes desde bytes ya leídos en una sola query compartida.
	 * No toca DB ni object cache.
	 *
	 * @param array<string, string> $bundle Bundle que puede contener keys extra.
	 * @return array{runtime: string, semantic: string, raw: array<string, string>}|null
	 * @internal
	 */
	public static function fingerprints_from_raw_bundle( array $bundle ): ?array {
		$raw = array_intersect_key( $bundle, array_fill_keys( self::authoritative_names(), true ) );
		if ( ! self::valid_raw_snapshot( $raw ) ) {
			return null;
		}
		return array(
			'runtime'  => self::hash_raw_snapshot( self::authoritative_names(), $raw ),
			'semantic' => self::hash_raw_snapshot( self::options(), $raw ),
			'raw'      => $raw,
		);
	}

	/**
	 * Hace que los lectores query-time consuman exactamente el mismo bundle que
	 * autorizó ServingLease, sin una segunda query divergente.
	 *
	 * @param array<string, string> $raw Bytes semánticos filtrados.
	 * @internal
	 */
	public static function adopt_authoritative_snapshot( array $raw ): bool {
		if ( ! self::$authoritative_available || ! self::valid_raw_snapshot( $raw ) ) {
			self::mark_authoritative_unavailable( 'Could not adopt the serving semantic snapshot.' );
			return false;
		}
		// Consumidores pueden haberse memoizado temprano desde una foto A (antes
		// de init:24). Vaciar todos los derivados ANTES de instalar B impide que
		// token/final B convivan con sinónimos, stopwords o facetas A. Ningún
		// reset revive latches: FacetRegistry conserva su autoridad sticky.
		try {
			Synonyms::flush();
			Stopwords::flush();
			Fuzzy::flush();
			FacetRegistry::invalidate_runtime_memo();
			Novedad::reset_cache();
		} catch ( \Throwable $exception ) {
			self::mark_authoritative_unavailable( 'Could not invalidate derived semantic memos.' );
			return false;
		}
		self::$authoritative_raw    = array_intersect_key( $raw, array_fill_keys( self::authoritative_names(), true ) );
		self::$authoritative_loaded = true;
		return true;
	}

	/**
	 * Registra el protocolo sobre los hooks genéricos de Core. Los pre-hooks de
	 * add/delete son actions y no pueden cancelar: ante ausencia de deuda durable
	 * se lanza antes del SQL. En update, el filtro devuelve el valor anterior y
	 * Core omite el write sin convertir una falla operativa en fatal del sitio.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'pre_update_option', array( $this, 'filter_pre_update' ), PHP_INT_MAX, 3 );
		add_action( 'add_option', array( $this, 'before_add' ), 1, 2 );
		add_action( 'delete_option', array( $this, 'before_delete' ), 1, 1 );

		add_action( 'updated_option', array( $this, 'after_update' ), PHP_INT_MAX, 3 );
		add_action( 'added_option', array( $this, 'after_add' ), PHP_INT_MAX, 2 );
		add_action( 'deleted_option', array( $this, 'after_delete' ), PHP_INT_MAX, 1 );

		// Repara SQL directo o un crash entre source write y post-hook antes de
		// que el front consulte en esta misma request.
		add_action( 'init', array( $this, 'reconcile_fingerprint' ), 23 );
	}

	/**
	 * @param mixed  $value     Valor final filtrado que Core intentará guardar.
	 * @param string $option    Nombre de option.
	 * @param mixed  $old_value Valor previo.
	 * @return mixed
	 */
	public function filter_pre_update( $value, string $option, $old_value ) {
		if ( ! $this->is_managed( $option ) || $this->same_value( $value, $old_value ) ) {
			return $value;
		}
		if ( $this->price_buckets_manage_this_write( $option ) ) {
			return $value;
		}

		return $this->stage( $option, 'update', $this->value_hash( $value ) ) ? $value : $old_value;
	}

	/**
	 * @param string $option Nombre de option.
	 * @param mixed  $value  Valor a insertar.
	 * @return void
	 */
	public function before_add( string $option, $value ): void {
		if ( ! $this->is_managed( $option ) || $this->price_buckets_manage_this_write( $option ) ) {
			return;
		}

		$value_hash = $this->value_hash( $value );
		$top        = $this->top_frame( $option );
		// update_option() ausente cae en add_option() dentro del mismo call. La
		// barrera del filtro update ya es suficiente y debe confirmarse aquí.
		if ( is_array( $top ) && 'update' === $top['operation'] && $value_hash === $top['value_hash'] ) {
			$last = count( $this->pending[ $option ] ) - 1;
			$this->pending[ $option ][ $last ]['operation'] = 'add';
			return;
		}

		if ( ! $this->stage( $option, 'add', $value_hash ) ) {
			self::record_unfenced_write( $option, 'add' );
		}
	}

	/**
	 * @param string $option Nombre de option.
	 * @return void
	 */
	public function before_delete( string $option ): void {
		if ( ! $this->is_managed( $option ) || $this->price_buckets_manage_this_write( $option ) ) {
			return;
		}
		if ( ! $this->stage( $option, 'delete', '__deleted__' ) ) {
			self::record_unfenced_write( $option, 'delete' );
		}
	}

	/**
	 * Un alta o una baja que no se pudo cercar: se anota y se deja pasar.
	 *
	 * `add_option` y `delete_option` son acciones de WordPress (no permiten veto
	 * retornando false). Si un fallo transitorio de base de datos impide a `stage()`
	 * registrar la intención, no se debe abortar con una excepción no capturada
	 * que interrumpiría la petición del usuario en el panel.
	 *
	 * En su lugar, se registra como escritura no cercada (`record_unfenced_write()`).
	 * Posteriormente `complete()` solicita la publicación o rebuild correspondiente, y
	 * `reconcile_fingerprint()` (en el hook `init`) reconcilia cualquier discrepancia
	 * entre la huella real y la huella sellada de configuración.
	 *
	 * @param string $option    Option gestionada.
	 * @param string $operation 'add' o 'delete'.
	 * @return void
	 */
	private static function record_unfenced_write( string $option, string $operation ): void {
		AuditLog::record(
			'option_semantica_sin_cercar',
			sprintf(
				'%s de %s sin intención previa persistida: la huella lo detecta y el reconcile monta la deuda.',
				'add' === $operation ? 'Alta' : 'Baja',
				$option
			)
		);
	}

	/** @param string $option Option. @param mixed $old_value Previo. @param mixed $value Nuevo. @return void */
	public function after_update( string $option, $old_value, $value ): void {
		unset( $old_value );
		if ( Install::OPTION_INDEX_GEN === $option ) {
			self::advance_generation_receipt( (string) $value );
			return;
		}
		unset( $value );
		$this->complete( $option, 'update' );
	}

	/** @param string $option Option. @param mixed $value Valor. @return void */
	public function after_add( string $option, $value ): void {
		if ( Install::OPTION_INDEX_GEN === $option ) {
			self::advance_generation_receipt( (string) $value );
			return;
		}
		unset( $value );
		$this->complete( $option, 'add' );
	}

	/** @param string $option Option. @return void */
	public function after_delete( string $option ): void {
		if ( Install::OPTION_INDEX_GEN === $option ) {
			$this->reconcile_fingerprint();
			return;
		}
		$this->complete( $option, 'delete' );
	}

	/**
	 * Detecta cualquier bypass a hooks comparando bytes crudos de todas las
	 * options. El target deriva del fingerprint para que retries sean idempotentes.
	 *
	 * @return bool True si el fingerprint ya estaba o quedó publicado/sellado.
	 */
	public function reconcile_fingerprint(): bool {
		if ( $this->reconciling ) {
			return false;
		}
		$this->reconciling = true;
		try {
			$before = self::current_fingerprint();
			if ( null === $before ) {
				return false;
			}
			$receipt = get_option( self::OPTION_FINGERPRINT, null );
			if ( self::receipt_matches( $receipt, $before ) ) {
				return true;
			}

			$identity = Install::request_generation_publication(
				'semantic_fingerprint_reconcile',
				'akb-sem-' . substr( $before, 0, 40 ),
				0
			);
			if ( ! is_array( $identity )
				|| ! Install::consume_generation_publications()
				|| ! Install::generation_publication_satisfied( $identity ) ) {
				return false;
			}

			return self::seal_if_unchanged( $before, (string) $identity['target'] );
		} finally {
			$this->reconciling = false;
		}
	}

	/**
	 * @param string $option    Option semántica.
	 * @param string $operation update|add|delete.
	 * @param string $value_hash Hash del valor intentado.
	 * @return bool
	 */
	private function stage( string $option, string $operation, string $value_hash ): bool {
		$identity = Install::request_generation_publication(
			'semantic_' . $operation . '_pre_' . $option,
			null,
			self::PREWRITE_DELAY
		);
		if ( ! is_array( $identity ) ) {
			return false;
		}

		// La definición de facetas alimenta el espejo materializado. Solicitar el
		// rebuild antes del source write elimina también su crash gap para todo
		// escritor (UI, CLI, código), no sólo para el handler administrativo.
		if ( self::feeds_the_mirror( $option )
			&& ! Install::request_rebuild( 'facet_registry_changed', time() + self::PREWRITE_DELAY ) ) {
			return false;
		}

		$this->pending[ $option ][] = array(
			'operation'  => $operation,
			'value_hash' => $value_hash,
			'identity'   => $identity,
		);
		return true;
	}

	/**
	 * ¿Esta option define el espejo materializado de filtros? Cambiarla exige
	 * reconstruirlo, no sólo invalidar cachés.
	 *
	 * @param string $option Option gestionada.
	 * @return bool
	 */
	private static function feeds_the_mirror( string $option ): bool {
		return FacetRegistry::OPTION_KEY === $option || 'default_product_cat' === $option;
	}

	/**
	 * Confirma el target LIFO correlacionado. Si no existe frame (hook cargado
	 * tarde, callback manual o estado inesperado), crea una intención inmediata
	 * de fallback; nunca interpreta la ausencia como una publicación exitosa.
	 *
	 * @return void
	 */
	private function complete( string $option, string $operation ): void {
		if ( ! $this->is_managed( $option ) ) {
			return;
		}
		$this->flush_runtime_memos( $option );
		if ( $this->price_buckets_manage_this_write( $option ) ) {
			return;
		}

		$before = self::current_fingerprint();
		$frame  = $this->pop_matching_frame( $option, $operation );
		$target = is_array( $frame ) ? (string) $frame['identity']['target'] : null;

		// Sin frame no existió un `stage()` previo, por lo que no se solicitó
		// el rebuild que actualiza el espejo de facetas. Dado que la simple
		// invalidación de caché no reconstruye la tabla de facetas, cuando la
		// opción afecta el espejo se solicita el rebuild explícitamente tras la
		// persistencia en base de datos.
		if ( ! is_array( $frame ) && self::feeds_the_mirror( $option )
			&& ! Install::request_rebuild( 'facet_registry_changed', time() ) ) {
			AuditLog::record(
				'rebuild_de_filtros_no_pedido',
				sprintf( 'Cambió %s sin intención previa y no se pudo pedir el rebuild: los filtros siguen con la definición anterior hasta reconstruir el índice a mano.', $option )
			);
		}
		$post   = Install::request_generation_publication(
			'semantic_' . $operation . '_post_' . $option,
			$target,
			0
		);
		if ( ! is_array( $post )
			|| ! Install::consume_generation_publications()
			|| ! Install::generation_publication_satisfied( $post )
			|| null === $before ) {
			return; // La intención pre-write queda durable y el init la recupera.
		}

		self::seal_if_unchanged( $before, (string) $post['target'] );
	}

	/**
	 * Sella una mutación gestionada fuera de los hooks (PriceBuckets/rebuild).
	 * El caller debe tomar el fingerprint DESPUÉS de su source write pero ANTES
	 * de publicar `$generation`; acá se revalidan target, purge y foto para no
	 * acreditar una mutación concurrente posterior a la invalidación.
	 *
	 * @param string $fingerprint Foto pre-publicación.
	 * @param string $generation  Target ya publicado.
	 * @return bool
	 */
	public static function seal_external_fingerprint( string $fingerprint, string $generation ): bool {
		return 1 === preg_match( '/^[a-f0-9]{64}$/', $fingerprint )
			&& '' !== $generation
			&& $generation === (string) get_option( Install::OPTION_INDEX_GEN, '' )
			&& Support\RestCache::has_purge_receipt( $generation )
			&& self::seal_if_unchanged( $fingerprint, $generation );
	}

	/**
	 * @param string $fingerprint Foto tomada antes de publicar.
	 * @param string $generation  Target físicamente verificado.
	 * @return bool
	 */
	private static function seal_if_unchanged( string $fingerprint, string $generation ): bool {
		if ( $fingerprint !== self::current_fingerprint() ) {
			return false;
		}
		$receipt = array(
			'protocol'     => self::FINGERPRINT_PROTOCOL,
			'fingerprint'  => $fingerprint,
			'generation'   => $generation,
			'published_at' => time(),
		);
		update_option( self::OPTION_FINGERPRINT, $receipt, false );
		$sealed = self::receipt_matches( get_option( self::OPTION_FINGERPRINT, null ), $fingerprint );
		if ( $sealed && class_exists( EditorialDiscountPublication::class ) && EditorialDiscountPublication::is_registered() ) {
			EditorialDiscountPublication::advance_generation_receipt( $generation );
		}
		return $sealed;
	}

	/**
	 * Una generación posterior también invalida caches con la misma semántica.
	 * Encadena el receipt a ese efecto físico para que el fast-path pueda exigir
	 * generación+purge ACTUALES sin republicar después de cada write live.
	 *
	 * @param string $generation Nueva generación persistida.
	 * @return bool
	 */
	public static function advance_generation_receipt( string $generation ): bool {
		if ( '' === $generation || ! Support\RestCache::has_purge_receipt( $generation ) ) {
			return false;
		}
		$fingerprint = self::current_fingerprint();
		$receipt     = get_option( self::OPTION_FINGERPRINT, null );
		if ( null === $fingerprint || ! self::valid_receipt_shape( $receipt )
			|| ! hash_equals( $fingerprint, (string) $receipt['fingerprint'] ) ) {
			return false;
		}

		$receipt['generation']   = $generation;
		$receipt['published_at'] = time();
		update_option( self::OPTION_FINGERPRINT, $receipt, false );
		$advanced = self::receipt_matches( get_option( self::OPTION_FINGERPRINT, null ), $fingerprint );
		if ( $advanced && class_exists( EditorialDiscountPublication::class ) && EditorialDiscountPublication::is_registered() ) {
			EditorialDiscountPublication::advance_generation_receipt( $generation );
		}
		return $advanced;
	}

	/**
	 * Fingerprint byte-exacto leído sin object cache; presencia/ausencia forma
	 * parte del payload, por lo que delete→defaults nunca colisiona con false.
	 *
	 * @return string|null null ante error de lectura.
	 */
	public static function current_fingerprint(): ?string {
		$names = self::options();
		sort( $names, SORT_STRING );
		$raw = self::read_raw_options( $names );
		return is_array( $raw ) && self::valid_raw_snapshot( $raw ) ? self::hash_raw_snapshot( $names, $raw ) : null;
	}

	/**
	 * ¿Hay un ajuste del buscador guardado que todavía no se publicó?
	 *
	 * Mientras la foto viva no tenga su receipt sellado, el gate de servido
	 * (`bundle_is_ready()`) sigue cerrado, y el panel lo atribuía a «precios y
	 * facetas» porque la única causa que sabía nombrar era la editorial.
	 *
	 * @return bool|null null si la foto no se pudo leer.
	 */
	public static function publication_pending(): ?bool {
		$current = self::current_fingerprint();

		return null === $current ? null : ! self::receipt_matches( get_option( self::OPTION_FINGERPRINT, null ), $current );
	}

	/** @param mixed $receipt Receipt. @return bool */
	private static function receipt_matches( $receipt, string $fingerprint ): bool {
		return self::valid_receipt_shape( $receipt )
			&& hash_equals( $fingerprint, (string) $receipt['fingerprint'] )
			&& (string) $receipt['generation'] === (string) get_option( Install::OPTION_INDEX_GEN, '' )
			&& Support\RestCache::has_purge_receipt( (string) $receipt['generation'] );
	}

	/** @return array<int, string> */
	private static function authoritative_names(): array {
		$names   = self::options();
		$names[] = Install::OPTION_INDEX_GEN;
		$names   = array_values( array_unique( $names, SORT_STRING ) );
		sort( $names, SORT_STRING );
		return $names;
	}

	/**
	 * Lee bytes directos de wp_options, sin object cache. El fallback existe
	 * sólo para harnesses aislados; producción siempre dispone de wpdb completo.
	 *
	 * @param array<int, string> $names Nombres exactos.
	 * @return array<string, string>|null
	 */
	private static function read_raw_options( array $names ): ?array {
		$names = array_values( array_unique( array_filter( $names, 'is_string' ), SORT_STRING ) );
		if ( empty( $names ) ) {
			return array();
		}

		global $wpdb;
		if ( is_object( $wpdb ) && isset( $wpdb->options )
			&& method_exists( $wpdb, 'prepare' ) && method_exists( $wpdb, 'get_results' ) ) {
			try {
				$placeholders     = implode( ', ', array_fill( 0, count( $names ), '%s' ) );
				$wpdb->last_error = '';
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- {$wpdb->options} es la tabla de WordPress y los marcadores viven dentro de {$placeholders}, generado una línea antes con un %s por nombre; los valores van en ...$names.
				$sql  = $wpdb->prepare( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name IN ({$placeholders}) ORDER BY option_name ASC", ...$names );
				if ( ! is_string( $sql ) || '' === $sql ) {
					return null;
				}
				$rows = $wpdb->get_results( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql viene de $wpdb->prepare() cuatro líneas arriba; se guarda en variable justo para comprobar is_string().
			} catch ( \Throwable $throwable ) {
				return null;
			}
			if ( ! is_array( $rows ) || '' !== trim( (string) ( $wpdb->last_error ?? '' ) ) ) {
				return null;
			}
			$out = array();
			foreach ( $rows as $row ) {
				$name = is_object( $row ) ? ( $row->option_name ?? null ) : ( is_array( $row ) ? ( $row['option_name'] ?? null ) : null );
				$raw  = is_object( $row ) ? ( $row->option_value ?? null ) : ( is_array( $row ) ? ( $row['option_value'] ?? null ) : null );
				if ( ! is_string( $name ) || ! in_array( $name, $names, true ) || ! is_string( $raw ) || array_key_exists( $name, $out ) ) {
					return null;
				}
				$out[ $name ] = $raw;
			}
			return $out;
		}

		if ( ! function_exists( 'get_option' ) ) {
			return null;
		}
		$out = array();
		foreach ( $names as $name ) {
			$missing = new \stdClass();
			if ( is_object( $wpdb ) && property_exists( $wpdb, 'last_error' ) ) {
				$wpdb->last_error = '';
			}
			try {
				$value = get_option( $name, $missing );
			} catch ( \Throwable $throwable ) {
				return null;
			}
			if ( is_object( $wpdb ) && property_exists( $wpdb, 'last_error' )
				&& '' !== trim( (string) $wpdb->last_error ) ) {
				return null;
			}
			if ( $missing !== $value ) {
				$out[ $name ] = self::serialize_value( $value );
			}
		}
		return $out;
	}

	/** @param array<int, string> $names @param array<string, string> $raw */
	private static function hash_raw_snapshot( array $names, array $raw ): string {
		sort( $names, SORT_STRING );
		$payload = '';
		foreach ( $names as $name ) {
			$payload .= pack( 'N', strlen( $name ) ) . $name;
			if ( array_key_exists( $name, $raw ) ) {
				$payload .= "\x01" . pack( 'N', strlen( $raw[ $name ] ) ) . $raw[ $name ];
			} else {
				$payload .= "\x00";
			}
		}
		return hash( 'sha256', $payload );
	}

	/** @return array{valid: bool, value: mixed} */
	private static function decode_raw_option( string $raw ): array {
		if ( 'N;' === $raw ) {
			return array( 'valid' => true, 'value' => null );
		}
		if ( 1 === preg_match( '/^(?:a|O|C|s|i|d|b):/', $raw ) ) {
			$value = @unserialize( $raw, array( 'allowed_classes' => false ) );
			return array(
				'valid' => ( false !== $value || 'b:0;' === $raw )
					&& serialize( $value ) === $raw
					&& ! is_object( $value ),
				'value' => $value,
			);
		}
		return array( 'valid' => true, 'value' => $raw );
	}

	/** @param mixed $value */
	private static function valid_authoritative_value( string $name, $value ): bool {
		$yes_no = array(
			Fuzzy::OPTION,
			'akb_search_sku_fastpath',
			Novedad::OPTION,
			'akb_search_popular_enabled',
			'akb_search_indexing',
			'akb_search_suggest',
			'akb_search_facets',
		);
		if ( in_array( $name, $yes_no, true ) ) {
			return is_string( $value ) && in_array( $value, array( 'yes', 'no' ), true );
		}
		if ( PriceBuckets::OPTION_MODE === $name ) {
			return is_string( $value ) && in_array( $value, array( 'auto', 'manual' ), true );
		}
		if ( 'default_product_cat' === $name ) {
			return ( is_int( $value ) || ( is_string( $value ) && 1 === preg_match( '/^\d+$/D', $value ) ) )
				&& (int) $value >= 0;
		}
		if ( Install::OPTION_INDEX_GEN === $name ) {
			return is_string( $value ) && '' !== $value;
		}
		if ( Synonyms::OPTION === $name ) {
			return is_array( $value ) && Synonyms::configuration_is_canonical( $value );
		}
		if ( Stopwords::OPTION === $name ) {
			if ( ! is_array( $value ) ) {
				return false;
			}
			foreach ( $value as $word ) {
				if ( ! Stopwords::word_is_valid( $word ) ) {
					return false;
				}
			}
			return true;
		}
		if ( FacetRegistry::OPTION_PRECIO_BUCKETS === $name ) {
			return is_array( $value ) && ( array() === $value || null === FacetRegistry::validate_precio_buckets( $value ) );
		}
		if ( FacetRegistry::OPTION_KEY === $name ) {
			return self::valid_facet_overrides( $value );
		}
		return false;
	}

	/** @param array<string, string> $raw */
	private static function valid_raw_snapshot( array $raw ): bool {
		$allowed = self::authoritative_names();
		foreach ( $raw as $name => $bytes ) {
			if ( ! is_string( $name ) || ! in_array( $name, $allowed, true ) || ! is_string( $bytes ) ) {
				return false;
			}
			$decoded = self::decode_raw_option( $bytes );
			if ( ! $decoded['valid'] || ! self::valid_authoritative_value( $name, $decoded['value'] ) ) {
				return false;
			}
		}
		return true;
	}

	/** @param mixed $value */
	private static function valid_facet_overrides( $value ): bool {
		if ( ! is_array( $value ) || count( $value ) > FacetRegistry::MAX_FACETS ) {
			return false;
		}
		$seed_contracts = array(
			'categoria'      => array( 'type' => 'taxonomy', 'taxonomy' => 'product_cat' ),
			'demografia'     => array( 'type' => 'taxonomy', 'taxonomy' => 'product_cat' ),
			'sello'          => array( 'type' => 'taxonomy', 'taxonomy' => 'product_cat' ),
			'editorial'      => array( 'type' => 'taxonomy', 'taxonomy' => 'product_brand' ),
			'pais'           => array( 'type' => 'taxonomy', 'taxonomy' => 'pa_pais' ),
			'genero'         => array( 'type' => 'taxonomy', 'taxonomy' => 'pa_genero' ),
			'autor'          => array( 'type' => 'taxonomy', 'taxonomy' => 'pa_autor' ),
			'serie'          => array( 'type' => 'taxonomy', 'taxonomy' => 'pa_serie' ),
			'disponibilidad' => array( 'type' => 'stock', 'taxonomy' => '' ),
			'oferta'         => array( 'type' => 'oferta', 'taxonomy' => '' ),
			'novedad'        => array( 'type' => 'novedad', 'taxonomy' => '' ),
			'precio'         => array( 'type' => 'precio', 'taxonomy' => '' ),
		);
		$allowed = array( 'visible', 'order', 'label', 'ui', 'type', 'taxonomy', 'parent_term', 'hierarchical', 'root_only' );
		foreach ( $value as $name => $definition ) {
			if ( ! FacetRegistry::name_is_valid( $name ) || ! is_array( $definition )
				|| array() !== array_diff( array_keys( $definition ), $allowed ) ) {
				return false;
			}
			$is_seed = isset( $seed_contracts[ $name ] );
			$type    = $is_seed ? $seed_contracts[ $name ]['type'] : ( $definition['type'] ?? null );
			$taxonomy = $is_seed ? $seed_contracts[ $name ]['taxonomy'] : ( $definition['taxonomy'] ?? null );
			if ( $is_seed ) {
				if ( array_key_exists( 'type', $definition ) && $definition['type'] !== $type ) {
					return false;
				}
				if ( array_key_exists( 'taxonomy', $definition ) && $definition['taxonomy'] !== $taxonomy ) {
					return false;
				}
			} elseif ( 'taxonomy' !== $type || ! is_string( $taxonomy )
				|| 1 !== preg_match( '/^[a-z0-9_\-]{1,64}$/D', $taxonomy ) ) {
				return false;
			}
			if ( array_key_exists( 'visible', $definition ) && ! is_bool( $definition['visible'] ) ) {
				return false;
			}
			if ( array_key_exists( 'order', $definition ) && ( ! is_int( $definition['order'] ) || $definition['order'] < 0 || $definition['order'] > 10000 ) ) {
				return false;
			}
			if ( array_key_exists( 'label', $definition ) && ( ! is_string( $definition['label'] ) || '' === trim( $definition['label'] ) || mb_strlen( $definition['label'] ) > FacetRegistry::MAX_LABEL_LENGTH ) ) {
				return false;
			}
			if ( ! $is_seed && ( ! isset( $definition['label'] ) || ! isset( $definition['ui'] ) ) ) {
				return false;
			}
			if ( array_key_exists( 'ui', $definition ) && ! in_array( $definition['ui'], array( 'checkboxes', 'radio', 'alpha' ), true ) ) {
				return false;
			}
			if ( array_key_exists( 'parent_term', $definition ) ) {
				if ( '' !== $definition['parent_term']
					&& ( ! is_int( $definition['parent_term'] ) || $definition['parent_term'] <= 0 || 'taxonomy' !== $type ) ) {
					return false;
				}
			}
			foreach ( array( 'hierarchical', 'root_only' ) as $boolean ) {
				if ( array_key_exists( $boolean, $definition ) && ( 'taxonomy' !== $type || ! is_bool( $definition[ $boolean ] ) ) ) {
					return false;
				}
			}
		}
		return true;
	}

	private static function mark_authoritative_unavailable( string $error ): void {
		if ( self::$authoritative_available ) {
			self::$authoritative_error = $error;
		}
		self::$authoritative_available = false;
	}

	/** @param mixed $receipt Receipt. @return bool */
	private static function valid_receipt_shape( $receipt ): bool {
		return is_array( $receipt )
			&& self::FINGERPRINT_PROTOCOL === (int) ( $receipt['protocol'] ?? 0 )
			&& 1 === preg_match( '/^[a-f0-9]{64}$/', (string) ( $receipt['fingerprint'] ?? '' ) )
			&& '' !== (string) ( $receipt['generation'] ?? '' )
			&& 0 < (int) ( $receipt['published_at'] ?? 0 );
	}

	/** @return array{operation: string, value_hash: string, identity: array<string, mixed>}|null */
	private function top_frame( string $option ): ?array {
		if ( empty( $this->pending[ $option ] ) ) {
			return null;
		}
		return $this->pending[ $option ][ count( $this->pending[ $option ] ) - 1 ];
	}

	/** @return array{operation: string, value_hash: string, identity: array<string, mixed>}|null */
	private function pop_matching_frame( string $option, string $operation ): ?array {
		$top = $this->top_frame( $option );
		if ( ! is_array( $top ) || $operation !== $top['operation'] ) {
			return null;
		}
		$frame = array_pop( $this->pending[ $option ] );
		if ( empty( $this->pending[ $option ] ) ) {
			unset( $this->pending[ $option ] );
		}
		return $frame;
	}

	private function is_managed( string $option ): bool {
		return in_array( $option, self::options(), true );
	}

	private function price_buckets_manage_this_write( string $option ): bool {
		return in_array( $option, array( FacetRegistry::OPTION_PRECIO_BUCKETS, PriceBuckets::OPTION_MODE ), true )
			&& PriceBuckets::manages_publication_context();
	}

	/** @param mixed $a Valor. @param mixed $b Valor. @return bool */
	private function same_value( $a, $b ): bool {
		return $a === $b || self::serialize_value( $a ) === self::serialize_value( $b );
	}

	/** @param mixed $value Valor. @return string */
	private function value_hash( $value ): string {
		return hash( 'sha256', self::serialize_value( $value ) );
	}

	/** @param mixed $value Valor. @return string */
	private static function serialize_value( $value ): string {
		if ( function_exists( 'maybe_serialize' ) ) {
			return (string) maybe_serialize( $value );
		}
		return is_array( $value ) || is_object( $value ) ? serialize( $value ) : (string) $value;
	}

	private function flush_runtime_memos( string $option ): void {
		self::reset_authoritative_snapshot();
		if ( Synonyms::OPTION === $option ) {
			Synonyms::flush();
		} elseif ( Stopwords::OPTION === $option ) {
			Stopwords::flush();
			Synonyms::flush();
		} elseif ( Fuzzy::OPTION === $option ) {
			Fuzzy::flush();
		} elseif ( FacetRegistry::OPTION_KEY === $option || FacetRegistry::OPTION_PRECIO_BUCKETS === $option
			|| PriceBuckets::OPTION_MODE === $option || 'default_product_cat' === $option ) {
			FacetRegistry::reset_cache();
		} elseif ( Novedad::OPTION === $option ) {
			Novedad::reset_cache();
		}
	}
}
