<?php
declare( strict_types=1 );

namespace Akibara\Search\EditorialDiscount;

use Akibara\Search\AuditLog;
use Akibara\Search\EditorialDiscountPublication;
use Akibara\Search\Install;
use Throwable;

/**
 * Domain service for option & term metadata write interception, validation, and rejection.
 *
 * Enforces editorial consistency before options and taxonomy term metadata are committed
 * to the WordPress database. Prevents corrupted snapshots, manages atomic write vetos,
 * handles administrative warning notices, and coordinates safe repair writes.
 */
final class OptionsWriteGuard {

	public const WRITE_UNCHANGED = 'sin_cambio';
	public const WRITE_FENCED = 'cercar';
	public const WRITE_REPAIRS = 'repara';
	public const WRITE_BREAKS = 'invalido';
	public const WRITE_STILL_BROKEN = 'config_rota';
	public const REJECTED_BUSY = 'ocupado';
	public const REJECTED_TRANSIENT_PREFIX = 'akb_search_rechazo_';

	private EditorialDiscountPublication $publication;

	/** @var array<string, string> */
	private array $repair_writes = array();

	private bool $write_veto_registered = false;

	public function __construct( EditorialDiscountPublication $publication ) {
		$this->publication = $publication;
	}

	/**
	 * Determines write verdict for a guarded WordPress option.
	 *
	 * @param string                                  $option Guarded option name.
	 * @param array{present: bool, raw: string}|null $row    Target DB row representation.
	 * @return string One of the WRITE_* constants.
	 */
	public static function write_verdict( string $option, ?array $row ): string {
		$before = EditorialDiscountPublication::raw_source_options();
		if ( ! is_array( $before ) || ! isset( $before[ $option ] ) || null === $row ) {
			return self::WRITE_FENCED;
		}

		$actual = $before[ $option ];
		if ( $row['present'] ? ( $actual['present'] && $row['raw'] === $actual['raw'] ) : ! $actual['present'] ) {
			return self::WRITE_UNCHANGED;
		}

		$database = EditorialDiscountPublication::raw_editorial_database_source();
		if ( ! is_array( $database ) ) {
			return self::WRITE_FENCED;
		}

		$after            = $before;
		$after[ $option ] = $row;

		if ( self::snapshot_readable( $before, $database ) ) {
			return self::snapshot_readable( $after, $database ) ? self::WRITE_FENCED : self::WRITE_BREAKS;
		}

		return self::snapshot_readable( $after, $database ) || self::repairs_own_group( $option, $before, $after, $database )
			? self::WRITE_REPAIRS
			: self::WRITE_STILL_BROKEN;
	}

	/**
	 * Formats a value as a raw options table row tuple.
	 *
	 * @param mixed $value Sanitized value.
	 * @return array{present: bool, raw: string}|null
	 */
	public static function value_row( $value ): ?array {
		$bytes = self::stored_option_bytes( $value );

		return null === $bytes ? null : array(
			'present' => true,
			'raw'     => $bytes,
		);
	}

	/**
	 * Computes serialized/string bytes stored by update_option/add_option in option_value.
	 *
	 * @param mixed $value Sanitized value.
	 * @return string|null null if value cannot be represented.
	 */
	public static function stored_option_bytes( $value ): ?string {
		$serialized = maybe_serialize( $value );
		if ( is_string( $serialized ) ) {
			return $serialized;
		}
		if ( null === $serialized || false === $serialized ) {
			return '';
		}
		if ( true === $serialized ) {
			return '1';
		}

		return is_int( $serialized ) || is_float( $serialized ) ? (string) $serialized : null;
	}

	/**
	 * Evaluates hypothetically whether the reader serves correctly with the candidate options.
	 * Preserves read authority latches across hypothetical evaluation.
	 *
	 * @param array<string, array{present: bool, raw: string}> $options
	 * @param array<string, mixed>                             $database
	 * @return bool
	 */
	public static function snapshot_readable( array $options, array $database ): bool {
		$latch = EditorialDiscountPublication::get_read_authority_latch();
		try {
			return is_array( EditorialDiscountPublication::source_snapshot_from_options( $options, $database ) );
		} catch ( Throwable $throwable ) {
			return false;
		} finally {
			EditorialDiscountPublication::set_read_authority_latch( $latch );
		}
	}

