<?php
/**
 * OptionStorage — motor de persistencia atómica y compensación CAS para opciones del buscador.
 *
 * @package Akibara\Search\Admin
 */

declare( strict_types = 1 );

namespace Akibara\Search\Admin;

defined( 'ABSPATH' ) || exit;

use Akibara\Search\SemanticPublication;
use Akibara\Search\Support;

/**
 * Persistencia exacta y recuperación transaccional Compare-And-Swap (CAS).
 */
final class OptionStorage {

	/**
	 * Persiste un conjunto de opciones de forma atómica.
	 *
	 * @param array<string, mixed> $values   Opciones con su valor.
	 * @param array<int, string>   $releases Opciones que tienen que quedar sin fila.
	 * @return bool
	 */
	public static function persist_options_exact( array $values, array $releases = array() ): bool {
		$snapshots = self::option_snapshots( array_merge( array_keys( $values ), $releases ) );
		if ( null === $snapshots ) {
			return false;
		}
		$attempted = array();
		$states    = array();
		foreach ( $values as $option => $value ) {
			$option            = (string) $option;
			$attempted[]       = $option;
			$expected          = self::expected_option_state( $option, $value );
			$states[ $option ] = $expected;
			try {
				$updated = update_option( $option, $value, false );
			} catch ( \Throwable $throwable ) {
				self::restore_option_snapshots( $snapshots, $attempted, $states );
				return false;
			}

			$current = self::option_snapshot( $option );
			if ( null === $current ) {
				self::restore_option_snapshots( $snapshots, $attempted, $states );
				return false;
			}
			if ( ! $updated && self::option_value_equals( $current, $value ) ) {
				// No-op legítimo: este grupo no escribió la fila y por tanto tampoco
				// adquiere derecho a compensarla si una opción posterior falla.
				array_pop( $attempted );
				unset( $states[ $option ] );
			} elseif ( ! self::option_states_equal( $current, $expected ) ) {
				self::restore_option_snapshots( $snapshots, $attempted, $states );
				return false;
			}
		}

		$absent = array(
			'present'  => false,
			'value'    => null,
			'raw'      => '',
			'autoload' => '',
		);
		foreach ( $releases as $option ) {
			$option = (string) $option;
			if ( empty( $snapshots[ $option ]['present'] ) ) {
				continue;
			}
			$attempted[]       = $option;
			$states[ $option ] = $absent;
			try {
				delete_option( $option );
			} catch ( \Throwable $throwable ) {
				self::restore_option_snapshots( $snapshots, $attempted, $states );
				return false;
			}

			// delete_option() devuelve false también si otro proceso la borró primero;
			// lo que decide es la relectura, no el booleano.
			$current = self::option_snapshot( $option );
			if ( null === $current || ! self::option_states_equal( $current, $absent ) ) {
				self::restore_option_snapshots( $snapshots, $attempted, $states );
				return false;
			}
		}
		return true;
	}

	/**
	 * Toma fotos exactas de un conjunto de options.
	 *
	 * @param array<int, string> $options Options a fotografiar.
	 * @return array<string, array{present: bool, value: mixed, raw: string, autoload: string}>|null
	 */
	public static function option_snapshots( array $options ): ?array {
		$snapshots = array();
		foreach ( $options as $option ) {
			$option   = (string) $option;
			$snapshot = self::option_snapshot( $option );
			if ( null === $snapshot ) {
				return null;
			}
			$snapshots[ $option ] = $snapshot;
		}
		return $snapshots;
	}