	/**
	 * Returns group of interdependent options evaluated together.
	 *
	 * @param string $option Guarded option name.
	 * @return array<int, string>
	 */
	public static function option_group( string $option ): array {
		return in_array( $option, array( EditorialDiscountPublication::OPTION_TIMEZONE, EditorialDiscountPublication::OPTION_GMT_OFFSET ), true )
			? array( EditorialDiscountPublication::OPTION_TIMEZONE, EditorialDiscountPublication::OPTION_GMT_OFFSET )
			: array( $option );
	}

	/**
	 * Returns neutral option rows where all options use default absence except required feed contract.
	 *
	 * @return array<string, array{present: bool, raw: string}>|null
	 */
	public static function neutral_source_options(): ?array {
		$provider = EditorialDiscountPublication::provider_descriptor();
		if ( ! is_array( $provider ) ) {
			return null;
		}

		$base = array_fill_keys(
			EditorialDiscountPublication::options(),
			array(
				'present' => false,
				'raw'     => '',
			)
		);

		if ( 'provider_absent' !== (string) ( $provider['kind'] ?? '' ) ) {
			$base[ EditorialDiscountPublication::OPTION_FEED ] = array(
				'present' => true,
				'raw'     => EditorialDiscountPublication::REQUIRED_FEED_CONTRACT,
			);
		}

		return $base;
	}

	/**
	 * Checks whether the group for this option is readable against neutral baseline.
	 *
	 * @param string                                            $option
	 * @param array<string, array{present: bool, raw: string}> $from
	 * @param array<string, array{present: bool, raw: string}> $base
	 * @param array<string, mixed>                             $database
	 * @return bool
	 */
	public static function group_readable( string $option, array $from, array $base, array $database ): bool {
		foreach ( self::option_group( $option ) as $name ) {
			$base[ $name ] = $from[ $name ];
		}

		return self::snapshot_readable( $base, $database );
	}

	/**
	 * Checks whether an incoming write repairs an already broken group.
	 *
	 * @param string                                            $option
	 * @param array<string, array{present: bool, raw: string}> $before
	 * @param array<string, array{present: bool, raw: string}> $after
	 * @param array<string, mixed>                             $database
	 * @return bool
	 */
	public static function repairs_own_group( string $option, array $before, array $after, array $database ): bool {
		$base = self::neutral_source_options();

		return null !== $base
			&& self::snapshot_readable( $base, $database )
			&& ! self::group_readable( $option, $before, $base, $database )
			&& self::group_readable( $option, $after, $base, $database );
	}

	/**
	 * Identifies currently broken options and their configuration locations.
	 *
	 * @return array<int, array{label: string, where: string}>
	 */
	public static function broken_options(): array {
		$options  = EditorialDiscountPublication::raw_source_options();
		$database = EditorialDiscountPublication::raw_editorial_database_source();
		$base     = is_array( $options ) && is_array( $database ) ? self::neutral_source_options() : null;
		if ( null === $base || ! self::snapshot_readable( $base, $database ) ) {
			return array();
		}

		$rotos     = array();
		$etiquetas = self::write_labels();
		$pantallas = self::repair_locations();
		foreach ( EditorialDiscountPublication::options() as $name ) {
			$label = $etiquetas[ $name ] ?? $name;
			if ( ! isset( $rotos[ $label ] ) && ! self::group_readable( $name, $options, $base, $database ) ) {
				$rotos[ $label ] = array(
					'label' => $label,
					'where' => $pantallas[ $name ] ?? '',
				);
			}
		}

		return array_values( $rotos );
	}

	/** @return array<int, string> */
	public static function broken_option_labels(): array {
		return array_column( self::broken_options(), 'label' );
	}

	/** @return array<string, string> */
	public static function repair_locations(): array {
		return array(
			'timezone_string'                               => 'Ajustes → Generales',
			'gmt_offset'                                    => 'Ajustes → Generales',
			'woocommerce_price_num_decimals'                => 'WooCommerce → Ajustes → General',
			'akibara_taxonomy_discount_enabled'             => 'Akibara → Descuentos',
			'akibara_taxonomy_discount_from'                => 'Akibara → Descuentos',
			'akibara_taxonomy_discount_to'                  => 'Akibara → Descuentos',
			'akibara_taxonomy_discount_apply_to'            => 'Akibara → Descuentos',
			'akibara_taxonomy_discount_sale_policy'         => 'Akibara → Descuentos',
			'akb_discount_max_pct'                          => 'Akibara → Descuentos',
			'akibara_business_search_feed_contract_version' => 'no tiene pantalla: la reescribe Akibara Business al cargarse',
		);
	}