	/**
	 * Lee valor+autoload en una sola foto DB-direct. Un error de metadata nunca
	 * se convierte en el autoload inventado de un rollback.
	 *
	 * @param string $option Option a fotografiar.
	 * @return array{present: bool, value: mixed, raw: string, autoload: string}|null
	 */
	public static function option_snapshot( string $option ): ?array {
		global $wpdb;
		if ( isset( $wpdb->options ) && method_exists( $wpdb, 'prepare' ) && method_exists( $wpdb, 'get_row' ) ) {
			$wpdb->last_error = '';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- foto raw intencional para CAS; no puede usar object cache.
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
					$option
				)
			);
			if ( '' !== (string) ( $wpdb->last_error ?? '' ) ) {
				return null;
			}
			if ( null === $row ) {
				return array(
					'present'  => false,
					'value'    => null,
					'raw'      => '',
					'autoload' => '',
				);
			}
			if ( ! is_object( $row ) && ! is_array( $row ) ) {
				return null;
			}
			$raw      = (string) ( is_object( $row ) ? ( $row->option_value ?? '' ) : ( $row['option_value'] ?? '' ) );
			$autoload = (string) ( is_object( $row ) ? ( $row->autoload ?? '' ) : ( $row['autoload'] ?? '' ) );
			if ( '' === $autoload ) {
				return null;
			}
			$value = function_exists( 'maybe_unserialize' ) ? maybe_unserialize( $raw ) : $raw;
			return array(
				'present'  => true,
				'value'    => $value,
				'raw'      => $raw,
				'autoload' => $autoload,
			);
		}

		// Harness defensivo; WordPress real siempre toma la foto SQL anterior.
		$missing = new \stdClass();
		$value   = get_option( $option, $missing );
		return $missing === $value
			? array(
				'present'  => false,
				'value'    => null,
				'raw'      => '',
				'autoload' => '',
			)
			: array(
				'present'  => true,
				'value'    => $value,
				'raw'      => Support\OptionCodec::encode( $value ),
				'autoload' => self::expected_autoload( $option, $value ),
			);
	}

	/**
	 * Rollback compensatorio best-effort; el caller siempre mostrará error y no
	 * auditará éxito aunque la base deje de aceptar también la compensación.
	 *
	 * @param array<string, array{present: bool, value: mixed, raw: string, autoload: string}>      $snapshots Fotos.
	 * @param array<int, string>|null                                                               $only      Subset intentado.
	 * @param array<string, array{present: bool, value: mixed, raw: string, autoload: string}>|null $attempted Estado escrito por este grupo.
	 * @return bool
	 */
	public static function restore_option_snapshots( array $snapshots, ?array $only = null, ?array $attempted = null ): bool {
		$options          = null === $only ? array_keys( $snapshots ) : $only;
		$ok               = true;
		$changed          = false;
		$semantic_changed = false;
		foreach ( array_reverse( $options ) as $option ) {
			if ( ! isset( $snapshots[ $option ] ) || ! isset( $attempted[ $option ] ) ) {
				$ok = false;
				continue;
			}
			$snapshot = $snapshots[ $option ];
			try {
				$restored = self::restore_option_snapshot_cas( $option, $snapshot, $attempted[ $option ] );
				$changed  = $restored['changed'] || $changed;
				if ( $restored['changed'] && in_array( (string) $option, SemanticPublication::options(), true ) ) {
					$semantic_changed = true;
				}
				$ok = $restored['ok'] && $ok;
			} catch ( \Throwable $throwable ) {
				$ok = false;
			}
		}
		if ( $changed && $semantic_changed ) {
			// El CAS directo evita lost-update pero no dispara hooks de Core. El
			// fingerprint DB-direct vuelve a publicar ahora o conserva deuda para init.
			$ok = ( new SemanticPublication() )->reconcile_fingerprint() && $ok;
		}
		return $ok;
	}

	/**
	 * Compensa sólo si la option todavía contiene exactamente el valor que este
	 * grupo intentó escribir. Otro writer que ya ganó nunca es sobrescrito.
	 *
	 * @param string                                                            $option    Option.
	 * @param array{present: bool, value: mixed, raw: string, autoload: string} $snapshot  Estado original.
	 * @param array{present: bool, value: mixed, raw: string, autoload: string} $attempted Estado propio esperado.
	 * @return array{ok: bool, changed: bool}
	 */
	public static function restore_option_snapshot_cas( string $option, array $snapshot, array $attempted ): array {
		$current = self::option_snapshot( $option );
		if ( null === $current ) {
			return array(
				'ok'      => false,
				'changed' => false,
			);
		}
		if ( self::option_states_equal( $current, $snapshot ) ) {
			return array(
				'ok'      => true,
				'changed' => false,
			);
		}
		if ( ! self::option_states_equal( $current, $attempted ) ) {
			return array(
				'ok'      => false,
				'changed' => false,
			);
		}

		global $wpdb;
		if ( ! isset( $wpdb->options ) || ! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'query' ) ) {
			// Harness sin SQL: conserva la semántica, aunque producción siempre usa CAS.
			if ( $snapshot['present'] ) {
				update_option( $option, $snapshot['value'], false );
			} else {
				delete_option( $option );
			}
			$observed = self::option_snapshot( $option );
			return array(
				'ok'      => null !== $observed && self::option_states_equal( $observed, $snapshot ),
				'changed' => true,
			);
		}

		$wpdb->last_error = '';
		if ( $attempted['present'] && $snapshot['present'] ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- CAS raw compensatorio; se limpia cache y relee debajo.
			$result = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->options} SET option_value = %s, autoload = %s
					 WHERE option_name = %s
					 AND BINARY option_value = BINARY %s
					 AND BINARY autoload = BINARY %s",
					(string) $snapshot['raw'],
					(string) $snapshot['autoload'],
					$option,
					(string) $attempted['raw'],
					(string) $attempted['autoload']
				)
			);
		} elseif ( $attempted['present'] ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- CAS raw compensatorio; se limpia cache y relee debajo.
			$result = $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->options} WHERE option_name = %s
					 AND BINARY option_value = BINARY %s
					 AND BINARY autoload = BINARY %s",
					$option,
					(string) $attempted['raw'],
					(string) $attempted['autoload']
				)
			);
		} elseif ( $snapshot['present'] ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- CAS raw compensatorio; se limpia cache y relee debajo.
			$result = $wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s)",
					$option,
					(string) $snapshot['raw'],
					(string) $snapshot['autoload']
				)
			);
		} else {
			return array(
				'ok'      => true,
				'changed' => false,
			);
		}

		self::clear_option_cache( $option );
		$observed = self::option_snapshot( $option );
		$ok       = false !== $result
			&& '' === (string) ( $wpdb->last_error ?? '' )
			&& null !== $observed
			&& self::option_states_equal( $observed, $snapshot );
		return array(
			'ok'      => $ok,
			'changed' => $ok && 0 < (int) $result,
		);
	}

	/**
	 * Construye el estado raw que debe dejar un write real de Core.
	 *
	 * @param string $option Option objetivo.
	 * @param mixed  $value  Valor objetivo.
	 * @return array{present: bool, value: mixed, raw: string, autoload: string}
	 */
	public static function expected_option_state( string $option, $value ): array {
		return array(
			'present'  => true,
			'value'    => $value,
			'raw'      => Support\OptionCodec::encode( $value ),
			'autoload' => self::expected_autoload( $option, $value ),
		);
	}

	/**
	 * Compara presencia, bytes y autoload de dos estados.
	 *
	 * @param array{present: bool, value: mixed, raw: string, autoload: string} $a Estado.
	 * @param array{present: bool, value: mixed, raw: string, autoload: string} $b Estado.
	 * @return bool
	 */
	public static function option_states_equal( array $a, array $b ): bool {
		return (bool) $a['present'] === (bool) $b['present']
			&& ( ! $a['present']
				|| ( (string) $a['raw'] === (string) $b['raw']
					&& (string) $a['autoload'] === (string) $b['autoload'] ) );
	}

	/**
	 * Compara sólo el valor raw para reconocer un no-op legítimo de Core.
	 *
	 * @param array{present: bool, value: mixed, raw: string, autoload: string} $state    Estado.
	 * @param mixed                                                             $expected Valor esperado.
	 * @return bool
	 */
	public static function option_value_equals( array $state, $expected ): bool {
		return ! empty( $state['present'] ) && Support\OptionCodec::encode( $expected ) === (string) $state['raw'];
	}

	/**
	 * Obtiene el valor de autoload que Core escribirá con `$autoload=false`.
	 *
	 * @param string $option Option objetivo.
	 * @param mixed  $value  Valor objetivo.
	 * @return string
	 */
	public static function expected_autoload( string $option, $value ): string {
		$serialized = Support\OptionCodec::encode( $value );
		if ( function_exists( 'wp_determine_option_autoload_value' ) ) {
			try {
				$autoload = wp_determine_option_autoload_value( $option, $value, $serialized, false );
				if ( is_string( $autoload ) && '' !== $autoload ) {
					return $autoload;
				}
			} catch ( \Throwable $throwable ) {
				// Harness sin Core completo; compatibilidad pre-6.6 debajo.
				unset( $throwable );
			}
		}
		return 'no';
	}

	/**
	 * Invalida todas las variantes del object cache de una option.
	 *
	 * @param string $option Option.
	 * @return void
	 */
	public static function clear_option_cache( string $option ): void {
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( $option, 'options' );
			wp_cache_delete( 'alloptions', 'options' );
			wp_cache_delete( 'notoptions', 'options' );
		}
	}
}