	/**
	 * Records rejection in AuditLog and schedules administrative notice.
	 *
	 * @param string $write Option or meta key.
	 * @param mixed  $value Rejected value.
	 * @param string $cause Rejection cause identifier.
	 * @return void
	 */
	public static function reject_write( string $write, $value, string $cause ): void {
		$broken = self::WRITE_STILL_BROKEN === $cause ? self::broken_option_labels() : array();
		AuditLog::record(
			'option_editorial_rechazada',
			sprintf(
				'%s = %s no se guardó (%s)%s',
				$write,
				self::describe_value( $value ),
				$cause,
				array() === $broken ? '' : '; ilegible: ' . implode( ', ', $broken )
			)
		);
		self::record_rejected_write( $write, $cause, $broken );
	}

	/**
	 * Human-readable string representation of arbitrary PHP value for logs and notices.
	 *
	 * @param mixed $value
	 * @return string
	 */
	public static function describe_value( $value ): string {
		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}
		if ( null === $value ) {
			return 'null';
		}
		if ( ! is_scalar( $value ) ) {
			return 'un valor ' . gettype( $value );
		}
		$text = (string) $value;

		return '«' . ( strlen( $text ) > 60 ? substr( $text, 0, 60 ) . '…' : $text ) . '»';
	}

	/** @return array<string, string> */
	public static function write_labels(): array {
		return array(
			'timezone_string'                               => 'la zona horaria del sitio',
			'gmt_offset'                                    => 'la zona horaria del sitio',
			'woocommerce_price_num_decimals'                => 'los decimales de los precios',
			'akibara_taxonomy_discount_enabled'             => 'el interruptor del descuento por editorial',
			'akibara_taxonomy_discount_from'                => 'la fecha de inicio del descuento',
			'akibara_taxonomy_discount_to'                  => 'la fecha de término del descuento',
			'akibara_taxonomy_discount_apply_to'            => 'a qué productos se aplica el descuento',
			'akibara_taxonomy_discount_sale_policy'         => 'qué pasa con los productos ya rebajados',
			'akb_discount_max_pct'                          => 'el tope de descuento',
			'akibara_business_search_feed_contract_version' => 'el contrato del feed de precios',
			EditorialDiscountPublication::META_KEY          => 'el % de descuento de una editorial',
		);
	}

	/** @return array<string, string> */
	public static function rejection_texts(): array {
		return array(
			self::REJECTED_BUSY      => 'El buscador está aplicando otro cambio de precios en este momento: espera un par de minutos y vuelve a guardar.',
			self::WRITE_BREAKS       => 'Con ese valor el buscador no podría leer la configuración de precios y la tienda se quedaría sin búsqueda ni filtros, así que se mantuvo el anterior. Revisa el dato (por ejemplo, que la fecha exista) y vuelve a guardar.',
			self::WRITE_STILL_BROKEN => 'La configuración de precios guardada ya tiene un dato que el buscador no puede leer%s y este cambio no lo arregla: corrige primero ese dato y después vuelve a guardar.',
		);
	}

	/**
	 * Persists user transient for administrative rejection notice.
	 *
	 * @param string             $write
	 * @param string             $cause
	 * @param array<int, string> $broken
	 * @return void
	 */
	public static function record_rejected_write( string $write, string $cause, array $broken = array() ): void {
		if ( ! function_exists( 'set_transient' ) || ! function_exists( 'get_current_user_id' ) ) {
			return;
		}

		$user_id = (int) get_current_user_id();
		if ( $user_id <= 0 ) {
			return;
		}

		if ( in_array( $write, EditorialDiscountPublication::options(), true ) && self::business_panel_reports_rejections() ) {
			if ( self::REJECTED_BUSY === $cause ) {
				return;
			}
			EditorialDiscountPublication::$precise_panel_rejections[ $write ] = true;
		}

		$previos = get_transient( self::REJECTED_TRANSIENT_PREFIX . $user_id );
		$previos = is_array( $previos ) ? $previos : array();
		$previos[ $write . '|' . $cause ] = array(
			'write'  => $write,
			'cause'  => $cause,
			'broken' => array_values( array_map( 'strval', $broken ) ),
		);

		set_transient( self::REJECTED_TRANSIENT_PREFIX . $user_id, $previos, 5 * MINUTE_IN_SECONDS );
	}

	/**
	 * Filters options reported by business panel to avoid duplicate notice collision.
	 *
	 * @param mixed $options
	 * @return mixed
	 */
	public static function filter_business_options_not_saved( $options ) {
		$precisas                                              = EditorialDiscountPublication::$precise_panel_rejections;
		EditorialDiscountPublication::$precise_panel_rejections = array();
		if ( ! is_array( $options ) || array() === $precisas ) {
			return $options;
		}

		return array_values(
			array_filter(
				$options,
				static fn( $option ): bool => ! is_string( $option ) || ! isset( $precisas[ $option ] )
			)
		);
	}

	/**
	 * Detects whether the current write is happening inside Akibara admin panel options update.
	 *
	 * @return bool
	 */
	public static function business_panel_reports_rejections(): bool {
		global $wp_filter;
		$hook      = $wp_filter['pre_update_option'] ?? null;
		$callbacks = is_object( $hook ) && isset( $hook->callbacks ) && is_array( $hook->callbacks )
			? $hook->callbacks
			: ( is_array( $hook ) ? $hook : array() );

		foreach ( $callbacks as $rows ) {
			foreach ( is_array( $rows ) ? $rows : array() as $row ) {
				$callback = is_array( $row ) ? ( $row['function'] ?? null ) : null;
				if ( is_array( $callback ) && isset( $callback[0], $callback[1] ) && is_object( $callback[0] )
					&& 'Akibara\\Business\\Modules\\AdminPanel\\AdminPanel' === get_class( $callback[0] )
					&& 'skip_untouched_default' === $callback[1] ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Groups transient rejection notices by cause and formats their labels.
	 *
	 * @param array<int|string, mixed> $avisos
	 * @param bool                     $zona_con_nombre
	 * @return array<string, array{labels: array<int, string>, broken: array<int, string>}>
	 */
	private static function group_rejected_notices( array $avisos, bool $zona_con_nombre ): array {
		$etiquetas = self::write_labels();
		$textos    = self::rejection_texts();
		$por_causa = array();

		foreach ( $avisos as $aviso ) {
			$write = is_array( $aviso ) ? (string) ( $aviso['write'] ?? '' ) : '';
			$cause = is_array( $aviso ) ? (string) ( $aviso['cause'] ?? '' ) : '';
			if ( '' === $write || ! isset( $textos[ $cause ] )
				|| ( EditorialDiscountPublication::OPTION_GMT_OFFSET === $write && $zona_con_nombre ) ) {
				continue;
			}
			$por_causa[ $cause ]['labels'][] = $etiquetas[ $write ] ?? $write;
			foreach ( (array) ( $aviso['broken'] ?? array() ) as $roto ) {
				$por_causa[ $cause ]['broken'][] = (string) $roto;
			}
		}

		return $por_causa;
	}

	/**
	 * Renders dismissible admin notice for rejected writes.
	 *
	 * @return void
	 */
	public static function render_rejected_write_notice(): void {
		if ( ! function_exists( 'get_transient' ) || ! function_exists( 'get_current_user_id' ) ) {
			return;
		}

		$user_id = (int) get_current_user_id();
		$avisos  = $user_id > 0 ? get_transient( self::REJECTED_TRANSIENT_PREFIX . $user_id ) : false;
		if ( ! is_array( $avisos ) || array() === $avisos ) {
			return;
		}

		delete_transient( self::REJECTED_TRANSIENT_PREFIX . $user_id );

		$zona_con_nombre = '' !== (string) get_option( EditorialDiscountPublication::OPTION_TIMEZONE, '' );
		$textos          = self::rejection_texts();
		$por_causa       = self::group_rejected_notices( $avisos, $zona_con_nombre );

		foreach ( $por_causa as $cause => $grupo ) {
			$rotos = array_values( array_unique( $grupo['broken'] ?? array() ) );
			printf(
				'<div class="notice notice-warning is-dismissible"><p><strong>Akibara</strong>: no se guardó %s. %s</p></div>',
				esc_html( implode( ', ', array_values( array_unique( $grupo['labels'] ) ) ) ),
				esc_html( sprintf( $textos[ $cause ], array() === $rotos ? '' : ' (' . implode( ', ', $rotos ) . ')' ) )
			);
		}
	}

	/**
	 * Rejects a term meta write that cannot be fenced or would break consistency.
	 *
	 * @param int   $term_id
	 * @param mixed $value
	 * @return bool Always false to veto write.
	 */
	public static function reject_term_meta_write( int $term_id, $value ): bool {
		$options  = EditorialDiscountPublication::raw_source_options();
		$database = EditorialDiscountPublication::raw_editorial_database_source();
		$cause    = is_array( $options ) && is_array( $database ) && ! self::snapshot_readable( $options, $database )
			? self::WRITE_STILL_BROKEN
			: self::REJECTED_BUSY;
		$broken   = self::WRITE_STILL_BROKEN === $cause ? self::broken_option_labels() : array();

		AuditLog::record(
			'option_editorial_rechazada',
			sprintf(
				'%s del término %d = %s no se guardó (%s)%s',
				EditorialDiscountPublication::META_KEY,
				$term_id,
				null === $value ? 'baja' : self::describe_value( $value ),
				$cause,
				array() === $broken ? '' : '; ilegible: ' . implode( ', ', $broken )
			)
		);
		self::record_rejected_write( EditorialDiscountPublication::META_KEY, $cause, $broken );

		return false;
	}

	/**
	 * Records an option write that occurred without an available fence.
	 *
	 * @param string $option
	 * @param string $operation add|delete.
	 * @return void
	 */
	public static function record_unfenced_option_write( string $option, string $operation ): void {
		AuditLog::record(
			'option_editorial_sin_cercar',
			sprintf(
				'%s de %s sin fence disponible: el gate cierra solo y el reconcile monta la deuda (editorial_source_drift).',
				'add' === $operation ? 'Alta' : 'Baja',
				$option
			)
		);
		EditorialDiscountPublication::clear_reconcile_mark();
	}

	/**
	 * Accepts an incoming option write that repairs a previously broken configuration.
	 *
	 * @param string $option
	 * @param mixed  $value
	 * @return void
	 */
	public function accept_repair( string $option, $value ): void {
		$this->repair_writes[ $option ] = EditorialDiscountPublication::value_hash( $value );
		Install::request_rebuild( 'option_repair_' . $option, time() + EditorialDiscountPublication::PREWRITE_DELAY );
		AuditLog::record(
			'option_editorial_reparada',
			sprintf( 'Se aceptó %s = %s: devuelve a un estado legible la configuración de precios que el buscador no podía leer.', $option, self::describe_value( $value ) )
		);
		EditorialDiscountPublication::clear_reconcile_mark();
	}

	/**
	 * Blocks an upcoming add_option or delete_option query by filtering wpdb query.
	 *
	 * @param string $option
	 * @param string $operation insert|delete.
	 * @return void
	 */
	public function block_option_write( string $option, string $operation ): void {
		$this->publication->blocked_writes[ $option ] = $operation;
		$this->publication->ensure_shutdown_registered();
		if ( ! $this->write_veto_registered && function_exists( 'add_filter' ) ) {
			add_filter( 'query', array( $this->publication, 'filter_blocked_option_write' ), PHP_INT_MAX );
			$this->write_veto_registered = true;
		}
	}

	/**
	 * Clears an unexecuted write veto.
	 *
	 * @param string $option
	 * @return void
	 */
	public function unblock_option_write( string $option ): void {
		unset( $this->publication->blocked_writes[ $option ] );
		if ( array() === $this->publication->blocked_writes && $this->write_veto_registered && function_exists( 'remove_filter' ) ) {
			remove_filter( 'query', array( $this->publication, 'filter_blocked_option_write' ), PHP_INT_MAX );
			$this->write_veto_registered = false;
		}
	}

	/**
	 * Intercepts SQL query to veto target option insert/delete.
	 *
	 * @param mixed $query
	 * @return mixed
	 */
	public function filter_blocked_option_write( $query ) {
		global $wpdb;
		if ( array() === $this->publication->blocked_writes || ! is_string( $query ) || ! is_object( $wpdb ) || ! isset( $wpdb->options ) ) {
			return $query;
		}

		foreach ( $this->publication->blocked_writes as $option => $operation ) {
			$propia = 'insert' === $operation
				? 0 === strpos( $query, 'INSERT INTO `' . $wpdb->options . "` (`option_name`, `option_value`, `autoload`) VALUES ('" . $option . "', " )
				: 'DELETE FROM `' . $wpdb->options . "` WHERE `option_name` = '" . $option . "'" === $query;
			if ( $propia ) {
				if ( 'delete' === $operation ) {
					$this->publication->vetoed_deletes[ $option ] = true;
				}
				$this->unblock_option_write( $option );
				return '';
			}
		}

		return $query;
	}

	/**
	 * Clears recorded repair write for an option once its frame completes.
	 *
	 * @param string $option
	 * @return void
	 */
	public function clear_repair_write( string $option ): void {
		unset( $this->repair_writes[ $option ] );
	}

	/**
	 * Restores options cache consistency and unblocks writes on shutdown.
	 *
	 * @return void
	 */
	public function forget_vetoed_deletes(): void {
		foreach ( array_keys( $this->publication->blocked_writes ) as $option ) {
			$this->unblock_option_write( (string) $option );
		}

		if ( array() === $this->publication->vetoed_deletes || ! function_exists( 'wp_cache_delete' ) ) {
			$this->publication->vetoed_deletes = array();
			return;
		}

		foreach ( array_keys( $this->publication->vetoed_deletes ) as $option ) {
			wp_cache_delete( $option, 'options' );
		}
		wp_cache_delete( 'notoptions', 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		$this->publication->vetoed_deletes = array();
	}

	/**
	 * Pre-update filter for guarded options.
	 *
	 * @param mixed  $value
	 * @param string $option
	 * @param mixed  $old_value
	 * @return mixed
	 */
	public function filter_pre_update_option( $value, string $option, $old_value ) {
		if ( ! in_array( $option, EditorialDiscountPublication::options(), true ) || EditorialDiscountPublication::same_value( $value, $old_value ) ) {
			return $value;
		}

		$verdict = self::write_verdict( $option, self::value_row( $value ) );
		if ( self::WRITE_UNCHANGED === $verdict ) {
			return $value;
		}

		if ( self::WRITE_REPAIRS === $verdict ) {
			$this->accept_repair( $option, $value );
			return $value;
		}

		if ( self::WRITE_FENCED === $verdict ) {
			$frame = $this->publication->stage_global( 'option_update_' . $option );
			if ( is_array( $frame ) ) {
				$frame['operation']  = 'update';
				$frame['value_hash'] = EditorialDiscountPublication::value_hash( $value );
				$this->publication->push_option_frame( $option, $frame );
				return $value;
			}
			$verdict = self::REJECTED_BUSY;
		}

		self::reject_write( $option, $value, $verdict );

		return $old_value;
	}

	/**
	 * Pre-add hook for guarded options.
	 *
	 * @param string $option
	 * @param mixed  $value
	 * @return void
	 */
	public function before_add_option( string $option, $value ): void {
		if ( ! in_array( $option, EditorialDiscountPublication::options(), true ) ) {
			return;
		}

		$this->unblock_option_write( $option );
		$hash = EditorialDiscountPublication::value_hash( $value );
		$top  = $this->publication->top_option_frame( $option );

		if ( is_array( $top ) && 'update' === $top['operation'] && $hash === $top['value_hash'] ) {
			$this->publication->update_top_option_frame_operation( $option, 'add' );
			return;
		}

		if ( ( $this->repair_writes[ $option ] ?? null ) === $hash ) {
			return;
		}

		$verdict = self::write_verdict( $option, self::value_row( $value ) );
		if ( self::WRITE_REPAIRS === $verdict ) {
			$this->accept_repair( $option, $value );
			return;
		}

		if ( self::WRITE_BREAKS === $verdict || self::WRITE_STILL_BROKEN === $verdict ) {
			$this->block_option_write( $option, 'insert' );
			self::reject_write( $option, $value, $verdict );
			return;
		}

		$frame = $this->publication->stage_global( 'option_add_' . $option );
		if ( ! is_array( $frame ) ) {
			self::record_unfenced_option_write( $option, 'add' );
			return;
		}

		$frame['operation']  = 'add';
		$frame['value_hash'] = $hash;
		$this->publication->push_option_frame( $option, $frame );
	}

	/**
	 * Pre-delete hook for guarded options.
	 *
	 * @param string $option
	 * @return void
	 */
	public function before_delete_option( string $option ): void {
		if ( ! in_array( $option, EditorialDiscountPublication::options(), true ) ) {
			return;
		}

		$this->unblock_option_write( $option );
		$verdict = self::write_verdict(
			$option,
			array(
				'present' => false,
				'raw'     => '',
			)
		);

		if ( self::WRITE_UNCHANGED === $verdict ) {
			return;
		}

		if ( self::WRITE_REPAIRS === $verdict ) {
			$this->accept_repair( $option, null );
			return;
		}

		if ( self::WRITE_BREAKS === $verdict || self::WRITE_STILL_BROKEN === $verdict ) {
			$this->block_option_write( $option, 'delete' );
			self::reject_write( $option, null, $verdict );
			return;
		}

		$frame = $this->publication->stage_global( 'option_delete_' . $option );
		if ( ! is_array( $frame ) ) {
			self::record_unfenced_option_write( $option, 'delete' );
			return;
		}

		$frame['operation']  = 'delete';
		$frame['value_hash'] = '__deleted__';
		$this->publication->push_option_frame( $option, $frame );
	}

	/**
	 * Post-update hook for guarded options.
	 *
	 * @param string $option
	 * @param mixed  $old
	 * @param mixed  $value
	 * @return void
	 */
	public function after_update_option( string $option, $old, $value ): void {
		unset( $old, $value );
		$this->publication->complete_option( $option, 'update' );
	}

	/**
	 * Post-add hook for guarded options.
	 *
	 * @param string $option
	 * @param mixed  $value
	 * @return void
	 */
	public function after_add_option( string $option, $value ): void {
		unset( $value );
		$this->publication->complete_option( $option, 'add' );
	}

	/**
	 * Post-delete hook for guarded options.
	 *
	 * @param string $option
	 * @return void
	 */
	public function after_delete_option( string $option ): void {
		$this->publication->complete_option( $option, 'delete' );
	}

	/**
	 * Pre-update hook for term metadata.
	 *
	 * @param mixed  $check
	 * @param int    $term_id
	 * @param string $key
	 * @param mixed  $value
	 * @param mixed  $previous
	 * @return mixed
	 */
	public function filter_update_term_metadata( $check, int $term_id, string $key, $value, $previous ) {
		unset( $previous );
		if ( EditorialDiscountPublication::META_KEY !== $key || null !== $check ) {
			return $check;
		}

		$frame = $this->publication->stage_term( $term_id, 'update', EditorialDiscountPublication::value_hash( $value ) );
		return is_array( $frame ) ? null : self::reject_term_meta_write( $term_id, $value );
	}

	/**
	 * Pre-add hook for term metadata.
	 *
	 * @param mixed  $check
	 * @param int    $term_id
	 * @param string $key
	 * @param mixed  $value
	 * @param bool   $unique
	 * @return mixed
	 */
	public function filter_add_term_metadata( $check, int $term_id, string $key, $value, bool $unique ) {
		unset( $unique );
		if ( EditorialDiscountPublication::META_KEY !== $key || null !== $check ) {
			return $check;
		}

		$hash = EditorialDiscountPublication::value_hash( $value );
		$top  = $this->publication->top_meta_frame();

		if ( is_array( $top ) && 'update' === (string) $top['operation']
			&& $term_id === (int) $top['term_id'] && $hash === (string) $top['value_hash'] ) {
			$this->publication->update_top_meta_frame_operation( 'add' );
			return null;
		}

		$frame = $this->publication->stage_term( $term_id, 'add', $hash );
		return is_array( $frame ) ? null : self::reject_term_meta_write( $term_id, $value );
	}

	/**
	 * Pre-delete hook for term metadata.
	 *
	 * @param mixed  $check
	 * @param int    $term_id
	 * @param string $key
	 * @param mixed  $value
	 * @param bool   $delete_all
	 * @return mixed
	 */
	public function filter_delete_term_metadata( $check, int $term_id, string $key, $value, bool $delete_all ) {
		unset( $value );
		if ( EditorialDiscountPublication::META_KEY !== $key || null !== $check ) {
			return $check;
		}

		$frame = $delete_all
			? $this->publication->stage_global( 'term_meta_delete_all' )
			: $this->publication->stage_term( $term_id, 'delete', '__deleted__' );

		if ( is_array( $frame ) && $delete_all ) {
			$frame['operation']  = 'delete';
			$frame['term_id']    = $term_id;
			$frame['value_hash'] = '__deleted_all__';
			$this->publication->push_meta_frame( $frame );
		}

		return is_array( $frame ) ? null : self::reject_term_meta_write( $term_id, null );
	}

	/**
	 * Pre-update by mid hook for term metadata.
	 *
	 * @param mixed  $check
	 * @param int    $meta_id
	 * @param mixed  $value
	 * @param mixed  $key
	 * @return mixed
	 */
	public function filter_update_term_metadata_by_mid( $check, int $meta_id, $value, $key ) {
		if ( null !== $check ) {
			return $check;
		}

		$row = EditorialDiscountPublication::term_meta_row( $meta_id );
		if ( EditorialDiscountPublication::is_term_meta_row_failed() ) {
			return false;
		}

		if ( ! is_array( $row ) ) {
			return null;
		}

		$new_key = false === $key ? (string) $row['meta_key'] : ( is_string( $key ) ? $key : '' );
		if ( '' === $new_key ) {
			return false;
		}

		if ( EditorialDiscountPublication::META_KEY !== (string) $row['meta_key'] && EditorialDiscountPublication::META_KEY !== $new_key ) {
			return null;
		}

		$frame = $this->publication->stage_term( (int) $row['term_id'], 'update', EditorialDiscountPublication::value_hash( $value ), $meta_id, $new_key );
		return is_array( $frame ) ? null : self::reject_term_meta_write( (int) $row['term_id'], $value );
	}

	/**
	 * Pre-delete by mid hook for term metadata.
	 *
	 * @param mixed $check
	 * @param int   $meta_id
	 * @return mixed
	 */
	public function filter_delete_term_metadata_by_mid( $check, int $meta_id ) {
		if ( null !== $check ) {
			return $check;
		}

		$row = EditorialDiscountPublication::term_meta_row( $meta_id );
		if ( EditorialDiscountPublication::is_term_meta_row_failed() ) {
			return false;
		}

		if ( ! is_array( $row ) || EditorialDiscountPublication::META_KEY !== $row['meta_key'] ) {
			return null;
		}

		$frame = $this->publication->stage_term( (int) $row['term_id'], 'delete', '__deleted__', $meta_id, EditorialDiscountPublication::META_KEY );
		return is_array( $frame ) ? null : self::reject_term_meta_write( (int) $row['term_id'], null );
	}

	/**
	 * Post-update hook for term metadata.
	 *
	 * @param int    $meta_id
	 * @param int    $term_id
	 * @param string $key
	 * @param mixed  $value
	 * @return void
	 */
	public function after_updated_term_meta( int $meta_id, int $term_id, string $key, $value ): void {
		unset( $value );
		$this->publication->complete_meta( 'update', $meta_id, $term_id, $key );
	}

	/**
	 * Post-add hook for term metadata.
	 *
	 * @param int    $meta_id
	 * @param int    $term_id
	 * @param string $key
	 * @param mixed  $value
	 * @return void
	 */
	public function after_added_term_meta( int $meta_id, int $term_id, string $key, $value ): void {
		unset( $value );
		$this->publication->complete_meta( 'add', $meta_id, $term_id, $key );
	}

	/**
	 * Post-delete hook for term metadata.
	 *
	 * @param mixed  $meta_ids
	 * @param int    $term_id
	 * @param string $key
	 * @param mixed  $value
	 * @return void
	 */
	public function after_deleted_term_meta( $meta_ids, int $term_id, string $key, $value ): void {
		unset( $meta_ids, $value );
		$this->publication->complete_meta( 'delete', 0, $term_id, $key );
	}
}
